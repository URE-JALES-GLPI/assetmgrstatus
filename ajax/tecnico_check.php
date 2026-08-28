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
$q = trim($_GET['q'] ?? '');
$q_norm = $q !== '' ? mb_strtolower($q, 'UTF-8') : '';
$q_ascii = '';
if ($q_norm !== '') { $q_ascii = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$q_norm); if($q_ascii===false) $q_ascii=$q_norm; $q_ascii=mb_strtolower($q_ascii,'UTF-8'); }

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
// Filtra por entidade (q)
if ($q_norm !== '') {
    $transfers = array_values(array_filter($transfers, function($t) use ($q_norm,$q_ascii){
        $hay = ($t['origin_entity_name'] ?? '') . ' ' . ($t['entity_dest_name'] ?? '') . ' #' . $t['id'] . ' ' . ($t['reason'] ?? '');
        $hay_low = mb_strtolower($hay,'UTF-8');
        if(mb_strpos($hay_low,$q_norm)!==false) return true;
        $hay_ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$hay); if($hay_ascii===false) $hay_ascii=$hay;
        $hay_ascii=mb_strtolower($hay_ascii,'UTF-8');
        return mb_strpos($hay_ascii,$q_ascii)!==false;
    }));
}
// Ordenação
if ($filter_sort === 'old') {
    usort($transfers, fn($a, $b) => strtotime($a['date_creation']) <=> strtotime($b['date_creation']));
} else {
    usort($transfers, fn($a, $b) => strtotime($b['date_creation']) <=> strtotime($a['date_creation']));
}

// Monta hash leve: id + status + datas + tech + progresso + assinatura (para detectar assinatura sem F5)
$parts = [];
foreach ($transfers as $t) {
    $parts[] = $t['id'] . ':' . $t['status'] . ':' . ($t['date_creation'] ?? '') . ':' . ($t['date_manutencao'] ?? '') . ':' . ($t['date_pronto'] ?? '') . ':' . ($t['date_finalizado'] ?? '') . ':' . ($t['date_cancelado'] ?? '') . ':' . ($t['users_id_tech'] ?? 0) . ':' . ($t['items_done'] ?? 0) . ':' . ($t['progress_pct'] ?? 0) . ':' . ($t['items_count'] ?? 0) . ':' . ($t['assinatura_data'] ?? '') . ':' . ($t['assinatura_tecnico_data'] ?? '') . ':' . (!empty($t['assinatura_image']) ? '1' : '0') . ':' . (!empty($t['assinatura_tecnico_image']) ? '1' : '0');
}
$hash = md5(implode('|', $parts));
$count = count($transfers);

// Também retorna timestamp da última alteração para debug (inclui assinatura)
$latest = '';
foreach ($transfers as $t) {
    $d = $t['date_creation'] ?? '';
    foreach (['date_manutencao','date_pronto','date_finalizado','date_cancelado','assinatura_data','assinatura_tecnico_data'] as $k) {
        if (!empty($t[$k]) && $t[$k] > $d) $d = $t[$k];
    }
    if ($d > $latest) $latest = $d;
}

echo json_encode(['hash' => $hash, 'count' => $count, 'latest' => $latest]);
