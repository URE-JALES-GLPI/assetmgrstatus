<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$itemtype = $_GET['itemtype'] ?? '';
$items_id = (int)($_GET['items_id'] ?? 0);

if (!$itemtype || !$items_id) {
    echo json_encode(null);
    exit;
}

$iter = $DB->request([
    'FROM'  => 'glpi_plugin_assetmgrstatus_records',
    'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id],
    'LIMIT' => 1,
]);

if ($iter->count() === 0) {
    echo json_encode(null);
    exit;
}

$row = $iter->current();

echo json_encode([
    'status'     => $row['am_status'],
    'reason'     => $row['reason'],
    'components' => $row['components'] ? json_decode($row['components'], true) : [],
    'expected_return_date' => $row['expected_return_date'] ?? null,
]);
