<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Texto que vai para addMessageAfterRedirect. O GLPI 11 imprime essa mensagem com |raw
 * (templates/components/messages_after_redirect_toasts.html.twig:58), então TODO trecho dinâmico sai escapado
 * (B-05). Mesma função do htmlescape() do GLPI (src/autoload/misc-functions.php:100), sem depender do GLPI.
 */
final class Texto
{
    public static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function recusa(Recusa $e): string
    {
        return 'Recusado: ' . self::esc($e->motivo) . ($e->getMessage() !== $e->motivo ? ' (' . self::esc($e->getMessage()) . ')' : '');
    }

    /** @param array{ok:bool,erro:?string,novos:int,conhecidos:int,rejeitados:list<string>,retirados:int} $c */
    public static function catalogo(array $c): string
    {
        if ($c['ok']) {
            return 'Catálogo: ' . $c['novos'] . ' novo(s), ' . $c['conhecidos'] . ' conhecido(s), ' . count($c['rejeitados'])
                . ' rejeitado(s), ' . $c['retirados'] . ' marcado(s) como retirado.';
        }

        return 'Catálogo indisponível: ' . self::esc((string) ($c['erro'] ?? '?'));
    }
}
