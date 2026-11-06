<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Pontos de observação da execução. Em produção é NaoObserva. Os testes injetam um observador que adultera o
 * staging ou simula a morte do processo (um Error que o Executor não captura) em um ponto nomeado.
 */
interface Observador
{
    public function ponto(string $nome, int $opId): void;
}
