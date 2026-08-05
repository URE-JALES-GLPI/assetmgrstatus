<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$itemtype = $_GET['itemtype'] ?? '';
$items_id = (int)($_GET['items_id'] ?? 0);

if (!$itemtype || !$items_id) {
    echo json_encode(null); exit;
}

$change = MaintenanceRecord::getUndoableChange($itemtype, $items_id);
if (!$change) {
    echo json_encode(['can_undo' => false]); exit;
}

$comp_list = MaintenanceRecord::getComponents();

// Estado atual
$cur = $DB->request([
    'FROM'  => 'glpi_plugin_assetmgrstatus_records',
    'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
    'LIMIT' => 1,
])->current();

$cur_comps = $cur && $cur['components'] ? json_decode($cur['components'], true) : [];
$prev_comps = $change['prev_components'] ? json_decode($change['prev_components'], true) : [];

// Monta labels dos componentes
$cur_comp_labels  = array_map(fn($k) => $comp_list[$k] ?? $k, array_keys($cur_comps));
$prev_comp_labels = array_map(fn($k) => $comp_list[$k] ?? $k, array_keys($prev_comps));

echo json_encode([
    'can_undo'    => true,
    'hours_left'  => round(48 - ((time() - strtotime($change['date_creation'])) / 3600), 1),
    'current' => [
        'status'     => MaintenanceRecord::getStatusLabel($cur['am_status'] ?? MaintenanceRecord::STATUS_ESTOQUE),
        'status_key' => $cur['am_status'] ?? MaintenanceRecord::STATUS_ESTOQUE,
        'reason'     => $cur['reason'] ?? '',
        'components' => $cur_comp_labels,
    ],
    'previous' => [
        'status'     => MaintenanceRecord::getStatusLabel($change['status_old'] ?? MaintenanceRecord::STATUS_ESTOQUE),
        'status_key' => $change['status_old'] ?? MaintenanceRecord::STATUS_ESTOQUE,
        'reason'     => $change['prev_reason'] ?? '',
        'components' => $prev_comp_labels,
    ],
]);
