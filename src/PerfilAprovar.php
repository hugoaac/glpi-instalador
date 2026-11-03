<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use CommonGLPI;

/** Segunda linha da matriz de direitos: só o bit UPDATE (aprovar). */
class PerfilAprovar extends CommonGLPI
{
    public static function getTypeName($nb = 0): string
    {
        return 'Instalador (aprovar)';
    }

    public function getRights($interface = 'central'): array
    {
        return [UPDATE => 'Aprovar'];
    }
}
