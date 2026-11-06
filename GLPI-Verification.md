# GLPI-Verification: instalador 0.1.0 (fatia b do M2)

- **Alvo:** GLPI 11.0.8 (`src/autoload/constants.php:43`), PHP 8.2+. Testado em PHP 8.5.7 (contêiner `glpi_app`, GLPI 11 local).
- **Escopo:** só verifica e planeja. **Nenhum código de execução** (teste `guardas` varre `src/` atrás de `proc_open`, `exec`, `eval`, `include`).

## Fatos do GLPI usados (arquivo:linha, 11.0.8)

| Fato | Onde |
|---|---|
| `glpi_max` é **exclusivo** (`version_compare($glpiVersion, $max, '<')`) | `src/Plugin.php:2361` |
| `Toolbox::getGuzzleClient` aceita as opções do cliente (usadas pelo informante) | `src/Toolbox.php:1358-1375` |
| `CronTask::register` com `mode` `MODE_EXTERNAL` | `src/CronTask.php:966-990`, constante em `:81` |
| `ProfileRight::addProfileRights` / `deleteProfileRights` | `src/ProfileRight.php:155, 191` |
| Estados do plugin (`NOTINSTALLED=2`, `NOTUPDATED=6`...) | `src/Plugin.php:80-105` |
| Charset/collation/sinal da chave primária dinâmicos | `src/DBConnection.php:891, 909, 927` |
| `csrf_token()` no Twig | `src/Glpi/Application/View/Extension/SecurityExtension.php:52` |
| `$DB->affectedRows()` / `insertId()` | `src/DBmysql.php:974, 629` |

## Hooks, tabelas, direitos, assets, CSRF

- **Hooks:** `CSRF_COMPLIANT`, `MENU_TOADD` (`admin`), aba de perfil (`addtabon Profile`). Nada de `ITEM_*`.
- **Tabelas (instala e desinstala limpo, dinâmico por `DBConnection`):** `glpi_plugin_instalador_pacotes` (UNIQUE `zip_sha256` e UNIQUE `(chave, versao)`, D2-19), `_versoes_vistas`, `_operacoes`, `_journal`. `date_*` como `timestamp NULL DEFAULT NULL`, `*_id` como `int unsigned` (sinal vem de `getDefaultPrimaryKeySignOption()`).
- **Migrations:** só o install (0.1.0). `setup.php` e `hook.php` leem a MESMA constante `PLUGIN_INSTALADOR_VERSION`.
- **Direitos:** `plugin_instalador` (ver / preparar / enviar offline) e `plugin_instalador_aprovar` (reservado à fatia c). Nenhum concedido no install.
- **Assets:** nenhum JS/CSS próprio (só Twig com as classes do GLPI). Nada em `public/`.
- **CSRF:** nenhum `Session::checkCSRF()` em `front/`. `pacote.form.php` (POST, multipart) leva `_glpi_csrf_token` no formulário; nenhum AJAX.
- **CronTask:** `InstaladorExecutor`, `MODE_EXTERNAL`, 1 min; guardas `PHP_SAPI`, dono do marketplace, `flock` + `GET_LOCK`.

## O que foi testado (executado)

- `php tests/run.php` no contêiner `glpi_app`: **201 asserções, 0 falhas** (grupos manifesto, zip, preparar-hostil, preparar, r1, versao, plano, catalogo, recebimento, guardas). Informante: 86, 0 falhas.
- **V1 e V2** reproduzidos byte a byte pelo PHP com sodium (sha256, assinatura, reserialização canônica); os 10 negativos do V1.
- **V4: os 30 arquivos do corpus** recusados pelo mesmo motivo do Node (`motivos.json` extraído do `HOSTIS` do `gerar.mjs`); `arvore-m1.zip` com a `arvore` do V3 (sem extrair e extraído); `git-archive-real.zip` aceito com a `arvore` do Node; `vinte-mil-e-um` -> `entradas`.
- **Releases reais** do `subticket` 1.1.0 e 1.2.1 verificados com a chave principal fixada; `arvore` do zip = `bd08cb48...` e `bf398199...`; o Preparar leva ambos a `PLANO_PRONTO` num marketplace limpo.
- **`preparar-hostil`:** o corpus inteiro passa pelo Preparar completo, no `/var/glpi/marketplace` real do contêiner, com listagem e hash antes/depois idênticos e nenhum `.hub-staging` deixado.
- **Mutações (12 no instalador, 3 no informante), todas pegas;** cada uma conferida com `cmp` (aplicou) e `cmp` (restaurou).
- **No GLPI local (smoke, `glpi_app`):** `glpi:plugin:install`/`activate` OK; `front/cron.php --force InstaladorExecutor` roda sem erro; recebimento dos dois releases reais pelo `ArmazemDb`; `Servico::pedir` + `Preparador` contra o banco: R1 mandou `subticket` para `GERENCIADO_EM_OUTRO_LUGAR / existe_fora_do_marketplace` (existe em `plugins/` neste GLPI); transição repetida recusada (0 linhas); retirado liga uma vez e não desliga; `uninstall` e reinstall/desinstalar limpos. A pasta do plugin foi retirada do contêiner depois.

## Não testado

