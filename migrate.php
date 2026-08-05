<?php
/**
 * Script de migração — adiciona colunas na tabela de histórico
 * Rodar UMA VEZ no servidor:
 * php /var/www/html/glpi/plugins/assetmgrstatus/migrate.php
 */

chdir('/var/www/html/glpi');
require 'inc/includes.php';

global $DB;

$columns = [
    'record_type'        => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `record_type` VARCHAR(50) NOT NULL DEFAULT 'status_change' AFTER `status_new`",
    'action_description' => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `action_description` LONGTEXT NULL AFTER `record_type`",
    'action_date'        => "ALTER TABLE `glpi_plugin_assetmgrstatus_histories` ADD COLUMN `action_date` DATE NULL AFTER `action_description`",
];

foreach ($columns as $col => $sql) {
    $check = $DB->request([
        'SELECT' => ['COLUMN_NAME'],
        'FROM'   => 'information_schema.COLUMNS',
        'WHERE'  => [
            'TABLE_SCHEMA' => $DB->dbdefault,
            'TABLE_NAME'   => 'glpi_plugin_assetmgrstatus_histories',
            'COLUMN_NAME'  => $col,
        ],
    ]);

    if ($check->count() === 0) {
        $DB->doQuery($sql);
        echo "✅ Coluna '$col' adicionada.\n";
    } else {
        echo "⏭️  Coluna '$col' já existe.\n";
    }
}

echo "\nMigração concluída!\n";
