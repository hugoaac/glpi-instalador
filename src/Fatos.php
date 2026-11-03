<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Fatos ASSINADOS (B-02). O anti-downgrade e a proveniência não confiam em linha de banco que alguém com
 * escrita no banco do GLPI possa forjar (glpi_plugins.version, operacoes.arvore_final, versoes_vistas).
 * Só valem manifestos guardados cuja assinatura é REVERIFICADA com as chaves fixadas toda vez que são usados.
 */
final class Fatos
{
    /** Manifesto do pacote guardado, reverificado; null se qualquer coisa não confere. */
    public static function manifesto(Assinatura $ass, array $pacote): ?array
    {
        $mb = base64_decode((string) ($pacote['manifesto'] ?? ''), true);
        $sb = base64_decode((string) ($pacote['sig'] ?? ''), true);
        if ($mb === false || $sb === false) {
            return null;
        }
        try {
            $m = $ass->verificar($mb, $sb)['manifesto'];
        } catch (Recusa) {
            return null;
        }
        if ($m['chave'] !== ($pacote['chave'] ?? null) || $m['versao'] !== ($pacote['versao'] ?? null) || $m['zip_sha256'] !== ($pacote['zip_sha256'] ?? null)) {
            return null;
        }

        return $m;
    }

    /**
     * O que está em marketplace/<chave>: o manifesto assinado cujo arvore_sha256 é a árvore da pasta.
     * Havendo mais de um (mesma árvore em duas versões), vale a MAIOR versão (o lado conservador do downgrade).
     */
    public static function instalado(Armazem $arm, Assinatura $ass, string $chave, string $arvoreM): ?array
    {
        $melhor = null;
        foreach ($arm->listarPacotes() as $p) {
            if (($p['chave'] ?? null) !== $chave) {
                continue;
            }
            $m = self::manifesto($ass, $p);
            if ($m === null || $m['arvore_sha256'] !== $arvoreM) {
                continue;
            }
            if ($melhor === null || Manifesto::compararVersao($m['versao'], $melhor['versao']) > 0) {
                $melhor = $m;
            }
        }

        return $melhor;
    }

    /**
     * Maior versão já vista da chave: o MÁXIMO entre a linha do banco, o espelho em arquivo e o maior
     * manifesto assinado guardado.
     *
     * @return array{versao_max:string,fonte:string}|null
     */
    public static function maiorVista(Armazem $arm, Assinatura $ass, Espelho $espelho, string $chave): ?array
    {
        $melhor = null;
        $oferta = static function (?string $v, string $fonte) use (&$melhor): void {
            if ($v !== null && ($melhor === null || Manifesto::compararVersao($v, $melhor['versao_max']) > 0)) {
                $melhor = ['versao_max' => $v, 'fonte' => $fonte];
            }
        };
        $linha = $arm->versaoVista($chave);
        $oferta($linha['versao_max'] ?? null, 'banco');
        $oferta($espelho->maximo($chave), 'espelho em arquivo');
        foreach ($arm->listarPacotes() as $p) {
            if (($p['chave'] ?? null) === $chave && ($m = self::manifesto($ass, $p)) !== null) {
                $oferta($m['versao'], 'manifesto assinado guardado');
            }
        }

        return $melhor;
    }
}
