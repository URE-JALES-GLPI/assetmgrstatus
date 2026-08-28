<?php
// Front handler for assinatura — alternative to ajax (more permissive for GLPI CSRF/front routing)
ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=UTF-8');

try {
    include('../../../inc/includes.php');

    // Garante colunas
    try {
        $hookFile = GLPI_ROOT . '/plugins/assetmgrstatus/hook.php';
        if (file_exists($hookFile)) {
            require_once $hookFile;
            if (function_exists('plugin_assetmgrstatus_schema')) @plugin_assetmgrstatus_schema();
        }
    } catch (Throwable $e) {}

    // Ping
    if (isset($_GET['ping'])) {
        echo json_encode(['ok'=>true,'ping'=>'pong','user'=>Session::getLoginUserID(),'hasAssinatura'=>Session::haveRight('plugin_assetmgrstatus_assinatura', READ)]);
        exit;
    }

    if (!Session::getLoginUserID()) {
        echo json_encode(['ok'=>false,'error'=>'Sessão expirada — faça login novamente']);
        exit;
    }
    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) {
        echo json_encode(['ok'=>false,'error'=>'Sem permissão (Assinatura). Ative em Administração > Perfis > Manutenção > Assinatura de Termos']);
        exit;
    }

    // Aceita JSON ou form-encoded
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!$data) $data = $_POST;
    if (isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if ($tmp) $data = $tmp;
    }

    $transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? 0);
    $doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
    $doc_number  = trim($data['doc_number'] ?? $data['document'] ?? '');
    $nome        = trim($data['nome'] ?? $data['name'] ?? '');
    $image       = trim($data['image'] ?? $data['assinatura_image'] ?? '');

    if (!$transfer_id || !$doc_type || !$doc_number || !$image) {
        echo json_encode(['ok'=>false,'error'=>'Dados incompletos']);
        exit;
    }

    $ok = false;
    $errDetail = '';
    try {
        $ok = \GlpiPlugin\Assetmgrstatus\Transfer::salvarAssinatura($transfer_id, $doc_type, $doc_number, $nome, $image);
        if (!$ok && \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error) $errDetail = \GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error;
    } catch (Throwable $e) {
        error_log('[assetmgrstatus] front assinatura.form salvar: '.$e->getMessage());
        echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
        exit;
    }

    if ($ok) {
        echo json_encode(['ok'=>true]);
    } else {
        $err = 'Falha ao salvar assinatura. Verifique se o termo existe, não está cancelado/já assinado e documento correto (CPF 11, RG 5-12).';
        if ($errDetail) $err .= ' Detalhe: '.$errDetail;
        global $DB;
        if (isset($DB) && $DB->error() && (stripos($DB->error(),'Unknown column')!==false)) {
            $err .= ' (colunas ainda não criadas — atualize o plugin para 2.0.2)';
        }
        echo json_encode(['ok'=>false,'error'=>$err]);
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] assinatura.form fatal: '.$e->getMessage());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok'=>false,'error'=>'Erro interno: '.$e->getMessage()]);
}
