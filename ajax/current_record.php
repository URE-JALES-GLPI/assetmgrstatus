<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();

header('Content-Type: application/json');

global $DB;

$itemtype = $_GET['itemtype'] ?? '';
$items_id = (int)($_GET['items_id'] ?? ($_GET['id'] ?? 0));

if (!$items_id) {
    echo json_encode(null);
    exit;
}

$where = ['items_id' => $items_id];
if ($itemtype) $where['itemtype'] = $itemtype;
$iter = $DB->request([
    'FROM'  => 'glpi_plugin_assetmgrstatus_records',
    'WHERE' => $where,
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
    'transfer_status' => $row['transfer_status'] ?? null,
    'transfers_id'    => $row['transfers_id'] ?? null,
]);
