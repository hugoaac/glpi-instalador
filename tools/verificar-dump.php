<?php

/**
 * Confere uma pasta de backup (backup.json x arquivos). Uso: php tools/verificar-dump.php /caminho/backup/op<id>
 * Só leitura. Restaurar (mysql --default-character-set=utf8mb4 <banco> < banco.sql) é o procedimento da fatia (d),
 * numa janela sem gravação, com o código anterior.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$dir = $argv[1] ?? '';
$j = is_file("$dir/backup.json") ? json_decode((string) file_get_contents("$dir/backup.json"), true) : null;
if (!is_array($j) || ($j['formato'] ?? '') !== 'instalador-backup-v1') {
    fwrite(STDERR, "backup.json ausente ou de outro formato\n");
    exit(2);
}
$erros = 0;
foreach ($j['arquivos'] as $nome => $hash) {
    $ok = preg_match('/^[a-z.]+\z/', (string) $nome) === 1 && is_file("$dir/$nome") && hash_file('sha256', "$dir/$nome") === $hash;
    echo ($ok ? 'ok   ' : 'ERRO ') . $nome . "\n";
    $erros += $ok ? 0 : 1;
}
foreach ($j['tabelas'] as $t => $n) {
    echo "     $t: $n linhas\n";
}
exit($erros === 0 ? 0 : 1);
