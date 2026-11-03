<?php

/**
 * instalador: detalhe de uma operação e o plano em modo simulação. Só leitura.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Instalador\Servico;

include('../../../inc/includes.php');

Session::checkRight('plugin_instalador', READ);

$id  = (int) ($_GET['id'] ?? 0);
$arm = Servico::armazem();
$op  = $id > 0 ? $arm->operacao($id) : null;
if ($op === null) {
    Html::displayNotFoundError();
}

$plano = json_decode((string) ($op['plano'] ?? ''), true);
$self  = Plugin::getWebDir('instalador') . '/front/operacao.php?id=' . $id;
$livre = @disk_free_space((string) GLPI_MARKETPLACE_DIR);

Html::header('Instalador', $self, 'admin', \GlpiPlugin\Instalador\Menu::class);
TemplateRenderer::getInstance()->display('@instalador/operacao.html.twig', [
    'op'          => $op,
    'plano'       => is_array($plano) ? $plano : null,
    'plano_json'  => is_array($plano) ? json_encode($plano, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '',
    'disco_livre' => $livre === false ? null : (int) $livre,
    'volta'       => Plugin::getWebDir('instalador') . '/front/instalador.php',
]);
Html::footer();
