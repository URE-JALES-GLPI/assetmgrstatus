<?php

define('PLUGIN_ASSETMGRSTATUS_VERSION', '2.0.1');
define('PLUGIN_ASSETMGRSTATUS_MIN_GLPI', '10.0.0');
define('PLUGIN_ASSETMGRSTATUS_MAX_GLPI', '12.0.0');

function plugin_version_assetmgrstatus(): array
{
    return [
        'name'         => 'Inventário de Ativos',
        'version'      => PLUGIN_ASSETMGRSTATUS_VERSION,
        'author'       => 'Leonardo Poiatti Fação',
        'license'      => 'GPL v2+',
        'homepage'     => 'https://github.com/poiattileo/assetmgrstatus',
        'requirements' => ['glpi' => ['min' => PLUGIN_ASSETMGRSTATUS_MIN_GLPI, 'max' => PLUGIN_ASSETMGRSTATUS_MAX_GLPI]],
    ];
}

function plugin_assetmgrstatus_check_prerequisites(): bool
{
    if (version_compare(GLPI_VERSION, PLUGIN_ASSETMGRSTATUS_MIN_GLPI, 'lt')) {
        echo 'Este plugin requer GLPI ' . PLUGIN_ASSETMGRSTATUS_MIN_GLPI . ' ou superior.';
        return false;
    }
    return true;
}

function plugin_assetmgrstatus_check_config(bool $verbose = false): bool { return true; }

function plugin_init_assetmgrstatus(): void
{
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['assetmgrstatus'] = true;
    $PLUGIN_HOOKS['change_profile']['assetmgrstatus'] = ['PluginAssetmgrstatusProfile', 'changeProfile'];

    Plugin::registerClass(\GlpiPlugin\Assetmgrstatus\MaintenanceRecord::class, [
        'addtabon' => [
            'Glpi\\CustomAsset\\DesktopAsset',
            'Glpi\\CustomAsset\\NotebookAsset',
            'Glpi\\CustomAsset\\CelularAsset',
            'Glpi\\CustomAsset\\TabletAsset',
        ]
    ]);

    Plugin::registerClass(\GlpiPlugin\Assetmgrstatus\Stats::class);

    Plugin::registerClass('PluginAssetmgrstatusProfile', ['addtabon' => 'Profile']);

    if (Session::getLoginUserID()) {
        $css_version = @filemtime(__DIR__ . '/public/css/assetmgrstatus.css') ?: time();
        $js_version  = @filemtime(__DIR__ . '/public/js/assetmgrstatus.js') ?: time();

        $PLUGIN_HOOKS['add_css']['assetmgrstatus']        = ['public/css/assetmgrstatus.css?v=' . $css_version];
        $PLUGIN_HOOKS['add_javascript']['assetmgrstatus'] = ['public/js/assetmgrstatus.js?v=' . $js_version];

        if (Session::haveRight('plugin_assetmgrstatus', READ)) {
            $PLUGIN_HOOKS['menu_toadd']['assetmgrstatus'] = [
                'tools' => 'PluginAssetmgrstatusMenu',
            ];
        }
    }
}
