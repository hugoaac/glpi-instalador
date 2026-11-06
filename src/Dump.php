<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use mysqli;
use mysqli_result;

/**
 * Dump em PHP do banco do plugin (desenho-m2 §5.7). Instruções de UMA LINHA, com seletores EXATOS (C2):
 * "LIKE 'glpi_plugin_<chave>_%'" trataria "_" como curinga e pegaria tabela de outro plugin.
 *
 *  - SET time_zone '+00:00' + transação REPEATABLE READ com snapshot consistente (D2-07.1);
 *  - texto e data saem de HEX() com introdutor do charset da coluna (_latin1 X'E9'), nunca por escape (D2-07.3);
 *  - colunas GENERATED ficam fora do INSERT (D2-07.4);
 *  - linhas lidas com MYSQLI_USE_RESULT, uma a uma (memória constante).
 * O $DB->dbh do GLPI é protected (DBmysql.php:88): a conexão é própria, com os parâmetros que o GLPI já carregou
 * (dbhost/dbuser/dbpassword/dbdefault são públicos, DBmysql.php:64-70), sem credencial nova.
 */
final class Dump implements Dumper
{
    private const TIPOS_NUMERICOS = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'decimal', 'numeric', 'float', 'double', 'real'];
    private const TIPOS_DATA = ['date', 'datetime', 'timestamp', 'time', 'year'];
    private const TIPOS_BINARIOS = ['binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob', 'bit', 'geometry', 'point', 'linestring', 'polygon'];

    public function __construct(private readonly mysqli $c)
    {
    }

    /** Conexão própria a partir do $DB do GLPI. */
    public static function doGlpi(): self
    {
        global $DB;
        $host = (string) $DB->dbhost;
        $port = 3306;
        if (preg_match('/^(.*):(\d+)$/', $host, $mm) === 1) {
            [$host, $port] = [$mm[1], (int) $mm[2]];
        }
        $c = new mysqli($host, (string) $DB->dbuser, rawurldecode((string) $DB->dbpassword), (string) $DB->dbdefault, $port);

        return new self($c);
    }

    public function gerar(string $chave, array $tabelasCore, string $dir): array
    {
        if (preg_match(Manifesto::RE_CHAVE, $chave) !== 1) {
            throw new Recusa('chave', 'chave inválida no dump');
        }
        foreach ($tabelasCore as $t) {
            if (preg_match('/^glpi_[a-z0-9_]{1,80}\z/', (string) $t) !== 1 || str_starts_with((string) $t, 'glpi_plugin_')) {
                throw new Recusa('tabela_core', 'tabela fora da regra no dump');
            }
        }
        $this->c->set_charset('utf8mb4');
        $this->q("SET SESSION time_zone = '+00:00'");
        $this->q('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->q('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        $arq = $dir . '/banco.sql';
        $fh = fopen($arq, 'xb');
        if ($fh === false) {
            throw new Recusa('dump_arquivo', 'não criou banco.sql');
        }
        @chmod($arq, 0600);
        $contagem = [];
        try {
            fwrite($fh, "SET NAMES utf8mb4;\nSET time_zone='+00:00';\nSET SESSION sql_mode=CONCAT(@@SESSION.sql_mode, ',NO_AUTO_VALUE_ON_ZERO');\nSET FOREIGN_KEY_CHECKS=0;\n");
            // 1. tabelas do plugin: SHOW TABLES + prefixo em PHP
            $prefixo = 'glpi_plugin_' . $chave . '_';
            $proprias = [];
            $res = $this->q('SHOW TABLES');
            while ($row = $res->fetch_row()) {
                if (str_starts_with((string) $row[0], $prefixo)) {
                    $proprias[] = (string) $row[0];
                }
            }
            $res->free();
            sort($proprias, SORT_STRING);
            foreach ($proprias as $t) {
                $contagem[$t] = $this->tabela($fh, $t, null, true, $chave);
            }
            // 2. linhas do core, por seletor exato
            $core = [
                ['glpi_plugins', "directory = '" . $this->esc($chave) . "'", 'directory'],
                ['glpi_configs', "context = '" . $this->esc('plugin:' . $chave) . "'", null],
                ['glpi_profilerights', "name = '" . $this->esc('plugin_' . $chave) . "' OR name LIKE '" . $this->esc('plugin!_' . $chave . '!_') . "%' ESCAPE '!'", 'name'],
            ];
            $Chave = ucfirst($chave);
            $ns = 'GlpiPlugin' . chr(92) . $Chave . chr(92);
            $itemWhere = "LEFT(itemtype, " . strlen('Plugin' . $Chave) . ") = '" . $this->esc('Plugin' . $Chave) . "' OR LEFT(itemtype, " . strlen($ns) . ") = CONCAT('GlpiPlugin', CHAR(92), '" . $this->esc($Chave) . "', CHAR(92))";
            $core[] = ['glpi_crontasks', $itemWhere, 'itemtype'];
            $core[] = ['glpi_displaypreferences', $itemWhere, 'itemtype'];
            foreach ($core as [$t, $where, $refiltro]) {
                $contagem[$t] = $this->tabela($fh, $t, $where, false, $chave, $refiltro);
            }
            // 3. tabelas_core_tocadas: a tabela inteira (já filtrada pela lista de recusa, conferida de novo)
            foreach ($tabelasCore as $t) {
                $contagem[(string) $t] = $this->tabela($fh, (string) $t, null, true, $chave);
            }
            $this->q('COMMIT');
        } finally {
            fclose($fh);
        }
        clearstatcache(true, $arq);

        return ['arquivos' => ['banco.sql' => hash_file('sha256', $arq)], 'tabelas' => $contagem];
    }

    /** @param resource $fh @return int linhas gravadas */
    private function tabela($fh, string $t, ?string $where, bool $inteira, string $chave, ?string $refiltro = null): int
    {
        $cols = [];
        $res = $this->q('SHOW FULL COLUMNS FROM `' . $t . '`');
        while ($r = $res->fetch_assoc()) {
            $cols[] = $r;
        }
        $res->free();
        $visiveis = array_values(array_filter($cols, static fn(array $c): bool => !str_contains(strtoupper((string) $c['Extra']), 'GENERATED')));
        if ($visiveis === []) {
            return 0;
        }
        if ($inteira) {
            $cr = $this->q('SHOW CREATE TABLE `' . $t . '`')->fetch_row();
            fwrite($fh, 'DROP TABLE IF EXISTS `' . $t . "`;\n");
            fwrite($fh, str_replace(["\r\n", "\n", "\r"], ' ', (string) $cr[1]) . ";\n");
        } else {
            fwrite($fh, 'DELETE FROM `' . $t . '` WHERE ' . $where . ";\n");
        }
        $sel = [];
        $nomes = [];
        $idxRefiltro = null;
        foreach ($visiveis as $i => $c) {
            $nomes[] = '`' . $c['Field'] . '`';
            $tipo = self::tipoBase((string) $c['Type']);
            $sel[] = in_array($tipo, self::TIPOS_NUMERICOS, true) ? '`' . $c['Field'] . '`' : 'HEX(`' . $c['Field'] . '`)';
            if ($refiltro !== null && $c['Field'] === $refiltro) {
                $idxRefiltro = $i;
            }
        }
        $sql = 'SELECT ' . implode(', ', $sel) . ' FROM `' . $t . '`' . ($where !== null ? ' WHERE ' . $where : '') . ' ORDER BY 1';
        $res = $this->c->query($sql, MYSQLI_USE_RESULT);
        if (!$res instanceof mysqli_result) {
            throw new Recusa('dump_select', $t);
        }
        $n = 0;
        $cab = 'INSERT INTO `' . $t . '` (' . implode(',', $nomes) . ') VALUES (';
        while ($row = $res->fetch_row()) {
            if ($idxRefiltro !== null && !self::doPlugin($t, (string) hex2bin((string) $row[$idxRefiltro]), $chave)) {
                continue;
            }
            $vals = [];
            foreach ($visiveis as $i => $c) {
                $vals[] = self::literal($row[$i], $c);
            }
            fwrite($fh, $cab . implode(',', $vals) . ");\n");
            ++$n;
        }
        $res->free();

        return $n;
    }

    /** Refiltro em PHP do que o SQL selecionou (a regra exata do desenho §5.7). */
    public static function doPlugin(string $tabela, string $valor, string $chave): bool
    {
        $Chave = ucfirst($chave);
        if ($tabela === 'glpi_plugins') {
            return $valor === $chave;
        }
        if ($tabela === 'glpi_profilerights') {
            return $valor === 'plugin_' . $chave || str_starts_with($valor, 'plugin_' . $chave . '_');
        }
        // crontasks / displaypreferences: itemtype
        $ns = 'GlpiPlugin' . chr(92) . $Chave . chr(92);
        if (str_starts_with($valor, $ns)) {
            return true;
        }
        $p = 'Plugin' . $Chave;
        if (!str_starts_with($valor, $p)) {
            return false;
        }
        $prox = substr($valor, strlen($p), 1);

        return $prox === '' || ($prox >= 'A' && $prox <= 'Z');
    }

    /** "int(10) unsigned" -> "int"; "enum('a','b')" -> "enum". */
    public static function tipoBase(string $type): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z].*$/s', '', $type));
    }

    /** @param array<string,mixed> $col linha de SHOW FULL COLUMNS */
    public static function literal(?string $v, array $col): string
    {
        if ($v === null) {
            return 'NULL';
        }
        $tipo = self::tipoBase((string) $col['Type']);
        if (in_array($tipo, self::TIPOS_NUMERICOS, true)) {
            if (preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?\z/', $v) !== 1) {
                throw new Recusa('dump_numero', 'valor numérico fora do formato');
            }

            return $v;
        }
        if (preg_match('/^([0-9A-F]{2})*\z/', $v) !== 1) {
            throw new Recusa('dump_hex', 'HEX() devolveu algo inesperado');
        }
        if ($v === '') {
            return in_array($tipo, self::TIPOS_BINARIOS, true) ? "X''" : "''";
        }
        if (in_array($tipo, self::TIPOS_BINARIOS, true)) {
            return "X'" . $v . "'";
        }
        $cs = 'utf8mb4';
        if (in_array($tipo, self::TIPOS_DATA, true)) {
            return "_ascii X'" . $v . "'";
        }
        if (($col['Collation'] ?? null) !== null && preg_match('/^([a-z0-9]+)_/', (string) $col['Collation'], $mm) === 1) {
            $cs = $mm[1];
        }

        return '_' . $cs . " X'" . $v . "'";
    }

    private function q(string $sql): mysqli_result|bool
    {
        $r = $this->c->query($sql);
        if ($r === false) {
            throw new Recusa('dump_sql', substr($sql, 0, 60));
        }

        return $r;
    }

    private function esc(string $s): string
    {
        return $this->c->real_escape_string($s);
    }
}
