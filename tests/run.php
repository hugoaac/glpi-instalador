<?php

/**
 * Testes sem dependência (não exige PHPUnit nem GLPI). Uso:
 *   php tests/run.php [--grupo manifesto|zip|preparar-hostil|preparar|r1|versao|catalogo|recebimento|plano|guardas]
 *
 * Só no terminal: com o docroot na raiz do GLPI este arquivo seria servido pela web e gastaria CPU (M1-06).
 * A chave de teste (semente 0x07 x 32) é injetada PELO CONSTRUTOR de Assinatura. Nunca por configuração.
 * Variável opcional GLPI_MARKETPLACE_DIR: o grupo preparar-hostil roda nele (listagem antes e depois).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Instalador\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $f = __DIR__ . '/../src/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($f)) {
            require_once $f;
        }
    }
});

use GlpiPlugin\Instalador\Arvore;
use GlpiPlugin\Instalador\Armazem;
use GlpiPlugin\Instalador\ArmazemMemoria;
use GlpiPlugin\Instalador\Assinatura;
use GlpiPlugin\Instalador\Caminhos;
use GlpiPlugin\Instalador\Catalogo;
use GlpiPlugin\Instalador\Contexto;
use GlpiPlugin\Instalador\Extrator;
use GlpiPlugin\Instalador\Manifesto;
use GlpiPlugin\Instalador\PainelApi;
use GlpiPlugin\Instalador\Plano;
use GlpiPlugin\Instalador\Preparador;
use GlpiPlugin\Instalador\Publicadores;
use GlpiPlugin\Instalador\R1;
use GlpiPlugin\Instalador\Recebimento;
use GlpiPlugin\Instalador\Recusa;
use GlpiPlugin\Instalador\ZipInspetor;

$grupoPedido = null;
foreach ($argv as $i => $a) {
    if ($a === '--grupo') {
        $grupoPedido = $argv[$i + 1] ?? null;
    }
}

$pass = 0;
$fail = 0;
function ok(bool $cond, string $name, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        ++$pass;
        echo "  ok   $name\n";
    } else {
        ++$fail;
        echo "  FAIL $name" . ($detail !== '' ? "\n       $detail" : '') . "\n";
    }
}
function eq(mixed $got, mixed $want, string $name): void
{
    ok($got === $want, $name, 'esperado: ' . var_export($want, true) . "\n       obtido:   " . var_export($got, true));
}
/** O motivo de uma Recusa, ou null se não recusou. */
function motivo(callable $f): ?string
{
    try {
        $f();

        return null;
    } catch (Recusa $e) {
        return $e->motivo;
    }
}
function rrmdir(string $d): void
{
    Extrator::apagar($d);
}
function grupo(string $nome): bool
{
    global $grupoPedido;
    if ($grupoPedido !== null && $grupoPedido !== $nome) {
        return false;
    }
    echo "$nome\n";

    return true;
}
/** Listagem recursiva com hash, incluindo ocultos (.hub-*). */
function listar(string $dir): array
{
    $out = [];
    $walk = static function (string $d, string $rel) use (&$walk, &$out): void {
        foreach (scandir($d, SCANDIR_SORT_NONE) as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $abs = "$d/$n";
            $r   = $rel === '' ? $n : "$rel/$n";
            if (is_link($abs)) {
                $out[$r] = 'L:' . readlink($abs);
            } elseif (is_dir($abs)) {
                $out[$r] = 'D';
                $walk($abs, $r);
            } else {
                $out[$r] = 'F:' . hash_file('sha256', $abs);
            }
        }
    };
    $walk($dir, '');
    ksort($out);

    return $out;
}

$tmp = sys_get_temp_dir() . '/instalador-test-' . getmypid();
mkdir($tmp, 0700, true);
register_shutdown_function(static fn() => rrmdir($tmp));

$seed    = str_repeat("\x07", 32);
$pubB64  = base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($seed)));
$chaveTeste = [['nome' => 'teste', 'publica' => $pubB64]];
$skTeste = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($seed));
$fx      = __DIR__ . '/fixtures/zips';

/** Manifesto assinado pela chave de teste para um zip. */
function embalar(string $zipPath, array $sobre = []): array
{
    global $skTeste, $chaveTeste;
    $m = array_merge([
        'formato' => Manifesto::FORMATO, 'chave' => 'teste', 'versao' => '1.0.0', 'repo' => 'glpi-teste', 'tag' => 'v1.0.0',
        'commit' => str_repeat('a', 40), 'zip_sha256' => hash_file('sha256', $zipPath), 'zip_bytes' => filesize($zipPath),
        'arvore_sha256' => str_repeat('0', 64), 'glpi_min' => '11.0.0', 'glpi_max' => null, 'migracao' => 'nenhuma',
        'tabelas_core_tocadas' => [], 'saude_tabelas' => ['glpi_plugin_teste_configs'], 'publicado_em' => '2026-11-01T12:00:00Z',
    ], $sobre);
    if (isset($sobre['versao']) && !isset($sobre['tag'])) {
        $m['tag'] = 'v' . $sobre['versao'];
    }
    $mb  = Manifesto::serializar($m);
    $sig = base64_encode(sodium_crypto_sign_detached(Manifesto::mensagem($mb), $skTeste));
    $kid = Publicadores::keyId(base64_decode($chaveTeste[0]['publica']));

    return [$mb, Manifesto::serializarAssinatura($kid, $sig), $m];
}

/** Ambiente de Preparar isolado: marketplace, docDir, armazém e uma operação PEDIDA para o pacote. */
function cenario(string $base, ?string $marketplace = null): array
{
    $mp  = $marketplace ?? ($base . '/mp');
    $doc = $base . '/doc';
    @mkdir($mp, 0755, true);
    @mkdir($doc, 0700, true);
    $ctx = new Contexto(realpath($mp), [realpath($mp), $base . '/plugins'], null, '11.0.8', 1, true, realpath($doc));

    return [$ctx, new ArmazemMemoria(), new Caminhos(realpath($mp), realpath($doc))];
}
function pedir(ArmazemMemoria $arm, Caminhos $cam, string $zip, string $mb, string $sb, array $m): int
{
    @mkdir($cam->pastaPacotes(), 0700, true);
    $dest = $cam->zip($m['zip_sha256']);
    if (!is_file($dest)) {
        copy($zip, $dest);
    }
    $pid = $arm->inserirPacote(['chave' => $m['chave'], 'versao' => $m['versao'], 'zip_sha256' => $m['zip_sha256'],
        'manifesto' => base64_encode($mb), 'sig' => base64_encode($sb), 'key_id' => 'x', 'origem' => 'upload']);

    return $arm->criarOperacao(['chave' => $m['chave'], 'pacotes_id' => $pid, 'tipo' => 'INSTALAR']);
}

