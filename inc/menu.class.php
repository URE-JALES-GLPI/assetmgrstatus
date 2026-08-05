<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginAssetmgrstatusMenu extends CommonGLPI
{
    static $rightname = 'plugin_assetmgrstatus';

    static function getTypeName($nb = 0): string { return 'Manutenção'; }
    static function getMenuName(): string { return 'Manutenção'; }
    static function getIcon(): string { return 'ti ti-tool'; }

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
                    'title' => 'Manutenção',
                    'page'  => $base . '/maintenance.php',
                    'icon'  => 'ti ti-tool',
                    'links' => ['search' => $base . '/maintenance.php'],
                ],
                'transfer' => [
                    'title' => 'Transferência',
                    'page'  => $base . '/transfer.php',
                    'icon'  => 'ti ti-transfer',
                    'links' => ['search' => $base . '/transfer.php'],
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
