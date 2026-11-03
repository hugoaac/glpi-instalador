<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Manifesto canônico e arquivo .sig (desenho-m2 §2.1 e §2.2). Espelho de src/pacote.js.
 * Regex sempre com \z: "$" aceitaria um "\n" final dentro de um valor.
 */
final class Manifesto
{
    public const FORMATO       = 'glpi-plugin-manifesto-v1';
    public const FORMATO_SIG   = 'glpi-plugin-assinatura-v1';
    public const MAX_MANIFESTO = 8192;
    public const MAX_SIG       = 1024;
    public const MAX_ZIP       = 26214400;

    /** D2-07: a mesma lista no release, no painel e aqui. */
    public const CORE_RECUSADAS = [
        'glpi_users', 'glpi_useremails', 'glpi_authldaps', 'glpi_authmails', 'glpi_configs', 'glpi_apiclients',
        'glpi_oauthclients', 'glpi_crontasks', 'glpi_profilerights', 'glpi_plugins',
    ];
    /** D2-09: o instalador não instala a si mesmo nem a quem ele usa durante a operação. */
    public const CHAVES_PROIBIDAS = ['instalador', 'informante'];

    public const CAMPOS = ['formato', 'chave', 'versao', 'repo', 'tag', 'commit', 'zip_sha256', 'zip_bytes',
        'arvore_sha256', 'glpi_min', 'glpi_max', 'migracao', 'tabelas_core_tocadas', 'saude_tabelas', 'publicado_em'];

    public const RE_CHAVE  = '/^[a-z0-9]{1,64}\z/';
    public const RE_VERSAO = '/^(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\.(0|[1-9][0-9]{0,4})\z/';
    public const RE_HEX64  = '/^[0-9a-f]{64}\z/';

    private const RE_REPO   = '/^[A-Za-z0-9._-]{1,100}\z/';
    private const RE_COMMIT = '/^[0-9a-f]{40}\z/';
    private const RE_GLPI   = '/^\d+\.\d+\.\d+\z/';
    private const RE_CORE   = '/^glpi_[a-z0-9_]{1,60}\z/';
    private const RE_KEYID  = '/^[0-9a-f]{16}\z/';
    private const RE_SIGB64 = '/^[A-Za-z0-9+\/]{86}==\z/';

    private static function ascii(string $b): bool
    {
        return $b !== '' && preg_match('/^[\x20-\x7e]+\z/', $b) === 1;
    }

    /** Mensagem assinada: "glpi-plugin-manifesto-v1\n" + bytes do manifesto. */
    public static function mensagem(string $manifestoBytes): string
    {
        return self::FORMATO . "\n" . $manifestoBytes;
    }

    /** @param array<string,mixed> $m */
    public static function serializar(array $m): string
    {
        $o = [];
        foreach (self::CAMPOS as $c) {
            $o[$c] = $m[$c] ?? null;
        }

        return json_encode($o, JSON_UNESCAPED_SLASHES);
    }

    /** Valida os BYTES (assinatura já conferida) e devolve o manifesto. */
    public static function validar(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_MANIFESTO) {
            throw new Recusa('canonico', 'tamanho');
        }
        if (!self::ascii($bytes)) {
            throw new Recusa('canonico', 'byte fora de ASCII imprimível');
        }
        $m = json_decode($bytes, true, 8);
        if (!is_array($m) || ($m !== [] && array_is_list($m))) {
            throw new Recusa('canonico', 'não é objeto');
        }
        if (array_keys($m) !== self::CAMPOS) {
            throw new Recusa('canonico', 'campos ou ordem');
        }

        $str = static fn(mixed $v, string $re): bool => is_string($v) && preg_match($re, $v) === 1;