// ======================================================================== manifesto
if (grupo('manifesto')) {
    $v1 = '{"formato":"glpi-plugin-manifesto-v1","chave":"teste","versao":"1.0.0","repo":"glpi-teste","tag":"v1.0.0","commit":"0123456789abcdef0123456789abcdef01234567","zip_sha256":"e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855","zip_bytes":1,"arvore_sha256":"e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045","glpi_min":"11.0.0","glpi_max":null,"migracao":"nenhuma","tabelas_core_tocadas":[],"saude_tabelas":["glpi_plugin_teste_configs"],"publicado_em":"2026-11-01T12:00:00Z"}';
    eq(Publicadores::keyId(base64_decode($pubB64)), 'fe812c12f3ab4ce6', 'V1: key_id da chave de teste');
    eq($pubB64, '6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=', 'V1: pública da semente 0x07');
    eq(hash('sha256', $v1), '86e71962e6e59fbf5573b8d0e33942f68c7c562b0b448fe4ac15b9c4c4113b37', 'V1: sha256 do manifesto');
    $m = Manifesto::validar($v1);
    eq(Manifesto::serializar($m), $v1, 'V1: reserialização canônica = entrada, byte a byte');
    $sigV1 = 'r+V5XkTf2mzbWmPZnabSjeXc8pgCbzMf/kMsN5Qli7l/HGDCN/TZzfB985dOkkblI9p8sNhX/sG8xraCaMZdAQ==';
    eq(base64_encode(sodium_crypto_sign_detached(Manifesto::mensagem($v1), $skTeste)), $sigV1, 'V1: assinatura byte a byte (Node)');
    $ass = new Assinatura($chaveTeste);
    $sigBytes = Manifesto::serializarAssinatura('fe812c12f3ab4ce6', $sigV1);
    eq($ass->verificar($v1, $sigBytes)['manifesto']['chave'], 'teste', 'V1: Assinatura::verificar aceita');

    $neg = static function (string $nome, string $esperado, string $bytes) use ($ass, $sigV1): void {
        // reassina: o motivo tem de vir do manifesto, não da assinatura
        global $skTeste;
        $sig = base64_encode(sodium_crypto_sign_detached(Manifesto::mensagem($bytes), $skTeste));
        eq(motivo(fn() => $ass->verificar($bytes, Manifesto::serializarAssinatura('fe812c12f3ab4ce6', $sig))), $esperado, "V1-negativo: $nome -> $esperado");
    };
    $neg('\\n no fim', 'canonico', $v1 . "\n");
    $trocado = str_replace('"chave":"teste","versao":"1.0.0"', '"versao":"1.0.0","chave":"teste"', $v1);
    $neg('chaves 2 e 3 trocadas', 'canonico', $trocado);
    $neg('chave repetida', 'canonico', str_replace('"chave":"teste",', '"chave":"teste","chave":"teste",', $v1));
    $neg('versao 1.0.0-rc1', 'versao', str_replace(['"versao":"1.0.0"', '"tag":"v1.0.0"'], ['"versao":"1.0.0-rc1"', '"tag":"v1.0.0-rc1"'], $v1));
    $neg('chave instalador', 'chave', str_replace('"chave":"teste"', '"chave":"instalador"', $v1));
    $neg('chave informante', 'chave', str_replace('"chave":"teste"', '"chave":"informante"', $v1));
    $neg('tabelas_core glpi_plugin_x_y', 'tabelas_core_tocadas', str_replace('"tabelas_core_tocadas":[]', '"tabelas_core_tocadas":["glpi_plugin_x_y"]', $v1));
    $neg('tabelas_core glpi_users', 'tabelas_core_tocadas', str_replace('"tabelas_core_tocadas":[]', '"tabelas_core_tocadas":["glpi_users"]', $v1));
    $neg('versao com \\n escapado dentro do valor (regex com \\z)', 'versao', str_replace('"versao":"1.0.0"', '"versao":"1.0.0\\n"', $v1));
    $neg('espaço depois dos dois pontos', 'canonico', str_replace('"chave":"teste"', '"chave": "teste"', $v1));
    $neg('zip_bytes como float', 'zip_bytes', str_replace('"zip_bytes":1,', '"zip_bytes":1.0,', $v1));
    $neg('publicado_em 30/02', 'publicado_em', str_replace('2026-11-01T12:00:00Z', '2026-02-30T12:00:00Z', $v1));
    $neg('tag diferente da versão', 'tag', str_replace('"tag":"v1.0.0"', '"tag":"v1.0.1"', $v1));
    $neg('saude_tabelas de outro plugin', 'saude_tabelas', str_replace('glpi_plugin_teste_configs', 'glpi_plugin_outro_configs', $v1));
    eq(motivo(fn() => $ass->verificar($v1, Manifesto::serializarAssinatura('fe812c12f3ab4ce6', substr($sigV1, 0, 4) . 'A' . substr($sigV1, 5)))), 'assinatura', 'V1-negativo: assinatura adulterada');
    eq(motivo(fn() => $ass->verificar($v1, '{"formato":"glpi-plugin-assinatura-v1","key_id":"fe812c12f3ab4ce6","assinatura":"' . base64_encode(sodium_crypto_sign_detached($v1, $skTeste)) . '"}')), 'assinatura', 'V1-negativo: assinatura sem o prefixo do formato');
    eq(motivo(fn() => $ass->verificar($v1, Manifesto::serializarAssinatura('0000000000000000', $sigV1))), 'key_id', 'V1-negativo: key_id 0000000000000000');
    // chaves fixadas
    eq(Publicadores::keyId(base64_decode(Publicadores::LISTA[0]['publica'])), '38f6f9ceaa9c094c', 'chave principal fixada tem key_id 38f6f9ceaa9c094c');
    eq(count(Publicadores::LISTA), 1, 'só a principal está fixada (a reserva ainda não existe)');
    ok(!Publicadores::prontoParaCliente(), 'prontoParaCliente() é falso sem a reserva');
    eq(motivo(fn() => (new Assinatura())->verificar($v1, $sigBytes)), 'key_id', 'a chave de teste NÃO vale no verificador de produção');
    eq(motivo(fn() => Manifesto::lerAssinatura(str_repeat('a', 1025))), 'sig', '.sig acima de 1024 bytes');
}

