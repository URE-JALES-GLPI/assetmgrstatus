<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ)) {
    echo json_encode(['hash' => '', 'count' => 0]);
    exit;
}

header('Content-Type: application/json');

$filter_status = $_GET['status'] ?? '';
$filter_tech   = (int)($_GET['tech'] ?? 0);
$filter_date   = $_GET['date'] ?? '';
$filter_sort   = $_GET['sort'] ?? 'recent';

$transfers = Transfer::getAll($filter_status);

// Filtra por técnico
if ($filter_tech) {
    $transfers = array_filter($transfers, fn($t) => (int)$t['users_id_tech'] === $filter_tech);
    $transfers = array_values($transfers);
}
// Filtra por data
if ($filter_date) {
    $transfers = array_filter($transfers, fn($t) => date('Y-m-d', strtotime($t['date_creation'])) === $filter_date);
    $transfers = array_values($transfers);
}
// Ordenação
if ($filter_sort === 'old') {
    usort($transfers, fn($a, $b) => strtotime($a['date_creation']) <=> strtotime($b['date_creation']));
} else {
    usort($transfers, fn($a, $b) => strtotime($b['date_creation']) <=> strtotime($a['date_creation']));
}

// Monta hash leve: id + status + datas + tech
$parts = [];
foreach ($transfers as $t) {
    $parts[] = $t['id'] . ':' . $t['status'] . ':' . ($t['date_creation'] ?? '') . ':' . ($t['date_manutencao'] ?? '') . ':' . ($t['date_pronto'] ?? '') . ':' . ($t['date_finalizado'] ?? '') . ':' . ($t['date_cancelado'] ?? '') . ':' . ($t['users_id_tech'] ?? 0);
}
$hash = md5(implode('|', $parts));
$count = count($transfers);

// Também retorna timestamp da última alteração para debug
$latest = '';
foreach ($transfers as $t) {
    $d = $t['date_creation'] ?? '';
    foreach (['date_manutencao','date_pronto','date_finalizado','date_cancelado'] as $k) {
        if (!empty($t[$k]) && $t[$k] > $d) $d = $t[$k];
    }
    if ($d > $latest) $latest = $d;
}

echo json_encode(['hash' => $hash, 'count' => $count, 'latest' => $latest]);