- **Páginas web** (`front/*.php`, Twig, aba de perfil, menu) renderizadas num navegador: não houve sessão HTTP. Só `php -l` e o carregamento via CLI.
- **`Api::transporteGuzzle`** contra o painel real; **`informante` 1.1.0 ativo** junto com o instalador (aqui o informante local ficou em 1.0.x).
- O caminho positivo do Preparar contra o **banco** (R1 `OK` + `PLANO_PRONTO` com `ArmazemDb`): coberto em memória; no banco só o desvio da R1.
- Grupo `preparar-hostil` no **PHP 8.5.10 / libzip do Hubedesk** (roteiro do Hugo).
- Upload multipart real (`upload_max_filesize` / `post_max_size`), cron como `www-data` no Hubedesk, `GLPI_MARKETPLACE_ALLOW_OVERRIDE=0` lido no FPM.
- Opcache sem revalidação (a fatia b não troca código).

## Correções e divergências em relação ao desenho

Ver a resposta de entrega: (1) catálogo rejeita `zip_repetido`; (2) `Api` é instância com métodos fixos, não estática; (3) o instalador calcula a própria `arvore` em vez de pedir ao informante; (4) `glpi_max` exclusivo; (5) assinatura vem antes do canônico, então 1 byte a mais no manifesto é `assinatura`, não `canonico`.

## Revisão do Sable (03/11): correções aplicadas

Código: B-01 (zip conferido no `.tmp`, envenenado apagado e rebaixado uma vez, upload substitui), B-02 (de e proveniência de manifesto assinado reverificado; maior visto = banco, espelho em arquivo e manifestos), B-03 (`GET_LOCK` com `SHA1(DATABASE())`), B-04 (varredura por `token_get_all`), B-05 (`Texto::esc`), B-06 (`euid`), B-07 (retomada de `PREPARANDO` e unicidade por coluna gerada), B-08, B-09 (falha fechado), B-10 (`RuntimeException` do insert), B-11 (folha conferida), B-14, B-15.
Ficam para a fatia (c): B-12, P-4, P-5, P-6. Não feitos: B-13 (só texto no desenho, §8), B-16 e B-17 (ver a resposta de entrega).

Testado (executado): `php tests/run.php` 261 asserções, 0 falhas (informante 87). 18 mutações novas no instalador, 17 pegas e 1 equivalente (B-01a: a conferência final após o rename apaga o arquivo, então o efeito observável é o mesmo; a ordem tmp-antes-do-rename não é observável por teste). Smoke no GLPI local: install/activate/uninstall; a coluna gerada `aberta_chave` com UNIQUE recusa a 2ª operação aberta na mesma chave e libera após fechar; `$DB->insert` duplicado lança `RuntimeException` (confirma o B-10); `GET_LOCK` com o nome novo devolve 1.
Não testado: `GET_LOCK` com dois bancos diferentes no mesmo servidor; o cron como root real (só a função `euidOk`).

## 0.2.0 (fatia c do M2): passos 1 a 5

- **Alvo:** GLPI 11.0.8, PHP 8.2+. **Versão:** `setup.php` 0.2.0 (a mesma constante vale no `hook.php`); sem DDL novo, mas a tela de Plugins mostra "Atualizar" (G7).
- **Hooks:** os mesmos da 0.1.0 (`CSRF_COMPLIANT`, `MENU_TOADD`). CronTask `InstaladorExecutor` (MODE_EXTERNAL) agora retoma, executa e prepara, no máximo UMA operação por rodada.
- **Tabelas/migrations:** nenhuma coluna nova. Estados novos em `estado` (`varchar(32)`): `APROVADA`, `BACKUP`, `TROCANDO`, `MIGRANDO`, `ATIVANDO`, `AGUARDANDO_CONCLUSAO`, `INCERTO`, `SEM_PLUGIN`, `INSTALADO_SEM_VALIDAR`. `INCERTO`/`SEM_PLUGIN` não estão em `aberta_chave` como fechados: a chave fica bloqueada pelo UNIQUE.
- **Direitos:** `plugin_instalador_aprovar` (UPDATE) para aprovar, com MFA TOTP ativo e reautenticação na hora.
- **Assets:** nenhum JS/CSS novo. **CSRF:** o formulário de aprovar envia `_glpi_csrf_token` e `front/operacao.php` NÃO chama `Session::checkCSRF()`.
- **Código de execução:** `proc_open` só em `src/Subprocesso.php` (B-04, lista de permitidos na varredura de `tests/grupos-b.php`).
- **Testado (contêiner `glpi_app`, `php tests/run.php`):** Executor com duplos de Console/Dumper sobre os zips REAIS do `subticket` 1.1.0 e 1.2.1; Dump contra o MariaDB real (`glpi_db`, isca `hubdump`); Subprocesso contra um `bin/console` falso; Aprovacao (parte pura). Mutações: ver o relatório da fatia.
- **NÃO testado:** `ReautenticadorGlpi` (precisa de sessão, senha e TOTP reais); `glpi:plugin:install/activate` reais com o `hubteste`; `kill -9` de verdade; a tela de aprovar no navegador; o fluxo na CronTask real. Tudo no roteiro `docs/ROTEIRO-HUBEDESK-C.md`.
