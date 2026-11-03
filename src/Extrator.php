<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use ZipArchive;

/**
 * Extrai um zip JÁ aceito pelo ZipInspetor (desenho-m2 §5.6). O ZipArchive só lê o que o parser cru
 * aprovou: os nomes vêm com FL_ENC_RAW e são conferidos um a um contra os do parser. Cada arquivo é criado
 * com fopen('xb') (nunca sobrescreve), em blocos de 64 KiB, com contagem real e CRC. Nunca cria link.
 */
final class Extrator
{
    /**
     * @param array{raiz:string,entradas:list<array<string,mixed>>} $info resultado de ZipInspetor::inspecionar
     */
    public static function extrair(string $zipPath, array $info, string $destino): void
    {
        if (file_exists($destino) || is_link($destino)) {
            throw new Recusa('staging_existe', 'destino já existe');
        }
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            throw new Recusa('formato', 'ZipArchive não abriu o arquivo');
        }
        try {
            if ($zip->numFiles !== count($info['entradas'])) {
                throw new Recusa('formato', 'ZipArchive vê outra quantidade de entradas');
            }
            if (!mkdir($destino, 0755, true)) {
                throw new Recusa('staging', 'não criou o destino');
            }
            $total = 0;
            foreach ($info['entradas'] as $i => $e) {
                $st = $zip->statIndex($i, ZipArchive::FL_ENC_RAW);
                if ($st === false || $st['name'] !== $e['nome']) {
                    throw new Recusa('formato', 'ZipArchive vê outro nome na entrada ' . $i);
                }
                $rel = implode('/', array_slice($e['segs'], 1));
                if ($rel === '') {
                    continue; // a própria raiz
                }
                $alvo = $destino . '/' . $rel;
                if ($e['dir']) {
                    if (!is_dir($alvo) && !mkdir($alvo, 0755, true)) {
                        throw new Recusa('staging', 'não criou ' . $rel);
                    }
                    continue;
                }
                $pai = dirname($alvo);
                if (!is_dir($pai) && !mkdir($pai, 0755, true)) {
                    throw new Recusa('staging', 'não criou ' . dirname($rel));
                }
                self::arquivo($zip, $i, $e, $alvo, $total);
            }
        } finally {
            $zip->close();
        }
    }

    /** @param array<string,mixed> $e */
    private static function arquivo(ZipArchive $zip, int $i, array $e, string $alvo, int &$total): void
    {
        $in = $zip->getStreamIndex($i);
        if ($in === false) {
            throw new Recusa('formato', 'sem stream para ' . $e['nome']);
        }
        $out = @fopen($alvo, 'xb');
        if ($out === false) {
            fclose($in);
            throw new Recusa('duplicado', $e['nome']);
        }
        $crc   = hash_init('crc32b');
        $lidos = 0;
        try {
            while (!feof($in)) {
                $b = fread($in, 65536);
                if ($b === false) {
                    throw new Recusa('formato', 'leitura falhou em ' . $e['nome']);
                }
                if ($b === '') {
                    continue;
                }
                $lidos += strlen($b);
                $total += strlen($b);
                if ($lidos > $e['tamanho'] || $total > ZipInspetor::MAX_TOTAL) {
                    throw new Recusa('tamanho', $e['nome']);
                }
                hash_update($crc, $b);
                fwrite($out, $b);
            }
        } finally {
            fclose($in);
            fclose($out);
        }
        if ($lidos !== $e['tamanho']) {
            throw new Recusa('tamanho', $e['nome']);
        }
        if (hexdec(hash_final($crc)) !== $e['crc']) {
            throw new Recusa('crc', $e['nome']);
        }
        @chmod($alvo, 0644);
    }

    /** Apaga uma pasta criada por nós (staging). Não segue link. */
    public static function apagar(string $dir): void
    {
        if (is_link($dir) || is_file($dir)) {
            @unlink($dir);

            return;
        }
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $n) {
            if ($n !== '.' && $n !== '..') {
                self::apagar($dir . '/' . $n);
            }
        }
        @rmdir($dir);
    }
}
