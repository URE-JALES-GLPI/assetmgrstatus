<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_assinatura', READ) && !Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus_admin', READ)) {
    echo json_encode(['ok' => false, 'error' => 'Sem permissão (Assinatura)']);
    exit;
}
header('Content-Type: application/json');

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) $data = $_POST;

$transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? 0);
$doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? ''));
$doc_number  = trim($data['doc_number'] ?? $data['document'] ?? '');
$nome        = trim($data['nome'] ?? $data['name'] ?? '');
$image       = trim($data['image'] ?? $data['assinatura_image'] ?? '');

if (!$transfer_id || !$doc_type || !$doc_number || !$image) {
    echo json_encode(['ok' => false, 'error' => 'Dados incompletos']);
    exit;
}

// Validação adicional de CPF/RG é feita em Transfer::salvarAssinatura
$ok = Transfer::salvarAssinatura($transfer_id, $doc_type, $doc_number, $nome, $image);
if ($ok) {
    echo json_encode(['ok' => true]);
} else {
    $err = 'Falha ao salvar assinatura. Verifique se o termo existe, não está cancelado/ já assinado e se o documento está correto (CPF 11 dígitos, RG 5-12).';
    if (Transfer::$last_ticket_error) $err .= ' ' . Transfer::$last_ticket_error;
    echo json_encode(['ok' => false, 'error' => $err]);
}
