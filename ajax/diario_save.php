<?php
include('../../../inc/includes.php');
Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ)) { echo json_encode(['ok'=>false, 'error'=>'Sem permissão']); exit; }
global $DB;
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? '';
$item_id = (int)($data['item_id'] ?? 0);
$transfer_id = (int)($data['transfer_id'] ?? 0);
$log = trim($data['log'] ?? '');
$components = $data['components'] ?? [];
if (!$item_id || !$transfer_id) { echo json_encode(['ok'=>false]); exit; }
$item = $DB->request(['SELECT'=>['id'],'FROM'=>'glpi_plugin_assetmgrstatus_transfer_items','WHERE'=>['id'=>$item_id,'transfers_id'=>$transfer_id],'LIMIT'=>1])->current();
if (!$item) { echo json_encode(['ok'=>false]); exit; }
if ($action === 'save') {
    $DB->update('glpi_plugin_assetmgrstatus_transfer_items',['work_log'=>$log,'work_components'=>json_encode($components)],['id'=>$item_id]);
    echo json_encode(['ok'=>true]);
} elseif ($action === 'done') {
    $DB->update('glpi_plugin_assetmgrstatus_transfer_items',['work_log'=>$log,'work_components'=>json_encode($components),'work_status'=>'done'],['id'=>$item_id]);
    echo json_encode(['ok'=>true]);
} elseif ($action === 'reopen') {
    $DB->update('glpi_plugin_assetmgrstatus_transfer_items',['work_status'=>'pending'],['id'=>$item_id]);
    echo json_encode(['ok'=>true]);
} else { echo json_encode(['ok'=>false]); }
