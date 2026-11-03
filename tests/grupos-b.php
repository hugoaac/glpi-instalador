<?php

/**
 * Grupos da revisão do Sable (fatia b): B-01..B-11 e o B-02 completo. Incluído por run.php (compartilha
 * as funções ok/eq/motivo/embalar/cenario/pedir e as variáveis $tmp, $chaveTeste, $fx).
 */

declare(strict_types=1);

use GlpiPlugin\Instalador\Arvore;
use GlpiPlugin\Instalador\ArmazemMemoria;
use GlpiPlugin\Instalador\Assinatura;
use GlpiPlugin\Instalador\Caminhos;
use GlpiPlugin\Instalador\Catalogo;
use GlpiPlugin\Instalador\Contexto;
use GlpiPlugin\Instalador\Cron;
use GlpiPlugin\Instalador\Espelho;
use GlpiPlugin\Instalador\Extrator;
use GlpiPlugin\Instalador\PainelApi;
use GlpiPlugin\Instalador\Preparador;
use GlpiPlugin\Instalador\R1;
use GlpiPlugin\Instalador\Recebimento;
use GlpiPlugin\Instalador\Recusa;
use GlpiPlugin\Instalador\Retomada;
use GlpiPlugin\Instalador\Texto;
use GlpiPlugin\Instalador\ZipInspetor;

/** Zip mínimo no perfil 2.6 (método 0, FAT, sem extra, sem comentário), com raiz única. */
function zipSimples(array $arquivos, string $raiz = 'teste'): string
{
    $out = '';
    $cd  = '';
    $n   = 0;
    foreach ($arquivos as $rel => $dados) {
        $nome = $raiz . '/' . $rel;
        $off  = strlen($out);
        $crc  = crc32($dados);
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, strlen($dados), strlen($dados), strlen($nome), 0) . $nome . $dados;
        $cd  .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0x21, $crc, strlen($dados), strlen($dados), strlen($nome), 0, 0, 0, 0, 0, $off) . $nome;
        $n++;
    }

    return $out . $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $n, $n, strlen($cd), strlen($out), 0);
}

/** Pacote 'teste' de uma versão, com conteúdo próprio, assinado pela chave de teste. @return array{0:string,1:string,2:string,3:array} */
function pacoteTeste(string $dir, string $versao, string $conteudo): array
{
    $zip = "$dir/teste-$versao.zip";
    file_put_contents($zip, zipSimples(['setup.php' => '<?php // ' . $conteudo, 'LEIA.md' => $conteudo]));
    $arv = ZipInspetor::arvoreDoZip($zip, 'teste')['arvore'];
    [$mb, $sb, $m] = embalar($zip, ['versao' => $versao, 'arvore_sha256' => $arv]);

    return [$zip, $mb, $sb, $m];
}

/** Simula "instalado pelo instalador": o pacote assinado está guardado e a pasta marketplace/teste é a dele. */
function instaladoTeste(ArmazemMemoria $arm, Contexto $ctx, Caminhos $cam, string $dir, string $versao, string $conteudo): array
{
    [$zip, $mb, $sb, $m] = pacoteTeste($dir, $versao, $conteudo);
    $pid  = $arm->inserirPacote(['chave' => 'teste', 'versao' => $versao, 'zip_sha256' => $m['zip_sha256'], 'manifesto' => base64_encode($mb), 'sig' => base64_encode($sb), 'key_id' => 'x', 'origem' => 'upload']);
    $info = ZipInspetor::inspecionar($zip, 'teste');
    Extrator::apagar($ctx->marketplace . '/teste');
    Extrator::extrair($zip, $info, $ctx->marketplace . '/teste');

    return [$zip, $mb, $sb, $m, $pid];
}

/** Pede e prepara; devolve [resultado do preparar, id da operação]. O staging de um PLANO_PRONTO é removido e a operação encerrada. */
function preparaTeste(ArmazemMemoria $arm, Contexto $ctx, Caminhos $cam, Assinatura $ass, string $zip, string $mb, string $sb, array $m, ?Espelho $esp = null): array
{
    $op = pedir($arm, $cam, $zip, $mb, $sb, $m);
    $r  = (new Preparador($ctx, $arm, $ass, null, $esp ?? new Espelho(null)))->preparar($op);
    Extrator::apagar($cam->stagingOp($op));
    @rmdir($cam->stagingRaiz());
    if ($r['estado'] === 'PLANO_PRONTO') {
        $arm->transitar($op, 'PLANO_PRONTO', 'CONCLUIDA'); // só para o teste poder pedir outra
    }

    return [$r, $op];
}

