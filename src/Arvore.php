<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * arvore_sha256: a MESMA definição de src/arvore.js do painel e de TreeHash do informante.
 * Uma linha por arquivo regular "<sha256(conteúdo)>  <caminho>\n", link como "symlink:<alvo>  <caminho>\n",
 * ordem por bytes do caminho, .git/.hg/.svn fora em qualquer nível, pasta vazia não conta.
 *
 * O instalador calcula a sua própria (e não pede ao informante): a verificação é independente.
 */
final class Arvore
{
    private const VCS = ['.git' => true, '.hg' => true, '.svn' => true];

    public static function linha(string $sha256, string $path): string
    {
        return $sha256 . '  ' . $path . "\n";
    }

    /** @param list<array{0:string,1:string}> $pares [caminho, linha] */
    public static function deLinhas(array $pares): string
    {
        usort($pares, static fn(array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));
        $ctx = hash_init('sha256');
        foreach ($pares as $p) {
            hash_update($ctx, $p[1]);
        }

        return hash_final($ctx);
    }

    public static function pasta(string $root): string
    {
        clearstatcache(true);
        $pares = [];
        self::walk(rtrim($root, '/\\'), '', $pares);

        return self::deLinhas($pares);
    }

    /** @param list<array{0:string,1:string}> $pares */
    private static function walk(string $dir, string $rel, array &$pares): void
    {
        $names = @scandir($dir, SCANDIR_SORT_NONE);
        if ($names === false) {
            throw new Recusa('arvore', 'pasta ilegível: ' . ($rel === '' ? '.' : $rel));
        }
        foreach ($names as $name) {
            if ($name === '.' || $name === '..' || isset(self::VCS[$name])) {
                continue;
            }
            $abs  = $dir . '/' . $name;
            $path = $rel === '' ? $name : $rel . '/' . $name;
            if (is_link($abs)) {
                $t = readlink($abs);
                if ($t === false) {
                    throw new Recusa('arvore', 'link ilegível: ' . $path);
                }
                $pares[] = [$path, 'symlink:' . $t . '  ' . $path . "\n"];
            } elseif (is_dir($abs)) {
                self::walk($abs, $path, $pares);
            } elseif (is_file($abs)) {
                $h = @hash_file('sha256', $abs);
                if ($h === false) {
                    throw new Recusa('arvore', 'arquivo ilegível: ' . $path);
                }
                $pares[] = [$path, self::linha($h, $path)];
            }
        }
    }

    /** true se há link simbólico ou VCS em qualquer nível (usado pela R1). */
    public static function temVcsOuLink(string $root): bool
    {
        $names = @scandir($root, SCANDIR_SORT_NONE);
        if ($names === false) {
            return true;
        }
        foreach ($names as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            if (isset(self::VCS[$n])) {
                return true;
            }
            $abs = $root . '/' . $n;
            if (is_link($abs)) {
                return true;
            }
            if (is_dir($abs) && self::temVcsOuLink($abs)) {
                return true;
            }
        }

        return false;
    }
}
