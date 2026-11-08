<?php

/**
 * Harness da prova AO VIVO da fatia (c): roda DENTRO do contêiner do GLPI, com o GLPI real (Kernel, banco, Subprocesso, Dump).
 * NÃO faz parte do plugin em produção (tools/ não é carregada pelo GLPI) e nunca deve ir para um ambiente de cliente.
 *
 * A chave de TESTE (semente 0x07 x 32) entra pelo construtor de Assinatura, só aqui. O Cron real usa Publicadores::LISTA
 * e por isso recusaria estes pacotes: este harness reproduz a rodada do Cron (Retomada + retomar/executar) com a Assinatura de teste.
 *
 * VIVO_EXIGIR_LIMITE=1 usa o Reautenticador como em produção (recusa em GLPI < 11.0.8); sem ela, desliga SÓ a exigência do limite de MFA.
 *
 * Uso (como www-data, o dono do marketplace):
 *   php tools/vivo.php empacotar <zip> <chave>        -> recebe o zip assinado com a chave de teste, imprime o id do pacote
 *   php tools/vivo.php pedir <pacoteId> <uid>         -> pede e prepara; imprime a operação
 *   php tools/vivo.php aprovar <opId> <login> <senha> <totp>
 *   php tools/vivo.php rodada                         -> Retomada::reconciliar, executor->retomar, senão executa UMA aprovada
 *   php tools/vivo.php totp <segredo>                 -> imprime o código TOTP (RFC 6238) de agora
 *   php tools/vivo.php reautenticar <uid> <login> <senha> <totp>
 */

declare(strict_types=1);

use Glpi\Kernel\Kernel;
use GlpiPlugin\Instalador\Aprovacao;
use GlpiPlugin\Instalador\Assinatura;
use GlpiPlugin\Instalador\Caminhos;
use GlpiPlugin\Instalador\Contexto;
use GlpiPlugin\Instalador\Dump;
use GlpiPlugin\Instalador\Executor;
use GlpiPlugin\Instalador\Manifesto;
use GlpiPlugin\Instalador\Preparador;
use GlpiPlugin\Instalador\Publicadores;
use GlpiPlugin\Instalador\ReautenticadorGlpi;
use GlpiPlugin\Instalador\Recebimento;
use GlpiPlugin\Instalador\Retomada;
use GlpiPlugin\Instalador\Servico;
use GlpiPlugin\Instalador\Subprocesso;
use GlpiPlugin\Instalador\ZipInspetor;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/** RFC 6238 (SHA-1, 30 s, 6 dígitos) sem depender do GLPI. */
function totp_rfc6238(string $segredoBase32, ?int $agora = null): string
{
    $alfa = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(strtoupper(rtrim($segredoBase32, '='))) as $c) {
        $bits .= str_pad(decbin((int) strpos($alfa, $c)), 5, '0', STR_PAD_LEFT);
    }
    $chave = '';
    foreach (str_split($bits, 8) as $b) {
        if (strlen($b) === 8) {
            $chave .= chr((int) bindec($b));
        }
    }
    $passo = intdiv($agora ?? time(), 30);
    $h = hash_hmac('sha1', pack('J', $passo), $chave, true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);

    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

$cmd = $argv[1] ?? '';
if ($cmd === 'totp') {
    echo totp_rfc6238((string) ($argv[2] ?? '')), "\n";
    exit(0);
}

require '/var/www/glpi/vendor/autoload.php';
(new Kernel())->boot();
global $DB;

$seed   = str_repeat("\x07", 32);
$par    = sodium_crypto_sign_seed_keypair($seed);
$pub    = sodium_crypto_sign_publickey($par);
$sk     = sodium_crypto_sign_secretkey($par);
$ass    = new Assinatura([['nome' => 'teste', 'publica' => base64_encode($pub)]]);
$ctx    = Contexto::doGlpi();
$arm    = Servico::armazem();
$cam    = new Caminhos($ctx->marketplace, $ctx->docDir);

$prep   = new Preparador($ctx, $arm, $ass, null, Servico::espelho($ctx));
$ex     = new Executor($ctx, $arm, $ass, $prep, Dump::doGlpi(), Subprocesso::doGlpi());

