<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus', READ)) {
    echo json_encode(['can_undo'=>false]); exit;
}
header('Content-Type: application/json');

$itemtype = $_GET['itemtype'] ?? '';
$items_id = (int)($_GET['items_id'] ?? 0);

if (!$itemtype || !$items_id) {
    echo json_encode(['can_undo' => false]);
    exit;
}

$change = MaintenanceRecord::getUndoableChange($itemtype, $items_id);

echo json_encode([
    'can_undo' => $change !== null,
    'hours_left' => $change ? round(48 - ((time() - strtotime($change['date_creation'])) / 3600), 1) : null,
]);
