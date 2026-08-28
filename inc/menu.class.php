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

        $options = [
            'maintenance' => [
                'title' => 'Inventário',
                'page'  => $base . '/maintenance.php',
                'icon'  => 'ti ti-clipboard-list',
                'links' => ['search' => $base . '/maintenance.php'],
            ],
            'dashboard' => [
                'title' => 'Dashboard',
                'page'  => $base . '/dashboard.php',
                'icon'  => 'ti ti-dashboard',
                'links' => ['search' => $base . '/dashboard.php'],
            ],
        ];
        // Técnico só aparece para quem tem permissão
        if (Session::haveRight('plugin_assetmgrstatus_tecnico', READ)) {
            $options['tecnico'] = [
                'title' => 'Técnico',
                'page'  => $base . '/tecnico.php',
                'icon'  => 'ti ti-tools',
                'links' => ['search' => $base . '/tecnico.php'],
            ];
        }
        // Assinatura — ao lado de Técnico, só quem tem permissão
        if (Session::haveRight('plugin_assetmgrstatus_assinatura', READ)) {
            $options['assinatura'] = [
                'title' => 'Assinatura',
                'page'  => $base . '/assinatura.php',
                'icon'  => 'ti ti-signature',
                'links' => ['search' => $base . '/assinatura.php'],
            ];
        }
        $options['reports'] = [
            'title' => 'Relatórios',
            'page'  => $base . '/reports.php',
            'icon'  => 'ti ti-report',
            'links' => ['search' => $base . '/reports.php'],
        ];

        return [
            'title'   => self::getMenuName(),
            'page'    => $base . '/maintenance.php',
            'icon'    => self::getIcon(),
            'options' => $options,
        ];
    }

    static function canView(): bool
    {
        return Session::getLoginUserID() > 0
            && Session::haveRight('plugin_assetmgrstatus', READ);
    }
}
