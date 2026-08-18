<?php
/**
 * Script de migração — garante tabelas e colunas do plugin
 * Rodar UMA VEZ no servidor:
 * php /var/www/html/glpi/plugins/assetmgrstatus/migrate.php
 */

require_once dirname(__DIR__, 2) . '/inc/includes.php';

// Em CLI algumas versões do GLPI não registram o autoload das classes de src/
// (ex.: DBConnection). Garante o carregamento dos autoloaders antes de usar.
if (!class_exists('DBConnection')) {
    $glpi_root   = dirname(__DIR__, 2);
    $candidates = [
        $glpi_root . '/inc/autoload.function.php',
        $glpi_root . '/inc/autoload.php',
        $glpi_root . '/vendor/autoload.php',
    ];
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            require_once $candidate;
        }
    }
}

if (!class_exists('DBConnection')) {
    fwrite(STDERR, "Erro: não foi possível carregar o GLPI (DBConnection não encontrado).\n");
    exit(1);
}

require_once __DIR__ . '/hook.php';

if (plugin_assetmgrstatus_schema()) {
    echo "Esquema do banco verificado/atualizado.\n";
}

echo "\nMigração concluída!\n";