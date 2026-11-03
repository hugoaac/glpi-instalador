<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use CronTask;
use Throwable;
use Toolbox;

/**
 * CronTask "InstaladorExecutor", MODE_EXTERNAL (mode=1 nunca roda com cron externo).
 * Roda com: php front/cron.php --force InstaladorExecutor
 *
 * Guardas: só CLI; dono de um arquivo criado = dono do marketplace; flock + GET_LOCK (D2-08).
 * Rodada: locks -> no máximo UMA operação preparada -> catálogo online (1 h) -> limpeza de staging órfão.
 * FATIA (b): a rodada só prepara e planeja. Aprovação, backup, troca e migração são da fatia (c).
 */
final class Cron
{
    public const TASK = 'InstaladorExecutor';
    public const LOCK = 'glpi_instalador_exec';
    private const LOG = 'instalador';

    /** @return array<string,string> */
    public static function cronInfo(string $name): array
    {
        return ['description' => 'Prepara pacotes assinados (verifica e monta o plano) e atualiza o catálogo do painel'];
    }

    public static function register(): void
    {
        CronTask::register(self::class, self::TASK, MINUTE_TIMESTAMP, [
            'comment' => 'Instalador: verificação e plano de pacotes assinados',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]);
    }

    public static function cronInstaladorExecutor(CronTask $task): int
    {
        global $DB;
        if (PHP_SAPI !== 'cli') {
            Toolbox::logInFile(self::LOG, "InstaladorExecutor recusou rodar fora da CLI\n");

            return 0;
        }
        $doc = rtrim((string) GLPI_PLUGIN_DOC_DIR, '/\\') . '/instalador';
        if (!is_dir($doc) && !mkdir($doc, 0700, true)) {
            Toolbox::logInFile(self::LOG, "não criou $doc\n");

            return 0;
        }
        $mp = realpath((string) GLPI_MARKETPLACE_DIR);
        $lockPath = $doc . '/execucao.lock';
        $fh = fopen($lockPath, 'c');
        if ($mp === false || $fh === false) {
            return 0;
        }
        // O processo que cria arquivos tem de ser o dono do marketplace (senão o www-data do FPM não o enxerga).
        if (fileowner($lockPath) !== fileowner($mp)) {
            Toolbox::logInFile(self::LOG, "dono do cron (uid " . fileowner($lockPath) . ") diferente do dono do marketplace (uid " . fileowner($mp) . "): nada feito\n");
            fclose($fh);

            return 0;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return 0;
        }
        $temLock = false;
        try {
            $res = $DB->doQuery("SELECT GET_LOCK('" . self::LOCK . "', 0) AS l");
            $row = $res ? $res->fetch_assoc() : null;
            if ((int) ($row['l'] ?? 0) !== 1) {
                return 0; // outro nó/processo está rodando
            }
            $temLock = true;

            return self::rodada($task);
        } catch (Throwable $e) {
            Toolbox::logInFile(self::LOG, 'rodada falhou: ' . get_class($e) . ': ' . $e->getMessage() . "\n");

            return 0;
        } finally {
            if ($temLock) {
                $DB->doQuery("SELECT RELEASE_LOCK('" . self::LOCK . "')");
            }
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private static function rodada(CronTask $task): int
    {
        $feito = 0;
        $arm = Servico::armazem();
        // no máximo uma operação por rodada
        foreach ($arm->operacoesPorEstado(['PEDIDA']) as $op) {
            $r = Servico::preparador()->preparar((int) $op['id']);
            $task->log('op ' . $op['id'] . ': ' . $r['estado'] . ' ' . ($r['resultado'] ?? '') . ' ' . ($r['motivo'] ?? ''));
            $feito++;
            break;
        }
        $ultima = (int) Config::get('catalogo_em');
        if (time() - $ultima >= Servico::CATALOGO_A_CADA && PainelInformante::requisito() === null) {
            $c = Servico::atualizarCatalogo();
            $task->log('catálogo: ' . ($c['ok'] ? $c['novos'] . ' novos' : (string) $c['erro']));
            $feito++;
        }
        self::limpar($arm);
        $task->addVolume($feito);

        return $feito > 0 ? 1 : 0;
    }

    /** Staging órfão (.hub-staging/op<id>) com mais de 7 dias e operação já encerrada ou inexistente. */
    private static function limpar(Armazem $arm): void
    {
        $mp = realpath((string) GLPI_MARKETPLACE_DIR);
        if ($mp === false || !is_dir($mp . '/.hub-staging')) {
            return;
        }
        foreach (scandir($mp . '/.hub-staging') ?: [] as $n) {
            if (preg_match('/^op([1-9][0-9]{0,9})\z/', $n, $mm) !== 1) {
                continue;
            }
            $dir = $mp . '/.hub-staging/' . $n;
            if (is_link($dir) || !is_dir($dir) || time() - (int) filemtime($dir) < 7 * 86400) {
                continue;
            }
            $op = $arm->operacao((int) $mm[1]);
            if ($op === null || in_array($op['estado'], ['FALHA', 'CONCLUIDA'], true)) {
                Extrator::apagar($dir);
            }
        }
    }
}
