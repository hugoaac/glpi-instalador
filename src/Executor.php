<?php

declare(strict_types=1);

namespace GlpiPlugin\Instalador;

use Exception;
use ZipArchive;

/**
 * Passos 1 a 5 da execução (desenho-m2 §5.5 / §5.8). Fatia (c): termina em AGUARDANDO_CONCLUSAO.
 * Concluir, ROLLBACK e restauração são da fatia (d); o que o rollback vai precisar (A, backup, journal T1) já fica gravado.
 *
 * Regras que valem em todo o arquivo:
 *  - toda mudança de estado é transitar(): UPDATE ... WHERE estado=<esperado>, 1 linha afetada (D2-08);
 *  - ANTES no journal -> transição -> ação -> DEPOIS;
 *  - caminhos só de Caminhos.php; chave, versão e hashes só do manifesto REVERIFICADO (D2-04);
 *  - INCERTO nunca repete a migração, e a chave fica bloqueada (a operação continua "aberta");
 *  - nenhum erro inesperado (Error) é capturado: o processo "morre" e a retomada decide pelo journal.
 */
final class Executor
{
    public const TIMEOUT_INSTALL  = 900;
    public const TIMEOUT_ACTIVATE = 120;
    /** Plugin.php:80-105 (GLPI 11.0.8) */
    public const ST_ACTIVATED = 1;
    public const ST_TOBECONFIGURED = 3;
    public const ST_NOTACTIVATED = 4;

    public function __construct(
        private readonly Contexto $ctx,
        private readonly Armazem $arm,
        private readonly Assinatura $ass,
        private readonly Preparador $prep,
        private readonly Dumper $dumper,
        private readonly Console $console,
        private readonly Observador $obs = new NaoObserva(),
        private readonly int $pollMs = 1000,
    ) {
    }

    /** @return array{estado:string,resultado:?string,motivo:?string} */
    public function executar(int $opId): array
    {
        $op = $this->arm->operacao($opId);
        if ($op === null || $op['estado'] !== 'APROVADA') {
            return $this->ret('IGNORADA', null, 'operação não está APROVADA');
        }
        // P-4: o aprovador tem de estar ativo ANTES de qualquer efeito (nada foi lido nem criado ainda).
        if ($this->login($op) === null) {
            return $this->encerrar($opId, 'APROVADA', 'FALHA', 'FALHA', 'aprovador_inativo');
        }
        // Decisão do Hugo (06/11): cron_limit > 1 recusa a execução, em vez de só avisar (D2-21).
        if ($this->ctx->cronLimit > 1) {
            return $this->encerrar($opId, 'APROVADA', 'FALHA', 'FALHA', 'cron_limit');
        }
        $cam = new Caminhos($this->ctx->marketplace, $this->ctx->docDir);
        try {
            // Passo 1 de novo (C5 + D2-04.1): staging do zero, assinatura reverificada, plano recalculado.
            $r = $this->prep->replanejar($opId);
            if (!isset($r['plano'])) {
                return $this->encerrar($opId, 'APROVADA', 'CONCLUIDA', (string) $r['resultado'], (string) $r['motivo']);
            }
            if (!hash_equals((string) $op['plano_sha256'], (string) $r['sha256'])) {
                // DIVERGENTE: nada foi tocado em marketplace/ além do staging, que sai agora.
                Extrator::apagar($cam->stagingOp($opId));
                $this->arm->journal($opId, 'APROVADA', 'ERRO', ['resultado' => 'DIVERGENTE', 'plano_aprovado' => $op['plano_sha256'], 'plano_agora' => $r['sha256']]);

                return $this->encerrar($opId, 'APROVADA', 'CONCLUIDA', 'DIVERGENTE', 'plano_mudou', false);
            }
            $m = Fatos::manifesto($this->ass, (array) $this->arm->pacotePorId((int) ($op['pacotes_id'] ?? 0)));
            if ($m === null || $m['chave'] !== $op['chave']) {
                throw new Recusa('chave_divergente');
            }
            $de = $r['plano']['de'];
            $this->arm->journal($opId, 'APROVADA', 'DEPOIS', ['plano_sha256' => $r['sha256'], 'de' => $de, 'para' => $m['versao']]);
            if (!$this->arm->transitar($opId, 'APROVADA', 'BACKUP', ['versao_de' => $de])) {
                return $this->ret('IGNORADA', null, 'transição concorrente');
            }
        } catch (Recusa $e) {
            Extrator::apagar($cam->stagingOp($opId));

            return $this->encerrar($opId, 'APROVADA', 'FALHA', 'FALHA', $e->motivo);
        }

        // Passo 2: backup.
        try {
            $this->backup($opId, $m, $de, $cam);
        } catch (Recusa | Exception $e) {
            Extrator::apagar($cam->B($opId));
            Extrator::apagar($cam->stagingOp($opId));

            return $this->encerrar($opId, 'BACKUP', 'FALHA', 'FALHA', $e instanceof Recusa ? $e->motivo : 'backup');
        }

        // Passo 3: troca de pastas.
        try {
            $final = $this->trocar($opId, $m, $de, $cam);
        } catch (Recusa $e) {
            // a recusa pode vir antes da transição BACKUP -> TROCANDO: encerra a partir do estado em que a operação está
            $atual = (string) ($this->arm->operacao($opId)['estado'] ?? 'TROCANDO');

            return $this->encerrar($opId, $atual, 'FALHA', 'FALHA', $e->motivo);
        }

        // Passo 4: migração (a única etapa que nunca se repete).
        return $this->migrar($opId, $m, $de, $final, $cam);
    }

