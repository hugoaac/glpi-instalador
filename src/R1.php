<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Regra R1 com proveniência (desenho-m2 §5.4, D2-01, D2-09). O instalador só escreve em GLPI_MARKETPLACE_DIR
 * e recusa a chave que exista em outro lugar, ou que esteja no marketplace sem ter sido posta por ele.
 */
final class R1
{
    public const OK                = 'OK';
    public const GERENCIADO        = 'GERENCIADO_EM_OUTRO_LUGAR';

    /** @return array{resultado:string,motivo:string} */
    public static function avaliar(Contexto $ctx, Armazem $armazem, string $chave): array
    {
        if (preg_match(Manifesto::RE_CHAVE, $chave) !== 1) {
            throw new Recusa('chave', 'chave inválida em R1');
        }
        $nao = static fn(string $motivo): array => ['resultado' => self::GERENCIADO, 'motivo' => $motivo];

        if (in_array($chave, Manifesto::CHAVES_PROIBIDAS, true)) {
            return $nao('dependencia_do_instalador'); // D2-09: troca manual
        }
        $m = realpath($ctx->marketplace);
        if ($m === false) {
            return $nao('marketplace_inexistente');
        }
        foreach ($ctx->pluginsDirs as $d) {
            $rd = realpath($d);
            if ($rd !== false && $rd === $m) {
                continue;
            }
            $base = $rd !== false ? $rd : $d;
            if (file_exists($base . '/' . $chave) || is_link($base . '/' . $chave)) {
                return $nao('existe_fora_do_marketplace');
            }
        }
        $alvo = $m . '/' . $chave;
        if (is_link($alvo)) {
            return $nao('link_no_marketplace');
        }
        if (file_exists($alvo)) {
            if (!is_dir($alvo) || Arvore::temVcsOuLink($alvo)) {
                return $nao('vcs_ou_link_no_marketplace');
            }
            // D2-01a: proveniência. O que está lá tem de ser exatamente o que a última operação deixou.
            $ult = $armazem->ultimaOperacaoOk($chave);
            if ($ult === null || ($ult['arvore_final'] ?? null) === null || Arvore::pasta($alvo) !== $ult['arvore_final']) {
                return $nao('marketplace_sem_origem');
            }
        } elseif ($armazem->registroPlugin($chave) !== null) {
            return $nao('registro_sem_pasta');
        }

        return ['resultado' => self::OK, 'motivo' => ''];
    }
}
