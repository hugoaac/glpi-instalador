<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use Auth;
use Glpi\Security\TOTPManager;
use Session;
use Throwable;

/**
 * Desenho-m2 §5.9, passos 4 a 8, na sequência de Glpi/Controller/Security/MFAController.php:117-143 (GLPI 11.0.8):
 *   is2FAEnabled (TOTPManager.php:268) -> checkMFARateLimit (:425; CONSOME uma tentativa e lança no limite de 5/15 min)
 *   -> Auth::validateLogin($login, $senha, noauto=true) (Auth.php:788; NÃO abre sessão) + mesmo id + is_active/is_deleted
 *   (validateLogin não confere, Auth.php:1058) -> verifyCodeForUser (:283; sem código de recuperação)
 *   -> clearMFAFailures (:438).
 * O login vem da SESSÃO ($_SESSION['glpiname']), nunca do formulário.
 */
final class ReautenticadorGlpi implements Reautenticador
{
    /**
     * $exigirLimite=false SÓ para a prova ao vivo em GLPI < 11.0.8 (tools/vivo.php): lá o core não tem o limite de tentativas
     * de MFA e a prova do limite fica para um GLPI 11.0.8+. O código de produção (front/operacao.php) usa o padrão: exige.
     */
    public function __construct(private readonly bool $exigirLimite = true)
    {
    }

    /** checkMFARateLimit/clearMFAFailures existem desde o GLPI 11.0.8 (TOTPManager.php:425/:438). Antes, chamá-los é um Error. */
    public static function temLimite(object|string $mfa): bool
    {
        return method_exists($mfa, 'checkMFARateLimit') && method_exists($mfa, 'clearMFAFailures');
    }

    public function verificar(int $uid, string $senha, string $totp): ?string
    {
        $mfa = new TOTPManager();
        $limite = self::temLimite($mfa);
        if (!$limite && $this->exigirLimite) {
            // Fecha de forma explícita: sem o limite, um Error "método indefinido" cairia no catch abaixo e viraria
            // "muitas tentativas" para TODA aprovação, com a causa escondida.
            return 'este GLPI não limita as tentativas de MFA (exige 11.0.8 ou mais); aprovação indisponível';
        }
        if (!$mfa->is2FAEnabled($uid)) {
            return 'aprovador sem MFA (TOTP) ativo';
        }
        if ($limite) {
            try {
                $mfa->checkMFARateLimit($uid);
            } catch (Throwable) {
                return 'muitas tentativas de MFA; aguarde 15 minutos';
            }
        }
        $login = (string) ($_SESSION['glpiname'] ?? '');
        if ($login === '' || $senha === '' || preg_match('/^[0-9]{6,8}\z/', $totp) !== 1) {
            return 'senha ou código inválidos';
        }
        $auth = new Auth();
        try {
            $ok = $auth->validateLogin($login, $senha, true);
        } catch (Throwable) {
            $ok = false;
        }
        $u = $auth->user->fields ?? [];
        if (!$ok || (int) $auth->user->getID() !== $uid || (int) ($u['is_active'] ?? 0) !== 1 || (int) ($u['is_deleted'] ?? 1) !== 0) {
            return 'senha ou código inválidos';
        }
        if (!$mfa->verifyCodeForUser($totp, $uid)) {
            return 'senha ou código inválidos';
        }
        if ($limite) {
            $mfa->clearMFAFailures($uid);
        }

        return null;
    }
}
