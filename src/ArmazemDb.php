<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Armazém em MySQL/MariaDB. Sempre $DB->request()/insert()/update()/delete(); nenhum SQL com valor interpolado.
 * Toda transição de estado é UPDATE ... WHERE id=? AND estado=<esperado> e só vale com 1 linha afetada (D2-08).
 */
final class ArmazemDb implements Armazem
{
    private const T_PACOTES = 'glpi_plugin_instalador_pacotes';
    private const T_VISTAS  = 'glpi_plugin_instalador_versoes_vistas';
    private const T_OPS     = 'glpi_plugin_instalador_operacoes';
    private const T_JOURNAL = 'glpi_plugin_instalador_journal';

    private static function agora(): string
    {
        return date('Y-m-d H:i:s');
    }

    /** @return array<string,mixed>|null */
    private function um(string $tabela, array $where): ?array
    {
        global $DB;
        foreach ($DB->request(['FROM' => $tabela, 'WHERE' => $where, 'LIMIT' => 1]) as $row) {
            return $row;
        }

        return null;
    }

    public function pacotePorId(int $id): ?array
    {
        return $this->um(self::T_PACOTES, ['id' => $id]);
    }

    public function pacotePorHash(string $zipSha256): ?array
    {
        return $this->um(self::T_PACOTES, ['zip_sha256' => $zipSha256]);
    }

    public function pacotePorVersao(string $chave, string $versao): ?array
    {
        return $this->um(self::T_PACOTES, ['chave' => $chave, 'versao' => $versao]);
    }

    public function inserirPacote(array $p): int
    {
        global $DB;
        $existente = $this->pacotePorVersao($p['chave'], $p['versao']);
        if ($existente !== null) {
            if ($existente['zip_sha256'] !== $p['zip_sha256']) {
                throw new Recusa('mesma_versao', $p['chave'] . ' ' . $p['versao']);
            }

            return (int) $existente['id'];
        }
        $ok = $DB->insert(self::T_PACOTES, [
            'chave' => $p['chave'], 'versao' => $p['versao'], 'zip_sha256' => $p['zip_sha256'],
            'manifesto' => $p['manifesto'], 'sig' => $p['sig'], 'key_id' => $p['key_id'], 'origem' => $p['origem'],
            'arquivo_ok' => 0, 'retirado' => 0, 'date_creation' => self::agora(),
        ]);
        if (!$ok) {
            // corrida contra o índice único (chave, versao) ou zip_sha256
            $de_novo = $this->pacotePorVersao($p['chave'], $p['versao']);
            if ($de_novo !== null && $de_novo['zip_sha256'] === $p['zip_sha256']) {
                return (int) $de_novo['id'];
            }
            throw new Recusa('mesma_versao', $p['chave'] . ' ' . $p['versao']);
        }

        return (int) $DB->insertId();
    }

    public function listarPacotes(): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::T_PACOTES, 'ORDER' => ['chave ASC', 'id DESC']]) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    public function marcarRetirados(array $zipSha256s): int
    {
        global $DB;
        $n = 0;
        foreach ($zipSha256s as $h) {
            if (preg_match(Manifesto::RE_HEX64, (string) $h) !== 1) {
                continue;
            }
            // Só liga: a cláusula retirado=0 e o valor 1 são fixos; não existe caminho que escreva 0.
            $DB->update(self::T_PACOTES, ['retirado' => 1], ['zip_sha256' => $h, 'retirado' => 0]);
            $n += (int) $DB->affectedRows();
        }

        return $n;
    }

    public function marcarArquivo(string $zipSha256, bool $ok): void
    {
        global $DB;
        $DB->update(self::T_PACOTES, ['arquivo_ok' => $ok ? 1 : 0, 'verificado_em' => self::agora()], ['zip_sha256' => $zipSha256]);
    }

    public function versaoVista(string $chave): ?array
    {
        $r = $this->um(self::T_VISTAS, ['chave' => $chave]);

        return $r === null ? null : ['versao_max' => (string) $r['versao_max'], 'zip_sha256' => (string) $r['zip_sha256']];
    }

    public function registrarVersaoVista(string $chave, string $versao, string $zipSha256): bool
    {
        global $DB;
        $atual = $this->versaoVista($chave);
        if ($atual === null) {
            $DB->insert(self::T_VISTAS, ['chave' => $chave, 'versao_max' => $versao, 'zip_sha256' => $zipSha256, 'visto_em' => self::agora()]);

            return true;
        }
        if (Manifesto::compararVersao($versao, $atual['versao_max']) > 0) {
            $DB->update(self::T_VISTAS, ['versao_max' => $versao, 'zip_sha256' => $zipSha256, 'visto_em' => self::agora()], ['chave' => $chave]);

            return true;
        }

        return false;
    }

    public function ultimaOperacaoOk(string $chave): ?array
    {
        global $DB;
        foreach ($DB->request([
            'FROM' => self::T_OPS,
            'WHERE' => ['chave' => $chave, 'resultado' => ['SUCESSO', 'INSTALADO_SEM_VALIDAR']],
            'ORDER' => 'id DESC', 'LIMIT' => 1,
        ]) as $row) {
            return $row;
        }

        return null;
    }

    public function registroPlugin(string $chave): ?array
    {
        $r = $this->um('glpi_plugins', ['directory' => $chave]);

        return $r === null ? null : ['version' => (string) $r['version'], 'state' => (int) $r['state']];
    }

    public function tabelasBackup(string $chave): array
    {
        global $DB;
        $prefixo = 'glpi_plugin_' . $chave . '_';
        $out = [];
        // SHOW TABLES e filtro em PHP: LIKE trataria "_" como curinga e pegaria tabela de outro plugin (C2).
        foreach ($DB->doQuery('SHOW TABLES') as $row) {
            $t = (string) array_values($row)[0];
            if (str_starts_with($t, $prefixo)) {
                $out[] = ['tabela' => $t, 'linhas' => (int) countElementsInTable($t)];
            }
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['tabela'], $b['tabela']));

        return $out;
    }

    public function criarOperacao(array $dados): int
    {
        global $DB;
        $agora = self::agora();
        $DB->insert(self::T_OPS, $dados + ['estado' => 'PEDIDA', 'date_creation' => $agora, 'date_mod' => $agora]);

        return (int) $DB->insertId();
    }

    public function operacao(int $id): ?array
    {
        return $this->um(self::T_OPS, ['id' => $id]);
    }

    public function transitar(int $id, string $de, string $para, array $campos = []): bool
    {
        global $DB;
        $DB->update(self::T_OPS, $campos + ['estado' => $para, 'date_mod' => self::agora()], ['id' => $id, 'estado' => $de]);

        return $DB->affectedRows() === 1;
    }

    public function journal(?int $operacaoId, string $passo, string $fase, array $dados): void
    {
        global $DB;
        $DB->insert(self::T_JOURNAL, [
            'operacoes_id' => $operacaoId, 'passo' => $passo, 'fase' => $fase,
            'dados' => json_encode($dados, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'criado_em' => self::agora(),
        ]);
    }

    public function operacoesPorEstado(array $estados): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::T_OPS, 'WHERE' => ['estado' => $estados], 'ORDER' => 'id ASC']) as $row) {
            $out[] = $row;
        }

        return $out;
    }

    /** @return list<array<string,mixed>> as últimas operações, para a tela */
    public function ultimasOperacoes(int $n = 30): array
    {
        global $DB;
        $out = [];
        foreach ($DB->request(['FROM' => self::T_OPS, 'ORDER' => 'id DESC', 'LIMIT' => $n]) as $row) {
            $out[] = $row;
        }

        return $out;
    }
}
