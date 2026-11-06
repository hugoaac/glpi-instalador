<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/** Dump do banco do plugin (desenho-m2 §5.7). Em produção é a classe Dump; nos testes, um duplo. */
interface Dumper
{
    /**
     * Grava banco.sql em $dir (0600) e devolve os fatos para o backup.json.
     *
     * @param list<string> $tabelasCore
     * @return array{arquivos:array<string,string>,tabelas:array<string,int>} nome => sha256; tabela => linhas gravadas
     */
    public function gerar(string $chave, array $tabelasCore, string $dir): array;
}
