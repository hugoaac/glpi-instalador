<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use CommonGLPI;
use Session;

/** Entrada Administração > Instalador. Devolve [] sem o direito, e o core omite a entrada. */
class Menu extends CommonGLPI
{
    public static $rightname = 'plugin_instalador';

    public static function getTypeName($nb = 0): string
    {
        return 'Instalador';
    }

    public static function getIcon(): string
    {
        return 'ti ti-package-import';
    }

    public static function getMenuContent(): array
    {
        if (!Session::haveRight('plugin_instalador', READ)) {
            return [];
        }

        return [
            'title' => self::getTypeName(),
            'page'  => '/plugins/instalador/front/instalador.php',
            'icon'  => self::getIcon(),
        ];
    }
}
