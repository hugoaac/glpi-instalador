<?php

/**
 * instalador: detalhe de uma operação, o plano e a APROVAÇÃO (fatia c).
 * CSRF: validado pelo CheckCsrfListener antes desta página. Nenhum Session::checkCSRF() manual aqui.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Instalador\Aprovacao;
use GlpiPlugin\Instalador\ReautenticadorGlpi;
use GlpiPlugin\Instalador\Servico;

include('../../../inc/includes.php');

Session::checkRight('plugin_instalador', READ);

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$arm = Servico::armazem();
$op  = $id > 0 ? $arm->operacao($id) : null;
if ($op === null) {
    Html::displayNotFoundError();
}
$self = Plugin::getWebDir('instalador') . '/front/operacao.php?id=' . $id;
$podeAprovar = Session::haveRight('plugin_instalador_aprovar', UPDATE);

if (isset($_POST['aprovar'])) {
    if (!$podeAprovar) {
        Html::displayRightError();
    }
    $res = Aprovacao::aprovar(
        $arm,
        new ReautenticadorGlpi(),
        $id,
        (int) Session::getLoginUserID(),
        Session::isImpersonateActive(),
        (string) ($_POST['plano_sha256'] ?? ''),
        ($_POST['backup_confirmado'] ?? '') === '1',
        ($_POST['abaixo_confirmado'] ?? '') === '1',
        (string) ($_POST['senha'] ?? ''),
        trim((string) ($_POST['totp'] ?? '')),
    );
    // A senha e o código TOTP não voltam ao formulário nem a lugar nenhum: saem de $_POST aqui.
    unset($_POST['senha'], $_POST['totp']);
    Session::addMessageAfterRedirect($res['ok'] ? 'Plano aprovado. O cron executa em até 1 minuto.' : htmlspecialchars((string) $res['erro']), false, $res['ok'] ? INFO : ERROR);
    Html::redirect($self);
}

$plano = json_decode((string) ($op['plano'] ?? ''), true);
$livre = @disk_free_space((string) GLPI_MARKETPLACE_DIR);

Html::header('Instalador', $self, 'admin', \GlpiPlugin\Instalador\Menu::class);
TemplateRenderer::getInstance()->display('@instalador/operacao.html.twig', [
    'op'           => $op,
    'plano'        => is_array($plano) ? $plano : null,
    'plano_json'   => is_array($plano) ? json_encode($plano, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
    'disco_livre'  => $livre === false ? null : (int) $livre,
    'volta'        => Plugin::getWebDir('instalador') . '/front/instalador.php',
    'pode_aprovar' => $podeAprovar && !Session::isImpersonateActive(),
    'acao'         => $self,
]);
Html::footer();
