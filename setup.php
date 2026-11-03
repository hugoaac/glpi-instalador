<?php

/**
 * instalador: verifica pacotes assinados e planeja a instalação em GLPI_MARKETPLACE_DIR.
 * Alvo: GLPI 11.0.x, PHP 8.2+.
 *
 * FATIA (b) do M2 (docs/desenho-m2.md do painel-adm): SÓ VERIFICA E PLANEJA. Não há backup, troca de pasta,
 * migração nem ativação neste código. Isso é a fatia (c).
 */

use Glpi\Plugin\Hooks;

if (!defined('GLPI_ROOT')) {
    die('Sorry. You cannot access this file directly');
}

// setup.php e hook.php leem a MESMA constante: versão dessincronizada deixaria a migration parada.
if (!defined('PLUGIN_INSTALADOR_VERSION')) {
    define('PLUGIN_INSTALADOR_VERSION', '0.1.0');
}
const PLUGIN_INSTALADOR_MIN_GLPI = '11.0.0';
const PLUGIN_INSTALADOR_MAX_GLPI = '11.99.99';

// Autoload próprio: o do GLPI só age depois do plugin carregado, e o install roda antes disso.
spl_autoload_register(static function (string $class): void {
    $prefix = 'GlpiPlugin\\Instalador\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

// O nome é plugin_init_{chave}, não plugin_{chave}_init: com o nome errado nada registra e o plugin some do menu sem erro.
function plugin_init_instalador(): void
{
    /** @var array $PLUGIN_HOOKS */
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['instalador'] = true;

    if (!Plugin::isPluginActive('instalador')) {
        return;
    }

    Plugin::registerClass(\GlpiPlugin\Instalador\Perfil::class, ['addtabon' => 'Profile']);

    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['instalador'] = [
        'admin' => \GlpiPlugin\Instalador\Menu::class,
    ];
}

function plugin_version_instalador(): array
{
    return [
        'name'         => 'Instalador',
        'version'      => PLUGIN_INSTALADOR_VERSION,
        'author'       => 'Hugo Cobra',
        'license'      => 'GPLv2+',
        'homepage'     => '',
        'requirements' => [
            'glpi' => ['min' => PLUGIN_INSTALADOR_MIN_GLPI, 'max' => PLUGIN_INSTALADOR_MAX_GLPI],
            'php'  => ['min' => '8.2'],
        ],
    ];
}

function plugin_instalador_check_prerequisites(): bool
{
    if (!extension_loaded('sodium') || !function_exists('sodium_crypto_sign_verify_detached')) {
        echo 'O instalador exige a extensão sodium do PHP (verificação Ed25519 do pacote).';

        return false;
    }
    if (!extension_loaded('zip') || !extension_loaded('zlib')) {
        echo 'O instalador exige as extensões zip e zlib do PHP.';

        return false;
    }

    return true;
}

function plugin_instalador_check_config($verbose = false): bool
{
    return true;
}