/** Varredura de código por token (B-04): devolve a lista de achados. @return list<string> */
function varrer(string $codigo, string $arquivo = 'x.php', array $dinamicasOk = []): array
{
    $proibidas = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'proc_terminate', 'call_user_func', 'call_user_func_array',
        'assert', 'create_function', 'putenv', 'dl', 'register_shutdown_function', 'forward_static_call', 'forward_static_call_array'];
    $nomes = [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED];
    $sig = [];
    foreach (token_get_all($codigo) as $tk) {
        if (is_array($tk) && in_array($tk[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $sig[] = is_array($tk) ? [$tk[0], $tk[1]] : [null, $tk];
    }
    $achados = [];
    $permiteRequire = $arquivo === 'setup.php';
    foreach ($sig as $i => [$id, $txt]) {
        $prox = $sig[$i + 1][1] ?? '';
        $ant  = $sig[$i - 1][0] ?? null;
        if ($id === T_EVAL) {
            $achados[] = 'eval';
        }
        $bootstrap = $id === T_INCLUDE && ($sig[$i + 1][1] ?? '') === '(' && ($sig[$i + 2][1] ?? '') === "'../../../inc/includes.php'";
        if (in_array($id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE], true) && !$bootstrap && !($permiteRequire && $id === T_REQUIRE_ONCE)) {
            $achados[] = 'include/require: ' . $txt;
        }
        if ($id === null && $txt === '`') {
            $achados[] = 'crase';
        }
        if (in_array($id, $nomes, true)) {
            $n = strtolower(ltrim($txt, chr(92)));
            $ehChamada = $prox === '(' && !in_array($ant, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true);
            if ($ehChamada && (in_array($n, $proibidas, true) || str_starts_with($n, 'pcntl_'))) {
                $achados[] = 'chamada proibida: ' . $n;
            }
            if (in_array($n, ['ffi', 'reflectionfunction'], true)) {
                $achados[] = 'classe proibida: ' . $n;
            }
        }
        if ($id === T_VARIABLE && $prox === '(' && !in_array($arquivo . ':' . $txt, $dinamicasOk, true)) {
            $achados[] = 'chamada dinâmica: ' . $txt . '()';
        }
        if ($id === null && $txt === ')' && $prox === '(') {
            $achados[] = 'chamada dinâmica: )(';
        }
        if ($id === T_CONSTANT_ENCAPSED_STRING && $prox === '(') {
            $achados[] = 'chamada por string: ' . $txt;
        }
        if ($id === null && $txt === '$' && ($sig[$i + 1][0] ?? null) === T_VARIABLE) {
            $achados[] = 'variável variável';
        }
    }

    return $achados;
}

/** Arquivos do plugin que a fatia (b) varre: src/, front/, hook.php e setup.php (nunca tests/). */
function arquivosDoPlugin(): array
{
    $raiz = realpath(__DIR__ . '/..');
    $out  = [];
    foreach (array_merge(glob("$raiz/src/*.php"), glob("$raiz/front/*.php"), ["$raiz/hook.php", "$raiz/setup.php"]) as $f) {
        $out[basename($f)] = $f;
    }

    return $out;
}

// ======================================================================== r1 (proveniência por fato assinado, B-02a)
if (grupo('r1')) {
    $base = "$tmp/r1";
    mkdir($base, 0700, true);
    [$ctx, $arm, $cam] = cenario($base);
    $ass = new Assinatura($chaveTeste);
    $mp  = $ctx->marketplace;
    eq(R1::avaliar($ctx, $arm, $ass, 'novo')['resultado'], 'OK', 'chave nova: OK');
    mkdir("$base/plugins/x", 0755, true);
    $r = R1::avaliar($ctx, $arm, $ass, 'x');
    eq([$r['resultado'], $r['motivo']], ['GERENCIADO_EM_OUTRO_LUGAR', 'existe_fora_do_marketplace'], 'plugins/x existe -> GERENCIADO_EM_OUTRO_LUGAR');
    foreach (['instalador', 'informante'] as $k) {
        eq(R1::avaliar($ctx, $arm, $ass, $k)['motivo'], 'dependencia_do_instalador', "recusa fixa: $k");
    }
    eq(motivo(fn() => R1::avaliar($ctx, $arm, $ass, '../x')), 'chave', 'chave com ../ é recusada antes de virar caminho');
    mkdir("$mp/comgit/.git", 0755, true);
    eq(R1::avaliar($ctx, $arm, $ass, 'comgit')['motivo'], 'vcs_ou_link_no_marketplace', 'marketplace/<chave>/.git -> GERENCIADO');
    @symlink("$base/plugins/x", "$mp/lnk");
    eq(R1::avaliar($ctx, $arm, $ass, 'lnk')['motivo'], 'link_no_marketplace', 'marketplace/<chave> é link -> GERENCIADO');
    mkdir("$mp/semorigem", 0755, true);
    file_put_contents("$mp/semorigem/setup.php", '<?php');
    eq(R1::avaliar($ctx, $arm, $ass, 'semorigem')['motivo'], 'marketplace_sem_origem', 'marketplace/x sem manifesto assinado correspondente -> marketplace_sem_origem');

    // com proveniência: a pasta tem a arvore de um manifesto assinado guardado
    [$zip, $mb, $sb, $m] = instaladoTeste($arm, $ctx, $cam, $base, '1.2.0', 'instalado');
    $r = R1::avaliar($ctx, $arm, $ass, 'teste');
    eq([$r['resultado'], $r['de'], $r['zip_sha256']], ['OK', '1.2.0', $m['zip_sha256']], 'pasta = arvore de manifesto assinado guardado -> OK, de = versão desse manifesto');
    file_put_contents("$mp/teste/setup.php", '<?php // editado à mão');
    eq(R1::avaliar($ctx, $arm, $ass, 'teste')['motivo'], 'marketplace_sem_origem', 'arvore diferente (edição manual) -> recusa');

    // B-02: escrita no banco não basta
    [, , , $m2] = instaladoTeste($arm, $ctx, $cam, $base, '1.2.0', 'instalado');
    file_put_contents("$mp/teste/setup.php", '<?php // trocado por quem escreve só no banco');
    $arvForjada = Arvore::pasta("$mp/teste");
    $arm->operacoes[50] = ['id' => 50, 'chave' => 'teste', 'resultado' => 'SUCESSO', 'arvore_final' => $arvForjada, 'estado' => 'CONCLUIDA', 'pacotes_id' => 0];
    eq(R1::avaliar($ctx, $arm, $ass, 'teste')['motivo'], 'marketplace_sem_origem', 'B-02: operação SUCESSO FORJADA com a arvore da pasta não dá proveniência');
    // manifesto guardado adulterado no banco (assinatura não confere) também não vale
    $arm->pacotes = [];
    $pf = $m2;
    $pf['arvore_sha256'] = $arvForjada;
    $mf = \GlpiPlugin\Instalador\Manifesto::serializar($pf);
    $arm->inserirPacote(['chave' => 'teste', 'versao' => '1.2.0', 'zip_sha256' => $m2['zip_sha256'], 'manifesto' => base64_encode($mf), 'sig' => base64_encode($sb), 'key_id' => 'x', 'origem' => 'upload']);
    eq(R1::avaliar($ctx, $arm, $ass, 'teste')['motivo'], 'marketplace_sem_origem', 'B-02: manifesto reescrito no banco é reverificado e recusado');
    // A linha do banco tem de ser a do manifesto assinado que ela guarda: chave, versão ou zip reescritos não valem.
    $linha = ['chave' => 'teste', 'versao' => '1.2.0', 'zip_sha256' => $m['zip_sha256'], 'manifesto' => base64_encode($mb), 'sig' => base64_encode($sb)];
    eq(\GlpiPlugin\Instalador\Fatos::manifesto($ass, $linha)['versao'] ?? null, '1.2.0', 'linha íntegra: o manifesto reverificado vale');
    foreach (['chave' => 'outro', 'versao' => '9.9.9', 'zip_sha256' => str_repeat('f', 64)] as $campo => $valor) {
        eq(\GlpiPlugin\Instalador\Fatos::manifesto($ass, [$campo => $valor] + $linha), null, "B-02: $campo reescrito na linha do banco não bate com o manifesto assinado");
    }
    $arm2 = new ArmazemMemoria();
    $arm2->plugins['orfao'] = ['version' => '1.0.0', 'state' => 1];
    eq(R1::avaliar($ctx, $arm2, $ass, 'orfao')['motivo'], 'registro_sem_pasta', 'linha em glpi_plugins sem pasta -> recusa');
}

// ======================================================================== versao (fato assinado, B-02)
if (grupo('versao')) {
    eq(\GlpiPlugin\Instalador\Manifesto::compararVersao('1.10.0', '1.9.9'), 1, 'semver numérico: 1.10.0 > 1.9.9');
    eq(\GlpiPlugin\Instalador\Manifesto::compararVersao('0.9.9', '1.0.0'), -1, 'menor');
    eq(\GlpiPlugin\Instalador\Plano::dentroDaFaixa('11.0.8', '11.0.0', null), true, 'glpi_max nulo: mesmo major do min');
    eq(\GlpiPlugin\Instalador\Plano::dentroDaFaixa('12.0.0', '11.0.0', null), false, 'glpi_max nulo: major seguinte fora');
    eq(\GlpiPlugin\Instalador\Plano::dentroDaFaixa('11.0.8', '11.0.0', '11.0.8'), false, 'glpi_max é exclusivo (Plugin.php:2361)');

    $base = "$tmp/versao";
    mkdir($base, 0700, true);
    [$ctx, $arm, $cam] = cenario($base);
    $ass = new Assinatura($chaveTeste);

    // instalado 1.2.0 (fato assinado) e o banco adulterado: versão rebaixada para 0.0.1 e operação falsa
    instaladoTeste($arm, $ctx, $cam, $base, '1.2.0', 'instalado');
    $arm->plugins['teste'] = ['version' => '0.0.1', 'state' => 1];                                  // glpi_plugins.version REBAIXADA
    $arm->operacoes[70] = ['id' => 70, 'chave' => 'teste', 'resultado' => 'SUCESSO', 'arvore_final' => str_repeat('a', 64), 'estado' => 'CONCLUIDA', 'pacotes_id' => 0]; // operação falsa
    $arm->vistas = [];                                                                               // versoes_vistas APAGADA
    [$z, $mb, $sb, $m] = pacoteTeste($base, '1.0.0', 'antigo');
    [$r] = preparaTeste($arm, $ctx, $cam, $ass, $z, $mb, $sb, $m);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'downgrade'], 'B-02: banco adulterado (version rebaixada, SUCESSO falsa, vistas apagada): downgrade continua recusado');

    [$z, $mb, $sb, $m] = pacoteTeste($base, '1.2.0', 'outro conteudo');
    eq(motivo(fn() => preparaTeste($arm, $ctx, $cam, $ass, $z, $mb, $sb, $m)), 'mesma_versao', 'mesma versão com outro hash: o índice (chave, versao) recusa já ao guardar (D2-19)');

    [$z, $mb, $sb, $m] = pacoteTeste($base, '1.3.0', 'novo');
    [$r] = preparaTeste($arm, $ctx, $cam, $ass, $z, $mb, $sb, $m);
    eq([$r['estado'], $r['plano']['tipo'], $r['plano']['de'], $r['plano']['para']], ['PLANO_PRONTO', 'ATUALIZAR', '1.2.0', '1.3.0'], 'versão maior -> ATUALIZAR 1.2.0 -> 1.3.0 (de vem do manifesto assinado, não do glpi_plugins)');

    // JA_NO_ALVO: o próprio pacote instalado
    $arm5 = new ArmazemMemoria();
    [$z, $mb, $sb, $m] = instaladoTeste($arm5, $ctx, $cam, $base, '1.2.0', 'instalado');
    [$r] = preparaTeste($arm5, $ctx, $cam, $ass, $z, $mb, $sb, $m);
    eq([$r['estado'], $r['resultado']], ['CONCLUIDA', 'JA_NO_ALVO'], 'mesma versão e mesmo hash -> JA_NO_ALVO');
    Extrator::apagar($ctx->marketplace . '/teste');

    // versoes_vistas apagada, com manifestos assinados maiores guardados: o aviso continua
    $arm3 = new ArmazemMemoria();
    [$z2, $mb2, $sb2, $m2] = pacoteTeste($base, '2.0.0', 'mais novo');
    $arm3->inserirPacote(['chave' => 'teste', 'versao' => '2.0.0', 'zip_sha256' => $m2['zip_sha256'], 'manifesto' => base64_encode($mb2), 'sig' => base64_encode($sb2), 'key_id' => 'x', 'origem' => 'upload']);
    $arm3->vistas = [];
    [$z, $mb, $sb, $m] = pacoteTeste($base, '1.0.0', 'antigo');
    [$r] = preparaTeste($arm3, $ctx, $cam, $ass, $z, $mb, $sb, $m);
    eq([$r['estado'], $r['plano']['abaixo_da_mais_nova'], $r['plano']['versao_mais_nova_conhecida']], ['PLANO_PRONTO', true, '2.0.0'], 'B-02: versoes_vistas apagada, manifesto assinado 2.0.0 guardado: o aviso "abaixo da mais nova" continua');

    // versoes_vistas apagada e SEM manifesto maior: o espelho em arquivo segura
    $arm4 = new ArmazemMemoria();
    $esp = new Espelho("$base/versoes_vistas.json");
    $esp->registrar('teste', '3.0.0');
    eq($esp->maximo('teste'), '3.0.0', 'espelho em arquivo grava a maior versão');
    $esp->registrar('teste', '1.0.0');
    eq($esp->maximo('teste'), '3.0.0', 'espelho só sobe');
    $arm4->vistas = [];
    [$r] = preparaTeste($arm4, $ctx, $cam, $ass, $z, $mb, $sb, $m, $esp);
    eq([$r['estado'], $r['plano']['abaixo_da_mais_nova'], $r['plano']['versao_mais_nova_conhecida']], ['PLANO_PRONTO', true, '3.0.0'], 'B-02: só o espelho em arquivo conhece a 3.0.0: o aviso continua');
    $arm4b = new ArmazemMemoria();
    $arm4b->vistas = ['teste' => ['versao_max' => '0.5.0', 'zip_sha256' => str_repeat('1', 64)]];  // banco REBAIXADO
    [$r] = preparaTeste($arm4b, $ctx, $cam, $ass, $z, $mb, $sb, $m, $esp);
    eq($r['plano']['versao_mais_nova_conhecida'], '3.0.0', 'B-02: linha de banco rebaixada não vence o espelho');

    // P-2 / registro
    $arm6 = new ArmazemMemoria();
    [$r] = preparaTeste($arm6, $ctx, $cam, $ass, $z, $mb, $sb, $m);
    eq([$arm6->vistas['teste']['versao_max'] ?? null, $r['plano']['abaixo_da_mais_nova']], ['1.0.0', false], 'Preparar registra versoes_vistas');

    // D2-19
    $a7 = new ArmazemMemoria();
    $a7->inserirPacote(['chave' => 'teste', 'versao' => '1.0.0', 'zip_sha256' => str_repeat('a', 64), 'manifesto' => '', 'sig' => '', 'key_id' => 'x', 'origem' => 'upload']);
    eq(motivo(fn() => $a7->inserirPacote(['chave' => 'teste', 'versao' => '1.0.0', 'zip_sha256' => str_repeat('b', 64), 'manifesto' => '', 'sig' => '', 'key_id' => 'x', 'origem' => 'upload'])), 'mesma_versao', '(chave, versao) com 2º hash -> mesma_versao');
    // B-10: o mesmo zip_sha256 em outra (chave, versao) não explode, é recusado
    eq(motivo(fn() => $a7->inserirPacote(['chave' => 'teste', 'versao' => '9.9.9', 'zip_sha256' => str_repeat('a', 64), 'manifesto' => '', 'sig' => '', 'key_id' => 'x', 'origem' => 'upload'])), 'zip_repetido', 'B-10: mesmo zip_sha256 em outra versão -> zip_repetido (sem exceção)');

    // faixa do GLPI
    [$zf, $mbf, $sbf, $mf] = (static function () use ($base): array {
        $zip = "$base/faixa.zip";
        file_put_contents($zip, zipSimples(['setup.php' => '<?php // faixa']));
        $arv = ZipInspetor::arvoreDoZip($zip, 'teste')['arvore'];
        [$mb, $sb, $m] = embalar($zip, ['arvore_sha256' => $arv, 'glpi_min' => '12.0.0']);

        return [$zip, $mb, $sb, $m];
    })();
    [$r] = preparaTeste(new ArmazemMemoria(), $ctx, $cam, $ass, $zf, $mbf, $sbf, $mf);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'glpi_fora_da_faixa'], 'glpi_min acima do GLPI -> FALHA glpi_fora_da_faixa');

    // retirado
    $a8 = new ArmazemMemoria();
    $op = pedir($a8, $cam, $z, $mb, $sb, $m);
    $a8->marcarRetirados([$m['zip_sha256']]);
    $r = (new Preparador($ctx, $a8, $ass))->preparar($op);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'retirado'], 'pacote retirado -> FALHA retirado');
    eq((new Preparador($ctx, $a8, $ass))->preparar($op)['estado'], 'IGNORADA', 'Preparar de operação já tratada é ignorado (transição condicional)');

    // CLI x FPM
    $ctxFpm = new Contexto($ctx->marketplace, $ctx->pluginsDirs, ['/outro'], '11.0.8', 1, true, $ctx->docDir);
    [$r] = preparaTeste(new ArmazemMemoria(), $ctxFpm, $cam, $ass, $z, $mb, $sb, $m);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'diretorios_cli_fpm'], 'CLI e FPM com diretórios diferentes -> FALHA diretorios_cli_fpm');
    $ctxNulo = new Contexto($ctx->marketplace, $ctx->pluginsDirs, null, '11.0.8', 1, true, $ctx->docDir);
    [$r] = preparaTeste(new ArmazemMemoria(), $ctxNulo, $cam, $ass, $z, $mb, $sb, $m);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'diretorios_cli_fpm_desconhecido'], 'B-09: FPM nunca gravou a sua visão -> falha fechado');
}

