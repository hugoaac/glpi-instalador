<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * D2-22: DDL dentro de plugin_init_<chave> roda fora do install controlado (no filho do activate ou na primeira
 * requisição FPM, possivelmente em vários workers). O aviso entra no plano. A regex é a MESMA de tools/release.mjs
 * do painel (DDL e a extração do corpo de plugin_init_<chave>), para a release e o cliente concordarem.
 */
final class Ddl
{
    public const RE_DDL = '/\b(CREATE|ALTER|DROP|RENAME)\s+TABLE\b|->\s*(createTable|dropTable|addField|changeField|dropField|addKey|dropKey|renameTable|migrationOneTable)\s*\(/i';

    /** O corpo de plugin_init_<chave> no texto do setup.php (até a próxima "function" no começo de linha, ou o fim). */
    public static function corpoDoInit(string $setup, string $chave): string
    {
        if (preg_match(Manifesto::RE_CHAVE, $chave) !== 1) {
            return '';
        }
        $re = '/function\s+plugin_init_' . $chave . '\s*\([\s\S]*?(?=\nfunction\s|\z)/';

        return preg_match($re, $setup, $mm) === 1 ? $mm[0] : '';
    }

    public static function temDdl(string $texto): bool
    {
        return preg_match(self::RE_DDL, $texto) === 1;
    }

    /** O setup.php da pasta extraída (S) faz DDL em plugin_init_<chave>? Arquivo ausente ou ilegível = false. */
    public static function noPluginInit(string $pasta, string $chave): bool
    {
        $f = $pasta . '/setup.php';
        if (!is_file($f) || is_link($f) || filesize($f) > 2 * 1024 * 1024) {
            return false;
        }

        return self::temDdl(self::corpoDoInit((string) file_get_contents($f), $chave));
    }
}
