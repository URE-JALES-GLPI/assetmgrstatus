<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\Transfer;
use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ)) {
    http_response_code(403);
    echo json_encode(['error' => 'Sem permissão']);
    exit;
}

header('Content-Type: application/json');

$transfer_id = (int)($_GET['id'] ?? 0);
if (!$transfer_id) {
    echo json_encode([]);
    exit;
}

$transfer = Transfer::getById($transfer_id);
if (!$transfer) {
    echo json_encode([]);
    exit;
}

$items = Transfer::getItems($transfer_id);
$result = [];
foreach ($items as $it) {
    $label = MaintenanceRecord::getStatusLabel($it['final_status'] ?? '');
    $result[] = [
        'id'           => (int)$it['items_id'],
        'transfer_item_id' => (int)$it['id'],
        'item_name'    => $it['item_name'],
        'itemtype'     => $it['itemtype'],
        'type_label'   => str_replace(['Glpi\\CustomAsset\\','Asset'], '', $it['itemtype']),
        'final_status' => $it['final_status'] ?? '',
        'final_status_label' => $label ?: ($it['final_status'] ?? '—'),
        'final_reason' => $it['final_reason'] ?? '',
        'origin_entity_name' => $it['origin_entity_name'] ?? '',
        'work_status'  => $it['work_status'] ?? 'pending',
    ];
}

echo json_encode($result);
