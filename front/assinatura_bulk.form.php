<?php
// Front handler assinatura em lote — espelho de ajax/assinatura_bulk_save.php via front/ (fallback CSRF)
ini_set('display_errors', '0');
error_reporting(E_ALL);

try {
    include('../../../inc/includes.php');
} catch (Throwable $e) {
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Falha ao carregar GLPI: ' . $e->getMessage()]);
    exit;
}
if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');

try {
    try {
        $hookFile = GLPI_ROOT . '/plugins/assetmgrstatus/hook.php';
        if (file_exists($hookFile)) { require_once $hookFile; if (function_exists('plugin_assetmgrstatus_schema')) @plugin_assetmgrstatus_schema(); }
    } catch (Throwable $e) { error_log('[assetmgrstatus] bulk.form schema: '.$e->getMessage()); }

    if (isset($_GET['ping'])) {
        echo json_encode(['ok'=>true,'ping'=>'pong','via'=>'front','user'=>Session::getLoginUserID()]);
        exit;
    }

    $uid = Session::getLoginUserID();
    if (!$uid) { echo json_encode(['ok'=>false,'error'=>'Sessão expirada']); exit; }
    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) { echo json_encode(['ok'=>false,'error'=>'Sem permissão (Assinatura)']); exit; }

    $data = null;
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) { $tmp = json_decode($raw, true); if (is_array($tmp) && !empty($tmp)) $data = $tmp; }
    if (!is_array($data) || empty($data)) { if (!empty($_POST)) $data = $_POST; elseif (!empty($_REQUEST)) $data = $_REQUEST; }
    if (is_array($data) && isset($data['payload']) && is_string($data['payload'])) { $tmp=json_decode($data['payload'],true); if(is_array($tmp)&&!empty($tmp)) $data=$tmp; }
    if (!is_array($data)) $data = [];

    $transfer_ids = $data['transfer_ids'] ?? $data['transferIds'] ?? $data['ids'] ?? $data['transfer_id'] ?? $data['id'] ?? [];
    if (is_string($transfer_ids)) {
        $s = trim($transfer_ids);
        if ($s !== '' && $s[0] === '[') { $dec=json_decode($s,true); if(is_array($dec)) $transfer_ids=$dec; else $transfer_ids=array_map('trim', explode(',', trim($s,'[]'))); }
        else $transfer_ids = $s === '' ? [] : array_map('trim', explode(',', $s));
    }
    if (!is_array($transfer_ids)) $transfer_ids = [$transfer_ids];
    $transfer_ids = array_values(array_unique(array_filter(array_map(fn($v)=>(int)trim((string)$v), $transfer_ids), fn($v)=>$v>0)));
    if (empty($transfer_ids) && !empty($data['transfer_id'])) $transfer_ids=[(int)$data['transfer_id']];
    if (empty($transfer_ids)) { echo json_encode(['ok'=>false,'error'=>'Nenhuma transferência selecionada']); exit; }
    if (count($transfer_ids) < 2) { echo json_encode(['ok'=>false,'error'=>'Selecione pelo menos 2 transferências']); exit; }

    $doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
    $doc_number  = trim($data['doc_number'] ?? $data['document'] ?? '');
    $nome        = trim($data['nome'] ?? $data['name'] ?? '');
    $image       = trim($data['image'] ?? $data['assinatura_image'] ?? '');
    $tec_doc_type   = strtoupper(trim($data['tec_doc_type'] ?? $data['tec_document_type'] ?? ''));
    $tec_doc_number = trim($data['tec_doc_number'] ?? $data['tec_document'] ?? '');
    $tec_nome       = trim($data['tec_nome'] ?? $data['tec_name'] ?? '');
    $tec_image      = trim($data['tec_image'] ?? $data['assinatura_tecnico_image'] ?? '');
    if ($image !== '' && strpos($image, ' ') !== false && strpos($image, 'data:image/')===0) $image=str_replace(' ','+',$image);
    if ($tec_image !== '' && strpos($tec_image,' ')!==false && strpos($tec_image,'data:image/')===0) $tec_image=str_replace(' ','+',$tec_image);

    $hasRecAlreadyAll=true; $needsTecAny=false;
    foreach($transfer_ids as $tid){ try{ $tr=\GlpiPlugin\Assetmgrstatus\Transfer::getById($tid); if($tr){ if(empty($tr['assinatura_image'])) $hasRecAlreadyAll=false; if(empty($tr['assinatura_tecnico_image'])) $needsTecAny=true; } else $hasRecAlreadyAll=false; }catch(Throwable $e){ $hasRecAlreadyAll=false; } }
    if(!$hasRecAlreadyAll){ if(!$doc_type||!$doc_number||!$image){ echo json_encode(['ok'=>false,'error'=>'Dados recebedor incompletos']); exit; } }
    else { if(empty($doc_type)||empty($image)){ try{ $ex=\GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_ids[0]); if($ex){ if(empty($doc_type)) $doc_type=$ex['assinatura_document_type']??'RG'; if(empty($doc_number)) $doc_number=$ex['assinatura_document']??'0'; if(empty($nome)) $nome=$ex['assinatura_nome']??''; if(empty($image)) $image=$ex['assinatura_image']??''; } }catch(Throwable $e){} } }
    $tecProvided=$tec_doc_type!==''||$tec_doc_number!==''||$tec_image!==''||$tec_nome!=='';
    if($hasRecAlreadyAll && $needsTecAny && !$tecProvided){ echo json_encode(['ok'=>false,'error'=>'Selecione o técnico']); exit; }
    if($tecProvided && (!$tec_doc_type||!$tec_doc_number||!$tec_image)){ echo json_encode(['ok'=>false,'error'=>'Dados técnico incompletos']); exit; }

    $res=\GlpiPlugin\Assetmgrstatus\Transfer::salvarAssinaturaBulk(
        $transfer_ids, $doc_type, $doc_number, $nome, $image,
        $tec_doc_type!==''?$tec_doc_type:null,
        $tec_doc_number!==''?$tec_doc_number:null,
        $tec_nome!==''?$tec_nome:null,
        $tec_image!==''?$tec_image:null
    );
    if($res['ok']) echo json_encode(['ok'=>true,'results'=>$res['results']]);
    else echo json_encode(['ok'=>false,'error'=>$res['error']??'Falha','results'=>$res['results']??[]]);
} catch (Throwable $e) {
    error_log('[assetmgrstatus] bulk.form fatal: '.$e->getMessage());
    if(!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
}
