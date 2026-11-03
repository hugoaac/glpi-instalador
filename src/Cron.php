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
    /** B-03: o nome do lock inclui o banco; GET_LOCK vale para o servidor inteiro e dois GLPI no mesmo MySQL se bloqueariam. */
    public const LOCK_NOME_SQL = "CONCAT('glpi_instalador_exec:', SHA1(DATABASE()))";
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
        // B-06: confere o euid do PROCESSO (não o dono de um lock antigo): nunca root, e igual ao dono do marketplace.
        $euid = function_exists('posix_geteuid') ? posix_geteuid() : -1;
        if (!self::euidOk($euid, (int) fileowner($mp))) {
            Toolbox::logInFile(self::LOG, "euid do cron ($euid) não é o dono do marketplace (uid " . fileowner($mp) . ") ou é root: nada feito\n");
            fclose($fh);

            return 0;
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return 0;
        }
        $temLock = false;
        try {
            $res = $DB->doQuery('SELECT GET_LOCK(' . self::LOCK_NOME_SQL . ', 0) AS l');
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
                $DB->doQuery('SELECT RELEASE_LOCK(' . self::LOCK_NOME_SQL . ')');
            }
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** O processo tem de ser o dono do marketplace e nunca root (uma falha no parser rodaria com privilégio máximo). */
    public static function euidOk(int $euid, int $donoMarketplace): bool
    {
        return $euid > 0 && $euid === $donoMarketplace;
    }

    private static function rodada(CronTask $task): int
    {
        $feito = 0;
        $arm = Servico::armazem();
        $ctx = Contexto::doGlpi();
        // B-07: PREPARANDO esquecido (kill -9, OOM) vira FALHA 'interrompida' e libera a chave.
        $n = Retomada::reconciliar($arm, new Caminhos($ctx->marketplace, $ctx->docDir));
        if ($n > 0) {
            $task->log("$n operação(ões) interrompida(s) marcada(s) como FALHA");
        }
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
