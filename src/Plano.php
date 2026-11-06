<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * O plano em modo simulação (desenho-m2 §5.6). JSON canônico; plano_sha256 exclui o espaço livre em disco
 * e as datas, que mudam sem que o plano mude. Aprovar é aprovar ESTE plano (fatia c): qualquer fato diferente
 * na execução vira DIVERGENTE.
 */
final class Plano
{
    /**
     * @param array<string,mixed> $m manifesto validado
     * @param array{resultado:string,motivo:string} $r1
     * @param list<array{tabela:string,linhas:int}> $tabelas
     * @return array{plano:array<string,mixed>,sha256:string,disco_livre:?int}
     */
    public static function montar(array $m, ?string $de, string $keyId, array $r1, Contexto $ctx, array $tabelas, ?array $vista, bool $abaixoDaMaisNova, int $expandido, bool $ddlNoInit = false): array
    {
        $cli = $ctx->pluginsDirs;
        $fpm = $ctx->fpmDirs;
        $selectores = [
            ['tabela' => 'glpi_plugins', 'seletor' => "directory = '" . $m['chave'] . "'"],
            ['tabela' => 'glpi_configs', 'seletor' => "context = 'plugin:" . $m['chave'] . "'"],
            ['tabela' => 'glpi_profilerights', 'seletor' => "name = 'plugin_" . $m['chave'] . "' ou prefixo 'plugin_" . $m['chave'] . "_'"],
            ['tabela' => 'glpi_crontasks', 'seletor' => "itemtype do plugin '" . $m['chave'] . "'"],
            ['tabela' => 'glpi_displaypreferences', 'seletor' => "itemtype do plugin '" . $m['chave'] . "'"],
        ];
        $plano = [
            'tipo'    => $de === null ? 'INSTALAR' : 'ATUALIZAR',
            'chave'   => $m['chave'],
            'de'      => $de,
            'para'    => $m['versao'],
            'migracao' => $m['migracao'],
            'tabelas_core_tocadas' => $m['tabelas_core_tocadas'],
            'pacote'  => [
                'zip_sha256'    => $m['zip_sha256'],
                'arvore_sha256' => $m['arvore_sha256'],
                'commit'        => $m['commit'],
                'key_id'        => $keyId,
                'publicado_em'  => $m['publicado_em'],
            ],
            'backup'  => [
                'tabelas_do_plugin' => $tabelas,
                'linhas_do_core'    => $selectores,
                'sensivel'          => 'pode conter dado pessoal do órgão (D2-07)',
                'retencao'          => '30 dias, exceto o da última operação de cada chave e o de um alvo de rollback possível',
            ],
            'r1'      => $r1,
            'glpi'    => ['atual' => $ctx->glpiVersao, 'min' => $m['glpi_min'], 'max' => $m['glpi_max'],
                'faixa_ok' => self::dentroDaFaixa($ctx->glpiVersao, $m['glpi_min'], $m['glpi_max'])],
            'diretorios' => ['cli' => $cli, 'fpm' => $fpm, 'iguais' => $fpm === null ? null : ($fpm === $cli)],
            'opcache_validate_timestamps' => $ctx->opcacheValidate,
            'cron_limit' => $ctx->cronLimit,
            'cron_limit_aviso' => $ctx->cronLimit > 1
                ? 'cron_limit > 1: código misto no mesmo processo durante a troca; recomendado 1 durante a janela (README)'
                : null,
            'versao_mais_nova_conhecida' => $vista['versao_max'] ?? null,
            'abaixo_da_mais_nova' => $abaixoDaMaisNova,
            'ddl_no_plugin_init' => $ddlNoInit,
            'ddl_no_plugin_init_aviso' => $ddlNoInit ? 'plugin_init_' . $m['chave'] . ' faz DDL fora do install controlado (D2-22): roda no filho do activate ou na primeira requisição FPM, em vários workers; falha ali não vira INCERTO' : null,
            'disco' => ['necessario' => max(1, $expandido) * 3],
        ];
        $livre = @disk_free_space($ctx->marketplace);

        return ['plano' => $plano, 'sha256' => hash('sha256', self::json($plano)), 'disco_livre' => $livre === false ? null : (int) $livre];
    }

    /** @param array<string,mixed> $plano */
    public static function json(array $plano): string
    {
        return json_encode($plano, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** glpi_min inclusive; glpi_max EXCLUSIVO (Plugin.php:2361); null = mesmo major do min (C3). */
    public static function dentroDaFaixa(string $glpi, string $min, ?string $max): bool
    {
        $glpi = self::tresPartes($glpi);
        if (version_compare($glpi, $min, '<')) {
            return false;
        }
        if ($max === null) {
            $major = (int) explode('.', $min)[0];

            return (int) explode('.', $glpi)[0] === $major;
        }

        return version_compare($glpi, $max, '<');
    }

    private static function tresPartes(string $v): string
    {
        return preg_match('/^\d+\.\d+\.\d+/', $v, $mm) === 1 ? $mm[0] : '0.0.0';
    }
}
