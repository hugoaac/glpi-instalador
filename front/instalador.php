<?php

/**
 * instalador: Administração > Instalador. Lista requisitos, pacotes conhecidos e operações.
 * Só leitura (GET). As ações estão em pacote.form.php. FATIA (b): verifica e planeja, não executa.
 */

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Instalador\Config;
use GlpiPlugin\Instalador\Servico;

include('../../../inc/includes.php');

Session::checkRight('plugin_instalador', READ);

// O FPM é quem sabe o que o FPM vê: cada abertura desta página regrava a visão se ela mudou.
Config::gravarVisaoFpm();

$self = Plugin::getWebDir('instalador') . '/front/instalador.php';
$arm  = Servico::armazem();

$cat = json_decode(Config::get('catalogo_status'), true);
$pacotes = [];
foreach ($arm->listarPacotes() as $p) {
    $vista = $arm->versaoVista((string) $p['chave']);
    $p['mais_nova'] = $vista['versao_max'] ?? '';
    $pacotes[] = $p;
}

Html::header('Instalador', $self, 'admin', \GlpiPlugin\Instalador\Menu::class);
TemplateRenderer::getInstance()->display('@instalador/instalador.html.twig', [
    'versao'     => PLUGIN_INSTALADOR_VERSION,
    'requisitos' => Servico::requisitos(),
    'pacotes'    => $pacotes,
    'operacoes'  => $arm->ultimasOperacoes(30),
    'catalogo'   => is_array($cat) ? $cat : null,
    'catalogo_em' => (int) Config::get('catalogo_em') > 0 ? date('Y-m-d H:i:s', (int) Config::get('catalogo_em')) : 'nunca',
    'pode_editar' => Session::haveRight('plugin_instalador', UPDATE),
    'acao'       => Plugin::getWebDir('instalador') . '/front/pacote.form.php',
    'url_operacao' => Plugin::getWebDir('instalador') . '/front/operacao.php',
]);
Html::footer();