    // ------------------------------------------------------------------------------------------ passo 2

    /** @param array<string,mixed> $m */
    private function backup(int $opId, array $m, ?string $de, Caminhos $cam): void
    {
        $B = $cam->B($opId);
        $this->arm->journal($opId, 'BACKUP', 'ANTES', ['chave' => $m['chave']]);
        if (!is_dir(dirname($B)) && !mkdir(dirname($B), 0700, true)) {
            throw new Recusa('backup_pasta');
        }
        if (!mkdir($B, 0700)) {
            throw new Recusa('backup_pasta', 'pasta do backup já existe ou não foi criada');
        }
        $M = $cam->M($m['chave']);
        $lista = is_dir($M) ? self::arquivosDe($M) : [];
        $arquivos = [];
        if ($lista !== []) {
            $z = new ZipArchive();
            if ($z->open($B . '/codigo.zip', ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new Recusa('backup_codigo');
            }
            foreach ($lista as $rel) {
                $z->addFile($M . '/' . $rel, $rel);
            }
            if (!$z->close()) {
                throw new Recusa('backup_codigo');
            }
            @chmod($B . '/codigo.zip', 0600);
        }
        file_put_contents($B . '/arquivos.txt', implode("\n", $lista) . ($lista === [] ? '' : "\n"));
        @chmod($B . '/arquivos.txt', 0600);

        $d = $this->dumper->gerar($m['chave'], $m['tabelas_core_tocadas'], $B);

        foreach (['codigo.zip', 'arquivos.txt'] as $n) {
            if (is_file($B . '/' . $n)) {
                $d['arquivos'][$n] = hash_file('sha256', $B . '/' . $n);
            }
        }
        ksort($d['arquivos']);
        $json = json_encode([
            'formato' => 'instalador-backup-v1', 'op' => $opId, 'chave' => $m['chave'], 'versao_de' => $de, 'versao_para' => $m['versao'],
            'arquivos' => $d['arquivos'], 'tabelas' => $d['tabelas'],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($B . '/backup.json', $json);
        @chmod($B . '/backup.json', 0600);
        // Os arquivos são RELIDOS e conferidos contra o que o backup.json afirma.
        $lido = json_decode((string) file_get_contents($B . '/backup.json'), true);
        foreach ($lido['arquivos'] as $nome => $hash) {
            if (!is_file($B . '/' . $nome) || hash_file('sha256', $B . '/' . $nome) !== $hash) {
                throw new Recusa('backup_hash', $nome);
            }
        }
        $this->arm->journal($opId, 'BACKUP', 'DEPOIS', ['backup_json_sha256' => hash('sha256', $json), 'arquivos' => count($d['arquivos']), 'tabelas' => $d['tabelas']]);
    }

    /** @return list<string> caminhos relativos, ordenados, só arquivos (sem link). */
    public static function arquivosDe(string $raiz): array
    {
        $out = [];
        self::andar($raiz, '', $out);
        sort($out, SORT_STRING);

        return $out;
    }

    /** @param list<string> $out */
    private static function andar(string $d, string $rel, array &$out): void
    {
        foreach (scandir($d) ?: [] as $n) {
            if ($n === '.' || $n === '..') {
                continue;
            }
            $abs = $d . '/' . $n;
            $r   = $rel === '' ? $n : $rel . '/' . $n;
            if (is_link($abs)) {
                continue;
            }
            if (is_dir($abs)) {
                self::andar($abs, $r, $out);
            } elseif (is_file($abs)) {
                $out[] = $r;
            }
        }
    }

    // ------------------------------------------------------------------------------------------ passo 3

    /** @param array<string,mixed> $m @return string arvore(M) depois do T2 */
    private function trocar(int $opId, array $m, ?string $de, Caminhos $cam): string
    {
        $chave = $m['chave'];
        $M = $cam->M($chave);
        $S = $cam->S($chave, $opId);
        $A = $de !== null ? $cam->A($chave, $de, $opId) : null;
        $anterior = is_dir($M) ? Arvore::pasta($M) : null;
        if (($anterior !== null) !== ($de !== null)) {
            throw new Recusa('troca_estado_inesperado', 'marketplace/<chave> não corresponde ao plano');
        }
        $reg = $this->arm->registroPlugin($chave);
        $this->arm->journal($opId, 'TROCAR', 'ANTES', ['passo' => 'T1', 'arvore_m' => $anterior,
            'version' => $reg['version'] ?? null, 'state' => $reg['state'] ?? null]);
        if (!$this->arm->transitar($opId, 'BACKUP', 'TROCANDO')) {
            throw new Recusa('transicao_concorrente');
        }
        $this->obs->ponto('antes_t1', $opId);
        if ($A !== null) {
            if (!is_dir(dirname($A)) && !mkdir(dirname($A), 0755, true)) {
                throw new Recusa('troca_pasta_anterior');
            }
            if (!rename($M, $A)) {
                throw new Recusa('troca_t1');
            }
        }
        $this->arm->journal($opId, 'TROCAR', 'DEPOIS', ['passo' => 'T1']);
        $this->obs->ponto('entre_t1_t2', $opId);

        // T2. B-12 / D2-12: recalcula arvore(S) IMEDIATAMENTE antes do rename. O staging fica dentro do marketplace
        // durante o backup (minutos); quem escreve ali nesse intervalo não pode ser promovido a código ativo.
        if (Arvore::pasta($S) !== $m['arvore_sha256']) {
            $this->desfazer($cam, $chave, $de, $opId, false);
            Extrator::apagar($cam->stagingOp($opId));
            $this->arm->journal($opId, 'TROCAR', 'ERRO', ['passo' => 'T2', 'motivo' => 'staging_alterado']);
            throw new Recusa('staging_alterado');
        }
        $this->arm->journal($opId, 'TROCAR', 'ANTES', ['passo' => 'T2', 'arvore_s' => $m['arvore_sha256']]);
        if (!rename($S, $M)) {
            $this->desfazer($cam, $chave, $de, $opId, false);
            throw new Recusa('troca_t2');
        }
        $this->obs->ponto('apos_t2', $opId);
        // arvore(M) DEPOIS do rename (B-12): é ela que vira arvore_final, a base da proveniência.
        $final = Arvore::pasta($M);
        if ($final !== $m['arvore_sha256']) {
            $this->desfazer($cam, $chave, $de, $opId, true);
            $this->arm->journal($opId, 'TROCAR', 'ERRO', ['passo' => 'T2', 'motivo' => 'arvore_final']);
            throw new Recusa('arvore_final');
        }
        @rmdir($cam->stagingOp($opId));
        $this->arm->journal($opId, 'TROCAR', 'DEPOIS', ['passo' => 'T2', 'arvore_m' => $final]);

        return $final;
    }

    /** Volta o código: M (se tem o código novo) vai para D; A volta para M. Restaura {version,state} do journal T1 (G7). */
    private function desfazer(Caminhos $cam, string $chave, ?string $de, int $opId, bool $mTemCodigoNovo): void
    {
        $M = $cam->M($chave);
        if ($mTemCodigoNovo && is_dir($M)) {
            $D = $cam->D($opId);
            if (is_dir(dirname($D)) || mkdir(dirname($D), 0755, true)) {
                rename($M, $D);
            }
        }
        if ($de !== null) {
            $A = $cam->A($chave, $de, $opId);
            if (is_dir($A) && !file_exists($M)) {
                rename($A, $M);
            }
        }
        $t1 = $this->t1($opId);
        if ($t1 !== null && isset($t1['version'], $t1['state'])) {
            $this->arm->restaurarRegistroPlugin($chave, (string) $t1['version'], (int) $t1['state']);
        }
    }

    /** @return array<string,mixed>|null os fatos do checkpoint T1 */
    private function t1(int $opId): ?array
    {
        foreach ($this->arm->journalDe($opId) as $j) {
            if ($j['passo'] === 'TROCAR' && $j['fase'] === 'ANTES' && ($j['dados']['passo'] ?? '') === 'T1') {
                return $j['dados'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------------------------------ passos 4 e 5

    /** @param array<string,mixed> $m @return array{estado:string,resultado:?string,motivo:?string} */
    private function migrar(int $opId, array $m, ?string $de, string $final, Caminhos $cam): array
    {
        $chave = $m['chave'];
        $op = (array) $this->arm->operacao($opId);
        // D2-20: relê o login e confere ativo AGORA. Nada migrou, mas o código já trocou: desfaz a troca.
        $login = $this->login($op);
        if ($login === null) {
            $this->desfazer($cam, $chave, $de, $opId, true);

            return $this->encerrar($opId, 'TROCANDO', 'FALHA', 'FALHA', 'aprovador_inativo');
        }
        $ini = hrtime(true);
        $this->arm->journal($opId, 'MIGRAR', 'ANTES', ['chave' => $chave, 'para' => $m['versao']]);
        if (!$this->arm->transitar($opId, 'TROCANDO', 'MIGRANDO', ['arvore_final' => $final, 'notupdated_inicio' => date('Y-m-d H:i:s')])) {
            return $this->ret('IGNORADA', null, 'transição concorrente');
        }
        $this->obs->ponto('antes_filho', $opId);
        $r = $this->console->executar('install', $chave, $login, $cam->B($opId), self::TIMEOUT_INSTALL, $this->arm, $opId);
        $janela = round((hrtime(true) - $ini) / 1e9, 3);
        $reg = $this->arm->registroPlugin($chave);
        $campos = ['notupdated_fim' => date('Y-m-d H:i:s'), 'migracao_s' => (int) ceil($janela)];
        $fatos = ['exit' => $r['exit'], 'timeout' => $r['timeout'], 'janela_notupdated_s' => $janela,
            'version' => $reg['version'] ?? null, 'state' => $reg['state'] ?? null];
        if ($r['timeout'] || $r['exit'] !== 0) {
            $this->arm->journal($opId, 'MIGRAR', 'ERRO', $fatos);

            return $this->incerto($opId, 'MIGRANDO', $r['timeout'] ? 'migracao_timeout' : 'migracao_erro', $campos);
        }
        // §5.8 / InstallCommand.php:126-133: exit 0 + state NOTACTIVATED/TOBECONFIGURED + version = para.
        if ($reg === null || !in_array($reg['state'], [self::ST_NOTACTIVATED, self::ST_TOBECONFIGURED], true) || $reg['version'] !== $m['versao']) {
            $this->arm->journal($opId, 'MIGRAR', 'ERRO', $fatos + ['motivo' => 'estado_apos_migracao']);

            return $this->incerto($opId, 'MIGRANDO', 'estado_apos_migracao', $campos);
        }
        $this->arm->journal($opId, 'MIGRAR', 'DEPOIS', $fatos);
        if (!$this->arm->transitar($opId, 'MIGRANDO', 'ATIVANDO', $campos)) {
            return $this->ret('IGNORADA', null, 'transição concorrente');
        }

        return $this->ativar($opId, $chave, $login, $this->t1($opId), $de === null);
    }

    /**
     * Passo 5. Só ativa se estava ativo antes (journal T1) ou se é instalação nova.
     *
     * @param array<string,mixed>|null $t1
     * @return array{estado:string,resultado:?string,motivo:?string}
     */
    private function ativar(int $opId, string $chave, string $login, ?array $t1, bool $instalacaoNova): array
    {
        $estavaAtivo = (int) ($t1['state'] ?? 0) === self::ST_ACTIVATED;
        if (!$instalacaoNova && !$estavaAtivo) {
            $this->arm->journal($opId, 'ATIVAR', 'DEPOIS', ['ativou' => false]);

            return $this->chegou($opId);
        }
        $this->arm->journal($opId, 'ATIVAR', 'ANTES', ['chave' => $chave]);
        $r = $this->console->executar('activate', $chave, $login, $this->dirB($opId), self::TIMEOUT_ACTIVATE, $this->arm, $opId);
        $reg = $this->arm->registroPlugin($chave);
        $fatos = ['exit' => $r['exit'], 'timeout' => $r['timeout'], 'version' => $reg['version'] ?? null, 'state' => $reg['state'] ?? null];
        if (!$r['timeout'] && $r['exit'] === 0 && ($reg['state'] ?? null) === self::ST_ACTIVATED) {
            $this->arm->journal($opId, 'ATIVAR', 'DEPOIS', $fatos + ['ativou' => true]);

            return $this->chegou($opId);
        }
        $this->arm->journal($opId, 'ATIVAR', 'ERRO', $fatos);

        return $this->encerrar($opId, 'ATIVANDO', 'INSTALADO_SEM_VALIDAR', 'INSTALADO_SEM_VALIDAR', 'ativacao', false);
    }

    /** @return array{estado:string,resultado:?string,motivo:?string} */
    private function chegou(int $opId): array
    {
        if (!$this->arm->transitar($opId, 'ATIVANDO', 'AGUARDANDO_CONCLUSAO')) {
            return $this->ret('IGNORADA', null, 'transição concorrente');
        }
        $this->arm->journal($opId, 'EXECUTAR', 'DEPOIS', ['estado' => 'AGUARDANDO_CONCLUSAO']);

        return $this->ret('AGUARDANDO_CONCLUSAO', null, null);
    }

    private function dirB(int $opId): string
    {
        return (new Caminhos($this->ctx->marketplace, $this->ctx->docDir))->B($opId);
    }

    // ------------------------------------------------------------------------------------------ retomada

    /**
     * Operações que ficaram no meio (kill -9, OOM, reboot). Com os dois locks na mão, nenhuma está andando.
     * Decide pelo journal e pelas árvores em disco. NUNCA repete a migração. @return int quantas foram resolvidas
     */
    public function retomar(): int
    {
        $n = 0;
        $cam = new Caminhos($this->ctx->marketplace, $this->ctx->docDir);
        foreach ($this->arm->operacoesPorEstado(['BACKUP', 'TROCANDO', 'MIGRANDO', 'ATIVANDO']) as $op) {
            $id = (int) $op['id'];
            switch ($op['estado']) {
                case 'BACKUP':
                    Extrator::apagar($cam->B($id));
                    Extrator::apagar($cam->stagingOp($id));
                    $this->arm->journal($id, 'RETOMADA', 'ERRO', ['de' => 'BACKUP']);
                    $this->encerrar($id, 'BACKUP', 'FALHA', 'FALHA', 'interrompido_no_backup');
                    break;
                case 'TROCANDO':
                    $this->retomarTrocando($op, $cam);
                    break;
                case 'MIGRANDO':
                    $this->retomarMigrando($op);
                    break;
                default:
                    $this->retomarAtivando($op);
            }
            $n++;
        }

        return $n;
    }

    /** @param array<string,mixed> $op */
    private function retomarTrocando(array $op, Caminhos $cam): void
    {
        $id = (int) $op['id'];
        $chave = (string) $op['chave'];
        $de = $op['versao_de'] ?? null;
        $t1 = $this->t1($id);
        $pacote = $this->arm->pacotePorId((int) ($op['pacotes_id'] ?? 0));
        $m = $pacote === null ? null : Fatos::manifesto($this->ass, $pacote);
        if ($t1 === null || $m === null || $m['chave'] !== $chave) {
            $this->incerto($id, 'TROCANDO', 'retomada_sem_fatos', []);

            return;
        }
        $M = $cam->M($chave);
        $S = $cam->S($chave, $id);
        $A = $de !== null ? $cam->A($chave, (string) $de, $id) : null;
        $aM = is_dir($M) ? Arvore::pasta($M) : null;
        $aA = ($A !== null && is_dir($A)) ? Arvore::pasta($A) : null;
        $sExiste = is_dir($S);
        $ant = $t1['arvore_m'] ?? null;
        $pac = $m['arvore_sha256'];
        $fatos = ['arvore_m' => $aM, 'arvore_a' => $aA, 's_existe' => $sExiste, 'anterior' => $ant, 'pacote' => $pac];
        $this->arm->journal($id, 'RETOMADA', 'ANTES', ['de' => 'TROCANDO'] + $fatos);

        if ($sExiste && $aA === null && $aM === $ant) {
            // antes do T1 (ou, em instalação nova, antes do T2)
            Extrator::apagar($cam->stagingOp($id));
            $this->voltarRegistro($chave, $t1);
            $this->encerrar($id, 'TROCANDO', 'FALHA', 'FALHA', 'interrompido_na_troca');
        } elseif ($sExiste && $ant !== null && $aM === null && $aA === $ant) {
            // entre T1 e T2: A -> M
            rename($A, $M);
            Extrator::apagar($cam->stagingOp($id));
            $this->voltarRegistro($chave, $t1);
            $this->encerrar($id, 'TROCANDO', 'FALHA', 'FALHA', 'interrompido_na_troca');
        } elseif (!$sExiste && $aM === $pac && ($de === null ? $aA === null : $aA === $ant)) {
            // depois do T2: M -> D, A -> M
            Extrator::apagar($cam->stagingOp($id));
            $D = $cam->D($id);
            if (is_dir(dirname($D)) || mkdir(dirname($D), 0755, true)) {
                rename($M, $D);
            }
            if ($A !== null && is_dir($A)) {
                rename($A, $M);
            }
            $this->voltarRegistro($chave, $t1);
            $this->encerrar($id, 'TROCANDO', 'FALHA', 'FALHA', 'interrompido_na_troca');
        } elseif ($aM === null && $aA === null) {
            $this->arm->journal($id, 'RETOMADA', 'ERRO', ['resultado' => 'SEM_PLUGIN']);
            $this->arm->transitar($id, 'TROCANDO', 'SEM_PLUGIN', ['resultado' => 'SEM_PLUGIN', 'motivo' => 'sem_pasta', 'bloqueia_chave' => 1]);
        } else {
            $this->incerto($id, 'TROCANDO', 'pastas_inesperadas', []);
        }
    }

    /** @param array<string,mixed> $op */
    private function retomarMigrando(array $op): void
    {
        $id = (int) $op['id'];
        $chave = (string) $op['chave'];
        $pid = (int) ($op['filho_pid'] ?? 0);
        $vivo = $pid > 0 ? $this->console->filhoVivo($pid, $chave) : false;
        $espera = 0;
        // Filho ainda vivo (o cron morreu, o filho não): espera acabar. Sem /proc (null) não há como saber: INCERTO já.
        while ($vivo === true && $espera < self::TIMEOUT_INSTALL * 1000) {
            usleep($this->pollMs * 1000);
            $espera += $this->pollMs;
            $vivo = $this->console->filhoVivo($pid, $chave);
        }
        $reg = $this->arm->registroPlugin($chave);
        $this->arm->journal($id, 'RETOMADA', 'ERRO', ['de' => 'MIGRANDO', 'filho_vivo_ao_chegar' => $pid > 0, 'esperou_ms' => $espera,
            'version' => $reg['version'] ?? null, 'state' => $reg['state'] ?? null]);
        // Sem código de saída do filho não há como afirmar sucesso: INCERTO, chave bloqueada, e NUNCA repete.
        $this->incerto($id, 'MIGRANDO', $vivo === null ? 'sem_proc' : 'filho_sem_resultado', []);
    }

    /** @param array<string,mixed> $op */
    private function retomarAtivando(array $op): void
    {
        $id = (int) $op['id'];
        $chave = (string) $op['chave'];
        $reg = $this->arm->registroPlugin($chave);
        $this->arm->journal($id, 'RETOMADA', 'ANTES', ['de' => 'ATIVANDO', 'version' => $reg['version'] ?? null, 'state' => $reg['state'] ?? null]);
        if (($reg['state'] ?? null) === self::ST_ACTIVATED) {
            $this->chegou($id);

            return;
        }
        $login = $this->login($op);
        if ($login === null) {
            $this->encerrar($id, 'ATIVANDO', 'INSTALADO_SEM_VALIDAR', 'INSTALADO_SEM_VALIDAR', 'aprovador_inativo', false);

            return;
        }
        // Ativar de novo é seguro (não migra nada): só tenta se o plano manda ativar.
        $this->ativar($id, $chave, $login, $this->t1($id), ($op['versao_de'] ?? null) === null);
    }

    /** @param array<string,mixed> $t1 */
    private function voltarRegistro(string $chave, array $t1): void
    {
        if (isset($t1['version'], $t1['state'])) {
            $this->arm->restaurarRegistroPlugin($chave, (string) $t1['version'], (int) $t1['state']);
        }
    }

    // ------------------------------------------------------------------------------------------ utilidades

    /** O login do aprovador, RELIDO do banco agora, ativo e não apagado; senão null (D2-20, P-4). @param array<string,mixed> $op */
    private function login(array $op): ?string
    {
        $uid = (int) ($op['aprovado_por'] ?? 0);
        if ($uid < 1) {
            return null;
        }
        $u = $this->arm->usuario($uid);
        if ($u === null || $u['is_active'] !== 1 || $u['is_deleted'] !== 0 || $u['login'] === '') {
            return null;
        }

        return $u['login'];
    }

    /** @param array<string,mixed> $extra */
    private function incerto(int $opId, string $de, string $motivo, array $extra): array
    {
        $this->arm->journal($opId, 'EXECUTAR', 'ERRO', ['resultado' => 'INCERTO', 'motivo' => $motivo]);
        $this->arm->transitar($opId, $de, 'INCERTO', $extra + ['resultado' => 'INCERTO', 'motivo' => $motivo, 'bloqueia_chave' => 1]);

        return $this->ret('INCERTO', 'INCERTO', $motivo);
    }

    /** Estado final. $fecha: grava o fim (FALHA/CONCLUIDA). */
    private function encerrar(int $opId, string $de, string $estado, string $resultado, string $motivo, bool $fecha = true): array
    {
        $campos = ['resultado' => $resultado, 'motivo' => $motivo];
        if ($fecha) {
            $campos['fim'] = date('Y-m-d H:i:s');
        }
        $this->arm->transitar($opId, $de, $estado, $campos);
        if ($estado === 'FALHA') {
            $this->arm->journal($opId, 'EXECUTAR', 'ERRO', ['resultado' => $resultado, 'motivo' => $motivo, 'de' => $de]);
        }

        return $this->ret($estado, $resultado, $motivo);
    }

    private function ret(string $estado, ?string $resultado, ?string $motivo): array
    {
        return ['estado' => $estado, 'resultado' => $resultado, 'motivo' => $motivo];
    }
}
