<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Estado interno em glpi_configs (contexto plugin:instalador). NUNCA guarda chave de publicador:
 * as chaves são constantes de Publicadores.
 */
final class Config
{
    public const CONTEXT = 'plugin:instalador';

    private const DEFAULTS = [
        'catalogo_em'     => '0',   // unix da última tentativa online
        'catalogo_status' => '',    // JSON do resultado
        'fpm_visao'       => '',    // JSON: o que o FPM viu (GLPI_PLUGINS_DIRECTORIES etc.)
    ];

    /** @return array<string,string> */
    public static function all(): array
    {
        return array_merge(self::DEFAULTS, array_map('strval', \Config::getConfigurationValues(self::CONTEXT)));
    }

    public static function get(string $nome): string
    {
        return self::all()[$nome] ?? '';
    }

    /** @param array<string,string|int> $valores */
    public static function set(array $valores): void
    {
        \Config::setConfigurationValues(self::CONTEXT, array_map('strval', $valores));
    }

    public static function install(): void
    {
        $atual = \Config::getConfigurationValues(self::CONTEXT);
        $falta = array_diff_key(self::DEFAULTS, $atual);
        if ($falta !== []) {
            \Config::setConfigurationValues(self::CONTEXT, $falta);
        }
    }

    public static function purge(): void
    {
        global $DB;
        $DB->delete('glpi_configs', ['context' => self::CONTEXT]);
    }

    /** O que o FPM viu, gravado por uma requisição web (a CLI não enxerga o FPM). null = nunca visto. */
    public static function visaoFpm(): ?array
    {
        $j = json_decode(self::get('fpm_visao'), true);

        return is_array($j) ? $j : null;
    }

    /** Chamado nas páginas web: grava a visão atual das constantes, só se mudou. */
    public static function gravarVisaoFpm(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }
        $v = json_encode(Contexto::visaoAtual(), JSON_UNESCAPED_SLASHES);
        if ($v !== self::get('fpm_visao')) {
            self::set(['fpm_visao' => $v]);
        }
    }
}