// ======================================================================== cache de zip (B-01)
if (grupo('cache-zip')) {
    $base = "$tmp/cache";
    mkdir($base, 0700, true);
    $ass = new Assinatura($chaveTeste);
    $zipBom = "$fx/arvore-m1.zip";
    [$mb, $sb, $m] = embalar($zipBom, ['arvore_sha256' => 'e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045']);
    $bom = file_get_contents($zipBom);
    $painel = new class ($bom) implements PainelApi {
        public array $respostas = [];
        public int $chamadas = 0;
        public function __construct(private string $bom) {}
        public function pedirCatalogo(): array { return ['status' => 500]; }
        public function pedirPacote(string $zipSha256, int $maxBytes, string $destinoTmp): array
        {
            $modo = $this->respostas[$this->chamadas++] ?? 'bom';
            file_put_contents($destinoTmp, $modo === 'truncado' ? substr($this->bom, 0, 100) : $this->bom);

            return ['status' => 200, 'corpo' => null, 'erro' => null, 'bytes' => 1];
        }
    };

    // download truncado: nada fica no nome definitivo; a rodada seguinte baixa de novo e chega a PLANO_PRONTO
    [$ctx, $arm, $cam] = cenario($base);
    $painel->respostas = ['truncado', 'bom'];
    $op1 = pedir($arm, $cam, $zipBom, $mb, $sb, $m, 'painel', false);
    $r = (new Preparador($ctx, $arm, $ass, $painel))->preparar($op1);
    eq([$r['estado'], $r['motivo']], ['FALHA', 'zip_bytes'], 'B-01: download truncado -> FALHA zip_bytes');
    ok(!file_exists($cam->zip($m['zip_sha256'])), 'B-01: o truncado NÃO foi para o nome definitivo (conferido no .tmp antes do rename)');
    eq(glob($cam->pastaPacotes() . '/*.tmp*'), [], 'B-01: nenhum .tmp sobrou');
    $op2 = $arm->criarOperacao(['chave' => 'teste', 'pacotes_id' => 1, 'tipo' => 'INSTALAR']);
    $r = (new Preparador($ctx, $arm, $ass, $painel))->preparar($op2);
    eq($r['estado'], 'PLANO_PRONTO', 'B-01: 2ª rodada baixa de novo e chega a PLANO_PRONTO (' . ($r['motivo'] ?? '') . ')');
    eq($painel->chamadas, 2, 'B-01: dois downloads no total');

    // zip envenenado no nome definitivo (origem painel): apagado e baixado UMA vez
    $base2 = "$tmp/cache2";
    mkdir($base2, 0700, true);
    [$ctx, $arm, $cam] = cenario($base2);
    @mkdir($cam->pastaPacotes(), 0700, true);
    file_put_contents($cam->zip($m['zip_sha256']), 'lixo');
    $painel->chamadas = 0;
    $painel->respostas = ['bom'];
    $op = pedir($arm, $cam, $zipBom, $mb, $sb, $m, 'painel', false);
    $r = (new Preparador($ctx, $arm, $ass, $painel))->preparar($op);
    eq([$r['estado'], $painel->chamadas], ['PLANO_PRONTO', 1], 'B-01: cache envenenado é apagado e baixado de novo UMA vez -> PLANO_PRONTO');
    // e se o novo download também vier errado: falha, sem laço
    $base3 = "$tmp/cache3";
    mkdir($base3, 0700, true);
    [$ctx, $arm, $cam] = cenario($base3);
    @mkdir($cam->pastaPacotes(), 0700, true);
    file_put_contents($cam->zip($m['zip_sha256']), 'lixo');
    $painel->chamadas = 0;
    $painel->respostas = ['truncado', 'truncado', 'truncado'];
    $op = pedir($arm, $cam, $zipBom, $mb, $sb, $m, 'painel', false);
    $r = (new Preparador($ctx, $arm, $ass, $painel))->preparar($op);
    eq([$r['estado'], $painel->chamadas], ['FALHA', 1], 'B-01: segundo download ruim também falha, e só tenta uma vez');

    // upload offline sobre arquivo envenenado: o destino é substituído
    $base4 = "$tmp/cache4";
    mkdir($base4, 0700, true);
    [$ctx, $arm, $cam] = cenario($base4);
    @mkdir($cam->pastaPacotes(), 0700, true);
    file_put_contents($cam->zip($m['zip_sha256']), 'lixo envenenado');
    copy($zipBom, "$base4/up.zip");
    $rec = Recebimento::receber($arm, $ass, $cam, "$base4/up.zip", $mb, $sb);
    eq(hash_file('sha256', $cam->zip($m['zip_sha256'])), $m['zip_sha256'], 'B-01: upload substitui o destino envenenado');
    eq((int) $arm->pacotePorId($rec['id'])['arquivo_ok'], 1, 'B-01: arquivo_ok só depois de conferir o arquivo final');
    $op = $arm->criarOperacao(['chave' => 'teste', 'pacotes_id' => $rec['id'], 'tipo' => 'INSTALAR']);
    $r = (new Preparador($ctx, $arm, $ass))->preparar($op);
    eq($r['estado'], 'PLANO_PRONTO', 'B-01: upload sobre envenenado chega a PLANO_PRONTO');
}

