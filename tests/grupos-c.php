<?php

/**
 * Fatia (c): passos 1 a 5 com duplos (Console e Dumper) e o ArmazemMemoria. Incluído por run.php.
 * Usa os zips REAIS do subticket (assinados pela chave principal): 1.1.0 instala do zero, 1.2.1 atualiza.
 */

declare(strict_types=1);

use GlpiPlugin\Instalador\Armazem;
use GlpiPlugin\Instalador\ArmazemMemoria;
use GlpiPlugin\Instalador\Assinatura;
use GlpiPlugin\Instalador\Arvore;
use GlpiPlugin\Instalador\Caminhos;
use GlpiPlugin\Instalador\Console;
use GlpiPlugin\Instalador\Contexto;
use GlpiPlugin\Instalador\Ddl;
use GlpiPlugin\Instalador\Dumper;
use GlpiPlugin\Instalador\Executor;
use GlpiPlugin\Instalador\Observador;
use GlpiPlugin\Instalador\Preparador;
use GlpiPlugin\Instalador\Subprocesso;

/** Simula a morte do processo: é um Error, e o Executor só captura Recusa e Exception. */
final class Morte extends Error
{
}

final class ConsoleFalso implements Console
{
    /** @var list<array{comando:string,chave:string,login:string}> */
    public array $chamadas = [];
    /** @var list<array<string,mixed>> respostas na ordem: exit, timeout, registro [version,state] */
    public array $respostas = [];
    public ?ArmazemMemoria $arm = null;
    /** @var list<?bool> */
    public array $vivo = [];

    public function executar(string $comando, string $chave, string $login, string $dirSaida, int $timeout, Armazem $arm, int $opId): array
    {
        $this->chamadas[] = ['comando' => $comando, 'chave' => $chave, 'login' => $login];
        $r = array_shift($this->respostas) ?? ['exit' => 0, 'timeout' => false];
        if ($comando === 'install') {
            $arm->transitar($opId, 'MIGRANDO', 'MIGRANDO', ['filho_pid' => 4242]);
        }
        if (isset($r['registro']) && $this->arm !== null) {
            $this->arm->plugins[$chave] = ['version' => $r['registro'][0], 'state' => $r['registro'][1]];
        }

        return ['exit' => $r['exit'], 'timeout' => $r['timeout'] ?? false, 'pid' => 4242, 'segundos' => 1];
    }

    public function filhoVivo(int $pid, string $chave): ?bool
    {
        return array_shift($this->vivo);
    }

    public function contar(string $comando): int
    {
        return count(array_filter($this->chamadas, static fn(array $c): bool => $c['comando'] === $comando));
    }
}

final class DumperFalso implements Dumper
{
    public int $chamadas = 0;

    public function gerar(string $chave, array $tabelasCore, string $dir): array
    {
        ++$this->chamadas;
        file_put_contents($dir . '/banco.sql', "-- falso $chave\n");

        return ['arquivos' => ['banco.sql' => hash_file('sha256', $dir . '/banco.sql')], 'tabelas' => []];
    }
}

final class ObservadorFalso implements Observador
{
    /** @var array<string,callable> */
    public array $acoes = [];

    public function ponto(string $nome, int $opId): void
    {
        if (isset($this->acoes[$nome])) {
            $this->acoes[$nome]($opId);
        }
    }
}

