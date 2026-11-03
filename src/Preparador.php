<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use Throwable;

/**
 * Passo 1 da execução (desenho-m2 §5.5, estado PREPARANDO): verifica o pacote, aplica R1, extrai no staging
 * e monta o plano em modo simulação. FATIA (b): não há backup, troca de pasta, migração nem ativação aqui.
 *
 * Ordem (a mais barata primeiro; nada é criado em marketplace/ até tudo passar):
 *  1 assinatura + manifesto canônico (chaves fixadas)  2 chave/versão do pacote = a da operação
 *  3 tamanho e sha256 do zip  4 retirado  5 perfil de zip (ZipInspetor) + arvore sem extrair
 *  6 R1 com proveniência  7 versão (downgrade / mesma / vista)  8 faixa do GLPI e diretórios CLI x FPM
 *  9 extração em .hub-staging/op<id>/<chave> + arvore(S) = manifesto  10 versoes_vistas  11 plano
 */
final class Preparador
{
    public function __construct(
        private readonly Contexto $ctx,
        private readonly Armazem $armazem,
        private readonly Assinatura $assinatura,
        private readonly ?PainelApi $painel = null,
    ) {
    }

    /** @return array{estado:string,resultado:?string,motivo:?string,plano:?array} */
    public function preparar(int $opId): array
    {
        $op = $this->armazem->operacao($opId);
        if ($op === null || !$this->armazem->transitar($opId, 'PEDIDA', 'PREPARANDO')) {
            return ['estado' => 'IGNORADA', 'resultado' => null, 'motivo' => 'operação não está PEDIDA', 'plano' => null];
        }
        $this->armazem->journal($opId, 'PREPARAR', 'ANTES', ['chave' => $op['chave'], 'pacotes_id' => $op['pacotes_id'] ?? null]);
        $caminhos = new Caminhos($this->ctx->marketplace, $this->ctx->docDir);
        $raizExistia = is_dir($caminhos->stagingRaiz());
        try {
            $r = $this->executar($opId, $op, $caminhos);
        } catch (Recusa $e) {
            $this->limpar($caminhos, $opId, $raizExistia);
            $r = ['resultado' => 'FALHA', 'motivo' => $e->motivo, 'detalhe' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->limpar($caminhos, $opId, $raizExistia);
            $r = ['resultado' => 'FALHA', 'motivo' => 'interno', 'detalhe' => get_class($e)];
        }

        if (isset($r['plano'])) {
            $ok = $this->armazem->transitar($opId, 'PREPARANDO', 'PLANO_PRONTO', [
                'tipo' => $r['plano']['tipo'], 'versao_de' => $r['plano']['de'], 'versao_para' => $r['plano']['para'],
                'migracao' => $r['plano']['migracao'], 'plano' => Plano::json($r['plano']), 'plano_sha256' => $r['sha256'],
            ]);
            $this->armazem->journal($opId, 'PREPARAR', 'DEPOIS', ['estado' => 'PLANO_PRONTO', 'plano_sha256' => $r['sha256']]);

            return ['estado' => $ok ? 'PLANO_PRONTO' : 'IGNORADA', 'resultado' => null, 'motivo' => null, 'plano' => $r['plano']];
        }
        $falha  = $r['resultado'] === 'FALHA';
        $estado = $falha ? 'FALHA' : 'CONCLUIDA';
        $this->armazem->transitar($opId, 'PREPARANDO', $estado, ['resultado' => $r['resultado'], 'motivo' => $r['motivo']]);
        $this->armazem->journal($opId, 'PREPARAR', $falha ? 'ERRO' : 'DEPOIS', ['resultado' => $r['resultado'], 'motivo' => $r['motivo']]);

        return ['estado' => $estado, 'resultado' => $r['resultado'], 'motivo' => $r['motivo'], 'plano' => null];
    }

    /** @param array<string,mixed> $op */
    private function executar(int $opId, array $op, Caminhos $caminhos): array
    {
        // 1-2. Assinatura e manifesto a partir dos bytes guardados (D2-04.1: reverifica sempre).
        $pacote = $this->armazem->pacotePorId((int) ($op['pacotes_id'] ?? 0));
        if ($pacote === null) {
            throw new Recusa('pacote_inexistente');
        }
        $mb = base64_decode((string) $pacote['manifesto'], true);
        $sb = base64_decode((string) $pacote['sig'], true);
        if ($mb === false || $sb === false) {
            throw new Recusa('canonico', 'base64 guardado inválido');
        }
        ['manifesto' => $m, 'key_id' => $keyId] = $this->assinatura->verificar($mb, $sb);
        if ($m['chave'] !== $op['chave'] || $m['chave'] !== $pacote['chave'] || $m['versao'] !== $pacote['versao'] || $m['zip_sha256'] !== $pacote['zip_sha256']) {
            throw new Recusa('chave_divergente');
        }

        // 3. O zip: baixa se for do painel e ainda não está em disco; confere tamanho e sha256 do manifesto.
        $zip = $caminhos->zip($m['zip_sha256']);
        if (!is_file($zip)) {
            $this->baixar($pacote, $m, $caminhos, $zip);
        }
        clearstatcache(true, $zip);
        if (filesize($zip) !== $m['zip_bytes']) {
            throw new Recusa('zip_bytes');
        }
        if (hash_file('sha256', $zip) !== $m['zip_sha256']) {
            throw new Recusa('zip_sha256');
        }

        // 4. Retirado (P-3: a marca é local e só vai num sentido).
        if ((int) ($pacote['retirado'] ?? 0) === 1) {
            throw new Recusa('retirado');
        }

        // 5. Perfil estrito + arvore sem extrair. Qualquer recusa do zip sai como zip_<motivo>.
        try {
            $conta = ['total' => 0];
            $info  = ZipInspetor::arvoreDoZip($zip, $m['chave'], $m['commit'], $conta);
        } catch (Recusa $e) {
            throw new Recusa('zip_' . $e->motivo, $e->getMessage());
        }
        if ($info['arvore'] !== $m['arvore_sha256']) {
            throw new Recusa('arvore', $info['arvore']);
        }

        // 6. R1 com proveniência.
        $r1 = R1::avaliar($this->ctx, $this->armazem, $m['chave']);
        if ($r1['resultado'] !== R1::OK) {
            return ['resultado' => R1::GERENCIADO, 'motivo' => $r1['motivo']];
        }

        // 7. Versão. "de" vem de glpi_plugins, só para a decisão; o código instalado nunca é carregado.
        $reg = $this->armazem->registroPlugin($m['chave']);
        $de  = null;
        if ($reg !== null) {
            if (preg_match(Manifesto::RE_VERSAO, (string) $reg['version']) !== 1) {
                throw new Recusa('versao_instalada_ilegivel', (string) $reg['version']);
            }
            $de = (string) $reg['version'];
        }
        $vista    = $this->armazem->versaoVista($m['chave']);
        $abaixo   = false;
        if ($de !== null) {
            $cmp = Manifesto::compararVersao($m['versao'], $de);
            if ($cmp < 0) {
                throw new Recusa('downgrade', $de . ' -> ' . $m['versao']);
            }
            if ($cmp === 0) {
                $ult = $this->armazem->ultimaOperacaoOk($m['chave']);
                $hashInstalado = $ult !== null ? ($this->armazem->pacotePorId((int) ($ult['pacotes_id'] ?? 0))['zip_sha256'] ?? null) : null;
                if ($hashInstalado === $m['zip_sha256']) {
                    return ['resultado' => 'JA_NO_ALVO', 'motivo' => ''];
                }
                throw new Recusa('mesma_versao', $m['versao']);
            }
        }
        if ($vista !== null && Manifesto::compararVersao($m['versao'], $vista['versao_max']) < 0) {
            $abaixo = true; // D2-03.1: a aprovação (fatia c) exigirá o segundo checkbox
        }

        // 8. Faixa do GLPI e invariante CLI x FPM.
        if (!Plano::dentroDaFaixa($this->ctx->glpiVersao, $m['glpi_min'], $m['glpi_max'])) {
            throw new Recusa('glpi_fora_da_faixa', $this->ctx->glpiVersao);
        }
        if ($this->ctx->fpmDirs !== null && $this->ctx->fpmDirs !== $this->ctx->pluginsDirs) {
            throw new Recusa('diretorios_cli_fpm');
        }

        // 9. Extração no staging (G10) e arvore da pasta extraída.
        $s = $caminhos->S($m['chave'], $opId);
        if (!is_dir($caminhos->stagingOp($opId)) && !mkdir($caminhos->stagingOp($opId), 0755, true)) {
            throw new Recusa('staging', 'não criou o staging da operação');
        }
        Extrator::extrair($zip, $info, $s);
        if (Arvore::pasta($s) !== $m['arvore_sha256']) {
            throw new Recusa('arvore_staging');
        }

        // 10. P-2: maior versão de manifesto VÁLIDO já visto.
        $this->armazem->registrarVersaoVista($m['chave'], $m['versao'], $m['zip_sha256']);
        $vista = $this->armazem->versaoVista($m['chave']);

        // 11. O plano.
        $p = Plano::montar($m, $de, $keyId, $r1, $this->ctx, $this->armazem->tabelasBackup($m['chave']), $vista, $abaixo, (int) $info['total']);

        return ['plano' => $p['plano'], 'sha256' => $p['sha256']];
    }

    /** @param array<string,mixed> $pacote @param array<string,mixed> $m */
    private function baixar(array $pacote, array $m, Caminhos $caminhos, string $zip): void
    {
        if (($pacote['origem'] ?? '') !== 'painel' || $this->painel === null) {
            throw new Recusa('zip_ausente');
        }
        if (!is_dir($caminhos->pastaPacotes()) && !mkdir($caminhos->pastaPacotes(), 0700, true)) {
            throw new Recusa('pasta_pacotes');
        }
        $tmp = $zip . '.tmp' . getmypid();
        $r   = $this->painel->pedirPacote($m['zip_sha256'], $m['zip_bytes'], $tmp);
        if (($r['status'] ?? null) !== 200) {
            @unlink($tmp);
            throw new Recusa('download', 'HTTP ' . ($r['status'] ?? '?'));
        }
        if (!rename($tmp, $zip)) {
            @unlink($tmp);
            throw new Recusa('download', 'rename');
        }
    }

    /** Falhou depois de criar staging: apaga a pasta da operação e a raiz do staging se ficou vazia. */
    private function limpar(Caminhos $caminhos, int $opId, bool $raizExistia): void
    {
        Extrator::apagar($caminhos->stagingOp($opId));
        $raiz = $caminhos->stagingRaiz();
        if (!$raizExistia && is_dir($raiz) && (scandir($raiz) ?: []) === ['.', '..']) {
            @rmdir($raiz);
        }
    }
}