// ======================================================================== zip
if (grupo('zip')) {
    $motivos = json_decode((string) file_get_contents("$fx/motivos.json"), true);
    ok(count($motivos) >= 30, 'motivos.json (extraído de HOSTIS do gerar.mjs do Node) tem as 30 fixtures');
    foreach ($motivos as $arq => $esperado) {
        $got = motivo(fn() => ZipInspetor::arvoreDoZip("$fx/$arq", 'teste'));
        eq($got, $esperado, "V4: $arq -> $esperado (mesmo motivo do Node)");
    }
    // V3
    $r = ZipInspetor::arvoreDoZip("$fx/arvore-m1.zip", 'teste');
    eq($r['arvore'], 'e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045', 'V3: arvore de arvore-m1.zip sem extrair');
    // positiva: saída real do git archive
    $real = json_decode((string) file_get_contents("$fx/git-archive-real.json"), true);
    $info = ZipInspetor::inspecionar("$fx/git-archive-real.zip", null, $real['commit']);
    $r    = ZipInspetor::arvoreDoZip("$fx/git-archive-real.zip", $info['raiz'], $real['commit']);
    eq($r['arvore'], $real['arvore'], 'git-archive-real.zip aceito, com a arvore do Node');
    eq(motivo(fn() => ZipInspetor::inspecionar("$fx/git-archive-real.zip", $info['raiz'], str_repeat('b', 40))), 'formato', 'git-archive-real com commit diferente do comentário -> formato');
    // vinte-mil-e-um: EOCD com 20001 entradas
    $eocd = pack('VvvvvVVv', 0x06054b50, 0, 0, 20001, 20001, 0, 0, 0);
    file_put_contents("$tmp/20001.zip", $eocd);
    eq(motivo(fn() => ZipInspetor::inspecionar("$tmp/20001.zip", 'teste')), 'entradas', 'vinte-mil-e-um -> entradas');
    // extração: tamanho-mentiroso e crc-errado passam pelo parser de estrutura e caem na extração
    foreach (['tamanho-mentiroso.zip' => ['tamanho', 'crc', 'formato'], 'crc-errado.zip' => ['crc']] as $arq => $aceitos) {
        $info = ZipInspetor::inspecionar("$fx/$arq", 'teste');
        $m = motivo(fn() => Extrator::extrair("$fx/$arq", $info, "$tmp/ext-" . basename($arq, '.zip')));
        ok($m !== null && in_array($m, $aceitos, true), "extração de $arq é recusada pelo Extrator ($m)");
    }
    // extração de zip bom: arvore da pasta = arvore do zip
    $info = ZipInspetor::inspecionar("$fx/arvore-m1.zip", 'teste');
    Extrator::extrair("$fx/arvore-m1.zip", $info, "$tmp/ext-ok");
    eq(Arvore::pasta("$tmp/ext-ok"), 'e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045', 'V3: arvore da pasta extraída = a do zip');
    eq(motivo(fn() => Extrator::extrair("$fx/arvore-m1.zip", $info, "$tmp/ext-ok")), 'staging_existe', 'Extrator não escreve em destino existente');
    // bomba por stream: para no primeiro pedaço a mais
    $conta = ['total' => 0];
    $m = motivo(function () use ($fx, &$conta) { ZipInspetor::arvoreDoZip("$fx/tamanho-mentiroso.zip", 'teste', null, $conta); });
    ok($m === 'tamanho' && $conta['total'] <= 4096 * 1032, 'tamanho-mentiroso para no primeiro pedaço inflado, nunca no fim do arquivo (' . $conta['total'] . ' bytes)');
    // zips reais do subticket (release de verdade): arvore do manifesto
    foreach (['1.1.0' => 'bd08cb488a48f2bedffdd7d7c76e3f4ef61be0ce2010f54cbbd0211d77dd0baa', '1.2.1' => 'bf398199821b826610eb04648697712144e999c6fc6f81511a584c9981f45dc5'] as $ver => $arv) {
        $mj = json_decode((string) file_get_contents(__DIR__ . "/fixtures/reais/subticket/subticket-$ver.manifesto.json"), true);
        $r  = ZipInspetor::arvoreDoZip(__DIR__ . "/fixtures/reais/subticket/subticket-$ver.zip", 'subticket', $mj['commit']);
        eq($r['arvore'], $arv, "subticket $ver real: arvore do zip = a do manifesto");
    }
}

