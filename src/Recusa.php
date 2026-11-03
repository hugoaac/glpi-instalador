<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use RuntimeException;

/**
 * Recusa por regra: o motivo é um código curto e estável (o mesmo do Node: canonico, versao, caminho,
 * tipo, raiz, duplicado, vcs, cifrado, formato, tamanho, razao, crc, metodo, extra, assinatura, key_id...).
 * Os testes comparam o motivo, não o texto.
 */
final class Recusa extends RuntimeException
{
    public function __construct(public readonly string $motivo, string $detalhe = '')
    {
        parent::__construct($detalhe !== '' ? $motivo . ': ' . $detalhe : $motivo);
    }
}
