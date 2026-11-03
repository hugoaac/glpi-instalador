<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Upload offline dos três arquivos (desenho-m2 §5.10): <chave>-<versao>.zip, .manifesto.json e .sig.
 * O nome dos arquivos não carrega confiança. Aqui só entra o que a assinatura e o manifesto canônico aprovam,
 * e o zip tem o tamanho e o sha256 do manifesto. A inspeção do perfil de zip fica no Preparar.
 */
final class Recebimento
{
    /**
     * @param string $zipTmp caminho do zip já recebido (em produção, resultado de move_uploaded_file para a pasta temporária)
     * @return array{id:int,manifesto:array<string,mixed>}
     */
    public static function receber(Armazem $armazem, Assinatura $assinatura, Caminhos $caminhos, string $zipTmp, string $manifestoBytes, string $sigBytes): array
    {
        ['manifesto' => $m, 'key_id' => $keyId] = $assinatura->verificar($manifestoBytes, $sigBytes);
        clearstatcache(true, $zipTmp);
        if (!is_file($zipTmp) || filesize($zipTmp) !== $m['zip_bytes']) {
            throw new Recusa('zip_bytes');
        }
        if (hash_file('sha256', $zipTmp) !== $m['zip_sha256']) {
            throw new Recusa('zip_sha256');
        }
        // D2-19: o índice (chave, versao) recusa o segundo hash da mesma versão ANTES de guardar o zip.
        $existente = $armazem->pacotePorVersao($m['chave'], $m['versao']);
        if ($existente !== null && $existente['zip_sha256'] !== $m['zip_sha256']) {
            $armazem->journal(null, 'UPLOAD', 'ERRO', ['motivo' => 'mesma_versao', 'chave' => $m['chave'], 'versao' => $m['versao'], 'zip_sha256' => $m['zip_sha256']]);
            throw new Recusa('mesma_versao', $m['chave'] . ' ' . $m['versao']);
        }
        $pasta = $caminhos->pastaPacotes();
        if (!is_dir($pasta) && !mkdir($pasta, 0700, true)) {
            throw new Recusa('pasta_pacotes');
        }
        $destino = $caminhos->zip($m['zip_sha256']);
        if (!is_file($destino) && !rename($zipTmp, $destino)) {
            throw new Recusa('guardar', 'não moveu o zip');
        }
        @chmod($destino, 0600);
        $id = $armazem->inserirPacote([
            'chave' => $m['chave'], 'versao' => $m['versao'], 'zip_sha256' => $m['zip_sha256'],
            'manifesto' => base64_encode($manifestoBytes), 'sig' => base64_encode($sigBytes), 'key_id' => $keyId, 'origem' => 'upload',
        ]);
        $armazem->marcarArquivo($m['zip_sha256'], true);
        $armazem->registrarVersaoVista($m['chave'], $m['versao'], $m['zip_sha256']);

        return ['id' => $id, 'manifesto' => $m];
    }
}
