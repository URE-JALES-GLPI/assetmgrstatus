<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginAssetmgrstatusMenu extends CommonGLPI
{
    static $rightname = 'plugin_assetmgrstatus';

    static function getTypeName($nb = 0): string { return 'Inventário'; }
    static function getMenuName(): string { return 'Inventário'; }
    static function getIcon(): string { return 'ti ti-clipboard-list'; }

    static function getMenuContent(): array
    {
        // Mesmo padrão do whatsappsimples que funciona no servidor
        $base = '../glpi/plugins/assetmgrstatus/front';

        return [
            'title'   => self::getMenuName(),
            'page'    => $base . '/dashboard.php',
            'icon'    => self::getIcon(),
            'options' => [
                'dashboard' => [
                    'title' => 'Dashboard',
                    'page'  => $base . '/dashboard.php',
                    'icon'  => 'ti ti-dashboard',
                    'links' => ['search' => $base . '/dashboard.php'],
                ],
                'maintenance' => [
                    'title' => 'Inventário',
                    'page'  => $base . '/maintenance.php',
                    'icon'  => 'ti ti-clipboard-list',
                    'links' => ['search' => $base . '/maintenance.php'],
                ],
                'tecnico' => [
                    'title' => 'Técnico',
                    'page'  => $base . '/tecnico.php',
                    'icon'  => 'ti ti-tools',
                    'links' => ['search' => $base . '/tecnico.php'],
                ],
                'reports' => [
                    'title' => 'Relatórios',
                    'page'  => $base . '/reports.php',
                    'icon'  => 'ti ti-report',
                    'links' => ['search' => $base . '/reports.php'],
                ],
            ],
        ];
    }

    static function canView(): bool
    {
        return Session::getLoginUserID() > 0
            && Session::haveRight('plugin_assetmgrstatus', READ);
    }
}