// ======================================================================== operações (B-07, B-08, B-09)
if (grupo('operacoes')) {
    $base = "$tmp/ops";
    mkdir($base, 0700, true);
    [$ctx, $arm, $cam] = cenario($base);
    $ass = new Assinatura($chaveTeste);
    $zip = "$fx/arvore-m1.zip";
    [$mb, $sb, $m] = embalar($zip, ['arvore_sha256' => 'e67b4d9203a36b1aecedae3b4eb7aa4ea3f459454aa7764dd8e3c0bd8cafa045']);
    // unicidade atômica de operação aberta por chave
    $op = pedir($arm, $cam, $zip, $mb, $sb, $m);
    eq(motivo(fn() => $arm->criarOperacao(['chave' => 'teste', 'pacotes_id' => 1, 'tipo' => 'INSTALAR'])), 'operacao_aberta', 'B-07: segunda operação aberta para a mesma chave é recusada pelo armazém');
    // PREPARANDO esquecida: retomada
    $arm->transitar($op, 'PEDIDA', 'PREPARANDO');
    mkdir($cam->S('teste', $op), 0755, true);
    file_put_contents($cam->S('teste', $op) . '/x', 'x');
    eq(Retomada::reconciliar($arm, $cam), 1, 'B-07: PREPARANDO esquecida é reconciliada');
    eq([$arm->operacao($op)['estado'], $arm->operacao($op)['motivo']], ['FALHA', 'interrompida'], 'B-07: vira FALHA interrompida');
    ok(!file_exists($cam->stagingOp($op)) && !file_exists($cam->stagingRaiz()), 'B-07: o staging dela foi limpo');
    ok($arm->criarOperacao(['chave' => 'teste', 'pacotes_id' => 1, 'tipo' => 'INSTALAR']) > 0, 'B-07: a chave foi liberada');

    // B-08: transição PREPARANDO -> PLANO_PRONTO falha: o journal não afirma PLANO_PRONTO
    $conc = new class extends ArmazemMemoria {
        public function transitar(int $id, string $de, string $para, array $campos = []): bool
        {
            return $para === 'PLANO_PRONTO' ? false : parent::transitar($id, $de, $para, $campos);
        }
    };
    $op = pedir($conc, $cam, $zip, $mb, $sb, $m);
    $r = (new Preparador($ctx, $conc, $ass))->preparar($op);
    eq($r['estado'], 'IGNORADA', 'B-08: transição concorrente -> IGNORADA');
    $afirmou = array_filter($conc->journal, static fn($j) => ($j['dados']['estado'] ?? '') === 'PLANO_PRONTO');
    eq(count($afirmou), 0, 'B-08: o journal NÃO registra PLANO_PRONTO');
    ok(count(array_filter($conc->journal, static fn($j) => ($j['dados']['motivo'] ?? '') === 'transicao_concorrente' && $j['fase'] === 'ERRO')) === 1, 'B-08: registra ERRO transicao_concorrente');
    ok(!file_exists($cam->stagingOp($op)), 'B-08: staging limpo');
}