// ======================================================================== preparar-hostil (Q1)
if (grupo('preparar-hostil')) {
    $motivos = json_decode((string) file_get_contents("$fx/motivos.json"), true);
    $env = getenv('GLPI_MARKETPLACE_DIR');
    $usaReal = is_string($env) && $env !== '' && is_dir($env);
    $base = "$tmp/hostil";
    mkdir($base, 0700, true);
    if ($usaReal) {
        echo "  (marketplace real: $env)\n";
    }
    $mpDir = $usaReal ? $env : null;
    [$ctx, $arm, $cam] = cenario($base, $mpDir);
    $mpReal = $ctx->marketplace;
    $antes = listar($mpReal);
    $ass   = new Assinatura($chaveTeste);
    $prep  = new Preparador($ctx, $arm, $ass);
    foreach ($motivos as $arq => $esperado) {
        $zip = "$fx/$arq";
        [$mb, $sb, $m] = embalar($zip);
        $m['versao'] = '1.0.0';
        $op  = pedir($arm, $cam, $zip, $mb, $sb, $m);
        $res = $prep->preparar($op);
        eq([$res['estado'], $res['motivo']], ['FALHA', 'zip_' . $esperado], "Preparar inteiro recusa $arq por zip_$esperado");
        // os hostis têm o mesmo (chave, versao); o armazém só aceita um por versão: limpa para o próximo
        $arm->pacotes = [];
        $arm->operacoes = [];
    }
    eq(listar($mpReal), $antes, 'marketplace/ e .hub-* idênticos antes e depois do corpus inteiro (hash por arquivo)');
    ok(!file_exists($mpReal . '/.hub-staging'), 'nenhum .hub-staging deixado pelos hostis');
    $achou = array_filter(array_keys(listar($mpReal)), static fn($p) => str_contains($p, 'teste'));
    eq(array_values($achou), [], 'nada com a chave "teste" em marketplace/');
    // positiva da mesma bateria: a arvore-m1 passa o MESMO Preparar e deixa o staging
    $zip = "$fx/arvore-m1.zip";
    [$mb, $sb, $m] = embalar($zip, ['arvore_sha256' => 'e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045']);
    $op  = pedir($arm, $cam, $zip, $mb, $sb, $m);
    $res = $prep->preparar($op);
    eq($res['estado'], 'PLANO_PRONTO', 'controle positivo: arvore-m1.zip chega a PLANO_PRONTO (' . ($res['motivo'] ?? '') . ')');
    ok(is_file("$mpReal/.hub-staging/op$op/teste/setup.php"), 'o staging fica em .hub-staging/op<id>/<chave>');
    ok(!file_exists("$mpReal/teste"), 'e marketplace/<chave> continua inexistente (nada de troca na fatia b)');
    Extrator::apagar("$mpReal/.hub-staging/op$op");
    @rmdir("$mpReal/.hub-staging");
    eq(listar($mpReal), $antes, 'depois de limpar o staging do controle, o marketplace volta ao estado inicial');
}

// ======================================================================== preparar (pacotes reais, chave principal)
if (grupo('preparar')) {
    $real = __DIR__ . '/fixtures/reais/subticket';
    $ass  = new Assinatura(); // chaves FIXADAS de produção: prova a principal
    $ler  = static fn(string $v): array => [file_get_contents("$real/subticket-$v.manifesto.json"), file_get_contents("$real/subticket-$v.sig"), "$real/subticket-$v.zip"];

    [$mb, $sb] = $ler('1.1.0');
    $v = $ass->verificar($mb, $sb);
    eq([$v['manifesto']['versao'], $v['key_id']], ['1.1.0', '38f6f9ceaa9c094c'], 'subticket 1.1.0 real verifica com a chave principal fixada');
    [$mb2, $sb2] = $ler('1.2.1');
    eq($ass->verificar($mb2, $sb2)['manifesto']['versao'], '1.2.1', 'subticket 1.2.1 real verifica com a chave principal fixada');
    eq(motivo(fn() => $ass->verificar($mb . ' ', $sb)), 'assinatura', '1 byte a mais no manifesto real -> recusado pela assinatura');
    $tr = $mb;
    $tr[10] = $tr[10] === 'x' ? 'y' : 'x';
    ok(motivo(fn() => $ass->verificar($tr, $sb)) !== null, '1 byte trocado no manifesto real -> recusado');

    $base = "$tmp/preparar";
    mkdir($base, 0700, true);
    [$ctx, $arm, $cam] = cenario($base);
    $prep = new Preparador($ctx, $arm, $ass);
    foreach (['1.1.0', '1.2.1'] as $ver) {
        [$mb, $sb, $zip] = $ler($ver);
        $m = json_decode($mb, true);
        $arm2 = new ArmazemMemoria();
        $prep2 = new Preparador($ctx, $arm2, $ass);
        $op  = pedir($arm2, $cam, $zip, $mb, $sb, $m);
        $res = $prep2->preparar($op);
        eq($res['estado'], 'PLANO_PRONTO', "Preparar subticket $ver real -> PLANO_PRONTO (" . ($res['motivo'] ?? '') . ')');
        $p = $res['plano'];
        eq([$p['tipo'], $p['de'], $p['para'], $p['r1']['resultado'], $p['pacote']['arvore_sha256']], ['INSTALAR', null, $ver, 'OK', $m['arvore_sha256']], "plano $ver: INSTALAR, de=null, R1 OK, arvore do manifesto");
        eq(Arvore::pasta($cam->S('subticket', $op)), $m['arvore_sha256'], "staging $ver tem a arvore do manifesto");
        eq($p['migracao'], 'aditiva', "plano $ver: migração aditiva");
        eq($p['cron_limit'], 1, 'plano mostra o cron_limit');
        eq(hash('sha256', Plano::json($p)), $arm2->operacao($op)['plano_sha256'], 'plano_sha256 = sha256 do JSON canônico do plano');
        Extrator::apagar($cam->stagingOp($op));
    }
    // 1 byte trocado no ZIP real
    [$mb, $sb, $zip] = $ler('1.1.0');
    $m = json_decode($mb, true);
    $bytes = file_get_contents($zip);
    $bytes[100] = $bytes[100] === 'x' ? 'y' : 'x';
    file_put_contents("$tmp/zip1byte.zip", $bytes);
    $arm3 = new ArmazemMemoria();
    @mkdir($cam->pastaPacotes(), 0700, true);
    $dest = $cam->zip($m['zip_sha256']);
    @unlink($dest);
    copy("$tmp/zip1byte.zip", $dest);
    $pid = $arm3->inserirPacote(['chave' => 'subticket', 'versao' => '1.1.0', 'zip_sha256' => $m['zip_sha256'], 'manifesto' => base64_encode($mb), 'sig' => base64_encode($sb), 'key_id' => 'x', 'origem' => 'upload']);
    $op = $arm3->criarOperacao(['chave' => 'subticket', 'pacotes_id' => $pid, 'tipo' => 'INSTALAR']);
    $antes = listar($ctx->marketplace);
    $res = (new Preparador($ctx, $arm3, $ass))->preparar($op);
    eq([$res['estado'], $res['motivo']], ['FALHA', 'zip_sha256'], 'zip com 1 byte trocado -> FALHA zip_sha256');
    eq(listar($ctx->marketplace), $antes, 'marketplace/ idêntico antes e depois do zip adulterado');
    // chave fora da lista: pacote assinado por outra semente
    [$mb, $sb] = embalar("$fx/arvore-m1.zip", ['arvore_sha256' => 'e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045']);
    eq(motivo(fn() => $ass->verificar($mb, $sb)), 'key_id', 'assinatura de chave fora da lista -> key_id');
}

