<?php
include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json');

if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ)) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Sem permissão']);
    exit;
}

$type = $_POST['type'] ?? '';
$id   = (int)($_POST['id'] ?? 0);
$to   = $_POST['to'] ?? '';

if (!$type || !$id || !$to) {
    echo json_encode(['success'=>false,'message'=>'Parâmetros inválidos']);
    exit;
}

try {
    if ($type === 'transfer') {
        // Mapeia coluna kanban para status GLPI
        $map = [
            'pendente'   => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PENDENTE,
            'emandamento'=> \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_MANUTENCAO,
            'retirada'   => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO,
            'concluido'  => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_FINALIZADO,
        ];
        $newStatus = $map[$to] ?? null;
        if (!$newStatus) throw new Exception('Status destino inválido');

        $transfer = \GlpiPlugin\Assetmgrstatus\Transfer::getById($id);
        if (!$transfer) throw new Exception('Transferência não encontrada');

        // Ações conforme destino
        if ($to === 'emandamento' && $transfer['status'] === \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PENDENTE) {
            // Pegar
            $ok = \GlpiPlugin\Assetmgrstatus\Transfer::pegar($id);
            if (!$ok) throw new Exception(\GlpiPlugin\Assetmgrstatus\Transfer::$last_ticket_error ?: 'Falha ao pegar');
        } elseif ($to === 'retirada' && $transfer['status'] === \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_MANUTENCAO) {
            // Marcar como pronto (sem finalizar, apenas pronto para retirada)
            // Usa método interno para mudar para pronto
            global $DB;
            $DB->update('glpi_plugin_assetmgrstatus_transfers', ['status' => \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO, 'date_pronto' => date('Y-m-d H:i:s')], ['id' => $id]);
            \GlpiPlugin\Assetmgrstatus\Transfer::logStatus($id, \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO, 'Movido via Kanban para RETIRADA');
        } elseif ($to === 'concluido' && $transfer['status'] === \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PRONTO) {
            // Finalizar (requer assinatura, mas permite via kanban)
            $ok = \GlpiPlugin\Assetmgrstatus\Transfer::finalizar($id);
            if (!$ok) throw new Exception('Falha ao finalizar - verifique assinatura');
        } elseif ($to === 'pendente' && $transfer['status'] !== \GlpiPlugin\Assetmgrstatus\Transfer::STATUS_PENDENTE) {
            throw new Exception('Não é permitido voltar para Pendente via Kanban');
        } else {
            throw new Exception('Transição não permitida de ' . $transfer['status'] . ' para ' . $to);
        }

        echo json_encode(['success'=>true]);
        exit;
    } elseif ($type === 'ticket') {
        // Chamados: Pendente (1) -> Pego (2) -> Concluído (5/6)
        $map = [
            'pendente'   => 1, // Novo
            'emandamento'=> 2, // Em andamento
            'concluido'  => 5, // Solucionado
        ];
        if ($to === 'retirada') {
            echo json_encode(['success'=>false,'message'=>'Chamados não podem ir para Retirada']);
            exit;
        }
        $newStatus = $map[$to] ?? null;
        if (!$newStatus) throw new Exception('Status destino inválido para chamado');

        $ticket = new Ticket();
        if (!$ticket->getFromDB($id)) throw new Exception('Chamado não encontrado');

        // Verifica se pode mudar status (precisa ter direito)
        $ok = $ticket->update(['id' => $id, 'status' => $newStatus]);
        if (!$ok) throw new Exception('Falha ao atualizar chamado');

        echo json_encode(['success'=>true]);
        exit;
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Tipo inválido']);
