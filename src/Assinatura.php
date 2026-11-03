<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Verifica o pacote contra as chaves FIXADAS. A assinatura vem antes de qualquer interpretação do
 * manifesto: só depois dela os bytes são lidos como JSON.
 *
 * As chaves entram pelo construtor. O padrão é Publicadores::LISTA; os testes injetam a de teste.
 * Não há outro caminho (configuração, variável, tela) para trocar o conjunto.
 */
final class Assinatura
{
    /** @var array<string,string> key_id => pública crua */
    private array $porId;

    /** @param list<array{nome:string,publica:string}>|null $chaves */
    public function __construct(?array $chaves = null)
    {
        $this->porId = Publicadores::porId($chaves);
    }

    /** @return array{manifesto:array,key_id:string} */
    public function verificar(string $manifestoBytes, string $sigBytes): array
    {
        $sig = Manifesto::lerAssinatura($sigBytes);
        $raw = $this->porId[$sig['key_id']] ?? null;
        if ($raw === null) {
            throw new Recusa('key_id', $sig['key_id']);
        }
        $bin = base64_decode($sig['assinatura'], true);
        if ($bin === false || strlen($bin) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($bin, Manifesto::mensagem($manifestoBytes), $raw)) {
            throw new Recusa('assinatura');
        }

        return ['manifesto' => Manifesto::validar($manifestoBytes), 'key_id' => $sig['key_id']];
    }
}