// ======================================================================== r1
if (grupo('r1')) {
    $base = "$tmp/r1";
    mkdir($base, 0700, true);
    [$ctx, $arm] = cenario($base);
    $mp = $ctx->marketplace;
    eq(R1::avaliar($ctx, $arm, 'novo')['resultado'], 'OK', 'chave nova: OK');
    mkdir("$base/plugins/x", 0755, true);
    eq(R1::avaliar($ctx, $arm, 'x'), ['resultado' => 'GERENCIADO_EM_OUTRO_LUGAR', 'motivo' => 'existe_fora_do_marketplace'], 'plugins/x existe -> GERENCIADO_EM_OUTRO_LUGAR');
    foreach (['instalador', 'informante'] as $k) {
        eq(R1::avaliar($ctx, $arm, $k), ['resultado' => 'GERENCIADO_EM_OUTRO_LUGAR', 'motivo' => 'dependencia_do_instalador'], "recusa fixa: $k");
    }
    eq(motivo(fn() => R1::avaliar($ctx, $arm, '../x')), 'chave', 'chave com ../ é recusada antes de virar caminho');
    // VCS no marketplace
    mkdir("$mp/comgit/.git", 0755, true);
    eq(R1::avaliar($ctx, $arm, 'comgit')['motivo'], 'vcs_ou_link_no_marketplace', 'marketplace/<chave>/.git -> GERENCIADO');
    // link
    @symlink("$base/plugins/x", "$mp/lnk");
    eq(R1::avaliar($ctx, $arm, 'lnk')['motivo'], 'link_no_marketplace', 'marketplace/<chave> é link -> GERENCIADO');
    // proveniência (D2-01)
    mkdir("$mp/semorigem", 0755, true);
    file_put_contents("$mp/semorigem/setup.php", '<?php');
    eq(R1::avaliar($ctx, $arm, 'semorigem')['motivo'], 'marketplace_sem_origem', 'marketplace/x sem histórico -> marketplace_sem_origem');
    mkdir("$mp/comorigem", 0755, true);
    file_put_contents("$mp/comorigem/setup.php", '<?php');
    $arv = Arvore::pasta("$mp/comorigem");
    $arm->operacoes[1] = ['id' => 1, 'chave' => 'comorigem', 'resultado' => 'SUCESSO', 'arvore_final' => $arv, 'estado' => 'CONCLUIDA'];
    eq(R1::avaliar($ctx, $arm, 'comorigem')['resultado'], 'OK', 'com histórico e arvore igual -> OK');
    file_put_contents("$mp/comorigem/setup.php", '<?php // editado');
    eq(R1::avaliar($ctx, $arm, 'comorigem')['motivo'], 'marketplace_sem_origem', 'com histórico e arvore DIFERENTE (edição manual) -> recusa');
    // INSTALADO_SEM_VALIDAR também conta; INCERTO/FALHA não
    $arm->operacoes[1]['resultado'] = 'FALHA';
    file_put_contents("$mp/comorigem/setup.php", '<?php');
    eq(R1::avaliar($ctx, $arm, 'comorigem')['motivo'], 'marketplace_sem_origem', 'última operação FALHA não dá proveniência');
    $arm->operacoes[1]['resultado'] = 'INSTALADO_SEM_VALIDAR';
    eq(R1::avaliar($ctx, $arm, 'comorigem')['resultado'], 'OK', 'INSTALADO_SEM_VALIDAR dá proveniência');
    // linha em glpi_plugins sem pasta
    $arm->plugins['orfao'] = ['version' => '1.0.0', 'state' => 1];
    eq(R1::avaliar($ctx, $arm, 'orfao')['motivo'], 'registro_sem_pasta', 'linha em glpi_plugins sem pasta -> recusa');
}

