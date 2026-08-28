<?php
ini_set('display_errors','0'); error_reporting(E_ALL);
try { include('../../../inc/includes.php'); } catch (Throwable $e) { if(!headers_sent()) header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['ok'=>false,'error'=>'GLPI load: '.$e->getMessage()]); exit; }
if(!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
use GlpiPlugin\Assetmgrstatus\Transfer;
try {
    try { $f=GLPI_ROOT.'/plugins/assetmgrstatus/hook.php'; if(file_exists($f)){ require_once $f; if(function_exists('plugin_assetmgrstatus_schema')) @plugin_assetmgrstatus_schema(); } } catch(Throwable $e){}
    $uid = Session::getLoginUserID();
    if(!$uid){ echo json_encode(['ok'=>false,'error'=>'Sessão expirada']); exit; }
    $has = Session::haveRight('plugin_assetmgrstatus_assinatura', READ) || Session::haveRight('plugin_assetmgrstatus_tecnico', READ) || Session::haveRight('plugin_assetmgrstatus_admin', READ) || Session::haveRight('plugin_assetmgrstatus', READ);
    if(!$has){ echo json_encode(['ok'=>false,'error'=>'Sem permissão (Técnicos)']); exit; }

    $method = $_SERVER['REQUEST_METHOD'];
    // Lista
    if($method==='GET' && (!isset($_POST['action']) && !isset($_GET['action']) || ($_GET['action']??'')==='list')){
        $list = Transfer::getTecnicosAssinaturas(true);
        // força array_values para nunca virar objeto JSON (evita bug F5 some)
        $list = array_values($list);
        echo json_encode(['ok'=>true,'data'=>$list]);
        exit;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if(!is_array($data) || empty($data)) $data = $_POST;
    if(isset($data['payload']) && is_string($data['payload'])){ $tmp=json_decode($data['payload'],true); if(is_array($tmp)) $data=$tmp; }
    $action = trim($data['action'] ?? $_GET['action'] ?? $_POST['action'] ?? '');
    if($action==='list'){
        $list = Transfer::getTecnicosAssinaturas(true);
        $list = array_values($list);
        echo json_encode(['ok'=>true,'data'=>$list]);
        exit;
    }
    if($action==='delete' || $action==='remove'){
        $id = (int)($data['id'] ?? 0);
        if(!$id){ echo json_encode(['ok'=>false,'error'=>'ID obrigatório']); exit; }
        $ok = Transfer::deleteTecnicoAssinatura($id);
        echo json_encode(['ok'=>$ok,'error'=> $ok ? null : Transfer::$last_ticket_error]);
        exit;
    }
    if($action==='add' || $action==='create' || $action==='save'){
        $name = trim($data['name'] ?? $data['nome'] ?? '');
        $doc_type = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
        $doc_number = trim($data['doc_number'] ?? $data['document'] ?? '');
        $image = trim($data['image'] ?? $data['assinatura_image'] ?? '');
        if($image!=='' && strpos($image,' ')!==false && strpos($image,'data:image/')===0) $image=str_replace(' ','+',$image);
        $id = Transfer::addTecnicoAssinatura($name, $doc_type, $doc_number, $image);
        if($id) echo json_encode(['ok'=>true,'id'=>$id]);
        else echo json_encode(['ok'=>false,'error'=>Transfer::$last_ticket_error ?: 'Falha']);
        exit;
    }
    if($action==='edit' || $action==='update'){
        $id = (int)($data['id'] ?? 0);
        $name = trim($data['name'] ?? $data['nome'] ?? '');
        $doc_type = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
        $doc_number = trim($data['doc_number'] ?? $data['document'] ?? '');
        $image = trim($data['image'] ?? $data['assinatura_image'] ?? '');
        if($image!=='' && strpos($image,' ')!==false && strpos($image,'data:image/')===0) $image=str_replace(' ','+',$image);
        if(!$id){ echo json_encode(['ok'=>false,'error'=>'ID obrigatório']); exit; }
        $ok = Transfer::updateTecnicoAssinatura($id, $name, $doc_type, $doc_number, $image);
        echo json_encode(['ok'=>$ok,'error'=> $ok ? null : Transfer::$last_ticket_error]);
        exit;
    }
    echo json_encode(['ok'=>false,'error'=>'Ação inválida']);
} catch(Throwable $e){
    error_log('[assetmgrstatus] tecnico_signature: '.$e->getMessage());
    if(!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'Erro: '.$e->getMessage()]);
}