        if ($m['formato'] !== self::FORMATO) {
            throw new Recusa('formato');
        }
        if (!$str($m['chave'], self::RE_CHAVE) || in_array($m['chave'], self::CHAVES_PROIBIDAS, true)) {
            throw new Recusa('chave');
        }
        if (!$str($m['versao'], self::RE_VERSAO)) {
            throw new Recusa('versao');
        }
        if (!$str($m['repo'], self::RE_REPO)) {
            throw new Recusa('repo');
        }
        if ($m['tag'] !== 'v' . $m['versao']) {
            throw new Recusa('tag');
        }
        if (!$str($m['commit'], self::RE_COMMIT)) {
            throw new Recusa('commit');
        }
        if (!$str($m['zip_sha256'], self::RE_HEX64)) {
            throw new Recusa('zip_sha256');
        }
        if (!is_int($m['zip_bytes']) || $m['zip_bytes'] < 1 || $m['zip_bytes'] > self::MAX_ZIP) {
            throw new Recusa('zip_bytes');
        }
        if (!$str($m['arvore_sha256'], self::RE_HEX64)) {
            throw new Recusa('arvore_sha256');
        }
        if (!$str($m['glpi_min'], self::RE_GLPI)) {
            throw new Recusa('glpi_min');
        }
        if ($m['glpi_max'] !== null && !$str($m['glpi_max'], self::RE_GLPI)) {
            throw new Recusa('glpi_max');
        }
        if (!in_array($m['migracao'], ['nenhuma', 'aditiva', 'destrutiva'], true)) {
            throw new Recusa('migracao');
        }
        if (!self::listaOrdenada($m['tabelas_core_tocadas'], 20, self::RE_CORE)) {
            throw new Recusa('tabelas_core_tocadas');
        }
        foreach ($m['tabelas_core_tocadas'] as $t) {
            if (str_starts_with($t, 'glpi_plugin_') || in_array($t, self::CORE_RECUSADAS, true)) {
                throw new Recusa('tabelas_core_tocadas', $t);
            }
        }
        $reSaude = '/^glpi_plugin_' . preg_quote($m['chave'], '/') . '_[a-z0-9_]{1,60}\z/';
        if (!self::listaOrdenada($m['saude_tabelas'], 20, $reSaude)) {
            throw new Recusa('saude_tabelas');
        }
        if (!self::dataValida($m['publicado_em'])) {
            throw new Recusa('publicado_em');
        }
        if (self::serializar($m) !== $bytes) {
            throw new Recusa('canonico', 'bytes diferentes da forma canônica');
        }

        return $m;
    }

    private static function listaOrdenada(mixed $v, int $max, string $re): bool
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > $max) {
            return false;
        }
        foreach ($v as $i => $x) {
            if (!is_string($x) || preg_match($re, $x) !== 1) {
                return false;
            }
            if ($i > 0 && strcmp($v[$i - 1], $x) >= 0) {
                return false;
            }
        }

        return true;
    }

    /** AAAA-MM-DDTHH:MM:SSZ com ida e volta (recusa 30/02, que o parser empurraria para março). */
    public static function dataValida(mixed $s): bool
    {
        if (!is_string($s) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $s) !== 1) {
            return false;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $s, new DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d\TH:i:s\Z') === $s;
    }

    public static function serializarAssinatura(string $keyId, string $assinatura): string
    {
        return json_encode(['formato' => self::FORMATO_SIG, 'key_id' => $keyId, 'assinatura' => $assinatura], JSON_UNESCAPED_SLASHES);
    }

    /** @return array{key_id:string,assinatura:string} */
    public static function lerAssinatura(string $bytes): array
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_SIG || !self::ascii($bytes)) {
            throw new Recusa('sig', 'tamanho ou bytes');
        }
        $s = json_decode($bytes, true, 8);
        if (!is_array($s) || ($s['formato'] ?? null) !== self::FORMATO_SIG || !is_string($s['key_id'] ?? null) || !is_string($s['assinatura'] ?? null)) {
            throw new Recusa('sig', 'campos');
        }
        if (preg_match(self::RE_KEYID, $s['key_id']) !== 1) {
            throw new Recusa('key_id');
        }
        if (preg_match(self::RE_SIGB64, $s['assinatura']) !== 1) {
            throw new Recusa('assinatura');
        }
        if (self::serializarAssinatura($s['key_id'], $s['assinatura']) !== $bytes) {
            throw new Recusa('sig', 'bytes diferentes da forma canônica');
        }

        return ['key_id' => $s['key_id'], 'assinatura' => $s['assinatura']];
    }

    /** Semver numérico: -1, 0 ou 1. As duas versões já passaram por RE_VERSAO (ou são lidas do GLPI e validadas antes). */
    public static function compararVersao(string $a, string $b): int
    {
        $pa = array_map('intval', explode('.', $a));
        $pb = array_map('intval', explode('.', $b));
        for ($i = 0; $i < 3; $i++) {
            if (($pa[$i] ?? 0) !== ($pb[$i] ?? 0)) {
                return ($pa[$i] ?? 0) < ($pb[$i] ?? 0) ? -1 : 1;
            }
        }

        return 0;
    }
}
