<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * O ÚNICO arquivo do plugin que chama proc_open (B-04, lista de permitidos). Desenho-m2 §5.8:
 *  - argv em ARRAY (sem shell); "--username=<login>" num único elemento, então um login que comece por "-" não vira opção;
 *  - $env = null: o filho herda o ambiente do cron e vê as mesmas constantes GLPI_*;
 *  - stdin em /dev/null; stdout/stderr em arquivos dentro do backup (op<id>/install.out e install.err);
 *  - timeout: TERM, depois KILL; o exitcode é o da PRIMEIRA leitura com running=false (proc_close depois devolve -1);
 *  - a chave passa pela regex de novo, aqui, antes de virar argumento.
 */
final class Subprocesso implements Console
{
    public function __construct(
        private readonly string $php = PHP_BINARY,
        private readonly string $glpiRoot = '',
    ) {
    }

    public static function doGlpi(): self
    {
        return new self(PHP_BINARY, defined('GLPI_ROOT') ? (string) constant('GLPI_ROOT') : '');
    }

    public function executar(string $comando, string $chave, string $login, string $dirSaida, int $timeout, Armazem $arm, int $opId): array
    {
        if (!in_array($comando, ['install', 'activate'], true)) {
            throw new Recusa('comando', 'comando não permitido');
        }
        if (preg_match(Manifesto::RE_CHAVE, $chave) !== 1) {
            throw new Recusa('chave', 'chave inválida para o console');
        }
        $argv = [$this->php, $this->glpiRoot . '/bin/console', 'glpi:plugin:' . $comando, '--no-interaction', '--username=' . $login, $chave];
        if (!is_dir($dirSaida)) {
            throw new Recusa('saida', 'pasta de saída inexistente');
        }
        $spec = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $dirSaida . '/' . $comando . '.out', 'w'],
            2 => ['file', $dirSaida . '/' . $comando . '.err', 'w'],
        ];
        $ini = time();
        $p = proc_open($argv, $spec, $pipes, $this->glpiRoot, null);
        if (!is_resource($p)) {
            return ['exit' => null, 'timeout' => false, 'pid' => 0, 'segundos' => 0];
        }
        $st  = proc_get_status($p);
        $pid = (int) $st['pid'];
        if ($comando === 'install') {
            $arm->transitar($opId, 'MIGRANDO', 'MIGRANDO', ['filho_pid' => $pid]);
            $arm->journal($opId, 'MIGRAR', 'ANTES', ['filho_pid' => $pid]);
        }
        $exit = null;
        $estourou = false;
        while (true) {
            $st = proc_get_status($p);
            if (!$st['running']) {
                $exit = (int) $st['exitcode']; // só a primeira leitura com running=false traz o código real
                break;
            }
            if (time() - $ini >= $timeout) {
                $estourou = true;
                proc_terminate($p, 15);
                $prazo = time() + 5;
                while (time() < $prazo && (proc_get_status($p)['running'])) {
                    usleep(200000);
                }
                if (proc_get_status($p)['running']) {
                    proc_terminate($p, 9);
                }
                break;
            }
            usleep(200000);
        }
        proc_close($p);

        return ['exit' => $estourou ? null : $exit, 'timeout' => $estourou, 'pid' => $pid, 'segundos' => time() - $ini];
    }

    /** O pid só vale se /proc/<pid>/cmdline tiver "glpi:plugin:install" e a chave, como argumentos inteiros (D2-04.3). */
    public function filhoVivo(int $pid, string $chave): ?bool
    {
        if ($pid < 1 || !is_dir('/proc/self')) {
            return null;
        }
        $f = '/proc/' . $pid . '/cmdline';
        $c = is_file($f) ? @file_get_contents($f) : false;
        if ($c === false || $c === '') {
            return false;
        }

        return self::cmdlineConfere($c, $chave);
    }

    /** @param string $cmdline argumentos separados por NUL */
    public static function cmdlineConfere(string $cmdline, string $chave): bool
    {
        $args = explode("\0", rtrim($cmdline, "\0"));

        return in_array('glpi:plugin:install', $args, true) && in_array($chave, $args, true);
    }
}
