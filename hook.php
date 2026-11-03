<?php

/**
 * instalador: install / uninstall. Tabelas glpi_plugin_instalador_*, direitos e a CronTask InstaladorExecutor.
 */

use GlpiPlugin\Instalador\Config;
use GlpiPlugin\Instalador\Cron;

function plugin_instalador_install(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    // Mesma constante do setup.php: a migration roda com a versão que a tela de Plugins mostra.
    $migration = new Migration(PLUGIN_INSTALADOR_VERSION);

    $charset = DBConnection::getDefaultCharset();
    $collate = DBConnection::getDefaultCollation();
    $sign    = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_instalador_pacotes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_instalador_pacotes` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `chave` varchar(64) NOT NULL,
            `versao` varchar(32) NOT NULL,
            `zip_sha256` char(64) NOT NULL,
            `manifesto` text NOT NULL,
            `sig` text NOT NULL,
            `key_id` char(16) NOT NULL,
            `origem` varchar(10) NOT NULL DEFAULT 'upload',
            `arquivo_ok` tinyint NOT NULL DEFAULT 0,
            `verificado_em` timestamp NULL DEFAULT NULL,
            `motivo_recusa` varchar(64) DEFAULT NULL,
            `retirado` tinyint NOT NULL DEFAULT 0,
            `date_creation` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `zip_sha256` (`zip_sha256`),
            UNIQUE KEY `chave_versao` (`chave`, `versao`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_instalador_versoes_vistas')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_instalador_versoes_vistas` (
            `chave` varchar(64) NOT NULL,
            `versao_max` varchar(32) NOT NULL,
            `zip_sha256` char(64) NOT NULL,
            `visto_em` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`chave`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_instalador_operacoes')) {
        $DB->doQuery("CREATE TABLE `glpi_plugin_instalador_operacoes` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `tipo` varchar(10) NOT NULL DEFAULT 'INSTALAR',
            `chave` varchar(64) NOT NULL,
            `versao_de` varchar(32) DEFAULT NULL,
            `versao_para` varchar(32) DEFAULT NULL,
            `pacotes_id` int {$sign} DEFAULT NULL,
            `operacao_origem_id` int {$sign} DEFAULT NULL,
            `migracao` varchar(12) DEFAULT NULL,
            `estado` varchar(32) NOT NULL DEFAULT 'PEDIDA',
            `resultado` varchar(32) DEFAULT NULL,
            `motivo` varchar(64) DEFAULT NULL,
            `plano` mediumtext,
            `plano_sha256` char(64) DEFAULT NULL,
            `pedido_por` int {$sign} NOT NULL DEFAULT 0,
            `aprovado_por` int {$sign} DEFAULT NULL,
            `aprovado_em` timestamp NULL DEFAULT NULL,
            `backup_confirmado_por` int {$sign} DEFAULT NULL,
            `backup_confirmado_em` timestamp NULL DEFAULT NULL,
            `abaixo_da_mais_nova_confirmado` tinyint NOT NULL DEFAULT 0,
            `inicio` timestamp NULL DEFAULT NULL,
            `fim` timestamp NULL DEFAULT NULL,
            `notupdated_inicio` timestamp NULL DEFAULT NULL,
            `notupdated_fim` timestamp NULL DEFAULT NULL,
            `migracao_s` int {$sign} DEFAULT NULL,
            `filho_pid` int {$sign} DEFAULT NULL,
            `arvore_final` char(64) DEFAULT NULL,
            `bloqueia_chave` tinyint NOT NULL DEFAULT 0,
            `aberta_chave` varchar(64) GENERATED ALWAYS AS (CASE WHEN `estado` IN ('FALHA','CONCLUIDA') THEN NULL ELSE `chave` END) VIRTUAL,
            `resolvido_por` int {$sign} DEFAULT NULL,
            `resolvido_em` timestamp NULL DEFAULT NULL,
            `resolucao` varchar(255) DEFAULT NULL,
            `date_creation` timestamp NULL DEFAULT NULL,
            `date_mod` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `aberta_chave` (`aberta_chave`),
            KEY `chave` (`chave`),
            KEY `estado` (`estado`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC");
    }

    if (!$DB->tableExists('glpi_plugin_instalador_journal')) {
        // Só INSERT. Guarda FATOS (hashes, versões, contagens), nunca caminho para executar (D2-04.2).
        $DB->doQuery("CREATE TABLE `glpi_plugin_instalador_journal` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `operacoes_id` int {$sign} DEFAULT NULL,
            `passo` varchar(32) NOT NULL,
            `fase` varchar(8) NOT NULL,
            `dados` mediumtext,
            `criado_em` timestamp NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `operacoes_id` (`operacoes_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collate} ROW_FORMAT=DYNAMIC");
    }

    // Direitos: nenhum é concedido no install (linhas com 0 em todos os perfis).
    ProfileRight::addProfileRights(['plugin_instalador', 'plugin_instalador_aprovar']);

    Config::install();
    Cron::register();

    $migration->executeMigration();

    return true;
}

function plugin_instalador_uninstall(): bool
{
    /** @var DBmysql $DB */
    global $DB;

    CronTask::unregister('instalador');
    ProfileRight::deleteProfileRights(['plugin_instalador', 'plugin_instalador_aprovar']);
    Config::purge();

    foreach (['journal', 'operacoes', 'versoes_vistas', 'pacotes'] as $t) {
        $DB->doQuery('DROP TABLE IF EXISTS `glpi_plugin_instalador_' . $t . '`');
    }

    // Os zips guardados e o lock saem; backup/ (fatia c) fica: é dado do órgão e a remoção é decisão humana.
    $dir = rtrim(GLPI_PLUGIN_DOC_DIR, '/\\') . '/instalador';
    foreach (glob($dir . '/pacotes/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir . '/pacotes');
    @unlink($dir . '/execucao.lock');
    @rmdir($dir);

    return true;
}
