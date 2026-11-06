# Roteiro ao vivo da fatia (c), Hubedesk (`ssh glpidev`)

Escrito, **não executado**. Separa o que é root/docker do que é git. Nada disto roda em produção. Pré-requisito: instalador 0.2.0 e informante 1.2.0 no GLPI de dev, `GLPI_MARKETPLACE_ALLOW_OVERRIDE=0`, `cron_limit = 1`, o informante já enviando com a chave de ambiente.

## A. Git (PC do Hugo, sem root)

1. `C:\dev\glpi-instalador`: `git log --oneline` mostra os commits da (c); `git push`.
2. `C:\dev\glpi-informante`: `git push` (1.2.0).
3. `C:\dev\glpi-hubteste`: criar `hugoaac/glpi-hubteste` no GitHub, `git remote add origin ...`, `git push origin main --tags`.
4. Release do `hubteste` `v1.0.0` e `v1.0.1` e do `subticket` `v1.1.0`/`v1.2.1` pelo `tools/release.mjs` do painel (fatia a). **O release passa a avisar DDL no `plugin_init`** (o instalador agora avisa também no plano).

## B. Root / docker (só o Hugo)

1. Copiar `glpi-instalador` e `glpi-informante` para `/glpi/plugins/` com `wsl -u root` (ou o caminho do dev) e `docker restart glpi_app` (opcache sem revalidação). Clicar em **Atualizar** nos dois plugins.
2. O dono do cron tem de ser o dono do `marketplace/` (nunca root): `docker exec -u www-data glpi_app php front/cron.php --force InstaladorExecutor`.
3. Conceder `plugin_instalador` e `plugin_instalador_aprovar` ao perfil de teste; o usuário aprovador precisa de **TOTP ativo** (Preferências, Autenticação em dois fatores).
4. `INSTALADOR_TEST_DB` só vale para o teste de unidade; **não** é usado ao vivo.
5. Para o `kill -9`: `echo 40 > $GLPI_PLUGIN_DOC_DIR/hubteste/sleep` (o install do `hubteste` 1.0.1 dorme 40 s).

## C. Casos, nesta ordem

| # | Passo | Esperado |
|---|---|---|
| 1 | Pedir `subticket` 1.1.0, esperar `PLANO_PRONTO`, abrir a operação | Plano completo; se o `plugin_init` do pacote faz DDL, o aviso `ddl_no_plugin_init_aviso` aparece |
| 2 | Aprovar com senha **errada** | Recusa; continua `PLANO_PRONTO`; nenhuma senha no journal (`SELECT dados FROM glpi_plugin_instalador_journal`) |
| 3 | Aprovar com usuário **sem MFA** | "aprovador sem MFA" |
| 4 | Aprovar **personificando** (Impersonate) | Recusa |
| 5 | Aprovar sem marcar o backup | Recusa |
| 6 | Aprovar certo; rodar o cron | `AGUARDANDO_CONCLUSAO`; `.../backup/op<id>/{backup.json,banco.sql,codigo.zip,arquivos.txt,install.out}`; `php tools/verificar-dump.php` confere; `notupdated_s` no journal; `glpi_plugins.state` do `subticket` = 1 |
| 7 | Criar dado de teste no `subticket`; pedir e aprovar 1.2.1 | Atualização; `.hub-anterior/subticket-1.1.0-op<id>` existe; dado preservado; journal completo |
| 8 | Informante: forçar envio (forçar a tarefa do informante em Configurar > Ações automáticas) | O painel recebe `operacoes` sem nome/login/motivo |
| 9 | **DIVERGENTE:** aprovar e, antes do cron, publicar versão mais nova do mesmo plugin (muda `versao_mais_nova_conhecida`) | `CONCLUIDA/DIVERGENTE`, `marketplace/` idêntico |
| 10 | **P-4:** aprovar e desativar o aprovador antes do cron | `FALHA aprovador_inativo`, sem `backup/op<id>` |
| 11 | `cron_limit = 2` + aprovada | `FALHA cron_limit` |
| 12 | **kill -9 do cron:** `hubteste` 1.0.0 instalado; aprovar 1.0.1 com `sleep=40`; durante o install, `kill -9 <pid do php front/cron.php>` | O filho segue vivo; a próxima rodada espera, depois `INCERTO`/`filho_sem_resultado`; **a coluna nova não é criada duas vezes**, `install.out` com uma execução só; chave bloqueada |
| 13 | **kill -9 do filho:** repetir e matar `php bin/console glpi:plugin:install` | `INCERTO`/`migracao_erro` (exit != 0) ou `filho_sem_resultado`; nunca repete |
| 14 | `kill -9` do cron durante o dump (`BACKUP`) | Próxima rodada: `FALHA interrompido_no_backup` |
| 15 | `kill -9` entre T1 e T2 (precisa de `sleep` artificial; opcional) | `FALHA interrompido_na_troca`, `A` volta para `M` |

## D. Desfazer o que instalou

- `hubteste`: Configurar > Plugins > Desinstalar; `rm -rf marketplace/hubteste marketplace/.hub-*`; `DROP TABLE glpi_plugin_hubteste_itens` se sobrar.
- `subticket` de teste: o mesmo, **só se a pasta não existia antes** do roteiro. Nunca em plugin que já estava lá.
- `backup/op<id>` é sensível (pode ter dado pessoal): apagar à mão ao fim.

## E. Pendências conhecidas para este roteiro

- O destravamento de chave `INCERTO` (reautenticação + texto) é da fatia (d); no dev, resolver por SQL: `UPDATE glpi_plugin_instalador_operacoes SET estado='FALHA' WHERE id=?` só depois de conferir `marketplace/<chave>` e `glpi_plugins`.
