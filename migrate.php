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

// Carrega o config de conexão do GLPI — versões novas usam config_db.php
// (define a classe DB com as credenciais); versões antigas usam config.php.
if (!class_exists('DB')) {
    $glpi_root = defined('GLPI_ROOT') ? GLPI_ROOT : dirname(__DIR__, 2);
    $config_files = [];
    if (defined('GLPI_CONFIG_DIR')) {
        $config_files[] = GLPI_CONFIG_DIR . '/config_db.php';
        $config_files[] = GLPI_CONFIG_DIR . '/config.php';
    }
    $config_files[] = $glpi_root . '/config/config_db.php';
    $config_files[] = $glpi_root . '/config/config.php';

    foreach (array_unique($config_files) as $config_file) {
        if (file_exists($config_file)) {
            require_once $config_file;
            break;
        }
    }
}

// Em CLI o GLPI nem sempre cria o $DB global — conecta explicitamente
if (!isset($GLOBALS['DB']) || $GLOBALS['DB'] === null) {
    $db_class = class_exists('DB') ? 'DB' : (class_exists('DBmysql') ? 'DBmysql' : null);
    if ($db_class === null) {
        fwrite(STDERR, "Erro: não foi possível carregar a classe de conexão do GLPI.\n");
        exit(1);
    }
    $GLOBALS['DB'] = new $db_class();
}

require_once __DIR__ . '/hook.php';

if (plugin_assetmgrstatus_schema()) {
    echo "Esquema do banco verificado/atualizado.\n";
}

echo "\nMigração concluída!\n";