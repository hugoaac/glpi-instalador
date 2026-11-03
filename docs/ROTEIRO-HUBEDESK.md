# Roteiro ao vivo da fatia (b) no Hubedesk

Executado pelo **Hugo**. Nada disto foi executado pelo agente. Alvo: `glpi-hubedesk-glpi-1` (GLPI 11.0.8, PHP 8.5.10), `GLPI_MARKETPLACE_DIR=/var/glpi/marketplace`.

Legenda: **[root]** = root no host ou `docker`; **[git]** = `user-glpidev` por `git`, sem root.

## 0. Pré-condições (fatia 0, §6.3 do desenho)

1. **[root]** `GLPI_MARKETPLACE_ALLOW_OVERRIDE=0` no `compose.yaml`; `docker compose config | head -1`; `up -d --no-deps --force-recreate glpi`. Prova: `docker exec -u www-data glpi-hubedesk-glpi-1 php -r 'echo getenv("GLPI_MARKETPLACE_ALLOW_OVERRIDE");'` deve dar `0`. A tela do instalador mostra o valor que o FPM leu.
2. **[root]** `docker inspect glpi-hubedesk-glpi-1 --format '{{json .Mounts}}'`: `marketplace` e `GLPI_PLUGIN_DOC_DIR` têm de ser *bind mount* (senão o Preparar funciona, mas a fatia c fica bloqueada).
3. **[root]** `docker exec -u www-data glpi-hubedesk-glpi-1 php -v` (esperado 8.5.10).
4. **Chaves:** o `key_id` `38f6f9ceaa9c094c` em `src/Publicadores.php` e em `publicador.js`. A reserva ainda não existe: a tela mostrará "Chaves de publicador fixadas: 1 de 2" em vermelho. É esperado no Hubedesk; **bloqueia qualquer cliente**.

## 1. Instalar o código (somente git, [git] como `user-glpidev`)

```
cd /srv/dev/plugins
git clone <repo>/glpi-instalador instalador          # ou git pull se já existir
cd glpi-informante && git pull                        # informante 1.1.0
```
Não clonar o `subticket` aqui (§6.4). O instalador e o informante ficam em `plugins/` (recusa fixa como alvo).

## 2. Informante 1.1.0 (G7) e instalador

1. No GLPI, Configurar > Plugins: o **informante** aparecerá em `NOTUPDATED` (hooks parados). Clique **Atualizar** (ou **[root]** `docker exec -u www-data glpi-hubedesk-glpi-1 php bin/console glpi:plugin:install informante` e `glpi:plugin:activate informante`). Confirme que o próximo relatório chega ao painel.
2. **[root]** `docker exec -u www-data ... php bin/console glpi:plugin:install instalador` e `glpi:plugin:activate instalador`.
3. Em Administração > Perfis > (seu perfil) > aba **Instalador**: dar "Ver" e "Atualizar" no primeiro direito. **Não** dar o direito de aprovar (é da fatia c).
4. **[root]** Reinício do FPM/contêiner (`docker restart glpi-hubedesk-glpi-1`): com `opcache.validate_timestamps=0`, PHP novo só roda depois disso.
5. **[root]** Pôr a ação automática `InstaladorExecutor` em modo CLI (já nasce `MODE_EXTERNAL`) e confirmar que o cron externo roda como `www-data`.

## 3. Roteiro (§7.3, passos 7 a 9)

**Antes de cada negativo e depois:** **[root]** `docker exec glpi-hubedesk-glpi-1 ls -laR /var/glpi/marketplace > /tmp/antes.txt` e a mesma saída em `/tmp/depois.txt`; `diff antes depois` tem de ser vazio.

7. No painel: permitir `subticket` e `hubteste` para `hubedesk-dev` (`ambiente_chaves`). No GLPI, abrir Administração > Instalador; **abrir pela web** uma vez (isso grava a visão do FPM). Clicar **Atualizar catálogo agora**: os pacotes aparecem com "assinatura ok".
8. **Plano do subticket 1.1.0:** **Preparar e planejar** no `subticket 1.1.0`; **[root]** `docker exec -u www-data glpi-hubedesk-glpi-1 php front/cron.php --force InstaladorExecutor` (a tela "Executar" **não** serve). Abrir a operação: `INSTALAR`, `de` vazio, R1 OK, diretórios iguais, `cron_limit` mostrado, backup marcado como sensível. **Esperado:** `marketplace/.hub-staging/op<id>/subticket` existe, `marketplace/subticket` **não** existe.
9. Negativos (com a listagem antes/depois):
   - **9a** Upload offline de um zip com 1 byte trocado (manipule uma cópia do release): recusa `zip_sha256`; nada novo em `marketplace/`.
   - **9b** Upload com `.sig` assinado por outra chave (por exemplo o `sig` de teste): recusa `key_id`.
   - **9c** Pacote `modernlogs` (release real): `GERENCIADO_EM_OUTRO_LUGAR / existe_fora_do_marketplace`. **[git]** `git -C /srv/dev/plugins/modernlogs status` e `rev-parse HEAD` iguais antes e depois.
   - **9d** `instalador` e `informante` como chave: nem chegam a existir (o `release` recusa; o manifesto também). Prova em teste: `php tests/run.php --grupo r1`.
   - **9e (Q1) `preparar-hostil`:**
     ```
     [root] docker cp <instalador>/tests ... # só se o plugin em plugins/ não for o clone: ele é, então basta o git
     [root] docker exec -u www-data glpi-hubedesk-glpi-1 ls -laR /var/glpi/marketplace > /tmp/antes.txt
     [root] docker exec -u www-data glpi-hubedesk-glpi-1 php /var/www/glpi/plugins/instalador/tests/run.php --grupo preparar-hostil
     [root] docker exec -u www-data glpi-hubedesk-glpi-1 ls -laR /var/glpi/marketplace > /tmp/depois.txt ; diff /tmp/antes.txt /tmp/depois.txt
     ```
     Esperado: `0 falharam`, `diff` vazio. O teste usa `GLPI_MARKETPLACE_DIR` do ambiente do contêiner e imprime `(marketplace real: /var/glpi/marketplace)`.
   - **9e-mutação:** **[git]** no clone, trocar em `src/Preparador.php` a linha `$info  = ZipInspetor::arvoreDoZip(...)` por `$info = ['arvore' => $m['arvore_sha256'], 'total' => 0, 'entradas' => [], 'raiz' => $m['chave']];`, repetir o comando acima (**tem de falhar**, ~32 falhas), e `git checkout src/Preparador.php`.

## 4. Depois

- Logs: `/var/glpi/logs/instalador.log` e o log do FPM sem erro novo em 24 h.
- `marketplace/.hub-staging/op<id>` do passo 8 pode ficar (a limpeza apaga órfão com mais de 7 dias de operação encerrada); para limpar já: **[root]** `rm -r` dessa pasta.
