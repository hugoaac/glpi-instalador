<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Perfil estrito de zip (desenho-m2 §2.6, D2-06). Espelho de src/zip.js do painel: a MESMA especificação.
 *
 * Os headers são lidos CRUS, com fopen/fread, antes de qualquer ZipArchive. Zip é um formato em que dois
 * leitores podem ver arquivos diferentes nos mesmos bytes (EOCD falso, nome local diferente do central,
 * extra 0x7075); por isso quem decide o que o arquivo contém é este parser, e o ZipArchive (Extrator) só
 * lê o que ele já aceitou. Nada é inflado inteiro em memória.
 */
final class ZipInspetor
{
    public const MAX_ENTRADAS = 20000;
    public const MAX_TOTAL    = 200 * 1024 * 1024;
    public const RAZAO        = 100;

    private const SIG_LOCAL      = 0x04034b50;
    private const SIG_CENTRAL    = 0x02014b50;
    private const SIG_EOCD       = 0x06054b50;
    private const SIG_EOCD64_LOC = 0x07064b50;
    private const SIG_DESCRITOR  = 0x08074b50;
    private const EXTRA_TIMESTAMP = 0x5455;
    // bit 3 = data descriptor, 1-2 = opções do deflate, 11 = nome UTF-8 (indiferente: o nome só pode ser ASCII).
    private const FLAGS_PERMITIDAS = 0x0001 | 0x0002 | 0x0004 | 0x0008 | 0x0800;
    private const PROIBIDOS = ['\\', ':', '*', '?', '"', '<', '>', '|'];
    private const RESERVADO = '/^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?\z/si';
    private const VCS = ['.git', '.hg', '.svn'];
    private const RAIZ_GITHUB = '/^[A-Za-z0-9._-]{1,200}\z/';

