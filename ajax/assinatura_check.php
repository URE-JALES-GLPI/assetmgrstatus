<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_assinatura', READ) && !Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus_admin', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    echo json_encode(['hash' => '', 'count' => 0, 'tec_count' => 0]);
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$filter = $_GET['f'] ?? 'pendente';
if (!in_array($filter, ['pendente','assinado','todos'], true)) $filter = 'pendente';

$all = Transfer::getAll();
$pendentes = array_values(array_filter($all, fn($t) => Transfer::precisaAssinatura($t)));
$assinados = array_values(array_filter($all, fn($t) => Transfer::isAssinado($t)));

if ($filter === 'pendente') $transfers = $pendentes;
elseif ($filter === 'assinado') $transfers = $assinados;
else $transfers = $all;

if ($filter === 'pendente') {
    usort($transfers, fn($a,$b) => strtotime($a['date_creation']) <=> strtotime($b['date_creation']));
} else {
    usort($transfers, fn($a,$b) => strtotime($b['assinatura_data'] ?? $b['date_creation']) <=> strtotime($a['assinatura_data'] ?? $a['date_creation']));
}

// Hash inclui status + assinatura + progresso + tecnicos (para detectar novo tecnico ou assinatura sem F5)
$parts = [];
foreach ($transfers as $t) {
    $parts[] = $t['id'] . ':' . $t['status'] . ':' . ($t['date_creation'] ?? '') . ':' . ($t['date_pronto'] ?? '') . ':' . ($t['date_finalizado'] ?? '') . ':' . ($t['assinatura_data'] ?? '') . ':' . ($t['assinatura_tecnico_data'] ?? '') . ':' . (!empty($t['assinatura_image']) ? '1' : '0') . ':' . (!empty($t['assinatura_tecnico_image']) ? '1' : '0') . ':' . ($t['items_count'] ?? 0);
}
$tecList = Transfer::getTecnicosAssinaturas(true);
$tec_count = count($tecList);
$parts[] = 'tec:' . $tec_count;
// hash leve dos ids dos tecnicos para detectar exclusao/cadastro sem mudar count (ex: troca nome)
$tecIds = implode(',', array_column($tecList, 'id'));
$parts[] = 'tec_ids:' . $tecIds;

$hash = md5(implode('|', $parts));
$count = count($transfers);

$latest = '';
foreach ($transfers as $t) {
    $d = $t['date_creation'] ?? '';
    foreach (['date_pronto','date_finalizado','assinatura_data','assinatura_tecnico_data'] as $k) {
        if (!empty($t[$k]) && $t[$k] > $d) $d = $t[$k];
    }
    if ($d > $latest) $latest = $d;
}

echo json_encode(['hash' => $hash, 'count' => $count, 'tec_count' => $tec_count, 'latest' => $latest]);
