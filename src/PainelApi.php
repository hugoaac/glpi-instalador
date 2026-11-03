<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * O que o instalador usa do painel. Em produção é PainelInformante (fachada Api do informante >= 1.1.0);
 * nos testes é um falso. Não há método de assinatura genérica (D2-14).
 */
interface PainelApi
{
    /** @return array{status:?int,corpo:?string,erro:?string} */
    public function pedirCatalogo(): array;

    /** @return array{status:?int,corpo:?string,erro:?string,bytes:int} */
    public function pedirPacote(string $zipSha256, int $maxBytes, string $destinoTmp): array;
}
