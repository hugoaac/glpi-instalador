<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Chaves de publicador FIXADAS NO CÓDIGO (desenho-m2 §2.2, D5). Nenhuma configuração, variável de ambiente,
 * tela ou banco acrescenta chave: trocar ou incluir uma é mudança de código, com commit. Os testes injetam a
 * chave de teste PELO CONSTRUTOR de Assinatura, nunca por aqui.
 *
 * Espelha src/publicador.js do painel (mesma lista, mesmo key_id).
 */
final class Publicadores
{
    /**
     * @var list<array{nome:string,publica:string}> base64 padrão dos 32 bytes crus.
     */
    public const LISTA = [
        // Cerimônia de 03/11/2026. key_id 38f6f9ceaa9c094c (16 primeiros hex de sha256 da pública crua).
        ['nome' => 'principal', 'publica' => 'nQTT8kshb2ydd76MPEnXKdrj5U/AhibrPy8XjvT82oQ='],
        // RESERVA: AINDA NÃO EXISTE. O Hugo a gera num Linux live sem rede (D2-10) e a pública entra aqui,
        // com commit. Nenhum GLPI de cliente recebe o instalador enquanto esta linha não existir (README).
        // ['nome' => 'reserva', 'publica' => '<base64 de 32 bytes>'],
    ];

    /** Quantas chaves um instalador precisa ter para ir a um cliente: principal + reserva. */
    public const CHAVES_PARA_CLIENTE = 2;

    /** key_id = 16 primeiros hex de sha256(pública crua). */
    public static function keyId(string $publicaCrua): string
    {
        return substr(hash('sha256', $publicaCrua), 0, 16);
    }

    /**
     * @param list<array{nome:string,publica:string}>|null $lista
     * @return array<string,string> key_id => pública crua
     */
    public static function porId(?array $lista = null): array
    {
        $out = [];
        foreach ($lista ?? self::LISTA as $c) {
            $raw = base64_decode($c['publica'], true);
            if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new \LogicException('chave de publicador inválida: ' . $c['nome']);
            }
            $out[self::keyId($raw)] = $raw;
        }

        return $out;
    }

    /** Pronto para cliente? Só com as duas chaves fixadas. */
    public static function prontoParaCliente(): bool
    {
        return count(self::LISTA) >= self::CHAVES_PARA_CLIENTE;
    }
}
