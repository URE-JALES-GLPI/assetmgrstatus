<?php

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class PluginAssetmgrstatusMenu extends CommonGLPI
{
    static string $rightname = 'plugin_assetmgrstatus';

    static function getTypeName($nb = 0): string { return 'Inventário'; }
    static function getMenuName(): string { return 'Inventário'; }
    static function getIcon(): string { return 'ti ti-clipboard-list'; }

    static function getMenuContent(): array
    {
        // Caminho absoluto sem root_doc (o GLPI prepend root_doc internamente,
        // mesmo padrão do protocolo que funciona com root_doc '' ou '/glpi').
        // Antes era '../glpi/...' relativo, que quebrava com root_doc vazio (docroot).
        $plugdir = (defined('GLPI_ROOT') && @is_dir(GLPI_ROOT . '/marketplace/assetmgrstatus')) ? 'marketplace' : 'plugins';
        $base = '/' . $plugdir . '/assetmgrstatus/front';

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
        // Assinatura — ao lado de Técnico; mesma regra da página (quem pode abrir, vê no menu)
        if (Session::haveRight('plugin_assetmgrstatus_assinatura', READ) || Session::haveRight('plugin_assetmgrstatus_tecnico', READ) || Session::haveRight('plugin_assetmgrstatus_admin', READ) || Session::haveRight('plugin_assetmgrstatus', READ)) {
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