// ======================================================================== cron (B-03, B-06)
if (grupo('cron')) {
    ok(Cron::euidOk(33, 33), 'B-06: euid = dono do marketplace, não root -> ok');
    ok(!Cron::euidOk(0, 0), 'B-06: root é recusado mesmo sendo dono');
    ok(!Cron::euidOk(0, 33), 'B-06: root com marketplace de outro dono -> recusado');
    ok(!Cron::euidOk(1000, 33), 'B-06: euid diferente do dono -> recusado');
    ok(!Cron::euidOk(-1, 33), 'B-06: sem posix_geteuid (-1) -> recusado');
    ok(str_contains(Cron::LOCK_NOME_SQL, 'glpi_instalador_exec:') && str_contains(Cron::LOCK_NOME_SQL, 'SHA1(DATABASE())'), 'B-03: o nome do GET_LOCK inclui o hash do banco');
    $cron = (string) file_get_contents(__DIR__ . '/../src/Cron.php');
    ok(substr_count($cron, 'LOCK_NOME_SQL') >= 3, 'B-03: GET_LOCK e RELEASE_LOCK usam a mesma expressão');
    ok(!str_contains($cron, "fileowner(\$lockPath)"), 'B-06: não confere mais o dono do arquivo de lock');
}

// ======================================================================== texto escapado (B-05)
if (grupo('texto')) {
    $c = ['ok' => false, 'erro' => 'cURL error 60: subject <script>alert(1)</script> "x"', 'novos' => 0, 'conhecidos' => 0, 'rejeitados' => [], 'retirados' => 0];
    $s = Texto::catalogo($c);
    ok(!str_contains($s, '<script>') && str_contains($s, '&lt;script&gt;'), 'B-05: erro do catálogo com <script> sai escapado');
    $s = Texto::recusa(new Recusa('x', '<img src=x onerror=alert(1)>'));
    ok(!str_contains($s, '<img') && str_contains($s, '&lt;img'), 'B-05: detalhe da recusa sai escapado');
    ok(!str_contains(Texto::esc("a'b\"c"), "'") && !str_contains(Texto::esc('"'), '"'), 'B-05: aspas escapadas');
    $front = (string) file_get_contents(__DIR__ . '/../front/pacote.form.php');
    ok(!str_contains($front, "\$c['erro']"), 'B-05: o front não concatena erro cru em mensagem');
}

