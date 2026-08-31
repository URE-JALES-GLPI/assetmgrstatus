<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\UserEntityFilter;

Session::checkLoginUser();

if (!Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Sem permissão ADMIN']);
    exit;
}

// Aceita POST com entities JSON e recursive
$entities_raw = $_POST['entities'] ?? '[]';
$recursive = !empty($_POST['recursive']) && $_POST['recursive'] !== '0';

$entities = [];
if (is_string($entities_raw)) {
    $decoded = json_decode($entities_raw, true);
    if (is_array($decoded)) {
        $entities = $decoded;
    } else if ($entities_raw !== '' && $entities_raw !== '[]') {
        // fallback: tenta parse como string separada por vírgula
        $entities = array_filter(array_map('trim', explode(',', $entities_raw)));
    }
} elseif (is_array($entities_raw)) {
    $entities = $entities_raw;
}

$entities = array_values(array_filter(array_map('intval', $entities), fn($v) => $v >= 0));

try {
    UserEntityFilter::save(Session::getLoginUserID(), $entities, $recursive);
    $_SESSION['plugin_assetmgrstatus_entity'] = $entities;
    $_SESSION['plugin_assetmgrstatus_entity_recursive'] = $recursive ? 1 : 0;
    echo json_encode(['ok' => true, 'entities' => $entities, 'recursive' => $recursive ? 1 : 0]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
