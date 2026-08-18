<?php

function plugin_assetmgrstatus_schema(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    // -------------------------------------------------------
    // 1. records — registro de manutenção por ativo
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_records')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_records` (
                `id`                    INT {$sign} NOT NULL AUTO_INCREMENT,
                `items_id`              INT {$sign} NOT NULL DEFAULT '0',
                `itemtype`              VARCHAR(100) NOT NULL DEFAULT '',
                `am_status`             VARCHAR(50)  NOT NULL DEFAULT 'estoque',
                `reason`                LONGTEXT     DEFAULT NULL,
                `components`            LONGTEXT     DEFAULT NULL,
                `expected_return_date`  DATE         DEFAULT NULL,
                `users_id_tech`         INT {$sign} NOT NULL DEFAULT '0',
                `users_id`              INT {$sign} NOT NULL DEFAULT '0',
                `transfer_status`       VARCHAR(50)  DEFAULT NULL,
                `transfers_id`          INT {$sign} DEFAULT NULL,
                `date_mod`              DATETIME     DEFAULT NULL,
                `date_creation`         DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `items_id`  (`items_id`),
                KEY `itemtype`  (`itemtype`),
                KEY `am_status` (`am_status`),
                KEY `itemtype_items` (`itemtype`,`items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    } else {
        // Colunas adicionadas após a primeira versão (instalações antigas)
        plugin_assetmgrstatus_add_columns('glpi_plugin_assetmgrstatus_records', [
            'expected_return_date' => "ALTER TABLE `glpi_plugin_assetmgrstatus_records` ADD COLUMN `expected_return_date` DATE DEFAULT NULL AFTER `components`",
            'transfer_status'      => "ALTER TABLE `glpi_plugin_assetmgrstatus_records` ADD COLUMN `transfer_status` VARCHAR(50) DEFAULT NULL AFTER `users_id`",
            'transfers_id'         => "ALTER TABLE `glpi_plugin_assetmgrstatus_records` ADD COLUMN `transfers_id` INT {$sign} DEFAULT NULL AFTER `transfer_status`",
        ]);
    }

    // -------------------------------------------------------
    // 2. histories — histórico de alterações por ativo
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_histories')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_histories` (
                `id`                 INT {$sign} NOT NULL AUTO_INCREMENT,
                `items_id`           INT {$sign} NOT NULL DEFAULT '0',
                `itemtype`           VARCHAR(100) NOT NULL DEFAULT '',
                `item_name`          VARCHAR(255) NOT NULL DEFAULT '',
                `status_old`         VARCHAR(50)  DEFAULT NULL,
                `status_new`         VARCHAR(50)  NOT NULL DEFAULT '',
                `record_type`        VARCHAR(50)  NOT NULL DEFAULT 'status_change',
                `reason`             LONGTEXT     DEFAULT NULL,
                `components`         LONGTEXT     DEFAULT NULL,
                `prev_reason`        LONGTEXT     DEFAULT NULL,
                `prev_components`    LONGTEXT     DEFAULT NULL,
                `is_undone`          TINYINT      NOT NULL DEFAULT 0,
                `photos`             LONGTEXT     DEFAULT NULL,
                `action_description` LONGTEXT     DEFAULT NULL,
                `action_date`        DATE         DEFAULT NULL,
                `users_id`           INT {$sign} NOT NULL DEFAULT '0',
                `date_creation`      DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `items_id` (`items_id`),
                KEY `itemtype` (`itemtype`),
                KEY `record_type` (`record_type`),
                KEY `hist_comp` (`items_id`,`itemtype`,`record_type`,`date_creation`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    } else {
        plugin_assetmgrstatus_add_columns('glpi_plugin_assetmgrstatus_histories', [
            'record_type'        => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `record_type` VARCHAR(50) NOT NULL DEFAULT 'status_change' AFTER `status_new`",
            'action_description' => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `action_description` LONGTEXT DEFAULT NULL AFTER `record_type`",
            'action_date'        => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `action_date` DATE DEFAULT NULL AFTER `action_description`",
            'prev_reason'        => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `prev_reason` LONGTEXT DEFAULT NULL AFTER `components`",
            'prev_components'    => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `prev_components` LONGTEXT DEFAULT NULL AFTER `prev_reason`",
            'is_undone'          => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `is_undone` TINYINT NOT NULL DEFAULT 0 AFTER `prev_components`",
        ]);
    }

    // -------------------------------------------------------
    // 3. transfers — transferências entre URE/escolas
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_transfers')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_transfers` (
                `id`               INT {$sign} NOT NULL AUTO_INCREMENT,
                `entity_dest`      INT {$sign} NOT NULL DEFAULT '0',
                `reason`           LONGTEXT     DEFAULT NULL,
                `status`           VARCHAR(50)  NOT NULL DEFAULT 'pendente',
                `users_id_created` INT {$sign} NOT NULL DEFAULT '0',
                `users_id_tech`    INT {$sign} NOT NULL DEFAULT '0',
                `tickets_id`       INT {$sign} NOT NULL DEFAULT '0',
                `date_pending`     DATETIME     DEFAULT NULL,
                `date_creation`    DATETIME     DEFAULT NULL,
                `date_manutencao`  DATETIME     DEFAULT NULL,
                `date_pronto`      DATETIME     DEFAULT NULL,
                `date_finalizado`  DATETIME     DEFAULT NULL,
                `date_cancelado`   DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `status` (`status`),
                KEY `entity_dest` (`entity_dest`),
                KEY `tickets_id` (`tickets_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    } else {
        plugin_assetmgrstatus_add_columns('glpi_plugin_assetmgrstatus_transfers', [
            'tickets_id'      => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `tickets_id` INT {$sign} NOT NULL DEFAULT '0' AFTER `users_id_tech`",
            'date_cancelado'  => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `date_cancelado` DATETIME DEFAULT NULL AFTER `date_finalizado`",
        ]);
    }

    // -------------------------------------------------------
    // 4. transfer_items — itens de cada transferência
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_transfer_items')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_transfer_items` (
                `id`                 INT {$sign} NOT NULL AUTO_INCREMENT,
                `transfers_id`       INT {$sign} NOT NULL DEFAULT '0',
                `items_id`           INT {$sign} NOT NULL DEFAULT '0',
                `itemtype`           VARCHAR(100) NOT NULL DEFAULT '',
                `item_name`          VARCHAR(255) NOT NULL DEFAULT '',
                `origin_entity_id`   INT {$sign} NOT NULL DEFAULT '0',
                `origin_entity_name` VARCHAR(255) DEFAULT NULL,
                `final_status`       VARCHAR(50)  DEFAULT NULL,
                `final_reason`       LONGTEXT     DEFAULT NULL,
                `final_components`   LONGTEXT     DEFAULT NULL,
                `work_log`           LONGTEXT     DEFAULT NULL,
                `work_components`    LONGTEXT     DEFAULT NULL,
                `work_status`        VARCHAR(20)  NOT NULL DEFAULT 'pending',
                PRIMARY KEY (`id`),
                KEY `transfers_id` (`transfers_id`),
                KEY `items_id` (`items_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // -------------------------------------------------------
    // 5. views — log de visualizações
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_views')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_views` (
                `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                `itemtype`      VARCHAR(100) NOT NULL DEFAULT '',
                `items_id`      INT {$sign} NOT NULL DEFAULT '0',
                `users_id`      INT {$sign} NOT NULL DEFAULT '0',
                `date_creation` DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `items_id` (`items_id`),
                KEY `itemtype` (`itemtype`),
                KEY `users_id` (`users_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // -------------------------------------------------------
    // 6. transfer_history — timeline de status das transferências
    // -------------------------------------------------------
    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_transfer_history')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_transfer_history` (
                `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                `transfers_id`  INT {$sign} NOT NULL DEFAULT '0',
                `status`        VARCHAR(50)  NOT NULL DEFAULT '',
                `users_id`      INT {$sign} NOT NULL DEFAULT '0',
                `note`          VARCHAR(255) DEFAULT NULL,
                `date_creation` DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `transfers_id` (`transfers_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    return true;
}

function plugin_assetmgrstatus_add_columns(string $table, array $columns): void
{
    global $DB;

    foreach ($columns as $col => $sql) {
        $exists = $DB->request([
            'SELECT' => ['COLUMN_NAME'],
            'FROM'   => 'information_schema.COLUMNS',
            'WHERE'  => [
                'TABLE_SCHEMA' => $DB->dbdefault,
                'TABLE_NAME'   => $table,
                'COLUMN_NAME'  => $col,
            ],
            'LIMIT' => 1,
        ])->count() > 0;

        if (!$exists) {
            $DB->doQuery($sql) or die($DB->error());
        }
    }
}

function plugin_assetmgrstatus_install(): bool
{
    plugin_assetmgrstatus_schema();

    // Cria direitos nos perfis existentes
    PluginAssetmgrstatusProfile::install();

    return true;
}

function plugin_assetmgrstatus_update(string $old_version): bool
{
    plugin_assetmgrstatus_schema();
    return true;
}

function plugin_assetmgrstatus_uninstall(): bool
{
    global $DB;

    foreach ([
        'glpi_plugin_assetmgrstatus_records',
        'glpi_plugin_assetmgrstatus_histories',
        'glpi_plugin_assetmgrstatus_transfers',
        'glpi_plugin_assetmgrstatus_transfer_items',
        'glpi_plugin_assetmgrstatus_views',
        'glpi_plugin_assetmgrstatus_transfer_history',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    PluginAssetmgrstatusProfile::uninstall();
    return true;
}