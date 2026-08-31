<?php
// Front handler assinatura — espelha ajax/assinatura_save.php mas via front/ (fallback)
// Sempre retorna JSON, nunca HTML 403, suporta JSON e FormData
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
        if (file_exists($hookFile)) {
            require_once $hookFile;
            if (function_exists('plugin_assetmgrstatus_schema')) @plugin_assetmgrstatus_schema();
        }
    } catch (Throwable $e) { error_log('[assetmgrstatus] assinatura.form schema: ' . $e->getMessage()); }

    if (isset($_GET['ping'])) {
        echo json_encode(['ok'=>true,'ping'=>'pong','via'=>'front','user'=>Session::getLoginUserID(),'hasAssinatura'=>Session::haveRight('plugin_assetmgrstatus_assinatura', READ),'hasTecnico'=>Session::haveRight('plugin_assetmgrstatus_tecnico', READ),'hasBasic'=>Session::haveRight('plugin_assetmgrstatus', READ)]);
        exit;
    }

    $uid = Session::getLoginUserID();
    if (!$uid) {
        echo json_encode(['ok'=>false,'error'=>'Sessão expirada — faça login novamente (front)']);
        exit;
    }
    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) {
        echo json_encode(['ok'=>false,'error'=>'Sem permissão (Assinatura) via front. Ative em Administração > Perfis > Manutenção > Assinatura de Termos']);
        exit;
    }

    $data = null;
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data) || empty($data)) {
        if (!empty($_POST)) $data = $_POST;
        elseif (!empty($_REQUEST)) $data = $_REQUEST;
    }
    if (is_array($data) && isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data)) $data = [];

    $transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? 0);
    $doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? $data['doc_type_rec'] ?? ''));
    $doc_number  = trim($data['doc_number'] ?? $data['document'] ?? $data['doc_number_rec'] ?? '');
    $nome        = trim($data['nome'] ?? $data['name'] ?? $data['nome_rec'] ?? '');
    $image       = trim($data['image'] ?? $data['assinatura_image'] ?? $data['image_rec'] ?? '');
    $tec_doc_type   = strtoupper(trim($data['tec_doc_type'] ?? $data['tec_document_type'] ?? $data['doc_type_tec'] ?? ''));
    $tec_doc_number = trim($data['tec_doc_number'] ?? $data['tec_document'] ?? $data['doc_number_tec'] ?? '');
    $tec_nome       = trim($data['tec_nome'] ?? $data['tec_name'] ?? $data['nome_tec'] ?? '');
    $tec_image      = trim($data['tec_image'] ?? $data['assinatura_tecnico_image'] ?? $data['image_tec'] ?? '');
    if ($image !== '' && strpos($image, ' ') !== false && strpos($image, 'data:image/') === 0) $image = str_replace(' ', '+', $image);
    if ($tec_image !== '' && strpos($tec_image, ' ') !== false && strpos($tec_image, 'data:image/') === 0) $tec_image = str_replace(' ', '+', $tec_image);

    $hasRecAlready=false; $needsTec=false;
    if($transfer_id){ try{ $tmpTr=\GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_id); if($tmpTr){ $hasRecAlready=!empty($tmpTr['assinatura_image']); $needsTec=empty($tmpTr['assinatura_tecnico_image']); } }catch(Throwable $e){} }
    if(!$hasRecAlready){
        if (!$transfer_id || !$doc_type || !$doc_number || !$image) {
            echo json_encode(['ok'=>false,'error'=>'Dados recebedor incompletos (front). Recebido: transfer=' . $transfer_id . ' doc_type=' . $doc_type]);
            exit;
        }
    } else {
        if(!$transfer_id){ echo json_encode(['ok'=>false,'error'=>'transfer_id obrigatório']); exit; }
        if(empty($doc_type)||empty($image)){
            try{ $ex=\GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_id); if($ex){ if(empty($doc_type)) $doc_type=$ex['assinatura_document_type']??'RG'; if(empty($doc_number)) $doc_number=$ex['assinatura_document']??'0'; if(empty($nome)) $nome=$ex['assinatura_nome']??''; if(empty($image)) $image=$ex['assinatura_image']??''; } }catch(Throwable $e){}
        }
    }
    $tecProvided = $tec_doc_type !== '' || $tec_doc_number !== '' || $tec_image !== '' || $tec_nome !== '';
    if($hasRecAlready && $needsTec && !$tecProvided){
        echo json_encode(['ok'=>false,'error'=>'Selecione o técnico responsável (assinatura do técnico obrigatória).']);
        exit;
    }
    if ($tecProvided && (!$tec_doc_type || !$tec_doc_number || !$tec_image)) {
        echo json_encode(['ok'=>false,'error'=>'Dados técnico incompletos (front)']);
        exit;
    }

    $ok = false; $errDetail='';
    try {
        $ok = \GlpiPlugin\Assetmgrstatus\Transfer::salvarAssinatura(
            $transfer_id, $doc_type, $doc_number, $nome, $image,
            $tec_doc_type !== '' ? $tec_doc_type : null,
            $tec_doc_number !== '' ? $tec_doc_number : null,
            $tec_nome !== '' ? $tec_nome : null,
            $tec_image !== '' ? $tec_image : null
        );
        if (!$ok && \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error) $errDetail = \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error;
    } catch (Throwable $e) {
        error_log('[assetmgrstatus] front assinatura.form salvar: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
        exit;
    }

    if ($ok) {
        echo json_encode(['ok'=>true]);
    } else {
        $err = 'Falha ao salvar assinatura (front).';
        if ($errDetail) $err .= ' Detalhe: '.$errDetail;
        global $DB;
        if (isset($DB) && $DB->error() && (stripos($DB->error(),'Unknown column')!==false)) $err .= ' (colunas ainda não criadas — atualize para 2.0.2)';
        echo json_encode(['ok'=>false,'error'=>$err]);
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] assinatura.form fatal: '.$e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
}