// ======================================================================== caminhos (B-11)
if (grupo('caminhos')) {
    $base = "$tmp/cam";
    mkdir("$base/mp", 0755, true);
    mkdir("$base/doc", 0700, true);
    mkdir("$base/fora", 0755, true);
    $cam = new Caminhos(realpath("$base/mp"), realpath("$base/doc"));
    symlink("$base/fora", "$base/mp/teste");
    eq(motivo(fn() => $cam->M('teste')), 'caminho_fora_da_base', 'B-11: M como link -> caminho_fora_da_base');
    symlink("$base/fora", "$base/mp/.hub-staging");
    eq(motivo(fn() => $cam->stagingRaiz()), 'caminho_fora_da_base', 'B-11: stagingRaiz como link (folha) -> recusa');
    eq(motivo(fn() => $cam->stagingOp(3)), 'caminho_fora_da_base', 'B-11: stagingOp passa pela conferência');
    unlink("$base/mp/.hub-staging");
    symlink("$base/fora", "$base/doc/pacotes");
    eq(motivo(fn() => $cam->pastaPacotes()), 'caminho_fora_da_base', 'B-11: pastaPacotes como link -> recusa');
    eq(motivo(fn() => $cam->zip(str_repeat('a', 64))), 'caminho_fora_da_base', 'B-11: zip() sob pacotes/ link -> recusa');
    unlink("$base/mp/teste");
    unlink("$base/doc/pacotes");
    ok(str_ends_with($cam->M('teste'), '/teste') && str_ends_with($cam->pastaPacotes(), '/pacotes'), 'sem link, os caminhos saem normais');
}

