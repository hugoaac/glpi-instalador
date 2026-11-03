<?php

/**
 * instalador: ações de escrita da fatia (b): enviar pacote offline, atualizar catálogo, pedir preparação.
 *
 * O token CSRF do POST já é validado pelo middleware do GLPI 11 (csrf_compliant):
 * NÃO chamar Session::checkCSRF() aqui, a segunda chamada consome o token e falha.
 * Upload multipart: o token vai no formulário (campo oculto) e também no cabeçalho, quando houver AJAX.
 */

use GlpiPlugin\Instalador\Assinatura;
use GlpiPlugin\Instalador\Caminhos;
use GlpiPlugin\Instalador\Contexto;
use GlpiPlugin\Instalador\Manifesto;
use GlpiPlugin\Instalador\Recebimento;
use GlpiPlugin\Instalador\Recusa;
use GlpiPlugin\Instalador\Servico;
use GlpiPlugin\Instalador\Texto;

include('../../../inc/includes.php');

Session::checkRight('plugin_instalador', UPDATE);

$volta = Plugin::getWebDir('instalador') . '/front/instalador.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::redirect($volta);
}

$acao = (string) ($_POST['acao'] ?? '');
try {
    switch ($acao) {
        case 'upload':
            $f = $_FILES;
            foreach (['zip', 'manifesto', 'sig'] as $campo) {
                if (!isset($f[$campo]) || ($f[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f[$campo]['tmp_name'])) {
                    throw new Recusa('upload', 'faltou o arquivo "' . $campo . '" ou o PHP o recusou (upload_max_filesize / post_max_size)');
                }
            }
            // Tamanhos antes de ler qualquer coisa.
            if ((int) $f['manifesto']['size'] > Manifesto::MAX_MANIFESTO || (int) $f['sig']['size'] > Manifesto::MAX_SIG || (int) $f['zip']['size'] > Manifesto::MAX_ZIP) {
                throw new Recusa('tamanho', 'arquivo acima do teto (zip 25 MiB, manifesto 8 KiB, sig 1 KiB)');
            }
            $ctx = Contexto::doGlpi();
            $cam = new Caminhos($ctx->marketplace, $ctx->docDir);
            if (!is_dir($cam->pastaPacotes()) && !mkdir($cam->pastaPacotes(), 0700, true)) {
                throw new Recusa('pasta_pacotes');
            }
            $tmpZip = $cam->pastaPacotes() . '/upload-' . bin2hex(random_bytes(8)) . '.tmp';
            if (!move_uploaded_file((string) $f['zip']['tmp_name'], $tmpZip)) {
                throw new Recusa('upload', 'não moveu o zip');
            }
            try {
                $r = Recebimento::receber(
                    Servico::armazem(),
                    new Assinatura(),
                    $cam,
                    $tmpZip,
                    (string) file_get_contents((string) $f['manifesto']['tmp_name']),
                    (string) file_get_contents((string) $f['sig']['tmp_name']),
                    Servico::espelho($ctx),
                );
            } finally {
                if (is_file($tmpZip)) {
                    @unlink($tmpZip);
                }
            }
            Session::addMessageAfterRedirect('Pacote ' . Texto::esc($r['manifesto']['chave']) . ' ' . Texto::esc($r['manifesto']['versao']) . ' verificado e guardado.', false, INFO);
            break;

        case 'catalogo':
            // B-05: o GLPI imprime a mensagem com |raw; todo texto dinâmico (inclusive o do cURL) passa por Texto::esc.
            $c = Servico::atualizarCatalogo();
            Session::addMessageAfterRedirect(Texto::catalogo($c), false, $c['ok'] ? INFO : ERROR);
            break;

        case 'pedir':
            $r = Servico::pedir((int) ($_POST['pacotes_id'] ?? 0), (int) Session::getLoginUserID());
            Session::addMessageAfterRedirect($r['msg'], false, $r['ok'] ? INFO : ERROR);
            break;

        default:
            Session::addMessageAfterRedirect('Ação desconhecida.', false, ERROR);
    }
} catch (Recusa $e) {
    Session::addMessageAfterRedirect(Texto::recusa($e), false, ERROR);
} catch (Throwable $e) {
    Toolbox::logInFile('instalador', 'pacote.form.php: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    Session::addMessageAfterRedirect('Falha inesperada (veja files/_log/instalador.log).', false, ERROR);
}
Html::redirect($volta);