if (grupo('executar')) {
    $real = __DIR__ . '/fixtures/reais/subticket';
    $ass  = new Assinatura();
    $ler  = static fn(string $v): array => [file_get_contents("$real/subticket-$v.manifesto.json"), file_get_contents("$real/subticket-$v.sig"), "$real/subticket-$v.zip"];
    $n = 0;

    /** Um ambiente novo: marketplace, armazém, duplos e o executor. */
    $amb = static function (int $cronLimit = 1) use (&$n, $tmp, $ass): array {
        ++$n;
        $base = "$tmp/exec$n";
        mkdir($base, 0700, true);
        [$ctx0, $arm, $cam] = cenario($base);
        $ctx = new Contexto($ctx0->marketplace, $ctx0->pluginsDirs, $ctx0->fpmDirs, '11.0.8', $cronLimit, true, $ctx0->docDir);
        $arm->usuarios[7] = ['login' => 'aprovador', 'is_active' => 1, 'is_deleted' => 0];
        $con = new ConsoleFalso();
        $con->arm = $arm;
        $dump = new DumperFalso();
        $obs = new ObservadorFalso();
        $prep = new Preparador($ctx, $arm, $ass);
        $ex = new Executor($ctx, $arm, $ass, $prep, $dump, $con, $obs, 1);

        return compact('ctx', 'arm', 'cam', 'con', 'dump', 'obs', 'prep', 'ex', 'base');
    };
    /** Pede, prepara e aprova (como o formulário faria). @return int id da operação */
    $aprovada = static function (array $a, string $ver, int $uid = 7) use ($ler): int {
        [$mb, $sb, $zip] = $ler($ver);
        $m = json_decode($mb, true);
        $op = pedir($a['arm'], $a['cam'], $zip, $mb, $sb, $m);
        $r = $a['prep']->preparar($op);
        if ($r['estado'] !== 'PLANO_PRONTO') {
            throw new RuntimeException("preparar $ver: " . ($r['motivo'] ?? $r['estado']));
        }
        $a['arm']->transitar($op, 'PLANO_PRONTO', 'APROVADA', ['aprovado_por' => $uid]);

        return $op;
    };
    $fecha = static function (array $a, int $op): void {
        $a['arm']->transitar($op, 'AGUARDANDO_CONCLUSAO', 'CONCLUIDA', ['resultado' => 'SUCESSO']);
    };
    $subtkM = static fn(array $a): string => $a['cam']->M('subticket');
    $inst = ['exit' => 0, 'registro' => ['1.1.0', 4]];

    // ---- 1. instalação nova, do zero
    echo " instalação nova\n";
    $a = $amb();
    $a['con']->respostas = [$inst, ['exit' => 0, 'registro' => ['1.1.0', 1]]];
    $op = $aprovada($a, '1.1.0');
    $r = $a['ex']->executar($op);
    eq($r['estado'], 'AGUARDANDO_CONCLUSAO', 'instalar do zero termina em AGUARDANDO_CONCLUSAO (' . ($r['motivo'] ?? '') . ')');
    [$mb] = $ler('1.1.0');
    $m110 = json_decode($mb, true);
    eq(Arvore::pasta($subtkM($a)), $m110['arvore_sha256'], 'M tem a arvore do manifesto');
    eq($a['arm']->operacao($op)['arvore_final'], $m110['arvore_sha256'], 'arvore_final gravada');
    eq([$a['con']->contar('install'), $a['con']->contar('activate')], [1, 1], 'install e activate uma vez cada');
    eq($a['con']->chamadas[0]['login'], 'aprovador', 'o login vem de glpi_users por aprovado_por');
    ok(is_file($a['cam']->B($op) . '/backup.json') && is_file($a['cam']->B($op) . '/banco.sql') && is_file($a['cam']->B($op) . '/arquivos.txt'), 'backup.json, banco.sql e arquivos.txt existem');
    ok(!is_dir($a['cam']->stagingOp($op)), 'staging da operação saiu');
    $fases = array_map(static fn(array $j): string => $j['passo'] . ':' . $j['fase'], $a['arm']->journalDe($op));
    foreach (['BACKUP:ANTES', 'BACKUP:DEPOIS', 'TROCAR:ANTES', 'TROCAR:DEPOIS', 'MIGRAR:ANTES', 'MIGRAR:DEPOIS', 'ATIVAR:DEPOIS', 'EXECUTAR:DEPOIS'] as $f) {
        ok(in_array($f, $fases, true), "journal tem $f");
    }
    $mig = array_values(array_filter($a['arm']->journalDe($op), static fn(array $j): bool => $j['passo'] === 'MIGRAR' && $j['fase'] === 'DEPOIS'))[0]['dados'];
    ok(isset($mig['janela_notupdated_s']) && $a['arm']->operacao($op)['notupdated_inicio'] !== null, 'a janela NOTUPDATED vai medida no journal e nas colunas');

    // ---- 2. atualização 1.1.0 -> 1.2.1, ativa antes
    echo " atualização\n";
    $fecha($a, $op);
    $a['arm']->plugins['subticket'] = ['version' => '1.1.0', 'state' => 1];
    $antes = Arvore::pasta($subtkM($a));
    $a['con']->respostas = [['exit' => 0, 'registro' => ['1.2.1', 4]], ['exit' => 0, 'registro' => ['1.2.1', 1]]];
    $op2 = $aprovada($a, '1.2.1');
    $r = $a['ex']->executar($op2);
    eq($r['estado'], 'AGUARDANDO_CONCLUSAO', 'atualizar termina em AGUARDANDO_CONCLUSAO (' . ($r['motivo'] ?? '') . ')');
    $Aop = $a['cam']->A('subticket', '1.1.0', $op2);
    eq(is_dir($Aop) ? Arvore::pasta($Aop) : null, $antes, 'A guarda a versão anterior intacta');
    $bj = json_decode((string) file_get_contents($a['cam']->B($op2) . '/backup.json'), true);
    ok(isset($bj['arquivos']['codigo.zip'], $bj['arquivos']['banco.sql'], $bj['arquivos']['arquivos.txt']), 'backup.json com hashes do código, do banco e da lista');
    ok(str_contains((string) file_get_contents($a['cam']->B($op2) . '/arquivos.txt'), "setup.php\n"), 'arquivos.txt lista a versão anterior');
    eq($a['con']->contar('activate'), 2, 'estava ativo: activate chamado');

    // ---- 2b. estava DESATIVADO: não ativa
    $b = $amb();
    $b['con']->respostas = [$inst, ['exit' => 0, 'registro' => ['1.1.0', 1]]];
    $o1 = $aprovada($b, '1.1.0');
    $b['ex']->executar($o1);
    $fecha($b, $o1);
    $b['arm']->plugins['subticket'] = ['version' => '1.1.0', 'state' => 4];
    $b['con']->respostas = [['exit' => 0, 'registro' => ['1.2.1', 4]]];
    $o2 = $aprovada($b, '1.2.1');
    $r = $b['ex']->executar($o2);
    eq([$r['estado'], $b['con']->contar('activate')], ['AGUARDANDO_CONCLUSAO', 1], 'estava desativado: não chama activate (só o da instalação nova)');

    // ---- 3. DIVERGENTE: um fato mudou entre a aprovação e a execução
    echo " DIVERGENTE\n";
    $d = $amb();
    $op = $aprovada($d, '1.1.0');
    $d['arm']->registrarVersaoVista('subticket', '9.9.9', str_repeat('c', 64)); // apareceu uma versão mais nova
    $antesMp = listar($d['ctx']->marketplace);
    $r = $d['ex']->executar($op);
    eq([$r['estado'], $r['resultado'], $r['motivo']], ['CONCLUIDA', 'DIVERGENTE', 'plano_mudou'], 'fato mudado -> DIVERGENTE');
    $depoisMp = listar($d['ctx']->marketplace);
    $semStaging = static fn(array $l): array => array_filter($l, static fn(string $k): bool => !str_starts_with($k, '.hub-staging'), ARRAY_FILTER_USE_KEY);
    eq($semStaging($depoisMp), $semStaging($antesMp), 'DIVERGENTE: marketplace intacto (fora do staging)');
    ok(!is_dir($d['cam']->B($op)) && $d['con']->chamadas === [] && $d['dump']->chamadas === [] || $d['dump']->chamadas === 0, 'DIVERGENTE: sem backup e sem console');
    ok(!is_dir($d['cam']->stagingOp($op)), 'DIVERGENTE: staging apagado');

    // ---- 4. P-4 e cron_limit: antes de qualquer efeito
    echo " P-4 e cron_limit\n";
    $p = $amb();
    $op = $aprovada($p, '1.1.0');
    $p['arm']->usuarios[7]['is_active'] = 0;
    $r = $p['ex']->executar($op);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'aprovador_inativo'], 'P-4: aprovador inativo em APROVADA -> FALHA');
    ok(!is_dir($p['cam']->B($op)) && !is_dir($p['cam']->M('subticket')) && $p['con']->chamadas === [] && $p['dump']->chamadas === 0, 'P-4: nada foi criado (nem backup, nem M, nem console)');
    $p = $amb();
    $op = $aprovada($p, '1.1.0');
    $p['arm']->usuarios[7]['is_deleted'] = 1;
    eq($p['ex']->executar($op)['motivo'], 'aprovador_inativo', 'P-4: aprovador apagado -> FALHA');
    $c = $amb(2);
    $op = $aprovada($c, '1.1.0');
    $r = $c['ex']->executar($op);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'cron_limit'], 'cron_limit > 1 recusa a execução');
    ok(!is_dir($c['cam']->B($op)) && !is_dir($c['cam']->M('subticket')) && $c['con']->chamadas === [], 'cron_limit: nada foi tocado');

    // ---- 5. login relido
    $l = $amb();
    $l['con']->respostas = [$inst, ['exit' => 0, 'registro' => ['1.1.0', 1]]];
    $op = $aprovada($l, '1.1.0');
    $l['arm']->usuarios[7]['login'] = 'renomeado';
    $l['ex']->executar($op);
    eq($l['con']->chamadas[0]['login'], 'renomeado', 'o --username é RELIDO do banco no momento da execução');

    // ---- 6. B-12: staging adulterado entre o backup e o rename
    echo " B-12\n";
    foreach (['antes_t1', 'entre_t1_t2'] as $ponto) {
        $s = $amb();
        $s['con']->respostas = [$inst, ['exit' => 0, 'registro' => ['1.1.0', 1]]];
        $o1 = $aprovada($s, '1.1.0');
        $s['ex']->executar($o1);
        $fecha($s, $o1);
        $s['arm']->plugins['subticket'] = ['version' => '1.1.0', 'state' => 1];
        $ant = Arvore::pasta($subtkM($s));
        $o2 = $aprovada($s, '1.2.1');
        $S = $s['cam']->S('subticket', $o2);
        $s['obs']->acoes[$ponto] = static function (int $id) use ($S): void {
            file_put_contents($S . '/backdoor.php', '<?php // plantado');
        };
        $r = $s['ex']->executar($o2);
        eq([$r['estado'], $r['motivo']], ['FALHA', 'staging_alterado'], "B-12 ($ponto): arvore(S) recalculada antes do rename -> staging_alterado");
        eq(Arvore::pasta($subtkM($s)), $ant, "B-12 ($ponto): M voltou à versão anterior");
        ok(!file_exists($subtkM($s) . '/backdoor.php') && $s['con']->contar('install') === 1, "B-12 ($ponto): nada plantado em M e nenhuma migração");
        eq($s['arm']->plugins['subticket'], ['version' => '1.1.0', 'state' => 1], "B-12 ($ponto): {version,state} do journal T1 restaurados (G7)");
    }

    // ---- 7. INCERTO nunca repete
    echo " INCERTO\n";
    foreach (['exit' => ['exit' => 3], 'timeout' => ['exit' => null, 'timeout' => true], 'estado errado' => ['exit' => 0, 'registro' => ['1.1.0', 6]]] as $nome => $resp) {
        $i = $amb();
        $i['con']->respostas = [$resp];
        $op = $aprovada($i, '1.1.0');
        $r = $i['ex']->executar($op);
        eq([$r['estado'], $r['resultado']], ['INCERTO', 'INCERTO'], "migração com $nome -> INCERTO");
        eq((int) $i['arm']->operacao($op)['bloqueia_chave'], 1, "INCERTO ($nome): chave bloqueada");
        $i['ex']->executar($op);
        $i['ex']->retomar();
        $i['ex']->executar($op);
        eq([$i['con']->contar('install'), $i['arm']->operacao($op)['estado']], [1, 'INCERTO'], "INCERTO ($nome): executar/retomar de novo NÃO repete install");
        eq(motivo(fn() => $i['arm']->criarOperacao(['chave' => 'subticket', 'tipo' => 'INSTALAR'])), 'operacao_aberta', "INCERTO ($nome): outra operação da chave é recusada");
        ok(is_dir($subtkM($i)), "INCERTO ($nome): o código novo fica onde está (decisão é humana)");
    }

    // ---- 8. kill -9 (morte simulada) em cada ponto
    echo " retomada depois de morte\n";
    $morrer = static function (int $id): void {
        throw new Morte('kill -9');
    };
    foreach (['antes_t1' => 'FALHA', 'entre_t1_t2' => 'FALHA', 'apos_t2' => 'FALHA', 'antes_filho' => 'INCERTO'] as $ponto => $esperado) {
        $k = $amb();
        $k['con']->respostas = [$inst, ['exit' => 0, 'registro' => ['1.1.0', 1]]];
        $o1 = $aprovada($k, '1.1.0');
        $k['ex']->executar($o1);
        $fecha($k, $o1);
        $k['arm']->plugins['subticket'] = ['version' => '1.1.0', 'state' => 1];
        $ant = Arvore::pasta($subtkM($k));
        $o2 = $aprovada($k, '1.2.1');
        $k['obs']->acoes[$ponto] = $morrer;
        $morreu = false;
        try {
            $k['ex']->executar($o2);
        } catch (Morte) {
            $morreu = true;
        }
        ok($morreu, "morte em $ponto");
        $k['obs']->acoes = [];
        $k['ex']->retomar();
        $op2 = $k['arm']->operacao($o2);
        eq($op2['estado'], $esperado, "retomada depois de morrer em $ponto -> $esperado (" . ($op2['motivo'] ?? '') . ')');
        if ($esperado === 'FALHA') {
            eq(Arvore::pasta($subtkM($k)), $ant, "retomada ($ponto): M voltou à versão anterior");
            ok(!is_dir($k['cam']->stagingOp($o2)), "retomada ($ponto): staging apagado");
            eq($k['arm']->plugins['subticket'], ['version' => '1.1.0', 'state' => 1], "retomada ($ponto): {version,state} restaurados");
        }
        eq($k['con']->contar('install'), 1, "retomada ($ponto): nenhum install novo (só o da instalação 1.1.0)");
    }
    // kill -9 do cron com o filho VIVO: espera o filho e vira INCERTO, sem repetir
    $k = $amb();
    $k['con']->respostas = [$inst, ['exit' => 0, 'registro' => ['1.1.0', 1]]];
    $o1 = $aprovada($k, '1.1.0');
    $k['obs']->acoes['antes_filho'] = $morrer;
    try {
        $k['ex']->executar($o1);
    } catch (Morte) {
    }
    $k['arm']->transitar($o1, 'MIGRANDO', 'MIGRANDO', ['filho_pid' => 4242]);
    $k['con']->vivo = [true, true, false];
    $k['ex']->retomar();
    eq([$k['arm']->operacao($o1)['estado'], $k['con']->contar('install'), $k['con']->vivo], ['INCERTO', 0, []], 'filho vivo: espera até acabar, vira INCERTO e não repete');
    // sem /proc: INCERTO na hora
    $k = $amb();
    $o1 = $aprovada($k, '1.1.0');
    $k['obs']->acoes['antes_filho'] = $morrer;
    try {
        $k['ex']->executar($o1);
    } catch (Morte) {
    }
    $k['arm']->transitar($o1, 'MIGRANDO', 'MIGRANDO', ['filho_pid' => 4242]);
    $k['con']->vivo = [null];
    $k['ex']->retomar();
    eq($k['arm']->operacao($o1)['motivo'], 'sem_proc', 'sem /proc: INCERTO (sem_proc)');
    // morte em ATIVANDO: o state ACTIVATED basta
    $k = $amb();
    $k['con']->respostas = [$inst, ['exit' => 0, 'registro' => ['1.1.0', 1]]];
    $o1 = $aprovada($k, '1.1.0');
    $k['obs']->acoes['antes_filho'] = static function (int $id): void {
    };
    $k['ex']->executar($o1);
    $k['arm']->transitar($o1, 'AGUARDANDO_CONCLUSAO', 'ATIVANDO');
    $k['con']->chamadas = [];
    $k['ex']->retomar();
    eq([$k['arm']->operacao($o1)['estado'], count($k['con']->chamadas)], ['AGUARDANDO_CONCLUSAO', 0], 'retomada de ATIVANDO com state ACTIVATED: AGUARDANDO_CONCLUSAO, sem comando');

    // ---- 9. ativação falha -> INSTALADO_SEM_VALIDAR
    $f = $amb();
    $f['con']->respostas = [$inst, ['exit' => 1]];
    $op = $aprovada($f, '1.1.0');
    $r = $f['ex']->executar($op);
    eq([$r['estado'], $r['resultado']], ['INSTALADO_SEM_VALIDAR', 'INSTALADO_SEM_VALIDAR'], 'activate falhou -> INSTALADO_SEM_VALIDAR');

    // ---- 10. backup que falha não troca nada
    $q = $amb();
    $op = $aprovada($q, '1.1.0');
    $q['obs']->acoes['antes_t1'] = static function (int $id): void {
    };
    $dumpRuim = new class implements Dumper {
        public function gerar(string $chave, array $tabelasCore, string $dir): array
        {
            throw new GlpiPlugin\Instalador\Recusa('dump', 'banco indisponível');
        }
    };
    $exRuim = new Executor($q['ctx'], $q['arm'], $ass, $q['prep'], $dumpRuim, $q['con']);
    $r = $exRuim->executar($op);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'dump'], 'dump que falha -> FALHA');
    ok(!is_dir($q['cam']->M('subticket')) && !is_dir($q['cam']->B($op)) && $q['con']->chamadas === [], 'dump que falha: nada trocado, backup parcial apagado');

    // ---- 11. cmdline do filho e DDL no plugin_init
    ok(Subprocesso::cmdlineConfere("php\0bin/console\0glpi:plugin:install\0--no-interaction\0--username=x\0hubteste\0", 'hubteste'), 'cmdline com install e a chave confere');
    ok(!Subprocesso::cmdlineConfere("php\0bin/console\0glpi:plugin:install\0--no-interaction\0outro\0", 'hubteste'), 'cmdline sem a chave não confere');
    ok(!Subprocesso::cmdlineConfere("php\0bin/console\0cache:clear\0hubteste\0", 'hubteste'), 'cmdline de outro comando não confere');
    $ini = "<?php\nfunction plugin_init_abc(): void {\n  global \$DB;\n  \$DB->doQuery('ALTER TABLE x ADD y int');\n}\nfunction plugin_version_abc() { return []; }\n";
    ok(Ddl::temDdl(Ddl::corpoDoInit($ini, 'abc')), 'DDL dentro de plugin_init_<chave> é achado');
    $fora = "<?php\nfunction plugin_init_abc(): void {\n}\nfunction plugin_abc_install() {\n  \$DB->doQuery('ALTER TABLE x ADD y int');\n}\n";
    ok(!Ddl::temDdl(Ddl::corpoDoInit($fora, 'abc')), 'DDL no install (fora do init) não é achado');
    ok(Ddl::temDdl(Ddl::corpoDoInit("<?php\nfunction plugin_init_abc() {\n \$migration->addField('t','c','int');\n}\n", 'abc')), 'addField no init é achado');
    mkdir("$tmp/ddl/abc", 0755, true);
    file_put_contents("$tmp/ddl/abc/setup.php", $ini);
    ok(Ddl::noPluginInit("$tmp/ddl/abc", 'abc'), 'noPluginInit(S) lê o setup.php do staging');
}

