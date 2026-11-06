<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/** Reautenticação de quem aprova (senha + TOTP, na hora). null = confere; senão, o motivo curto para a tela. */
interface Reautenticador
{
    public function verificar(int $uid, string $senha, string $totp): ?string;
}
