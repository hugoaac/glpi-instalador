<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Persistência do instalador. A lógica (Preparador, Catalogo, R1) só fala com esta interface: em produção
 * é ArmazemDb (tabelas glpi_plugin_instalador_*); nos testes é ArmazemMemoria, com as mesmas garantias de
 * unicidade e de transição condicional.
 */
interface Armazem
{
    /** @return array<string,mixed>|null */
    public function pacotePorId(int $id): ?array;

    /** @return array<string,mixed>|null */
    public function pacotePorHash(string $zipSha256): ?array;

    /** @return array<string,mixed>|null */
    public function pacotePorVersao(string $chave, string $versao): ?array;

    /**
     * Insere o pacote. (chave, versao) é único (D2-19): a mesma versão com outro hash lança Recusa('mesma_versao');
     * o mesmo hash devolve o id existente.
     *
     * @param array<string,mixed> $p chave, versao, zip_sha256, manifesto (b64), sig (b64), key_id, origem
     */
    public function inserirPacote(array $p): int;

    /** @return list<array<string,mixed>> */
    public function listarPacotes(): array;

    /** P-3: a marca de retirado SÓ vai num sentido. Nunca existe "desmarcar". */
    public function marcarRetirados(array $zipSha256s): int;

    public function marcarArquivo(string $zipSha256, bool $ok): void;

    /** @return array{versao_max:string,zip_sha256:string}|null */
    public function versaoVista(string $chave): ?array;

    /** P-2: só sobe. Devolve true se a maior versão vista mudou. */
    public function registrarVersaoVista(string $chave, string $versao, string $zipSha256): bool;

    /** Última operação da chave com resultado SUCESSO ou INSTALADO_SEM_VALIDAR (inclui ROLLBACK). @return array<string,mixed>|null */
    public function ultimaOperacaoOk(string $chave): ?array;

    /** Linha de glpi_plugins com directory = chave. @return array{version:string,state:int}|null */
    public function registroPlugin(string $chave): ?array;

    /** Tabelas do plugin (prefixo filtrado em PHP) com estimativa de linhas. @return list<array{tabela:string,linhas:int}> */
    public function tabelasBackup(string $chave): array;

    /** @param array<string,mixed> $dados @return int id */
    public function criarOperacao(array $dados): int;

    /** @return array<string,mixed>|null */
    public function operacao(int $id): ?array;

    /**
     * Transição condicional (D2-08): UPDATE ... WHERE id=? AND estado=<de>. true só com exatamente 1 linha afetada.
     *
     * @param array<string,mixed> $campos
     */
    public function transitar(int $id, string $de, string $para, array $campos = []): bool;

    /** Só INSERT. $dados são FATOS (hashes, versões, contagens), nunca caminho para executar. */
    public function journal(?int $operacaoId, string $passo, string $fase, array $dados): void;

    /** Pastas de staging órfãs e operações abertas há muito tempo: usado pela limpeza. @return list<array<string,mixed>> */
    public function operacoesPorEstado(array $estados): array;

    /** Usuário por id (glpi_users). Só os campos que a execução relê: login, is_active, is_deleted (D2-20, P-4). @return array{login:string,is_active:int,is_deleted:int}|null */
    public function usuario(int $id): ?array;

    /** G7: devolve {version, state} de glpi_plugins ao que o journal T1 registrou. Só atualiza linha existente. */
    public function restaurarRegistroPlugin(string $chave, string $version, int $state): void;

    /** O journal de uma operação, em ordem. Só leitura; os dados são FATOS para comparar, nunca para executar. @return list<array{passo:string,fase:string,dados:array<string,mixed>}> */
    public function journalDe(int $operacaoId): array;
}
