<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use Throwable;

/**
 * Processa o catálogo do painel (desenho-m2 §2.3). O painel é espelho NÃO confiável: nada do catálogo vale
 * antes de a assinatura de CADA item passar contra as chaves fixadas, e o que `retirados` diz só restringe.
 */
final class Catalogo
{
    public const FORMATO    = 'painel-catalogo-v1';
    public const MAX_ITENS  = 500;
    public const MAX_BYTES  = 524288;

    /**
     * @return array{ok:bool,erro:?string,novos:int,conhecidos:int,rejeitados:list<string>,retirados:int,vistas:int}
     */
    public static function atualizar(PainelApi $painel, Armazem $armazem, Assinatura $assinatura, ?Espelho $espelho = null): array
    {
        $r = ['ok' => false, 'erro' => null, 'novos' => 0, 'conhecidos' => 0, 'rejeitados' => [], 'retirados' => 0, 'vistas' => 0];
        try {
            $resp = $painel->pedirCatalogo();
        } catch (Throwable $e) {
            $r['erro'] = 'painel: ' . mb_substr($e->getMessage(), 0, 120);

            return $r;
        }
        if (($resp['status'] ?? null) !== 200 || !is_string($resp['corpo'] ?? null)) {
            $r['erro'] = $resp['erro'] ?? ('HTTP ' . ($resp['status'] ?? '?'));

            return $r;
        }
        $corpo = $resp['corpo'];
        if (strlen($corpo) > self::MAX_BYTES) {
            $r['erro'] = 'catálogo acima de 512 KiB';

            return $r;
        }
        $j = json_decode($corpo, true, 8);
        if (!is_array($j) || ($j['formato'] ?? null) !== self::FORMATO || !is_array($j['pacotes'] ?? null) || !is_array($j['retirados'] ?? null)) {
            $r['erro'] = 'catálogo em formato desconhecido';

            return $r;
        }
        if (count($j['pacotes']) > self::MAX_ITENS || count($j['retirados']) > self::MAX_ITENS * 4) {
            $r['erro'] = 'catálogo com itens demais';

            return $r;
        }

        foreach ($j['pacotes'] as $i => $item) {
            try {
                if (!is_array($item) || !is_string($item['manifesto_b64'] ?? null) || !is_string($item['sig_b64'] ?? null)) {
                    throw new Recusa('item');
                }
                $mb = base64_decode($item['manifesto_b64'], true);
                $sb = base64_decode($item['sig_b64'], true);
                if ($mb === false || $sb === false) {
                    throw new Recusa('base64');
                }
                ['manifesto' => $m, 'key_id' => $keyId] = $assinatura->verificar($mb, $sb);
            } catch (Recusa $e) {
                $r['rejeitados'][] = "item $i: " . $e->motivo;
                continue;
            }
            // P-2: a cada manifesto VÁLIDO (assinatura + canônico), não só no Preparar e no upload.
            if ($armazem->registrarVersaoVista($m['chave'], $m['versao'], $m['zip_sha256'])) {
                $r['vistas']++;
            }
            $espelho?->registrar($m['chave'], $m['versao']); // B-02b: o espelho em arquivo acompanha
            $existente = $armazem->pacotePorHash($m['zip_sha256']);
            if ($existente !== null) {
                if ($existente['chave'] !== $m['chave'] || $existente['versao'] !== $m['versao']) {
                    $r['rejeitados'][] = "item $i: zip_repetido";
                    continue;
                }
                $r['conhecidos']++;
                continue;
            }
            try {
                $armazem->inserirPacote([
                    'chave' => $m['chave'], 'versao' => $m['versao'], 'zip_sha256' => $m['zip_sha256'],
                    'manifesto' => base64_encode($mb), 'sig' => base64_encode($sb), 'key_id' => $keyId, 'origem' => 'painel',
                ]);
                $r['novos']++;
            } catch (Recusa $e) { // D2-19: a mesma (chave, versao) com outro hash
                $r['rejeitados'][] = "item $i: " . $e->motivo;
                $armazem->journal(null, 'CATALOGO', 'ERRO', ['motivo' => $e->motivo, 'chave' => $m['chave'], 'versao' => $m['versao'], 'zip_sha256' => $m['zip_sha256']]);
            }
        }

        // P-3: só liga a marca. Um catálogo posterior sem o hash NÃO a desfaz (não existe caminho de desmarcar).
        $hashes = array_values(array_filter($j['retirados'], static fn($h): bool => is_string($h) && preg_match(Manifesto::RE_HEX64, $h) === 1));
        $r['retirados'] = $armazem->marcarRetirados($hashes);
        $r['ok'] = true;

        return $r;
    }
}