// ======================================================================== Subprocesso de verdade (proc_open) contra um bin/console falso
if (grupo('subprocesso')) {
    $raiz = "$tmp/sub";
    mkdir("$raiz/bin", 0755, true);
    mkdir("$raiz/saida", 0755, true);
    file_put_contents("$raiz/bin/console", <<<'PHP'
<?php
echo json_encode($argv), "\n";
fwrite(STDERR, "erro-de-teste\n");
$chave = end($argv);
// Como o console real: "glpi:plugin:activate" NÃO tem --username (Symfony: The "--username" option does not exist). Achado ao vivo.
if (($argv[1] ?? '') === 'glpi:plugin:activate') {
    foreach ($argv as $a) {
        if (str_starts_with($a, '--username')) { fwrite(STDERR, "The --username option does not exist.\n"); exit(1); }
    }
}
if ($chave === 'erro') { exit(3); }
if ($chave === 'lento') { sleep(60); }
exit(0);
PHP);
    $sp = new Subprocesso(PHP_BINARY, $raiz);
    $arm = new ArmazemMemoria();
    $arm->idBase = 900000;
    $id = $arm->criarOperacao(['chave' => 'ok', 'tipo' => 'INSTALAR']);
    $arm->transitar($id, 'PEDIDA', 'MIGRANDO');
    $r = $sp->executar('install', 'ok', '-evil user', "$raiz/saida", 20, $arm, $id);
    eq([$r['exit'], $r['timeout']], [0, false], 'filho que sai 0: exit 0, sem timeout');
    $argvSaida = json_decode((string) strtok((string) file_get_contents("$raiz/saida/install.out"), "\n"), true);
    eq(array_slice($argvSaida, 1), ['glpi:plugin:install', '--no-interaction', '--username=-evil user', 'ok'], 'argv em array: --username=<login> num ÚNICO elemento (login com "-" e espaço não vira opção)');
    ok(str_contains((string) file_get_contents("$raiz/saida/install.err"), 'erro-de-teste'), 'stderr vai para install.err');
    eq($arm->operacao($id)['filho_pid'], $r['pid'], 'o pid do filho é gravado em operacoes.filho_pid');
    $ra = $sp->executar('activate', 'ok', 'hugo', "$raiz/saida", 20, $arm, $id);
    eq($ra['exit'], 0, 'activate: o console real não aceita --username, então ele não vai no argv');
    $argvAct = json_decode((string) strtok((string) file_get_contents("$raiz/saida/activate.out"), "\n"), true);
    eq(array_slice($argvAct, 1), ['glpi:plugin:activate', '--no-interaction', 'ok'], 'activate: argv sem --username');
    $r = $sp->executar('install', 'erro', 'x', "$raiz/saida", 20, $arm, $id);
    eq([$r['exit'], $r['timeout']], [3, false], 'filho que sai 3: exit 3 (a primeira leitura com running=false)');
    $t0 = time();
    $r = $sp->executar('install', 'lento', 'x', "$raiz/saida", 2, $arm, $id);
    eq([$r['exit'], $r['timeout']], [null, true], 'timeout: exit nulo e timeout verdadeiro');
    ok(time() - $t0 < 15 && !is_dir('/proc/' . $r['pid']), 'timeout: o filho foi morto (TERM/KILL), sem processo órfão');
    eq(motivo(fn() => $sp->executar('uninstall', 'ok', 'x', "$raiz/saida", 5, $arm, $id)), 'comando', 'só install e activate');
    eq(motivo(fn() => $sp->executar('install', '../x', 'x', "$raiz/saida", 5, $arm, $id)), 'chave', 'chave revalidada pela regex antes de virar argumento');
    eq([$sp->filhoVivo(getmypid(), 'ok')], [false], 'filhoVivo: o pid do próprio teste não é glpi:plugin:install');
}

