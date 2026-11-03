<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use GlpiPlugin\Informante\Api;
use RuntimeException;

/**
 * Adaptador de produção: usa SÓ a fachada fixa do informante (pedirCatalogo / pedirPacote). O cliente HTTP
 * (sem redirect, TLS verificado, connect 5 s, total 120 s, tetos no stream) vive no Api do informante (D2-18).
 */
final class PainelInformante implements PainelApi
{
    public const VERSAO_MINIMA = '1.1.0';

    private ?Api $api = null;

    /** Informante >= 1.1.0 ativo? Devolve o motivo da falta, ou null se está tudo certo. */
    public static function requisito(): ?string
    {
        if (!defined('PLUGIN_INFORMANTE_VERSION') || !class_exists(Api::class)) {
            return 'o plugin informante >= ' . self::VERSAO_MINIMA . ' precisa estar instalado e ativo';
        }
        if (version_compare((string) constant('PLUGIN_INFORMANTE_VERSION'), self::VERSAO_MINIMA, '<')) {
            return 'informante ' . constant('PLUGIN_INFORMANTE_VERSION') . ' é velho demais (precisa >= ' . self::VERSAO_MINIMA . ')';
        }

        return null;
    }

    private function api(): Api
    {
        $falta = self::requisito();
        if ($falta !== null) {
            throw new RuntimeException($falta);
        }

        return $this->api ??= Api::doGlpi();
    }

    public function pedirCatalogo(): array
    {
        return $this->api()->pedirCatalogo();
    }

    public function pedirPacote(string $zipSha256, int $maxBytes, string $destinoTmp): array
    {
        return $this->api()->pedirPacote($zipSha256, $maxBytes, $destinoTmp);
    }
}
