<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * O ÚNICO lugar que monta caminho (desenho-m2 §5.2, D2-04.2). Recebe (chave, versao, opId), revalida a
 * regex de cada um e o tipo inteiro, e devolve caminhos a partir de realpath(GLPI_MARKETPLACE_DIR).
 * O journal só é comparado, nunca executado: nenhum caminho vem do banco.
 *
 *   M  = marketplace/<chave>
 *   S  = marketplace/.hub-staging/op<id>/<chave>
 *   A  = marketplace/.hub-anterior/<chave>-<versao>-op<id>
 *   D  = marketplace/.hub-descartado/op<id>
 *   B  = <docDir>/backup/op<id>
 *   zip= <docDir>/pacotes/<sha256>.zip
 */
final class Caminhos
{
    private readonly string $mp;
    private readonly string $doc;

    public function __construct(string $marketplaceReal, string $docDir)
    {
        $this->mp  = rtrim($marketplaceReal, '/\\');
        $this->doc = rtrim($docDir, '/\\');
        if ($this->mp === '' || !str_starts_with($this->mp, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $this->mp) !== 1) {
            throw new Recusa('caminho_base', 'marketplace precisa ser caminho absoluto');
        }
    }

    public function marketplace(): string
    {
        return $this->mp;
    }

    public function docDir(): string
    {
        return $this->doc;
    }

    private static function chave(string $c): string
    {
        if (preg_match(Manifesto::RE_CHAVE, $c) !== 1) {
            throw new Recusa('chave', 'chave inválida para caminho');
        }

        return $c;
    }

    private static function versao(string $v): string
    {
        if (preg_match(Manifesto::RE_VERSAO, $v) !== 1) {
            throw new Recusa('versao', 'versão inválida para caminho');
        }

        return $v;
    }

    private static function op(int $id): string
    {
        if ($id < 1) {
            throw new Recusa('op', 'id de operação inválido');
        }

        return 'op' . $id;
    }

    public function M(string $chave): string
    {
        return $this->conferir($this->mp . '/' . self::chave($chave), $this->mp);
    }

    public function stagingRaiz(): string
    {
        return $this->mp . '/.hub-staging';
    }

    public function stagingOp(int $opId): string
    {
        return $this->stagingRaiz() . '/' . self::op($opId);
    }

    public function S(string $chave, int $opId): string
    {
        return $this->conferir($this->stagingOp($opId) . '/' . self::chave($chave), $this->mp);
    }

    public function A(string $chave, string $versao, int $opId): string
    {
        return $this->conferir($this->mp . '/.hub-anterior/' . self::chave($chave) . '-' . self::versao($versao) . '-' . self::op($opId), $this->mp);
    }

    public function D(int $opId): string
    {
        return $this->conferir($this->mp . '/.hub-descartado/' . self::op($opId), $this->mp);
    }

    public function B(int $opId): string
    {
        return $this->conferir($this->doc . '/backup/' . self::op($opId), $this->doc);
    }

    public function zip(string $sha256): string
    {
        if (preg_match(Manifesto::RE_HEX64, $sha256) !== 1) {
            throw new Recusa('zip_sha256', 'hash inválido para caminho');
        }

        return $this->conferir($this->doc . '/pacotes/' . $sha256 . '.zip', $this->doc);
    }

    public function pastaPacotes(): string
    {
        return $this->doc . '/pacotes';
    }

    /** O pai, se já existe, tem de resolver para dentro da base (um link no meio do caminho vira recusa). */
    private function conferir(string $caminho, string $base): string
    {
        $pai = dirname($caminho);
        while (!file_exists($pai) && !is_link($pai) && $pai !== dirname($pai)) {
            $pai = dirname($pai);
        }
        $real  = realpath($pai);
        $baseR = realpath($base);
        if ($real === false || $baseR === false || ($real !== $baseR && !str_starts_with($real, $baseR . DIRECTORY_SEPARATOR))) {
            throw new Recusa('caminho_fora_da_base', $caminho);
        }

        return $caminho;
    }
}
