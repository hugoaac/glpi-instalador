<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

/**
 * Aprovar um plano (desenho-m2 §5.9). A parte que decide é pura e testada; a parte que fala com o GLPI
 * (senha + TOTP) fica atrás de Reautenticador.
 *
 * Aprovar = gravar o plano_sha256 aprovado. Quem confirmou o backup fica SÓ no GLPI (P-10: nome nenhum sobe ao painel):
 * a coluna guarda o id do usuário, e o informante nunca lê estas colunas.
 * A senha e o código TOTP existem só nos argumentos desta chamada: não vão ao journal, ao banco nem ao log.
 */
final class Aprovacao
{
    /** PLANO_PRONTO vence em 24 h (D2-03 / §5.5). */
    public const VALIDADE_S = 86400;

    /**
     * @return array{ok:bool,erro:?string}
     */
    public static function aprovar(
        Armazem $arm,
        Reautenticador $re,
        int $opId,
        int $uid,
        bool $personificando,
        string $planoShaDoForm,
        bool $backupConfirmado,
        bool $abaixoConfirmado,
        string $senha,
        string $totp,
        ?int $agora = null,
    ): array {
        $op = $arm->operacao($opId);
        if ($op === null || $op['estado'] !== 'PLANO_PRONTO') {
            return self::nao('operação não está com o plano pronto');
        }
        if ($personificando) {
            return self::nao('não se aprova personificando outro usuário (D2-13)');
        }
        if ($uid < 1) {
            return self::nao('sem usuário logado');
        }
        $agora ??= time();
        $mod = isset($op['date_mod']) ? (int) strtotime((string) $op['date_mod']) : $agora;
        if ($agora - $mod > self::VALIDADE_S) {
            $arm->transitar($opId, 'PLANO_PRONTO', 'FALHA', ['resultado' => 'FALHA', 'motivo' => 'expirado']);
            $arm->journal($opId, 'APROVAR', 'ERRO', ['motivo' => 'expirado']);

            return self::nao('o plano expirou (24 h); peça de novo');
        }
        // Passos 4 a 8: MFA ativo, limite, senha, usuário ativo, TOTP (sem código de recuperação).
        $falha = $re->verificar($uid, $senha, $totp);
        if ($falha !== null) {
            $arm->journal($opId, 'APROVAR', 'ERRO', ['motivo' => 'reautenticacao', 'codigo' => $falha]); // sem senha, sem código
            return self::nao($falha);
        }
        // 9. o formulário aprova ESTE plano
        if ($planoShaDoForm === '' || !hash_equals((string) $op['plano_sha256'], $planoShaDoForm)) {
            return self::nao('o plano mudou desde que a tela foi aberta; recarregue');
        }
        // 10. D14: backup completo do banco com menos de 24 h
        if (!$backupConfirmado) {
            return self::nao('confirme o backup completo do banco, com menos de 24 h');
        }
        // 11. D2-03.1
        $plano = json_decode((string) $op['plano'], true);
        $abaixo = is_array($plano) && ($plano['abaixo_da_mais_nova'] ?? false) === true;
        if ($abaixo && !$abaixoConfirmado) {
            return self::nao('confirme a instalação de versão abaixo da mais nova conhecida');
        }
        // 12. transição condicional
        $data = date('Y-m-d H:i:s', $agora);
        $ok = $arm->transitar($opId, 'PLANO_PRONTO', 'APROVADA', [
            'aprovado_por' => $uid, 'aprovado_em' => $data,
            'backup_confirmado_por' => $uid, 'backup_confirmado_em' => $data,
            'abaixo_da_mais_nova_confirmado' => $abaixo && $abaixoConfirmado ? 1 : 0,
        ]);
        if (!$ok) {
            return self::nao('a operação mudou de estado; recarregue');
        }
        $arm->journal($opId, 'APROVAR', 'DEPOIS', ['plano_sha256' => $op['plano_sha256'], 'aprovador_id' => $uid, 'abaixo_confirmado' => $abaixo && $abaixoConfirmado]);

        return ['ok' => true, 'erro' => null];
    }

    /** @return array{ok:bool,erro:?string} */
    private static function nao(string $erro): array
    {
        return ['ok' => false, 'erro' => $erro];
    }
}
