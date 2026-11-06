<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/** Armazém em memória para os testes. Reproduz unicidade, retirado de mão única e transição condicional. */
class ArmazemMemoria implements Armazem
{
    /** @var array<int,array<string,mixed>> */
    public array $pacotes = [];
    /** @var array<string,array{versao_max:string,zip_sha256:string}> */
    public array $vistas = [];
    /** @var array<int,array<string,mixed>> */
    public array $operacoes = [];
    /**
     * Primeiro id = idBase + 1. Teste que roda sobre um marketplace REAL usa um idBase alto: com ids a partir
     * de 1, o staging do teste era o .hub-staging/op1 de uma operação real, e a limpeza o apagava
     * (Hubedesk, 06/11/2026).
     */
    public int $idBase = 0;
    /** @var list<array<string,mixed>> */
    public array $journal = [];
    /** @var array<string,array{version:string,state:int}> */
    public array $plugins = [];
    /** @var array<string,list<array{tabela:string,linhas:int}>> */
    public array $tabelas = [];

    public function pacotePorId(int $id): ?array
    {
        return $this->pacotes[$id] ?? null;
    }

    public function pacotePorHash(string $zipSha256): ?array
    {
        foreach ($this->pacotes as $p) {
            if ($p['zip_sha256'] === $zipSha256) {
                return $p;
            }
        }

        return null;
    }

    public function pacotePorVersao(string $chave, string $versao): ?array
    {
        foreach ($this->pacotes as $p) {
            if ($p['chave'] === $chave && $p['versao'] === $versao) {
                return $p;
            }
        }

        return null;
    }

    public function inserirPacote(array $p): int
    {
        $porHash = $this->pacotePorHash($p['zip_sha256']);
        if ($porHash !== null && ($porHash['chave'] !== $p['chave'] || $porHash['versao'] !== $p['versao'])) {
            throw new Recusa('zip_repetido', $p['zip_sha256']);
        }
        $existente = $this->pacotePorVersao($p['chave'], $p['versao']);
        if ($existente !== null) {
            if ($existente['zip_sha256'] !== $p['zip_sha256']) {
                throw new Recusa('mesma_versao', $p['chave'] . ' ' . $p['versao']);
            }

            return (int) $existente['id'];
        }
        $id = count($this->pacotes) + 1;
        $this->pacotes[$id] = $p + ['id' => $id, 'arquivo_ok' => 0, 'retirado' => 0, 'verificado_em' => null, 'motivo_recusa' => null];
        $this->pacotes[$id]['id'] = $id;

        return $id;
    }

    public function listarPacotes(): array
    {
        return array_values($this->pacotes);
    }

    public function marcarRetirados(array $zipSha256s): int
    {
        $n = 0;
        foreach ($this->pacotes as $id => $p) {
            if (in_array($p['zip_sha256'], $zipSha256s, true) && (int) $p['retirado'] === 0) {
                $this->pacotes[$id]['retirado'] = 1;
                $n++;
            }
        }

        return $n;
    }

    public function marcarArquivo(string $zipSha256, bool $ok): void
    {
        foreach ($this->pacotes as $id => $p) {
            if ($p['zip_sha256'] === $zipSha256) {
                $this->pacotes[$id]['arquivo_ok'] = $ok ? 1 : 0;
            }
        }
    }

    public function versaoVista(string $chave): ?array
    {
        return $this->vistas[$chave] ?? null;
    }

    public function registrarVersaoVista(string $chave, string $versao, string $zipSha256): bool
    {
        $v = $this->vistas[$chave] ?? null;
        if ($v === null || Manifesto::compararVersao($versao, $v['versao_max']) > 0) {
            $this->vistas[$chave] = ['versao_max' => $versao, 'zip_sha256' => $zipSha256];

            return true;
        }

        return false;
    }

    public function ultimaOperacaoOk(string $chave): ?array
    {
        $ult = null;
        foreach ($this->operacoes as $o) {
            if ($o['chave'] === $chave && in_array($o['resultado'] ?? null, ['SUCESSO', 'INSTALADO_SEM_VALIDAR'], true)) {
                $ult = $o;
            }
        }

        return $ult;
    }

    public function registroPlugin(string $chave): ?array
    {
        return $this->plugins[$chave] ?? null;
    }

    public function tabelasBackup(string $chave): array
    {
        return $this->tabelas[$chave] ?? [];
    }

    public function criarOperacao(array $dados): int
    {
        // B-07: no máximo UMA operação aberta por chave (no banco: coluna gerada com UNIQUE).
        foreach ($this->operacoes as $o) {
            if ($o['chave'] === $dados['chave'] && !in_array($o['estado'], ['FALHA', 'CONCLUIDA'], true)) {
                throw new Recusa('operacao_aberta', $dados['chave']);
            }
        }
        $id = $this->idBase + count($this->operacoes) + 1;
        $this->operacoes[$id] = $dados + ['id' => $id, 'estado' => 'PEDIDA', 'resultado' => null, 'motivo' => null,
            'plano' => null, 'plano_sha256' => null, 'arvore_final' => null];
        $this->operacoes[$id]['id'] = $id;

        return $id;
    }

    public function operacao(int $id): ?array
    {
        return $this->operacoes[$id] ?? null;
    }

    public function transitar(int $id, string $de, string $para, array $campos = []): bool
    {
        if (!isset($this->operacoes[$id]) || $this->operacoes[$id]['estado'] !== $de) {
            return false;
        }
        $this->operacoes[$id] = array_merge($this->operacoes[$id], $campos, ['estado' => $para]);

        return true;
    }

    public function journal(?int $operacaoId, string $passo, string $fase, array $dados): void
    {
        $this->journal[] = ['operacoes_id' => $operacaoId, 'passo' => $passo, 'fase' => $fase, 'dados' => $dados];
    }

    public function operacoesPorEstado(array $estados): array
    {
        return array_values(array_filter($this->operacoes, static fn(array $o): bool => in_array($o['estado'], $estados, true)));
    }

    /** @var array<int,array{login:string,is_active:int,is_deleted:int}> */
    public array $usuarios = [];

    public function usuario(int $id): ?array
    {
        return $this->usuarios[$id] ?? null;
    }

    public function restaurarRegistroPlugin(string $chave, string $version, int $state): void
    {
        if (isset($this->plugins[$chave])) {
            $this->plugins[$chave] = ['version' => $version, 'state' => $state];
        }
    }

    public function journalDe(int $operacaoId): array
    {
        return array_values(array_filter($this->journal, static fn(array $j): bool => $j['operacoes_id'] === $operacaoId));
    }
}