// ======================================================================== catálogo sem exceção (B-10)
if (grupo('catalogo-b10')) {
    $zip = "$fx/arvore-m1.zip";
    [$mb, $sb] = embalar($zip, ['versao' => '1.0.0']);
    [$mb2, $sb2] = embalar($zip, ['versao' => '1.1.0']); // MESMO zip_sha256 em outra versão
    $painel = new class implements PainelApi {
        public array $resp = [];
        public function pedirCatalogo(): array { return $this->resp; }
        public function pedirPacote(string $zipSha256, int $maxBytes, string $destinoTmp): array { return ['status' => 404]; }
    };
    $item = static fn(string $a, string $b): array => ['manifesto_b64' => base64_encode($a), 'sig_b64' => base64_encode($b)];
    $painel->resp = ['status' => 200, 'corpo' => json_encode(['formato' => 'painel-catalogo-v1', 'pacotes' => [$item($mb, $sb), $item($mb2, $sb2)], 'retirados' => []]), 'erro' => null];
    $arm = new ArmazemMemoria();
    $r = Catalogo::atualizar($painel, $arm, new Assinatura($chaveTeste));
    eq([$r['ok'], $r['novos'], count($r['rejeitados'])], [true, 1, 1], 'B-10: zip_repetido no catálogo é rejeitado sem derrubar a rodada');
}

