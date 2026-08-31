<?php
// Endpoint assinatura — aceita JSON ou FormData, sempre retorna JSON (nunca HTML 403)
ini_set('display_errors', '0');
error_reporting(E_ALL);

// NÃO envia header antes do include — copia do padrão diario_save.php que funciona
try {
    include('../../../inc/includes.php');
} catch (Throwable $e) {
    // Se falhar include, ainda tenta retornar JSON
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Falha ao carregar GLPI: ' . $e->getMessage()]);
    exit;
}
if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');

try {
    // Garante colunas (auto-migração)
    try {
        $hookFile = GLPI_ROOT . '/plugins/assetmgrstatus/hook.php';
        if (file_exists($hookFile)) {
            require_once $hookFile;
            if (function_exists('plugin_assetmgrstatus_schema')) @plugin_assetmgrstatus_schema();
        }
    } catch (Throwable $e) {
        error_log('[assetmgrstatus] assinatura_save schema: ' . $e->getMessage());
    }

    // Ping diagnóstico — GET ?ping=1 deve retornar JSON mesmo sem permissão completa
    if (($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'HEAD') && isset($_GET['ping'])) {
        echo json_encode(['ok' => true, 'ping' => 'pong', 'user' => Session::getLoginUserID(), 'hasAssinatura' => Session::haveRight('plugin_assetmgrstatus_assinatura', READ), 'hasTecnico' => Session::haveRight('plugin_assetmgrstatus_tecnico', READ), 'hasBasic' => Session::haveRight('plugin_assetmgrstatus', READ)]);
        exit;
    }

    // NÃO usa Session::checkLoginUser() — ele imprime HTML 403. Retorna JSON.
    $uid = Session::getLoginUserID();
    if (!$uid) {
        echo json_encode(['ok' => false, 'error' => 'Sessão expirada — faça login novamente no GLPI e recarregue a página (F5). Se persistir, limpe cookies.']);
        exit;
    }

    // Permissão ampliada (igual front/assinatura.php)
    $hasAssinatura = Session::haveRight('plugin_assetmgrstatus_assinatura', READ);
    $hasTecnico    = Session::haveRight('plugin_assetmgrstatus_tecnico', READ);
    $hasAdmin      = Session::haveRight('plugin_assetmgrstatus_admin', READ);
    $hasBasic      = Session::haveRight('plugin_assetmgrstatus', READ);
    if (!$hasAssinatura && !$hasTecnico && !$hasAdmin && !$hasBasic) {
        echo json_encode(['ok' => false, 'error' => 'Sem permissão (Assinatura). Perfil #' . (int)($_SESSION['glpiactiveprofile']['id'] ?? 0) . ' precisa de "Assinatura de Termos" (ou ao menos Acesso à Manutenção) em Administração > Perfis > Manutenção. Faça logout/login após alterar.']);
        exit;
    }

    // Lê payload: suporta JSON (application/json) e FormData (multipart/form-data / x-www-form-urlencoded)
    $data = null;
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    // Tenta JSON primeiro se content-type indicar json ou body parecer json
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $tmp = json_decode($raw, true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data) || empty($data)) {
        // Fallback FormData / POST
        if (!empty($_POST)) $data = $_POST;
    }
    // Caso cliente enviou JSON dentro de campo payload (legado)
    if (is_array($data) && isset($data['payload']) && is_string($data['payload'])) {
        $tmp = json_decode($data['payload'], true);
        if (is_array($tmp) && !empty($tmp)) $data = $tmp;
    }
    if (!is_array($data)) $data = [];

    $transfer_id = (int)($data['transfer_id'] ?? $data['id'] ?? 0);
    // Recebedor
    $doc_type    = strtoupper(trim($data['doc_type'] ?? $data['document_type'] ?? $data['doc_type_rec'] ?? $data['rec_doc_type'] ?? ''));
    $doc_number  = trim($data['doc_number'] ?? $data['document'] ?? $data['doc_number_rec'] ?? $data['rec_doc_number'] ?? '');
    $nome        = trim($data['nome'] ?? $data['name'] ?? $data['nome_rec'] ?? $data['rec_nome'] ?? '');
    $image       = trim($data['image'] ?? $data['assinatura_image'] ?? $data['image_rec'] ?? $data['rec_image'] ?? '');
    // Técnico (opcional — fluxo dual)
    $tec_doc_type   = strtoupper(trim($data['tec_doc_type'] ?? $data['tec_document_type'] ?? $data['doc_type_tec'] ?? $data['document_type_tec'] ?? ''));
    $tec_doc_number = trim($data['tec_doc_number'] ?? $data['tec_document'] ?? $data['doc_number_tec'] ?? $data['document_tec'] ?? '');
    $tec_nome       = trim($data['tec_nome'] ?? $data['tec_name'] ?? $data['nome_tec'] ?? $data['name_tec'] ?? '');
    $tec_image      = trim($data['tec_image'] ?? $data['assinatura_tecnico_image'] ?? $data['image_tec'] ?? $data['tec_assinatura_image'] ?? '');

    // FormData multipart pode truncar base64 com + sendo espaço — corrige
    if ($image !== '' && strpos($image, ' ') !== false && strpos($image, 'data:image/') === 0) $image = str_replace(' ', '+', $image);
    if ($tec_image !== '' && strpos($tec_image, ' ') !== false && strpos($tec_image, 'data:image/') === 0) $tec_image = str_replace(' ', '+', $tec_image);

    // Verifica se já tem recebedor para permitir salvar só técnico
    $hasRecAlready = false; $needsTec = false;
    if ($transfer_id) {
        try { $tmpTr = \GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_id); if($tmpTr){ $hasRecAlready = !empty($tmpTr['assinatura_image']); $needsTec = empty($tmpTr['assinatura_tecnico_image']); } } catch(Throwable $e) {}
    }
    if (!$hasRecAlready) {
        if (!$transfer_id || !$doc_type || !$doc_number || !$image) {
            echo json_encode(['ok' => false, 'error' => 'Dados recebedor incompletos (transfer_id/doc/image obrigatórios). Recebido: transfer=' . $transfer_id . ' doc_type=' . $doc_type . ' doc_len=' . strlen($doc_number) . ' img_len=' . strlen($image)]);
            exit;
        }
    } else {
        if (!$transfer_id) { echo json_encode(['ok'=>false,'error'=>'transfer_id obrigatório']); exit; }
        // preenche com dados existentes se vier vazio (evita falhar no salvarAssinatura)
        if (empty($doc_type) || empty($image)) {
            try { $ex=\GlpiPlugin\Assetmgrstatus\Transfer::getById($transfer_id); if($ex){ if(empty($doc_type)) $doc_type=$ex['assinatura_document_type']??'RG'; if(empty($doc_number)) $doc_number=$ex['assinatura_document']??'0'; if(empty($nome)) $nome=$ex['assinatura_nome']??''; if(empty($image)) $image=$ex['assinatura_image']??''; } } catch(Throwable $e){}
        }
    }
    $tecProvided = $tec_doc_type !== '' || $tec_doc_number !== '' || $tec_image !== '' || $tec_nome !== '';
    if ($hasRecAlready && $needsTec && !$tecProvided) {
        echo json_encode(['ok'=>false,'error'=>'Selecione o técnico responsável (assinatura do técnico obrigatória).']);
        exit;
    }
    if ($tecProvided && (!$tec_doc_type || !$tec_doc_number || !$tec_image)) {
        echo json_encode(['ok' => false, 'error' => 'Dados técnico incompletos (doc_type/doc_number/image). Se enviar técnico precisa todos.']);
        exit;
    }

    $ok = false;
    $errDetail = '';
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
        error_log('[assetmgrstatus] salvarAssinatura exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        echo json_encode(['ok' => false, 'error' => 'Erro interno ao salvar: ' . $e->getMessage()]);
        exit;
    }

    if ($ok) {
        echo json_encode(['ok' => true]);
    } else {
        $err = 'Falha ao salvar assinatura. Verifique se o termo existe, não está cancelado/já assinado e se o documento está correto (CPF 11 dígitos, RG 5-12).';
        if ($errDetail) $err .= ' Detalhe: ' . $errDetail;
        global $DB;
        if (isset($DB) && $DB->error()) {
            $dbErr = $DB->error();
            if (stripos($dbErr, 'Unknown column') !== false || stripos($dbErr, 'assinatura') !== false) {
                $err .= ' (colunas de assinatura ainda não criadas — atualize o plugin para 2.0.2 em Configurar > Plugins > Verificar/Atualizar)';
            }
        }
        echo json_encode(['ok' => false, 'error' => $err]);
    }
} catch (Throwable $e) {
    error_log('[assetmgrstatus] assinatura_save fatal: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
}