// ======================================================================== versao
if (grupo('versao')) {
    eq(Manifesto::compararVersao('1.10.0', '1.9.9'), 1, 'semver numérico: 1.10.0 > 1.9.9');
    eq(Manifesto::compararVersao('1.2.1', '1.2.1'), 0, 'igual');
    eq(Manifesto::compararVersao('0.9.9', '1.0.0'), -1, 'menor');
    eq(Plano::dentroDaFaixa('11.0.8', '11.0.0', null), true, 'glpi_max nulo: mesmo major do min');
    eq(Plano::dentroDaFaixa('12.0.0', '11.0.0', null), false, 'glpi_max nulo: major seguinte fora');
    eq(Plano::dentroDaFaixa('11.0.8', '11.0.9', null), false, 'abaixo do min');
    eq(Plano::dentroDaFaixa('11.0.8', '11.0.0', '11.0.8'), false, 'glpi_max é exclusivo (Plugin.php:2361)');

    $base = "$tmp/versao";
    mkdir($base, 0700, true);
    [$ctx, $arm, $cam] = cenario($base);
    $ass  = new Assinatura($chaveTeste);
    $zip  = "$fx/arvore-m1.zip";
    $ARV  = 'e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045';
    $rodar = static function (string $versao, array $armCampos = [], array $sobre = []) use ($ctx, $cam, $ass, $zip, $ARV): array {
        global $skTeste;
        $arm = new ArmazemMemoria();
        foreach ($armCampos as $k => $v) {
            $arm->$k = $v;
        }
        [$mb, $sb, $m] = embalar($zip, ['versao' => $versao, 'arvore_sha256' => $ARV] + $sobre);
        $op = pedir($arm, $cam, $zip, $mb, $sb, $m);
        $r  = (new Preparador($ctx, $arm, $ass))->preparar($op);
        Extrator::apagar($cam->stagingOp($op));
        @rmdir($cam->stagingRaiz());

        return [$r, $arm, $op];
    };
    [$r] = $rodar('1.0.0', ['plugins' => ['teste' => ['version' => '1.2.0', 'state' => 1]]]);
    // linha em glpi_plugins sem pasta é R1 antes do downgrade
    eq([$r['resultado'], $r['motivo']], ['GERENCIADO_EM_OUTRO_LUGAR', 'registro_sem_pasta'], 'versão instalada sem pasta no marketplace é recusada pela R1');
    // downgrade com pasta + proveniência
    mkdir($ctx->marketplace . '/teste', 0755, true);
    file_put_contents($ctx->marketplace . '/teste/setup.php', '<?php');
    $arvM = Arvore::pasta($ctx->marketplace . '/teste');
    $hist = [1 => ['id' => 1, 'chave' => 'teste', 'resultado' => 'SUCESSO', 'arvore_final' => $arvM, 'estado' => 'CONCLUIDA', 'pacotes_id' => 0]];
    $inst = ['teste' => ['version' => '1.2.0', 'state' => 1]];
    [$r] = $rodar('1.0.0', ['plugins' => $inst, 'operacoes' => $hist]);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'downgrade'], 'versão menor que a instalada -> FALHA downgrade');
    [$r] = $rodar('1.2.0', ['plugins' => $inst, 'operacoes' => $hist]);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'mesma_versao'], 'mesma versão, hash diferente do instalado -> FALHA mesma_versao');
    [$r, $a2, $op2] = $rodar('1.3.0', ['plugins' => $inst, 'operacoes' => $hist]);
    eq([$r['estado'], $r['plano']['tipo'], $r['plano']['de'], $r['plano']['para']], ['PLANO_PRONTO', 'ATUALIZAR', '1.2.0', '1.3.0'], 'versão maior -> ATUALIZAR 1.2.0 -> 1.3.0');
    // JA_NO_ALVO: a última operação instalou exatamente este hash
    $arm4 = new ArmazemMemoria();
    [$mb, $sb, $m] = embalar($zip, ['versao' => '1.2.0', 'arvore_sha256' => $ARV]);
    $op = pedir($arm4, $cam, $zip, $mb, $sb, $m);
    $arm4->plugins = $inst;
    $arm4->operacoes[99] = ['id' => 99, 'chave' => 'teste', 'resultado' => 'SUCESSO', 'arvore_final' => $arvM, 'estado' => 'CONCLUIDA', 'pacotes_id' => 1];
    $r = (new Preparador($ctx, $arm4, $ass))->preparar($op);
    eq([$r['estado'], $r['resultado']], ['CONCLUIDA', 'JA_NO_ALVO'], 'mesma versão e mesmo hash -> JA_NO_ALVO');
    rrmdir($ctx->marketplace . '/teste');
    // versoes_vistas (D2-03 + P-2)
    [$r, $a5] = $rodar('1.0.0', ['vistas' => ['teste' => ['versao_max' => '2.0.0', 'zip_sha256' => str_repeat('1', 64)]]]);
    eq([$r['estado'], $r['plano']['abaixo_da_mais_nova'], $r['plano']['versao_mais_nova_conhecida']], ['PLANO_PRONTO', true, '2.0.0'], 'instalação nova abaixo da mais nova vista: plano exige o 2º checkbox');
    [$r, $a6] = $rodar('1.0.0');
    eq([$a6->vistas['teste']['versao_max'] ?? null, $r['plano']['abaixo_da_mais_nova']], ['1.0.0', false], 'Preparar registra versoes_vistas');
    // índice único (chave, versao) — D2-19
    $a7 = new ArmazemMemoria();
    $a7->inserirPacote(['chave' => 'teste', 'versao' => '1.0.0', 'zip_sha256' => str_repeat('a', 64), 'manifesto' => '', 'sig' => '', 'key_id' => 'x', 'origem' => 'upload']);
    eq(motivo(fn() => $a7->inserirPacote(['chave' => 'teste', 'versao' => '1.0.0', 'zip_sha256' => str_repeat('b', 64), 'manifesto' => '', 'sig' => '', 'key_id' => 'x', 'origem' => 'upload'])), 'mesma_versao', '(chave, versao) com 2º hash -> mesma_versao');
    // faixa do GLPI
    [$r] = $rodar('1.0.0', [], ['glpi_min' => '12.0.0']);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'glpi_fora_da_faixa'], 'glpi_min acima do GLPI -> FALHA glpi_fora_da_faixa');
    // retirado
    $a8 = new ArmazemMemoria();
    [$mb, $sb, $m] = embalar($zip, ['arvore_sha256' => $ARV]);
    $op = pedir($a8, $cam, $zip, $mb, $sb, $m);
    $a8->marcarRetirados([$m['zip_sha256']]);
    $r = (new Preparador($ctx, $a8, $ass))->preparar($op);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'retirado'], 'pacote retirado -> FALHA retirado');
    // operação que não está PEDIDA não roda duas vezes
    eq((new Preparador($ctx, $a8, $ass))->preparar($op)['estado'], 'IGNORADA', 'Preparar de operação já tratada é ignorado (transição condicional)');
    // diretórios CLI x FPM
    $ctxFpm = new Contexto($ctx->marketplace, $ctx->pluginsDirs, ['/outro'], '11.0.8', 1, true, $ctx->docDir);
    $a9 = new ArmazemMemoria();
    $op = pedir($a9, $cam, $zip, $mb, $sb, $m);
    $r = (new Preparador($ctxFpm, $a9, $ass))->preparar($op);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'diretorios_cli_fpm'], 'CLI e FPM com diretórios diferentes -> FALHA diretorios_cli_fpm');
}

