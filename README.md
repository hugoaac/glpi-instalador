# instalador

Plugin do GLPI 11 que **verifica pacotes de plugin assinados e mostra o plano de instalação**. Alvo: GLPI 11.0.x, PHP 8.2+.

> **Versão 0.2.0 = fatia (c) do M2: passos 1 a 5 de verdade** (aprovação com senha + TOTP, backup com dump, troca de pastas, migração, ativação), até `AGUARDANDO_CONCLUSAO`. Concluir, rollback e restauração são da fatia (d).

Desenho: `docs/desenho-m2.md` do repositório `painel-adm` (§5). O painel (`adm.hubedesk.com.br`) é um **espelho não confiável**: quem decide é este plugin, com as chaves de publicador **fixadas no código** (`src/Publicadores.php`). A aprovação é humana e acontece no GLPI, nunca no painel.

## Chaves de publicador: leia antes de entregar a qualquer cliente

`src/Publicadores.php` tem hoje **uma** chave, a principal (`key_id 38f6f9ceaa9c094c`). A **reserva** ainda será gerada pelo Hugo num Linux sem rede (desenho §4.4, D2-10) e o lugar dela está marcado no arquivo.

**Nenhum GLPI de cliente recebe o instalador sem as duas chaves.** A tela mostra a falta como requisito não atendido, e `Publicadores::prontoParaCliente()` só vira verdadeiro com duas.

Nenhuma configuração, variável de ambiente ou tela acrescenta chave. Trocar uma chave é mudança de código, com commit, e vale para o instalador novo; o que já está implantado só muda por troca manual (D2-09).

## Requisitos

- Plugin **informante >= 1.1.0** instalado e ativo (traz o transporte e a identidade do ambiente: `Api::pedirCatalogo()` e `Api::pedirPacote()`).
- Extensões `sodium`, `zip` e `zlib`.
- `GLPI_MARKETPLACE_DIR` gravável pelo mesmo usuário que roda o cron.
- `GLPI_MARKETPLACE_ALLOW_OVERRIDE=0` (a string `"false"` vira verdadeira numa variável de ambiente; C1 do desenho).
- O **instalador e o informante nunca são alvo**: a troca deles é manual (D2-09).

## O que esta versão faz

1. **Catálogo online** (a cada 1 h, na CronTask `InstaladorExecutor`, ou pelo botão). Cada item só vale depois que a **assinatura de cada manifesto** confere com as chaves fixadas. `retirados` só restringe, e a marca de retirado **só vai num sentido**: um catálogo posterior sem o hash não a desfaz (P-3).
2. **Upload offline** dos três arquivos do release (`.zip`, `.manifesto.json`, `.sig`).
3. **Preparar**: reverifica a assinatura a partir dos bytes guardados, confere tamanho e sha256 do zip, aplica o **perfil estrito de zip** (parser cru antes de qualquer `ZipArchive`), compara a `arvore_sha256` sem extrair, aplica a regra **R1 com proveniência**, as recusas de versão (downgrade, mesma versão com outro hash, "abaixo da mais nova já vista"), a faixa do GLPI e a igualdade CLI x FPM, e só então extrai em `marketplace/.hub-staging/op<id>/<chave>`.
4. **Plano em modo simulação**: tipo, de -> para, migração declarada, R1, faixa do GLPI, diretórios, `cron_limit`, o que o backup cobriria (e que ele é **sensível**), disco, `plano_sha256`.

Tabelas: `glpi_plugin_instalador_{pacotes,versoes_vistas,operacoes,journal}`. Índice único `(chave, versao)` (D2-19). Direitos: `plugin_instalador` (ver / preparar / enviar offline) e `plugin_instalador_aprovar` (fatia c). **Nenhum é concedido no install.**

## Texto obrigatório (desenho §5.12)

- **"Migração" inclui DDL em `plugin_init`** (D2-22). Um plugin que faz `ALTER TABLE` dentro de `plugin_init` executa isso no filho do `activate` ou na primeira requisição do FPM, possivelmente em vários workers ao mesmo tempo. Uma falha ali não vira `INCERTO`. O `release` avisa e exige `migracao` diferente de `nenhuma`.
- **O backup local é sensível** (pode conter dado pessoal do órgão) e tem **retenção de 30 dias**, exceto o da última operação de cada chave e o de alvo de rollback possível (D2-07; fatia c).
- **`cron_limit = 1` durante a janela de mudança** (D2-21). Com `cron_limit > 1`, outro processo pode rodar com código misto durante a troca. O plano mostra o valor e avisa.
- **A troca do `instalador` e do `informante` é manual** (D2-09): a chave deles é recusada em todo ponto (manifesto, R1, caminhos).
- **Adoção de plugin do marketplace sem proveniência = D13**: pasta em `marketplace/<chave>` que o instalador não pôs lá (ou que foi editada depois) é `GERENCIADO_EM_OUTRO_LUGAR / marketplace_sem_origem`. Adotar é passo humano.

## Cron

`InstaladorExecutor` é `MODE_EXTERNAL`. **A tela "Executar" do GLPI não serve** (a guarda é `PHP_SAPI === 'cli'`). Use:

```
php front/cron.php --force InstaladorExecutor
```

Guardas: só CLI; o dono do arquivo criado precisa ser o dono de `GLPI_MARKETPLACE_DIR`; `flock` em `GLPI_PLUGIN_DOC_DIR/instalador/execucao.lock` **e** `GET_LOCK('glpi_instalador_exec', 0)` (cobre `kill -9`, vários nós e NFS). Cada rodada prepara no máximo uma operação.

## Testes

```
php tests/run.php                      # tudo
php tests/run.php --grupo preparar-hostil
```

No contêiner local:

```
tar c --exclude=.git . | docker exec -i glpi_app sh -c 'd=$(mktemp -d) && tar x -C "$d" && cd "$d" && php tests/run.php'
```

`tests/run.php` responde 404 fora do CLI. O grupo `preparar-hostil` passa o corpus V4 inteiro pelo **Preparar completo**, com a chave de teste injetada pelo construtor de `Assinatura`, e compara a listagem (com hash) de `marketplace/` e `.hub-*` antes e depois. Se a variável `GLPI_MARKETPLACE_DIR` estiver definida (é o caso no Hubedesk), ele roda nesse diretório real.

`tests/fixtures/zips/` é o corpus do `painel-adm` (`test/fixtures/zips/`); `motivos.json` foi extraído do `HOSTIS` do `gerar.mjs` do Node, e o PHP recusa cada arquivo pelo **mesmo motivo**. `tests/fixtures/reais/subticket/` tem os releases reais 1.1.0 e 1.2.1 assinados pela chave principal.

## Mapa

```
setup.php hook.php            versão única (PLUGIN_INSTALADOR_VERSION), install/uninstall, direitos, cron
src/Publicadores.php          chaves fixadas
src/Manifesto.php Assinatura  manifesto canônico (§2.1), .sig (§2.2), verificação
src/ZipInspetor.php Extrator  perfil estrito §2.6 (parser cru) e extração
src/Arvore.php Caminhos.php   arvore_sha256; único lugar que monta caminho (D2-04.2)
src/R1.php Plano.php          regra de pasta única com proveniência; plano em simulação
src/Preparador.php Catalogo.php Recebimento.php
src/Armazem*.php              persistência (MySQL em produção, memória nos testes)
```