// ======================================================================== aprovação (a parte pura; senha/TOTP atrás do Reautenticador)
final class ReautFalso implements GlpiPlugin\Instalador\Reautenticador
{
    public int $chamadas = 0;
    public ?string $resposta = null;
    public array $vistos = [];

    public function verificar(int $uid, string $senha, string $totp): ?string
    {
        ++$this->chamadas;
        $this->vistos[] = [$uid, $senha, $totp];

        return $this->resposta;
    }
}

if (grupo('aprovacao')) {
    // Achado na prova ao vivo (GLPI 11.0.7): sem checkMFARateLimit o core lança Error; temLimite() decide ANTES, de forma explícita.
    $semLimite = new class {
        public function is2FAEnabled(int $u): bool { return true; }
    };
    $comLimite = new class {
        public function checkMFARateLimit(int $u): void {}
        public function clearMFAFailures(int $u): void {}
    };
    $R = GlpiPlugin\Instalador\ReautenticadorGlpi::class;
    ok(!$R::temLimite($semLimite), 'temLimite: TOTPManager sem checkMFARateLimit (GLPI < 11.0.8) não tem limite');
    ok($R::temLimite($comLimite), 'temLimite: com checkMFARateLimit e clearMFAFailures tem limite');
    ok(!$R::temLimite(new class { public function checkMFARateLimit(int $u): void {} }), 'temLimite: só um dos dois métodos não basta');
    $mk = static function (bool $abaixo = false): array {
        $arm = new ArmazemMemoria();
        $id = $arm->criarOperacao(['chave' => 'x', 'tipo' => 'INSTALAR']);
        $arm->transitar($id, 'PEDIDA', 'PLANO_PRONTO', ['plano' => json_encode(['abaixo_da_mais_nova' => $abaixo]), 'plano_sha256' => str_repeat('a', 64)]);

        return [$arm, $id, new ReautFalso()];
    };
    $A = GlpiPlugin\Instalador\Aprovacao::class;
    $sha = str_repeat('a', 64);

    [$arm, $id, $re] = $mk();
    $r = $A::aprovar($arm, $re, $id, 5, false, $sha, true, false, 'segredo', '123456');
    eq([$r['ok'], $arm->operacao($id)['estado'], $arm->operacao($id)['aprovado_por'], $arm->operacao($id)['backup_confirmado_por']], [true, 'APROVADA', 5, 5], 'aprovar grava o aprovador e quem confirmou o backup (ids, só no GLPI)');
    ok(!str_contains(json_encode($arm->journal), 'segredo') && !str_contains(json_encode($arm->operacoes), 'segredo') && !str_contains(json_encode($arm->journal), '123456'), 'a senha e o TOTP não aparecem no journal nem na operação');

    [$arm, $id, $re] = $mk();
    $r = $A::aprovar($arm, $re, $id, 5, true, $sha, true, false, 's', '123456');
    ok(!$r['ok'] && $re->chamadas === 0 && $arm->operacao($id)['estado'] === 'PLANO_PRONTO', 'personificação: recusa antes de qualquer reautenticação');
    [$arm, $id, $re] = $mk();
    $re->resposta = 'senha ou código inválidos';
    $r = $A::aprovar($arm, $re, $id, 5, false, $sha, true, false, 's', '123456');
    ok(!$r['ok'] && $arm->operacao($id)['estado'] === 'PLANO_PRONTO', 'senha/TOTP errados: continua PLANO_PRONTO');
    [$arm, $id, $re] = $mk();
    $r = $A::aprovar($arm, $re, $id, 5, false, str_repeat('b', 64), true, false, 's', '123456');
    ok(!$r['ok'] && $arm->operacao($id)['estado'] === 'PLANO_PRONTO', 'plano_sha256 do formulário diferente do banco: recusa');
    [$arm, $id, $re] = $mk();
    ok(!$A::aprovar($arm, $re, $id, 5, false, $sha, false, false, 's', '123456')['ok'], 'sem o checkbox do backup: recusa');
    [$arm, $id, $re] = $mk(true);
    ok(!$A::aprovar($arm, $re, $id, 5, false, $sha, true, false, 's', '123456')['ok'], 'versão abaixo da mais nova sem o 2º checkbox: recusa');
    $r = $A::aprovar($arm, $re, $id, 5, false, $sha, true, true, 's', '123456');
    eq([$r['ok'], (int) $arm->operacao($id)['abaixo_da_mais_nova_confirmado']], [true, 1], 'com o 2º checkbox: aprova e grava abaixo_da_mais_nova_confirmado');
    [$arm, $id, $re] = $mk();
    $arm->operacoes[$id]['date_mod'] = date('Y-m-d H:i:s', time() - 90000);
    $r = $A::aprovar($arm, $re, $id, 5, false, $sha, true, false, 's', '123456');
    eq([$r['ok'], $arm->operacao($id)['estado'], $arm->operacao($id)['motivo']], [false, 'FALHA', 'expirado'], 'PLANO_PRONTO com mais de 24 h: FALHA expirado');
    [$arm, $id, $re] = $mk();
    $arm->transitar($id, 'PLANO_PRONTO', 'FALHA');
    ok(!$A::aprovar($arm, $re, $id, 5, false, $sha, true, false, 's', '123456')['ok'], 'só se aprova operação em PLANO_PRONTO (transição condicional)');
}
