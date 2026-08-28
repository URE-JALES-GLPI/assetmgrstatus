<?php
include('../../../inc/includes.php');
Session::checkLoginUser();
header('Content-Type: application/json');
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Sem permissão']);
    exit;
}
$type = $_GET['type'] ?? $_POST['type'] ?? '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$type || !$id) {
    echo json_encode(['success'=>false,'message'=>'Parâmetros inválidos']);
    exit;
}
try {
    if ($type === 'ticket') {
        $ticket = new Ticket();
        if (!$ticket->getFromDB($id)) throw new Exception('Chamado não encontrado');
        $f = $ticket->fields;
        // Categoria
        $catName = '';
        if (!empty($f['itilcategories_id'])) {
            $cat = new ITILCategory();
            if ($cat->getFromDB($f['itilcategories_id'])) $catName = $cat->getField('completename') ?: $cat->getField('name');
        }
        // Entidade
        $entName = '';
        if (!empty($f['entities_id'])) {
            $ent = new Entity();
            if ($ent->getFromDB($f['entities_id'])) $entName = $ent->getField('completename') ?: $ent->getField('name');
        }
        // Atribuído
        $assigned = '';
        $assigned_id = 0;
        global $DB;
        $iter = $DB->request(['SELECT' => ['users_id'], 'FROM' => 'glpi_tickets_users', 'WHERE' => ['tickets_id' => $id, 'type' => 2], 'LIMIT' => 1]);
        if ($iter->count() > 0) {
            $assigned_id = (int)$iter->current()['users_id'];
            $u = new User();
            if ($u->getFromDB($assigned_id)) $assigned = $u->getName();
        }
        $statusLabel = Ticket::getStatus($f['status'] ?? 0) ?? $f['status'];
        $prioLabel = method_exists('Ticket','getPriorityName') ? Ticket::getPriorityName($f['priority'] ?? 3) : $f['priority'];
        $content = $f['content'] ?? '';
        // Strip tags for preview but keep original for modal (allow html safe)
        $contentHtml = nl2br(htmlspecialchars($content));
        echo json_encode([
            'success'=>true,
            'type'=>'ticket',
            'data'=>[
                'id' => $id,
                'name' => $f['name'] ?? 'Sem título',
                'category' => $catName ?: 'Sem categoria',
                'entity' => $entName,
                'status' => (int)($f['status'] ?? 0),
                'status_label' => $statusLabel,
                'priority' => $prioLabel,
                'assigned' => $assigned,
                'assigned_id' => $assigned_id,
                'date' => $f['date'] ?? '',
                'date_mod' => $f['date_mod'] ?? '',
                'content' => $content,
                'content_html' => $contentHtml,
                'urgency' => $f['urgency'] ?? '',
                'impact' => $f['impact'] ?? '',
                'itilcategories_id' => (int)($f['itilcategories_id'] ?? 0),
            ]
        ]);
        exit;
    } elseif ($type === 'transfer') {
        $transfer = \GlpiPlugin\Assetmgrstatus\Transfer::getById($id);
        if (!$transfer) throw new Exception('Transferência não encontrada');
        $items = \GlpiPlugin\Assetmgrstatus\Transfer::getItems($id);
        $timeline = \GlpiPlugin\Assetmgrstatus\Transfer::getTimeline($id);
        // Adiciona nomes de status
        $statusLabel = \GlpiPlugin\Assetmgrstatus\Transfer::getStatusOptions()[$transfer['status']] ?? $transfer['status'];
        // Busca entidade nomes se necessário (já tem origin_entity_name e entity_dest_name via getAll? Mas getById não traz; usa items)
        $destName = '';
        if (!empty($transfer['entity_dest'])) {
            $ent = new Entity();
            if ($ent->getFromDB($transfer['entity_dest'])) $destName = $ent->getField('name') ?: $ent->getField('completename');
        }
        $originName = '';
        if (!empty($items)) {
            $first = reset($items);
            $originName = $first['origin_entity_name'] ?? '';
        }
        echo json_encode([
            'success'=>true,
            'type'=>'transfer',
            'data'=>[
                'id' => $id,
                'status' => $transfer['status'],
                'status_label' => $statusLabel,
                'status_color' => \GlpiPlugin\Assetmgrstatus\Transfer::getStatusColor($transfer['status']),
                'origin' => $originName,
                'dest' => $destName ?: 'URE',
                'reason' => $transfer['reason'] ?? '',
                'date_creation' => $transfer['date_creation'] ?? '',
                'date_pending' => $transfer['date_pending'] ?? '',
                'date_manutencao' => $transfer['date_manutencao'] ?? '',
                'date_pronto' => $transfer['date_pronto'] ?? '',
                'date_finalizado' => $transfer['date_finalizado'] ?? '',
                'date_cancelado' => $transfer['date_cancelado'] ?? '',
                'tech' => \GlpiPlugin\Assetmgrstatus\Transfer::getUserName((int)($transfer['users_id_tech'] ?? 0)),
                'creator' => \GlpiPlugin\Assetmgrstatus\Transfer::getUserName((int)($transfer['users_id_created'] ?? 0)),
                'tickets_id' => (int)($transfer['tickets_id'] ?? 0),
                'items' => array_map(function($it){ return ['id'=>$it['items_id'], 'name'=>$it['item_name'], 'type'=>$it['itemtype'], 'final_status'=>$it['final_status'] ?? '', 'final_reason'=>$it['final_reason'] ?? '']; }, $items),
                'items_count' => count($items),
                'timeline' => array_slice($timeline, 0, 10),
            ]
        ]);
        exit;
    } else {
        throw new Exception('Tipo inválido');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}
