<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

final class NaoObserva implements Observador
{
    public function ponto(string $nome, int $opId): void
    {
    }
}
