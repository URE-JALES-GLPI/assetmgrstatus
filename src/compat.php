<?php
/**
 * Compatibilidade AssetMgrStatus entre GLPI 10/11 e GLPI 12.
 *
 * Mesmo esquema de kanpro/inc/compat.php e protocolo/src/compat.php.
 * Helpers guardados por function_exists/method_exists para funcionar nas
 * versões suportadas. No GLPI 12: Plugin::getWebDir()/getPhpDir() foram
 * removidos, Session::getNewCSRFToken() e Session::checkCSRF()/validateCSRF()
 * viraram no-ops (só emitem deprecation) e o CSRF passou a ser validado pelo
 * CheckCsrfListener (Sec-Fetch-Site/Origin).
 */
if (!defined('GLPI_ROOT')) {
    // setup.php roda antes do GLPI_ROOT existir (carrega manifest); não morre aqui.
    // As funções abaixo só usam GLPI_ROOT em runtime, quando já definido.
}

if (!function_exists('ams_glpi_version')) {
    function ams_glpi_version(): string
    {
        return defined('GLPI_VERSION') ? (string) GLPI_VERSION : '';
    }
}

if (!function_exists('ams_glpi_major')) {
    function ams_glpi_major(): int
    {
        $v = ams_glpi_version();
        if ($v === '') {
            return 0;
        }
        return (int) explode('.', $v)[0];
    }
}

if (!function_exists('ams_is_glpi12_plus')) {
    function ams_is_glpi12_plus(): bool
    {
        return ams_glpi_major() >= 12;
    }
}

if (!function_exists('ams_web_dir')) {
    /**
     * URL base do plugin (ex.: "/glpi/plugins/assetmgrstatus").
     * GLPI <=11: usa Plugin::getWebDir(); GLPI 12 (método removido): monta via root_doc.
     */
    function ams_web_dir(string $plugin = 'assetmgrstatus'): string
    {
        if (method_exists('Plugin', 'getWebDir')) {
            try {
                return (string) Plugin::getWebDir($plugin);
            } catch (\Throwable $e) {
                // cai para o fallback abaixo
            }
        }
        global $CFG_GLPI;
        $root = (is_array($CFG_GLPI) && isset($CFG_GLPI['root_doc'])) ? (string) $CFG_GLPI['root_doc'] : '';
        if (defined('GLPI_ROOT')) {
            $base = (string) GLPI_ROOT;
            if (@is_dir($base . '/marketplace/' . $plugin)) {
                return $root . '/marketplace/' . $plugin;
            }
        }
        return $root . '/plugins/' . $plugin;
    }
}

if (!function_exists('ams_php_dir')) {
    /**
     * Caminho físico do plugin. GLPI <=11: usa Plugin::getPhpDir(); GLPI 12: GLPI_ROOT/plugins.
     */
    function ams_php_dir(string $plugin = 'assetmgrstatus'): string
    {
        if (method_exists('Plugin', 'getPhpDir')) {
            try {
                return (string) Plugin::getPhpDir($plugin);
            } catch (\Throwable $e) {
            }
        }
        if (defined('GLPI_ROOT')) {
            $base = (string) GLPI_ROOT;
            if (@is_dir($base . '/marketplace/' . $plugin)) {
                return $base . '/marketplace/' . $plugin;
            }
            return $base . '/plugins/' . $plugin;
        }
        return __DIR__ . '/..';
    }
}

if (!function_exists('ams_csrf_token')) {
    /**
     * Token CSRF da página. No GLPI 12 não há token (retorna ''); o CSRF é
     * tratado pelo listener do core. No GLPI <=11 mantém o comportamento atual.
     */
    function ams_csrf_token(): string
    {
        if (ams_is_glpi12_plus()) {
            return '';
        }
        if (method_exists('Session', 'getNewCSRFToken')) {
            try {
                return (string) Session::getNewCSRFToken();
            } catch (\Throwable $e) {
                return '';
            }
        }
        return '';
    }
}

if (!function_exists('ams_check_csrf')) {
    /**
     * Validação CSRF explícita. No GLPI 12 é no-op (o CheckCsrfListener cuida);
     * no GLPI <=11 delega para Session::checkCSRF() preservando o token.
     */
    function ams_check_csrf(array $data): void
    {
        if (ams_is_glpi12_plus()) {
            return;
        }
        Session::checkCSRF($data, true);
    }
}

if (!function_exists('ams_validate_csrf')) {
    /**
     * Wrapper de Session::validateCSRF() com gate de versão.
     * No GLPI 12 sempre retorna true (listener do core valida).
     */
    function ams_validate_csrf(array $data): bool
    {
        if (ams_is_glpi12_plus()) {
            return true;
        }
        if (method_exists('Session', 'validateCSRF')) {
            try {
                return (bool) Session::validateCSRF($data);
            } catch (\Throwable $e) {
                return false;
            }
        }
        return true;
    }
}
