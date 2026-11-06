<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Os fatos do ambiente que o plano registra e o Preparar confere. Value object: nos testes é montado à mão;
 * no GLPI vem de doGlpi() (constantes do processo atual e da visão que o FPM gravou).
 */
final class Contexto
{
    /**
     * @param list<string>      $pluginsDirs  GLPI_PLUGINS_DIRECTORIES como o CLI vê
     * @param list<string>|null $fpmDirs      o mesmo, como o FPM viu (gravado numa requisição web); null = nunca visto
     */
    public function __construct(
        public readonly string $marketplace,
        public readonly array $pluginsDirs,
        public readonly ?array $fpmDirs,
        public readonly string $glpiVersao,
        public readonly int $cronLimit,
        public readonly ?bool $opcacheValidate,
        public readonly string $docDir,
    ) {
    }

    /**
     * C1: o override do marketplace nativo está desligado? O teste é o MESMO do GLPI
     * (`!GLPI_MARKETPLACE_ALLOW_OVERRIDE`, Marketplace/Controller.php:392): o valor vem de variável de
     * ambiente como string, então "0" desliga e "false" (string não vazia) liga.
     */
    public static function overrideDesligado(mixed $valor): bool
    {
        return !$valor;
    }

    /** Lista de diretórios do processo atual, normalizada para comparação CLI x FPM. */
    public static function visaoAtual(): array
    {
        $dirs = defined('GLPI_PLUGINS_DIRECTORIES') ? (array) constant('GLPI_PLUGINS_DIRECTORIES') : [];

        return [
            'plugins'     => array_values(array_map('strval', $dirs)),
            'marketplace' => defined('GLPI_MARKETPLACE_DIR') ? (string) constant('GLPI_MARKETPLACE_DIR') : '',
            'doc'         => defined('GLPI_PLUGIN_DOC_DIR') ? (string) constant('GLPI_PLUGIN_DOC_DIR') : '',
            'allow_override' => defined('GLPI_MARKETPLACE_ALLOW_OVERRIDE') ? var_export(constant('GLPI_MARKETPLACE_ALLOW_OVERRIDE'), true) : 'indefinido',
        ];
    }

    public static function doGlpi(): self
    {
        global $CFG_GLPI;
        $v   = self::visaoAtual();
        $mp  = realpath($v['marketplace']);
        if ($mp === false) {
            throw new Recusa('marketplace', 'GLPI_MARKETPLACE_DIR não existe');
        }
        $fpm = Config::visaoFpm();
        $opc = ini_get('opcache.validate_timestamps');

        return new self(
            $mp,
            $v['plugins'],
            $fpm === null ? null : ($fpm['plugins'] ?? null),
            defined('GLPI_VERSION') ? (string) constant('GLPI_VERSION') : '0.0.0',
            max(1, (int) ($CFG_GLPI['cron_limit'] ?? 1)),
            $opc === false ? null : ((string) $opc === '1'),
            rtrim($v['doc'], '/\\') . '/instalador',
        );
    }
}