switch ($cmd) {
    case 'empacotar':
        $zip = (string) $argv[2];
        $chave = (string) $argv[3];
        $z = new ZipArchive();
        $z->open($zip);
        $commit = (string) $z->getArchiveComment();
        $z->close();
        $info = ZipInspetor::arvoreDoZip($zip, $chave, $commit);
        $versao = null;
        $z->open($zip);
        $setup = (string) $z->getFromName($chave . '/setup.php');
        $z->close();
        if (preg_match("/define\\('PLUGIN_[A-Z]+_VERSION', '([0-9.]+)'\\)/", $setup, $mm) === 1) {
            $versao = $mm[1];
        }
        $m = [
            'formato' => Manifesto::FORMATO, 'chave' => $chave, 'versao' => $versao, 'repo' => 'glpi-' . $chave, 'tag' => 'v' . $versao,
            'commit' => $commit, 'zip_sha256' => hash_file('sha256', $zip), 'zip_bytes' => filesize($zip), 'arvore_sha256' => $info['arvore'],
            'glpi_min' => '11.0.0', 'glpi_max' => null, 'migracao' => 'aditiva', 'tabelas_core_tocadas' => [],
            'saude_tabelas' => ['glpi_plugin_' . $chave . '_itens'], 'publicado_em' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $mb  = Manifesto::serializar($m);
        $sig = base64_encode(sodium_crypto_sign_detached(Manifesto::mensagem($mb), $sk));
        $sb  = Manifesto::serializarAssinatura(Publicadores::keyId($pub), $sig);
        $tmp = sys_get_temp_dir() . '/vivo-' . getmypid() . '.zip';
        copy($zip, $tmp);
        $r = Recebimento::receber($arm, $ass, $cam, $tmp, $mb, $sb, Servico::espelho($ctx));
        echo json_encode(['pacote' => $r['id'], 'versao' => $versao, 'arvore' => $info['arvore']]), "\n";
        break;

    case 'pedir':
        $p = Servico::pedir((int) $argv[2], (int) $argv[3]);
        echo json_encode($p, JSON_UNESCAPED_UNICODE), "\n";
        if ($p['ok']) {
            $r = $prep->preparar((int) $p['id']);
            echo json_encode(['op' => $p['id'], 'estado' => $r['estado'], 'resultado' => $r['resultado'], 'motivo' => $r['motivo']], JSON_UNESCAPED_UNICODE), "\n";
        }
        break;

    case 'aprovar':
        [, , $opId, $login, $senha, $cod] = $argv;
        $uid = 0;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_users', 'WHERE' => ['name' => $login]]) as $row) {
            $uid = (int) $row['id'];
        }
        $_SESSION['glpiname'] = $login; // o Reautenticador lê o login da SESSÃO, nunca do formulário
        $op = $arm->operacao((int) $opId);
        $r = Aprovacao::aprovar($arm, new ReautenticadorGlpi(getenv('VIVO_EXIGIR_LIMITE') === '1'), (int) $opId, $uid, getenv('VIVO_PERSONIFICANDO') === '1', (string) $op['plano_sha256'], true, true, $senha, $cod);
        echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'reautenticar':
        [, , $uid, $login, $senha, $cod] = $argv;
        $_SESSION['glpiname'] = $login;
        $antes = $_SESSION;
        $r = (new ReautenticadorGlpi(getenv('VIVO_EXIGIR_LIMITE') === '1'))->verificar((int) $uid, $senha, $cod);
        $depois = $_SESSION;
        echo json_encode(['erro' => $r, 'sessao_igual' => $antes === $depois, 'chaves' => array_keys($depois)], JSON_UNESCAPED_UNICODE), "\n";
        break;

    case 'rodada':
        $n = Retomada::reconciliar($arm, $cam);
        $retomadas = $ex->retomar();
        echo "reconciliadas=$n retomadas=$retomadas\n";
        if ($retomadas === 0) {
            foreach ($arm->operacoesPorEstado(['APROVADA']) as $op) {
                $r = $ex->executar((int) $op['id']);
                echo 'op ' . $op['id'] . ': ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";
                break;
            }
        }
        break;

    default:
        fwrite(STDERR, "comando desconhecido\n");
        exit(2);
}