// ======================================================================== plano
if (grupo('plano')) {
    $base = "$tmp/plano";
    mkdir($base, 0700, true);
    [$ctx, $arm, $cam] = cenario($base);
    $ctx5 = new Contexto($ctx->marketplace, $ctx->pluginsDirs, null, '11.0.8', 5, null, $ctx->docDir);
    $m = Manifesto::validar(file_get_contents(__DIR__ . '/fixtures/reais/subticket/subticket-1.2.1.manifesto.json'));
    $p1 = Plano::montar($m, '1.1.0', '38f6f9ceaa9c094c', ['resultado' => 'OK', 'motivo' => ''], $ctx, [], null, false, 100);
    $p5 = Plano::montar($m, '1.1.0', '38f6f9ceaa9c094c', ['resultado' => 'OK', 'motivo' => ''], $ctx5, [], null, false, 100);
    eq($p1['plano']['cron_limit_aviso'], null, 'cron_limit 1: sem aviso');
    ok($p5['plano']['cron_limit_aviso'] !== null && str_contains($p5['plano']['cron_limit_aviso'], 'cron_limit'), 'cron_limit 5: aviso em destaque (D2-21)');
    ok($p1['sha256'] !== $p5['sha256'], 'cron_limit é fato do plano: muda o plano_sha256');
    $p1b = Plano::montar($m, '1.1.0', '38f6f9ceaa9c094c', ['resultado' => 'OK', 'motivo' => ''], $ctx, [], null, false, 100);
    eq($p1b['sha256'], $p1['sha256'], 'plano_sha256 estável (exclui disco livre e datas)');
    $p2 = Plano::montar($m, '1.0.0', '38f6f9ceaa9c094c', ['resultado' => 'OK', 'motivo' => ''], $ctx, [], null, false, 100);
    ok($p2['sha256'] !== $p1['sha256'], '"de" diferente muda o plano_sha256');
    ok(str_contains($p1['plano']['backup']['sensivel'], 'dado pessoal'), 'plano marca o backup como sensível (D2-07)');
    ok(str_contains($p1['plano']['backup']['retencao'], '30 dias'), 'plano diz a retenção do backup');
    $ctxF = new Contexto($ctx->marketplace, $ctx->pluginsDirs, $ctx->pluginsDirs, '11.0.8', 1, true, $ctx->docDir);
    eq(Plano::montar($m, null, 'k', ['resultado' => 'OK', 'motivo' => ''], $ctxF, [], null, false, 1)['plano']['diretorios']['iguais'], true, 'diretórios iguais CLI x FPM');
}

// ======================================================================== catalogo
if (grupo('catalogo')) {
    $zip = "$fx/arvore-m1.zip";
    [$mb, $sb, $m] = embalar($zip, ['versao' => '1.0.0']);
    [$mb2, $sb2, $m2] = embalar("$fx/bomba.zip", ['versao' => '1.1.0']);
    $item = static fn(string $mb, string $sb): array => ['manifesto_b64' => base64_encode($mb), 'sig_b64' => base64_encode($sb)];
    $painel = new class implements PainelApi {
        public array $resp = [];
        public function pedirCatalogo(): array { return $this->resp; }
        public function pedirPacote(string $zipSha256, int $maxBytes, string $destinoTmp): array { return ['status' => 404]; }
    };
    $cat = static fn(array $pacotes, array $retirados): array => ['status' => 200, 'corpo' => json_encode(['formato' => 'painel-catalogo-v1', 'gerado_em' => '2026-11-01T12:00:00Z', 'pacotes' => $pacotes, 'retirados' => $retirados]), 'erro' => null];
    $ass = new Assinatura($chaveTeste);
    $arm = new ArmazemMemoria();
    $painel->resp = $cat([$item($mb, $sb), $item($mb2, $sb2)], []);
    $r = Catalogo::atualizar($painel, $arm, $ass);
    eq([$r['ok'], $r['novos'], $r['rejeitados']], [true, 2, []], 'catálogo com 2 itens válidos: 2 novos');
    eq($arm->versaoVista('teste')['versao_max'], '1.1.0', 'P-2: versoes_vistas atualizada pelo catálogo (sem Preparar nem upload)');
    // item com assinatura de outra chave e item adulterado
    $outra = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(str_repeat("\x08", 32)));
    $sigFalsa = Manifesto::serializarAssinatura('fe812c12f3ab4ce6', base64_encode(sodium_crypto_sign_detached(Manifesto::mensagem($mb), $outra)));
    $mbRuim = str_replace('"1.0.0"', '"1.0.1"', $mb);
    $painel->resp = $cat([$item($mb, $sigFalsa), $item($mbRuim, $sb)], []);
    $arm2 = new ArmazemMemoria();
    $r = Catalogo::atualizar($painel, $arm2, $ass);
    eq([$r['novos'], count($r['rejeitados']), $arm2->vistas], [0, 2, []], 'itens com assinatura inválida são rejeitados, não gravados e não contam em versoes_vistas');
    // a mesma versão com outro hash: não grava, journal
    [$mb3, $sb3] = embalar("$fx/razao.zip", ['versao' => '1.0.0']); // outro zip, MESMA (chave, versao) => D2-19
    $painel->resp = $cat([$item($mb3, $sb3)], []);
    $r = Catalogo::atualizar($painel, $arm, $ass);
    eq([$r['novos'], count($r['rejeitados'])], [0, 1], 'D2-19: 2º hash da mesma (chave, versao) vindo do catálogo é rejeitado');
    ok(count(array_filter($arm->journal, static fn($j) => ($j['dados']['motivo'] ?? '') === 'mesma_versao')) === 1, '... e vai para o journal');
    // retirados (P-3): liga e nunca desliga
    $sha = $m['zip_sha256'];
    $painel->resp = $cat([], [$sha]);
    $r = Catalogo::atualizar($painel, $arm, $ass);
    eq($r['retirados'], 1, 'catálogo com o hash em retirados marca o pacote');
    eq((int) $arm->pacotePorHash($sha)['retirado'], 1, 'pacote fica retirado');
    $painel->resp = $cat([$item($mb, $sb)], []);
    Catalogo::atualizar($painel, $arm, $ass);
    eq((int) $arm->pacotePorHash($sha)['retirado'], 1, 'P-3: catálogo posterior SEM o hash e com o pacote de volta não desfaz a marca');
    ok(!method_exists($arm, 'desmarcarRetirado') && !method_exists(Armazem::class, 'desmarcarRetirado'), 'P-3: não existe operação de desmarcar');
    // falhas de transporte e formato
    $painel->resp = ['status' => 500, 'corpo' => 'x', 'erro' => null];
    eq(Catalogo::atualizar($painel, $arm, $ass)['ok'], false, 'HTTP 500 -> não ok');
    $painel->resp = ['status' => 200, 'corpo' => '{"formato":"outro"}', 'erro' => null];
    eq(Catalogo::atualizar($painel, $arm, $ass)['erro'], 'catálogo em formato desconhecido', 'formato desconhecido');
    $painel->resp = ['status' => 200, 'corpo' => str_repeat('a', 524289), 'erro' => null];
    eq(Catalogo::atualizar($painel, $arm, $ass)['erro'], 'catálogo acima de 512 KiB', 'catálogo acima de 512 KiB');
}

