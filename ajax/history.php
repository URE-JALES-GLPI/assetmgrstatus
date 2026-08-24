<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;

Session::checkLoginUser();

header('Content-Type: application/json');

$itemtype = $_GET['itemtype'] ?? '';
$items_id = (int)($_GET['items_id'] ?? 0);

if (!$itemtype || !$items_id) {
    echo json_encode([]);
    exit;
}

$history   = MaintenanceRecord::getHistory($itemtype, $items_id, 3);
$comp_list = MaintenanceRecord::getComponents();
$result    = [];

foreach ($history as $h) {
    $u     = new User();
    $uname = ($h['users_id'] && $u->getFromDB($h['users_id'])) ? $u->getName() : 'Sistema';
    $rt    = $h['record_type'] ?? MaintenanceRecord::RECORD_STATUS_CHANGE;
    $comps = $h['components'] ? json_decode($h['components'], true) : [];

    $comp_labels = [];
    foreach ($comps as $ck => $cd) {
        $comp_labels[] = ($comp_list[$ck] ?? $ck) . ($cd ? ': ' . $cd : '');
    }

    $result[] = [
        'record_type'        => $rt,
        'type_label'         => MaintenanceRecord::getRecordTypeLabel($rt),
        'border_color'       => MaintenanceRecord::getRecordTypeColor($rt),
        'status_new'         => $h['status_new'],
        'status_new_label'   => MaintenanceRecord::getStatusLabel($h['status_new']),
        'status_new_badge'   => MaintenanceRecord::getStatusBadgeClass($h['status_new']),
        'status_old'         => $h['status_old'],
        'status_old_label'   => $h['status_old'] ? MaintenanceRecord::getStatusLabel($h['status_old']) : null,
        'status_old_badge'   => $h['status_old'] ? MaintenanceRecord::getStatusBadgeClass($h['status_old']) : null,
        'description'        => $h['action_description'] ?: $h['reason'],
        'action_date'        => $h['action_date'] ? date('d/m/Y', strtotime($h['action_date'])) : null,
        'components'         => $comp_labels,
        'user'               => $uname,
        'date'               => date('d/m/Y H:i', strtotime($h['date_creation'])),
    ];
}

echo json_encode($result);
