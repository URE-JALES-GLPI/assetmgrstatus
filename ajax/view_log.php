<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus', READ) && !Session::haveRight('plugin_assetmgrstatus_tecnico', READ)) {
    echo json_encode(null); exit;
}
header('Content-Type: application/json');

$itemtype = $_GET['itemtype'] ?? '';
$items_id = (int)($_GET['items_id'] ?? 0);

if (!$itemtype || !$items_id) {
    echo json_encode(null); exit;
}

// Registra a visualização
MaintenanceRecord::logView($itemtype, $items_id);

// Retorna dados para o modal
echo json_encode([
    'views'    => MaintenanceRecord::getRecentViews($itemtype, $items_id, 5),
    'timeline' => MaintenanceRecord::getMiniTimeline($itemtype, $items_id, 6),
    'photo'    => MaintenanceRecord::getAssetPhotoUrl($itemtype, $items_id),
]);
