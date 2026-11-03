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
                );
            } finally {
                if (is_file($tmpZip)) {
                    @unlink($tmpZip);
                }
            }
            Session::addMessageAfterRedirect('Pacote ' . $r['manifesto']['chave'] . ' ' . $r['manifesto']['versao'] . ' verificado e guardado.', false, INFO);
            break;

        case 'catalogo':
            $c = Servico::atualizarCatalogo();
            if ($c['ok']) {
                Session::addMessageAfterRedirect('Catálogo: ' . $c['novos'] . ' novo(s), ' . $c['conhecidos'] . ' conhecido(s), ' . count($c['rejeitados']) . ' rejeitado(s), ' . $c['retirados'] . ' marcado(s) como retirado.', false, INFO);
            } else {
                Session::addMessageAfterRedirect('Catálogo indisponível: ' . ($c['erro'] ?? '?'), false, ERROR);
            }
            break;

        case 'pedir':
            $r = Servico::pedir((int) ($_POST['pacotes_id'] ?? 0), (int) Session::getLoginUserID());
            Session::addMessageAfterRedirect($r['msg'], false, $r['ok'] ? INFO : ERROR);
            break;

        default:
            Session::addMessageAfterRedirect('Ação desconhecida.', false, ERROR);
    }
} catch (Recusa $e) {
    Session::addMessageAfterRedirect('Recusado: ' . $e->motivo . ($e->getMessage() !== $e->motivo ? ' (' . $e->getMessage() . ')' : ''), false, ERROR);
} catch (Throwable $e) {
    Toolbox::logInFile('instalador', 'pacote.form.php: ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    Session::addMessageAfterRedirect('Falha inesperada (veja files/_log/instalador.log).', false, ERROR);
}
Html::redirect($volta);
