<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * O que a execução precisa do console do GLPI (glpi:plugin:install / activate). Em produção é o Subprocesso
 * (proc_open); nos testes é um duplo. Esta interface existe para que SÓ Subprocesso.php use proc_open (B-04).
 */
interface Console
{
    /**
     * Roda "bin/console glpi:plugin:<comando>" para a chave, como o login dado, e espera até o timeout.
     * O pid do filho é gravado em operacoes.filho_pid (estado MIGRANDO) assim que existe.
     *
     * @param 'install'|'activate' $comando
     * @return array{exit:?int,timeout:bool,pid:int,segundos:int}
     */
    public function executar(string $comando, string $chave, string $login, string $dirSaida, int $timeout, Armazem $arm, int $opId): array;

    /** O pid ainda é o filho de glpi:plugin:install desta chave? null = não há como saber (sem /proc): vale como INCERTO. */
    public function filhoVivo(int $pid, string $chave): ?bool;
}
