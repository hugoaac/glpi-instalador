<?php

/**
 * Dump contra um banco REAL. Só roda com INSTALADOR_TEST_DB="host|usuario|senha|banco".
 * Isca: chave "hubdump" (tabelas glpi_plugin_hubdump_*), perfil 9999 e contextos plugin:hubdump*. O teste só apaga o que criou.
 */

declare(strict_types=1);

use GlpiPlugin\Instalador\Dump;

if (grupo('dump')) {
    $cfg = getenv('INSTALADOR_TEST_DB');
    if ($cfg === false || $cfg === '') {
        echo "  (sem INSTALADOR_TEST_DB: grupo dump não roda)\n";

        return;
    }
    [$h, $u, $pw, $bd] = explode('|', $cfg);
    $c = new mysqli($h, $u, $pw, $bd);
    $c->set_charset('utf8mb4');
    $limpa = static function () use ($c): void {
        foreach (['glpi_plugin_hubdump_a', 'glpi_plugin_hubdump_b', 'glpi_plugin_hubdumpx_a'] as $t) {
            $c->query("DROP TABLE IF EXISTS `$t`");
        }
        $c->query("DELETE FROM glpi_profilerights WHERE profiles_id = 9999 AND name IN ('plugin_hubdump', 'plugin_hubdump_x', 'plugin_hubdumpx')");
        $c->query("DELETE FROM glpi_configs WHERE context IN ('plugin:hubdump', 'plugin:hubdumpx')");
    };
    $limpa();
    $c->query("CREATE TABLE glpi_plugin_hubdump_a (id int unsigned NOT NULL AUTO_INCREMENT, t varchar(50) CHARACTER SET latin1 COLLATE latin1_swedish_ci, u text CHARACTER SET utf8mb4, b blob, n decimal(10,2), ts timestamp NULL DEFAULT NULL, g int AS (id * 2) VIRTUAL, e enum('x','y'), PRIMARY KEY (id)) ENGINE=InnoDB");
    $c->query('CREATE TABLE glpi_plugin_hubdump_b (k int NOT NULL, v varchar(10), PRIMARY KEY (k)) ENGINE=InnoDB');
    $c->query('CREATE TABLE glpi_plugin_hubdumpx_a (k int) ENGINE=InnoDB');
    $c->query('INSERT INTO glpi_plugin_hubdumpx_a VALUES (1)');
    $c->query("SET time_zone='+00:00'");
    $c->query("INSERT INTO glpi_plugin_hubdump_a (t, u, b, n, ts, e) VALUES (_latin1 X'E9E3', 'ação 😀 ''aspas'' \\\\ barra', X'00FF0A27', -12.50, '2026-03-08 02:30:00', 'y'), (NULL, '', X'', 0, NULL, NULL)");
    $c->query("INSERT INTO glpi_plugin_hubdump_a (id, u) VALUES (0, 'zero')");
    $c->query("INSERT INTO glpi_plugin_hubdump_b VALUES (1, 'um')");
    $c->query("INSERT INTO glpi_profilerights (profiles_id, name, rights) VALUES (9999, 'plugin_hubdump', 3), (9999, 'plugin_hubdump_x', 1), (9999, 'plugin_hubdumpx', 7)");
    $c->query("INSERT INTO glpi_configs (context, name, value) VALUES ('plugin:hubdump', 'a', 'v'), ('plugin:hubdumpx', 'a', 'w')");
    $foto = static function () use ($c): string {
        $s = '';
        $r = $c->query("SELECT id, HEX(COALESCE(t,'')), HEX(COALESCE(u,'')), HEX(COALESCE(b,'')), n, COALESCE(UNIX_TIMESTAMP(ts),'nulo'), g, COALESCE(e,'-') FROM glpi_plugin_hubdump_a ORDER BY id");
        while ($row = $r->fetch_row()) {
            $s .= 'a:' . implode('|', array_map('strval', $row)) . "\n";
        }
        $r = $c->query('SELECT k, v FROM glpi_plugin_hubdump_b');
        while ($row = $r->fetch_row()) {
            $s .= 'b:' . implode('|', array_map('strval', $row)) . "\n";
        }

        return $s;
    };
    $antes = $foto();
    $dir = "$tmp/dump";
    mkdir($dir, 0700, true);
    $r = (new Dump(new mysqli($h, $u, $pw, $bd)))->gerar('hubdump', [], $dir);
    $sql = (string) file_get_contents("$dir/banco.sql");
    eq($r['tabelas']['glpi_plugin_hubdump_a'], 3, 'dump: 3 linhas em hubdump_a');
    ok(!isset($r['tabelas']['glpi_plugin_hubdumpx_a']) && !str_contains($sql, 'hubdumpx_a'), 'seletor EXATO: tabela de outro plugin (hubdumpx) fora do dump (C2)');
    eq($r['tabelas']['glpi_profilerights'], 2, 'profilerights: plugin_hubdump e plugin_hubdump_x, não plugin_hubdumpx');
    eq($r['tabelas']['glpi_configs'], 1, 'configs: só plugin:hubdump');
    ok(str_contains($sql, "_latin1 X'E9E3'"), "texto latin1 sai como _latin1 X'E9E3' (D2-07.3)");
    ok(preg_match('/^INSERT INTO `glpi_plugin_hubdump_a` \([^)]*`g`/m', $sql) === 0 && str_contains($sql, 'GENERATED ALWAYS AS'), 'coluna GENERATED no CREATE mas fora do INSERT (D2-07.4)');
    ok(str_starts_with($sql, "SET NAMES utf8mb4;\nSET time_zone='+00:00';"), 'cabeçalho do banco.sql');
    $limpa();
    foreach (explode("\n", rtrim($sql)) as $linha) { // o que o cliente mysql faria: uma instrução por linha
        $c->query($linha);
    }
    eq($foto(), $antes, 'dump, apaga, restaura: bytes, NULL, id 0, TIMESTAMP, emoji, aspas e barra voltam iguais');
    eq((int) $c->query("SELECT rights FROM glpi_profilerights WHERE name='plugin_hubdump' AND profiles_id=9999")->fetch_row()[0], 3, 'profilerights restaurado');
    eq((int) $c->query("SELECT COUNT(*) FROM glpi_profilerights WHERE name='plugin_hubdumpx' AND profiles_id=9999")->fetch_row()[0], 0, 'profilerights do outro plugin NÃO foi tocado pela restauração');

    $ns = 'GlpiPlugin' . chr(92);
    ok(Dump::doPlugin('glpi_crontasks', 'PluginHubdumpFoo', 'hubdump') && Dump::doPlugin('glpi_crontasks', 'PluginHubdump', 'hubdump'), 'itemtype PluginHubdumpFoo e PluginHubdump são do plugin');
    ok(!Dump::doPlugin('glpi_crontasks', 'PluginHubdumpxFoo', 'hubdump') && !Dump::doPlugin('glpi_crontasks', 'Pluginhubdumpfoo', 'hubdump'), 'PluginHubdumpxFoo e minúscula após o prefixo não são do plugin');
    ok(Dump::doPlugin('glpi_displaypreferences', $ns . 'Hubdump' . chr(92) . 'X', 'hubdump') && !Dump::doPlugin('glpi_displaypreferences', $ns . 'Hubdumpx' . chr(92) . 'X', 'hubdump'), 'namespace do plugin é exato');
    $limpa();

    file_put_contents("$dir/backup.json", json_encode(['formato' => 'instalador-backup-v1', 'arquivos' => ['banco.sql' => $r['arquivos']['banco.sql']], 'tabelas' => []]));
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/../tools/verificar-dump.php') . ' ' . escapeshellarg($dir) . ' >/dev/null';
    exec($cmd, $o, $rc);
    eq($rc, 0, 'tools/verificar-dump.php confere um backup íntegro');
    file_put_contents("$dir/banco.sql", $sql . ' ');
    exec($cmd, $o, $rc);
    eq($rc, 1, 'tools/verificar-dump.php recusa banco.sql adulterado');
}