    /**
     * Lê e valida a estrutura inteira sem inflar nada.
     *
     * @param string|null $raiz   a pasta única esperada (a chave do plugin), ou null para o zipball do GitHub
     * @param string|null $commit o comentário do zip tem de ser vazio ou igual a ele
     * @return array{raiz:string,entradas:list<array<string,mixed>>,total:int,bytes:int}
     */
    public static function inspecionar(string $path, ?string $raiz, ?string $commit = null): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new Recusa('formato', 'arquivo ilegível');
        }
        try {
            return self::inspecionarAberto($fh, $path, $raiz, $commit);
        } finally {
            fclose($fh);
        }
    }

    /** @param resource $fh */
    private static function ler($fh, int $pos, int $n): string
    {
        if ($n === 0) {
            return '';
        }
        if ($pos < 0 || fseek($fh, $pos) !== 0) {
            throw new Recusa('formato', 'leitura curta em ' . $pos);
        }
        $buf = '';
        while (strlen($buf) < $n) {
            $r = fread($fh, $n - strlen($buf));
            if ($r === false || $r === '') {
                throw new Recusa('formato', 'leitura curta em ' . $pos);
            }
            $buf .= $r;
        }

        return $buf;
    }

    private static function u16(string $s, int $o): int
    {
        return unpack('v', $s, $o)[1];
    }

    private static function u32(string $s, int $o): int
    {
        return unpack('V', $s, $o)[1];
    }

    /** Extra fields: só 0x5455 (o que o git archive grava), bem formado até o último byte. */
    private static function conferirExtra(string $buf, string $nome): void
    {
        $i = 0;
        $n = strlen($buf);
        while ($i < $n) {
            if ($i + 4 > $n) {
                throw new Recusa('formato', 'extra truncado em ' . $nome);
            }
            $id  = self::u16($buf, $i);
            $len = self::u16($buf, $i + 2);
            if ($i + 4 + $len > $n) {
                throw new Recusa('formato', 'extra truncado em ' . $nome);
            }
            if ($id !== self::EXTRA_TIMESTAMP) {
                throw new Recusa('extra', sprintf('0x%04x em %s', $id, $nome));
            }
            $i += 4 + $len;
        }
    }

    /**
     * Nome cru: bytes 0x20-0x7E, segmentos que nenhum sistema de arquivos reinterpreta.
     *
     * @return array{0:string,1:bool,2:list<string>} [nome, é_pasta, segmentos]
     */
    private static function conferirNome(string $bytes): array
    {
        if (preg_match('/[^\x20-\x7e]/', $bytes, $mm, PREG_OFFSET_CAPTURE) === 1) {
            throw new Recusa('caminho', sprintf('byte 0x%x no nome', ord($mm[0][0])));
        }
        foreach (self::PROIBIDOS as $ch) {
            if (str_contains($bytes, $ch)) {
                throw new Recusa('caminho', '"' . $ch . '" em ' . $bytes);
            }
        }
        $dir  = str_ends_with($bytes, '/');
        $segs = explode('/', $dir ? substr($bytes, 0, -1) : $bytes);
        foreach ($segs as $s) {
            if ($s === '' || $s === '.' || $s === '..') {
                throw new Recusa('caminho', $bytes);
            }
            if (str_ends_with($s, '.') || str_ends_with($s, ' ')) {
                throw new Recusa('caminho', $bytes);
            }
            if (preg_match(self::RESERVADO, $s) === 1) {
                throw new Recusa('caminho', $bytes);
            }
        }
        foreach ($segs as $s) {
            if (in_array(strtolower($s), self::VCS, true)) {
                throw new Recusa('vcs', $bytes);
            }
        }

        return [$bytes, $dir, $segs];
    }

    /**
     * Made-by: opsys 0 (FAT, o que o git archive grava em arquivo comum e pasta) com os 32 bits inteiros do
     * atributo em {0, 16} (P-1); ou opsys 3 (Unix, executável no git archive) com tipo regular.
     * Link simbólico e qualquer outro tipo: recusa.
     */
    private static function conferirTipo(int $madeBy, int $attr, string $nome, bool $dir): void
    {
        $opsys = $madeBy >> 8;
        if ($opsys === 0) {
            if ($attr !== ($dir ? 16 : 0)) {
                throw new Recusa('tipo', sprintf('atributo 0x%x em %s', $attr, $nome));
            }

            return;
        }
        if ($opsys === 3) {
            $tipo = ($attr >> 16) & 0170000;
            if ($dir || $tipo !== 0100000 || ($attr & 0x10) !== 0) {
                throw new Recusa('tipo', sprintf('modo %o em %s', $attr >> 16, $nome));
            }

            return;
        }

        throw new Recusa('tipo', 'opsys ' . $opsys . ' em ' . $nome);
    }

    /** @param resource $fh */
    private static function inspecionarAberto($fh, string $path, ?string $raiz, ?string $commit): array
    {
        clearstatcache(true, $path);
        $size = (int) filesize($path);
        if ($size < 22) {
            throw new Recusa('formato', 'pequeno demais');
        }

        // 1. EOCD no último byte; comentário vazio ou = commit; sem zip64. O comentário só pode ter 0 ou 40
        // bytes, então o EOCD só pode estar em dois lugares (nada de procurar a assinatura na cauda).
        $cauda = self::ler($fh, max(0, $size - 22 - 40), min($size, 22 + 40));
        $base  = $size - strlen($cauda);
        $cands = [];
        foreach ([0, 40] as $c) {
            $i = strlen($cauda) - 22 - $c;
            if ($i >= 0 && self::u32($cauda, $i) === self::SIG_EOCD && $i + 22 + self::u16($cauda, $i + 20) === strlen($cauda)) {
                $cands[] = $i;
            }
        }
        if (count($cands) !== 1) {
            throw new Recusa('formato', 'EOCD não termina no fim do arquivo ou comentário fora do perfil');
        }
        $eocd     = $cands[0];
        $disco    = self::u16($cauda, $eocd + 4);
        $discoCd  = self::u16($cauda, $eocd + 6);
        $nDisco   = self::u16($cauda, $eocd + 8);
        $n        = self::u16($cauda, $eocd + 10);
        $cdTam    = self::u32($cauda, $eocd + 12);
        $cdOffset = self::u32($cauda, $eocd + 16);
        $comLen   = self::u16($cauda, $eocd + 20);
        if ($disco !== 0 || $discoCd !== 0 || $nDisco !== $n) {
            throw new Recusa('formato', 'zip em vários discos');
        }
        if ($n === 0xffff || $cdTam === 0xffffffff || $cdOffset === 0xffffffff) {
            throw new Recusa('formato', 'zip64');
        }
        if ($base + $eocd >= 20 && self::u32(self::ler($fh, $base + $eocd - 20, 4), 0) === self::SIG_EOCD64_LOC) {
            throw new Recusa('formato', 'zip64');
        }
        $comentario = substr($cauda, $eocd + 22, $comLen);
        if ($comentario !== '' && $comentario !== $commit) {
            throw new Recusa('formato', 'comentário diferente do commit');
        }
        if ($n > self::MAX_ENTRADAS) {
            throw new Recusa('entradas', (string) $n);
        }
        if ($n === 0) {
            throw new Recusa('formato', 'zip vazio');
        }
        $posEocd = $base + $eocd;
        if ($cdOffset + $cdTam !== $posEocd) {
            throw new Recusa('formato', 'diretório central fora do lugar');
        }

        // Diretório central.
        $cd        = self::ler($fh, $cdOffset, $cdTam);
        $cdLen     = strlen($cd);
        $entradas  = [];
        $raizVista = $raiz;
        $arquivos  = [];
        $declaradas = [];
        $pastas    = [];
        $total     = 0;
        $p         = 0;
        for ($k = 0; $k < $n; $k++) {
            if ($p + 46 > $cdLen || self::u32($cd, $p) !== self::SIG_CENTRAL) {
                throw new Recusa('formato', 'entrada central ' . $k);
            }
            $madeBy     = self::u16($cd, $p + 4);
            $flags      = self::u16($cd, $p + 8);
            $metodo     = self::u16($cd, $p + 10);
            $crc        = self::u32($cd, $p + 16);
            $comprimido = self::u32($cd, $p + 20);
            $tamanho    = self::u32($cd, $p + 24);
            $nLen       = self::u16($cd, $p + 28);
            $xLen       = self::u16($cd, $p + 30);
            $cLen       = self::u16($cd, $p + 32);
            $discoIni   = self::u16($cd, $p + 34);
            $attr       = self::u32($cd, $p + 38);
            $offset     = self::u32($cd, $p + 42);
            $fim        = $p + 46 + $nLen + $xLen + $cLen;
            if ($fim > $cdLen) {
                throw new Recusa('formato', 'entrada central ' . $k . ' truncada');
            }
            $nomeBytes = substr($cd, $p + 46, $nLen);
            $extra     = substr($cd, $p + 46 + $nLen, $xLen);
            $p         = $fim;

            if (($flags & 0x0041) !== 0) {
                throw new Recusa('cifrado', $nomeBytes);
            }
            if (($flags & ~self::FLAGS_PERMITIDAS) !== 0) {
                throw new Recusa('formato', sprintf('flags 0x%x', $flags));
            }
            if ($metodo !== 0 && $metodo !== 8) {
                throw new Recusa('metodo', $metodo . ' em ' . $nomeBytes);
            }
            if ($cLen !== 0 || $discoIni !== 0) {
                throw new Recusa('formato', 'comentário de entrada ou outro disco');
            }
            if ($comprimido === 0xffffffff || $tamanho === 0xffffffff || $offset === 0xffffffff) {
                throw new Recusa('formato', 'zip64');
            }
            self::conferirExtra($extra, $nomeBytes);
            [$nome, $dir, $segs] = self::conferirNome($nomeBytes);
            self::conferirTipo($madeBy, $attr, $nome, $dir);

            // Raiz única: a chave do plugin (ou a do zipball do GitHub, aprendida na 1ª entrada).
            if ($raizVista === null) {
                if (preg_match(self::RAIZ_GITHUB, $segs[0]) !== 1) {
                    throw new Recusa('raiz', $segs[0]);
                }
                $raizVista = $segs[0];
            }
            if ($segs[0] !== $raizVista) {
                throw new Recusa('raiz', $nome);
            }
            if (count($segs) === 1 && !$dir) {
                throw new Recusa('raiz', 'arquivo ' . $nome . ' no lugar da raiz');
            }

            // Duplicata sem distinguir caixa, e arquivo x pasta com o mesmo nome.
            $chaveNome = strtolower($dir ? substr($nome, 0, -1) : $nome);
            $partes    = explode('/', $chaveNome);
            array_pop($partes);
            $ancestrais = [];
            foreach ($partes as $i => $_) {
                $ancestrais[] = implode('/', array_slice($partes, 0, $i + 1));
            }
            if ($dir) {
                if (isset($declaradas[$chaveNome]) || isset($arquivos[$chaveNome])) {
                    throw new Recusa('duplicado', $nome);
                }
                $declaradas[$chaveNome] = true;
                $pastas[$chaveNome]     = true;
            } else {
                if (isset($arquivos[$chaveNome]) || isset($pastas[$chaveNome])) {
                    throw new Recusa('duplicado', $nome);
                }
                $arquivos[$chaveNome] = true;
            }
            foreach ($ancestrais as $a) {
                if (isset($arquivos[$a])) {
                    throw new Recusa('duplicado', $nome);
                }
                $pastas[$a] = true;
            }

            if ($dir && ($tamanho !== 0 || $comprimido !== 0)) {
                throw new Recusa('formato', 'pasta com conteúdo: ' . $nome);
            }
            if ($metodo === 0 && $comprimido !== $tamanho) {
                throw new Recusa('formato', 'tamanhos de entrada sem compressão: ' . $nome);
            }
            $total += $tamanho;
            $entradas[] = ['nome' => $nome, 'dir' => $dir, 'segs' => $segs, 'flags' => $flags, 'metodo' => $metodo,
                'crc' => $crc, 'comprimido' => $comprimido, 'tamanho' => $tamanho, 'offset' => $offset, 'inicio' => 0];
        }
        if ($p !== $cdLen) {
            throw new Recusa('formato', 'sobra no diretório central');
        }
        if ($total > self::MAX_TOTAL) {
            throw new Recusa('tamanho', $total . ' bytes declarados');
        }
        if ($total > self::RAZAO * $size) {
            throw new Recusa('razao', $total . ' / ' . $size);
        }

        // 2-3. Entradas contíguas desde o offset 0 até o diretório central; header local igual ao central.
        $ordem = array_keys($entradas);
        usort($ordem, static fn(int $a, int $b): int => $entradas[$a]['offset'] <=> $entradas[$b]['offset']);
        $esperado = 0;
        foreach ($ordem as $idx) {
            $e = &$entradas[$idx];
            if ($e['offset'] !== $esperado) {
                throw new Recusa('formato', 'lacuna ou sobreposição antes de ' . $e['nome']);
            }
            $h = self::ler($fh, $e['offset'], 30);
            if (self::u32($h, 0) !== self::SIG_LOCAL) {
                throw new Recusa('formato', 'header local de ' . $e['nome']);
            }
            $lFlags  = self::u16($h, 6);
            $lMetodo = self::u16($h, 8);
            $lCrc    = self::u32($h, 14);
            $lComp   = self::u32($h, 18);
            $lTam    = self::u32($h, 22);
            $lNLen   = self::u16($h, 26);
            $lXLen   = self::u16($h, 28);
            $nomeLocal = self::ler($fh, $e['offset'] + 30, $lNLen);
            if ($nomeLocal !== $e['nome']) {
                throw new Recusa('formato', 'nome local diferente: ' . $e['nome']);
            }
            self::conferirExtra(self::ler($fh, $e['offset'] + 30 + $lNLen, $lXLen), $e['nome']);
            if ($lFlags !== $e['flags'] || $lMetodo !== $e['metodo']) {
                throw new Recusa('formato', 'header local diferente: ' . $e['nome']);
            }
            $e['inicio'] = $e['offset'] + 30 + $lNLen + $lXLen;
            $fimEntrada  = $e['inicio'] + $e['comprimido'];
            if (($e['flags'] & 0x0008) !== 0) {
                // Data descriptor: o local traz zeros (ou os mesmos valores) e o descritor traz os do central.
                if (($lCrc !== 0 && $lCrc !== $e['crc']) || ($lComp !== 0 && $lComp !== $e['comprimido']) || ($lTam !== 0 && $lTam !== $e['tamanho'])) {
                    throw new Recusa('formato', 'header local diferente: ' . $e['nome']);
                }
                $d      = self::ler($fh, $fimEntrada, 16);
                $comSig = self::u32($d, 0) === self::SIG_DESCRITOR;
                $o      = $comSig ? 4 : 0;
                if (self::u32($d, $o) !== $e['crc'] || self::u32($d, $o + 4) !== $e['comprimido'] || self::u32($d, $o + 8) !== $e['tamanho']) {
                    throw new Recusa('formato', 'data descriptor diferente: ' . $e['nome']);
                }
                $fimEntrada += $comSig ? 16 : 12;
            } elseif ($lCrc !== $e['crc'] || $lComp !== $e['comprimido'] || $lTam !== $e['tamanho']) {
                throw new Recusa('formato', 'header local diferente: ' . $e['nome']);
            }
            $esperado = $fimEntrada;
            unset($e);
        }
        if ($esperado !== $cdOffset) {
            throw new Recusa('formato', 'bytes entre a última entrada e o diretório central');
        }

        return ['raiz' => (string) $raizVista, 'entradas' => $entradas, 'total' => $total, 'bytes' => $size];
    }

    /**
     * Conteúdo de uma entrada por stream: infla em pedaços pequenos (a expansão máxima do deflate é ~1032x,
     * então cada pedaço de 4 KiB comprimidos rende no máximo ~4 MiB), confere o tamanho real e o CRC.
     * $aoLer recebe cada pedaço inflado. $conta['total'] soma os bytes inflados de todas as entradas.
     *
     * @param array<string,mixed> $e
     * @param array{total:int} $conta
     */
    public static function lerEntrada(string $path, array $e, array &$conta, callable $aoLer): void
    {
        if ($e['comprimido'] === 0) {
            if ($e['tamanho'] !== 0) {
                throw new Recusa('tamanho', $e['nome']);
            }
            if ($e['crc'] !== 0) {
                throw new Recusa('crc', $e['nome']);
            }

            return;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new Recusa('formato', 'arquivo ilegível');
        }
        try {
            $ctx = $e['metodo'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
            $crc = hash_init('crc32b');
            $lidos = 0;
            $restante = $e['comprimido'];
            $pos = $e['inicio'];
            while ($restante > 0) {
                $n     = min(4096, $restante);
                $bruto = self::ler($fh, $pos, $n);
                $pos += $n;
                $restante -= $n;
                if ($ctx !== null) {
                    $chunk = @inflate_add($ctx, $bruto, $restante === 0 ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
                    if ($chunk === false) {
                        throw new Recusa('formato', $e['nome'] . ': dados deflate inválidos');
                    }
                } else {
                    $chunk = $bruto;
                }
                $lidos += strlen($chunk);
                $conta['total'] += strlen($chunk);
                if ($lidos > $e['tamanho'] || $conta['total'] > self::MAX_TOTAL) {
                    throw new Recusa('tamanho', $e['nome']);
                }
                hash_update($crc, $chunk);
                $aoLer($chunk);
            }
            if ($ctx !== null && inflate_get_status($ctx) !== ZLIB_STREAM_END) {
                throw new Recusa('formato', $e['nome'] . ': deflate truncado');
            }
            if ($lidos !== $e['tamanho']) {
                throw new Recusa('tamanho', $e['nome']);
            }
            if (hexdec(hash_final($crc)) !== $e['crc']) {
                throw new Recusa('crc', $e['nome']);
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * arvore_sha256 do conteúdo sob a raiz, igual à de uma pasta extraída, sem extrair.
     *
     * @param array{total:int} $conta
     * @return array{raiz:string,entradas:list<array<string,mixed>>,total:int,bytes:int,arvore:string,expandido:int}
     */
    public static function arvoreDoZip(string $path, ?string $raiz, ?string $commit = null, array &$conta = ['total' => 0]): array
    {
        $info  = self::inspecionar($path, $raiz, $commit);
        $pares = [];
        foreach ($info['entradas'] as $e) {
            if ($e['dir']) {
                continue;
            }
            $h = hash_init('sha256');
            self::lerEntrada($path, $e, $conta, static function (string $chunk) use ($h): void {
                hash_update($h, $chunk);
            });
            $rel     = implode('/', array_slice($e['segs'], 1));
            $pares[] = [$rel, Arvore::linha(hash_final($h), $rel)];
        }

        return $info + ['arvore' => Arvore::deLinhas($pares), 'expandido' => $conta['total']];
    }
}
