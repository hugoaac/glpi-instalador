<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Regra R1 com proveniência (desenho-m2 §5.4, D2-01, D2-09). O instalador só escreve em GLPI_MARKETPLACE_DIR
 * e recusa a chave que exista em outro lugar, ou que esteja no marketplace sem ter sido posta por ele.
 *
 * B-02: a proveniência vem de um fato ASSINADO, não de linha de banco. A pasta marketplace/<chave> tem de ter a
 * arvore_sha256 de um manifesto assinado guardado (reverificado agora); a versão instalada ("de") é a desse
 * manifesto. Operação SUCESSO forjada no banco, ou glpi_plugins.version rebaixada, não valem nada aqui.
 */
final class R1
{
    public const OK         = 'OK';
    public const GERENCIADO = 'GERENCIADO_EM_OUTRO_LUGAR';

    /** @return array{resultado:string,motivo:string,de:?string,zip_sha256:?string} */
    public static function avaliar(Contexto $ctx, Armazem $armazem, Assinatura $assinatura, string $chave): array
    {
        if (preg_match(Manifesto::RE_CHAVE, $chave) !== 1) {
            throw new Recusa('chave', 'chave inválida em R1');
        }
        $nao = static fn(string $motivo): array => ['resultado' => self::GERENCIADO, 'motivo' => $motivo, 'de' => null, 'zip_sha256' => null];

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
            $fato = Fatos::instalado($armazem, $assinatura, $chave, Arvore::pasta($alvo));
            if ($fato === null) {
                return $nao('marketplace_sem_origem');
            }

            return ['resultado' => self::OK, 'motivo' => '', 'de' => $fato['versao'], 'zip_sha256' => $fato['zip_sha256']];
        }
        if ($armazem->registroPlugin($chave) !== null) {
            return $nao('registro_sem_pasta');
        }

        return ['resultado' => self::OK, 'motivo' => '', 'de' => null, 'zip_sha256' => null];
    }
}