// ======================================================================== varredura por token (B-04)
if (grupo('varredura')) {
    $ruins = [
        'include sem espaço' => '<?php include("x.php");',
        'require_once' => '<?php require_once "x.php";',
        'crase' => '<?php $a = `id`;',
        'pcntl_exec' => '<?php pcntl_exec("/bin/sh");',
        'call_user_func com string' => '<?php call_user_func("sys" . "tem", "id");',
        'chamada dinâmica $f()' => '<?php $f = "system"; $f("id");',
        'chamada ")("' => '<?php ("sys" . "tem")("id");',
        'string chamada' => '<?php "system"("id");',
        'system escondido atrás de // dentro de string' => '<?php $u = "a//"; system("id");',
        'qualificado com barra' => '<?php \\system("id");',
        'ReflectionFunction' => '<?php new ReflectionFunction("system");',
        'FFI' => '<?php FFI::cdef("");',
        'eval' => '<?php eval("1;");',
        'proc_open' => '<?php proc_open("ls", [], $p);',
        'variável variável' => '<?php $$a = 1;',
    ];
    foreach ($ruins as $nome => $cod) {
        ok(varrer($cod) !== [], "B-04: a varredura pega: $nome");
    }
    ok(varrer('<?php class A { function system() {} function f() { return $this->exec(1) . self::call_user_func(2); } }') === [], 'B-04: método chamado system/exec numa classe não é falso positivo');
    ok(varrer('<?php require_once $f;', 'setup.php') === [], 'B-04: o autoloader do setup.php é o único require permitido');
    ok(varrer('<?php require_once $f;', 'outro.php') !== [], 'B-04: require_once fora do setup.php é achado');

    $dinamicasOk = ['Manifesto.php:$str', 'ZipInspetor.php:$aoLer', 'R1.php:$nao', 'Fatos.php:$oferta'];
    $todos = [];
    foreach (arquivosDoPlugin() as $nome => $f) {
        foreach (varrer((string) file_get_contents($f), $nome, $dinamicasOk) as $a) {
            $todos[] = "$nome: $a";
        }
    }
    eq($todos, [], 'fatia (b): src/, front/, hook.php e setup.php sem execução de código (varredura por token)');
}
