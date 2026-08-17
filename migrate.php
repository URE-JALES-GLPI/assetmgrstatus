<?php
/**
 * Script de migração — garante tabelas e colunas do plugin
 * Rodar UMA VEZ no servidor:
 * php /var/www/html/glpi/plugins/assetmgrstatus/migrate.php
 */

require_once dirname(__DIR__, 2) . '/inc/includes.php';
require_once __DIR__ . '/hook.php';

if (plugin_assetmgrstatus_schema()) {
    echo "Esquema do banco verificado/atualizado.\n";
}

echo "\nMigração concluída!\n";