// ======================================================================== recebimento (upload offline)
if (grupo('recebimento')) {
    $base = "$tmp/receb";
    mkdir($base, 0700, true);
    [$ctx, $arm, $cam] = cenario($base);
    $ass = new Assinatura($chaveTeste);
    $zip = "$fx/arvore-m1.zip";
    [$mb, $sb, $m] = embalar($zip);
    copy($zip, "$base/up.zip");
    $r = Recebimento::receber($arm, $ass, $cam, "$base/up.zip", $mb, $sb);
    eq($arm->pacotePorId($r['id'])['origem'], 'upload', 'upload offline guardado com origem=upload');
    ok(is_file($cam->zip($m['zip_sha256'])), 'zip guardado em <docDir>/pacotes/<sha256>.zip (fora de marketplace/)');
    eq($arm->versaoVista('teste')['versao_max'], '1.0.0', 'upload atualiza versoes_vistas');
    $tr = file_get_contents($zip);
    $tr[50] = $tr[50] === 'x' ? 'y' : 'x';
    file_put_contents("$base/ruim.zip", $tr);
    eq(motivo(fn() => Recebimento::receber($arm, $ass, $cam, "$base/ruim.zip", $mb, $sb)), 'zip_sha256', 'upload com 1 byte trocado no zip -> zip_sha256');
    [$mb2, $sb2] = embalar($zip, ['repo' => 'outro']); // mesmo zip, mesma versão, manifesto diferente => mesmo hash: aceito
    file_put_contents("$base/z2.zip", file_get_contents("$fx/bomba.zip"));
    [$mb3, $sb3] = embalar("$base/z2.zip");          // outro zip, MESMA versão 1.0.0
    eq(motivo(fn() => Recebimento::receber($arm, $ass, $cam, "$base/z2.zip", $mb3, $sb3)), 'mesma_versao', 'upload da mesma (chave, versao) com outro hash -> mesma_versao');
    ok(count(array_filter($arm->journal, static fn($j) => ($j['dados']['motivo'] ?? '') === 'mesma_versao')) >= 1, '... e o journal registra');
    eq(motivo(fn() => Recebimento::receber($arm, $ass, $cam, "$base/up.zip", $mb, substr($sb, 0, -2) . '}}')), 'sig', '.sig malformado');
}

// ======================================================================== guardas
if (grupo('guardas')) {
    $base = "$tmp/guardas";
    mkdir("$base/mp", 0755, true);
    mkdir("$base/doc", 0700, true);
    $cam = new Caminhos(realpath("$base/mp"), realpath("$base/doc"));
    ok(str_ends_with($cam->S('teste', 7), '/.hub-staging/op7/teste'), 'S = .hub-staging/op<id>/<chave>');
    ok(str_ends_with($cam->A('teste', '1.2.3', 7), '/.hub-anterior/teste-1.2.3-op7'), 'A = .hub-anterior/<chave>-<versao>-op<id>');
    ok(str_ends_with($cam->D(7), '/.hub-descartado/op7'), 'D = .hub-descartado/op<id>');
    ok(str_ends_with($cam->B(7), '/backup/op7'), 'B = <docDir>/backup/op<id>');
    foreach (['../x', 'a/b', 'A', '', 'a b', str_repeat('a', 65)] as $ruim) {
        eq(motivo(fn() => $cam->M($ruim)), 'chave', 'Caminhos recusa chave ' . json_encode($ruim));
    }
    eq(motivo(fn() => $cam->A('teste', '1.2', 1)), 'versao', 'Caminhos recusa versão malformada');
    eq(motivo(fn() => $cam->S('teste', 0)), 'op', 'Caminhos recusa op < 1');
    eq(motivo(fn() => $cam->zip('../../etc/passwd')), 'zip_sha256', 'Caminhos recusa hash de zip malformado');
    // link no meio do caminho: .hub-staging apontando para fora
    mkdir("$base/fora", 0755, true);
    symlink("$base/fora", "$base/mp/.hub-staging");
    eq(motivo(fn() => $cam->S('teste', 1)), 'caminho_fora_da_base', 'pai que é link para fora da base -> caminho_fora_da_base');
    unlink("$base/mp/.hub-staging");
    // o código de src/ não carrega nada do pacote nem executa
    $proibidos = ['proc_open', 'shell_exec', 'exec(', 'passthru', 'system(', 'popen(', 'eval(', 'include ', 'require '];
    $achados = [];
    foreach (glob(__DIR__ . '/../src/*.php') as $f) {
        $txt = (string) file_get_contents($f);
        foreach ($proibidos as $p) {
            if (str_contains(preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $txt), $p)) {
                $achados[] = basename($f) . ':' . $p;
            }
        }
    }
    eq($achados, [], 'fatia (b): nenhum código de execução em src/ (sem proc_open/exec/eval/include)');
    // nenhuma API de configuração acrescenta chave
    $txt = (string) file_get_contents(__DIR__ . '/../src/Publicadores.php');
    ok(!str_contains($txt, 'getenv') && !str_contains($txt, 'Config::'), 'Publicadores não lê ambiente nem configuração');
}

echo "\n$pass passaram, $fail falharam\n";
exit($fail === 0 ? 0 : 1);
