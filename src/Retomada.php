<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * B-07. Com os dois locks na mão (nenhum outro processo está preparando), qualquer operação ainda em PREPARANDO
 * foi interrompida (kill -9, OOM, reboot). Vira FALHA 'interrompida' e o staging dela é apagado: a chave deixa de
 * ficar bloqueada para sempre. A Preparação é idempotente e recomeça de uma nova operação.
 */
final class Retomada
{
    public static function reconciliar(Armazem $arm, Caminhos $caminhos): int
    {
        $n = 0;
        foreach ($arm->operacoesPorEstado(['PREPARANDO']) as $op) {
            $id = (int) $op['id'];
            if ($arm->transitar($id, 'PREPARANDO', 'FALHA', ['resultado' => 'FALHA', 'motivo' => 'interrompida'])) {
                $arm->journal($id, 'PREPARAR', 'ERRO', ['motivo' => 'interrompida']);
                Extrator::apagar($caminhos->stagingOp($id));
                $n++;
            }
        }
        $raiz = $caminhos->stagingRaiz();
        if ($n > 0 && is_dir($raiz) && (scandir($raiz) ?: []) === ['.', '..']) {
            @rmdir($raiz);
        }

        return $n;
    }
}
