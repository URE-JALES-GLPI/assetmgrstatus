<?php

function plugin_assetmgrstatus_install(): bool
{
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();

    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_records')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_records` (
                `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                `items_id`      INT {$sign} NOT NULL DEFAULT '0',
                `itemtype`      VARCHAR(100) NOT NULL DEFAULT '',
                `am_status`     VARCHAR(50)  NOT NULL DEFAULT 'estoque',
                `reason`        LONGTEXT     DEFAULT NULL,
                `components`    LONGTEXT     DEFAULT NULL,
                `users_id_tech` INT {$sign} NOT NULL DEFAULT '0',
                `users_id`      INT {$sign} NOT NULL DEFAULT '0',
                `date_mod`      DATETIME     DEFAULT NULL,
                `date_creation` DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `items_id`  (`items_id`),
                KEY `itemtype`  (`itemtype`),
                KEY `am_status` (`am_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    if (!$DB->tableExists('glpi_plugin_assetmgrstatus_histories')) {
        $DB->doQuery("
            CREATE TABLE `glpi_plugin_assetmgrstatus_histories` (
                `id`            INT {$sign} NOT NULL AUTO_INCREMENT,
                `items_id`      INT {$sign} NOT NULL DEFAULT '0',
                `itemtype`      VARCHAR(100) NOT NULL DEFAULT '',
                `item_name`     VARCHAR(255) NOT NULL DEFAULT '',
                `status_old`    VARCHAR(50)  DEFAULT NULL,
                `status_new`    VARCHAR(50)  NOT NULL DEFAULT '',
                `reason`        LONGTEXT     DEFAULT NULL,
                `components`    LONGTEXT     DEFAULT NULL,
                `photos`        LONGTEXT     DEFAULT NULL,
                `users_id`      INT {$sign} NOT NULL DEFAULT '0',
                `date_creation` DATETIME     DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `items_id` (`items_id`),
                KEY `itemtype` (`itemtype`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}
        ") or die($DB->error());
    }

    // Cria direitos nos perfis existentes
    PluginAssetmgrstatusProfile::install();

    return true;
}

function plugin_assetmgrstatus_uninstall(): bool
{
    global $DB;

    foreach ([
        'glpi_plugin_assetmgrstatus_records',
        'glpi_plugin_assetmgrstatus_histories',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQuery("DROP TABLE `{$table}`");
        }
    }

    PluginAssetmgrstatusProfile::uninstall();
    return true;
}
