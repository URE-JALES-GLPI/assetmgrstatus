<?php

namespace GlpiPlugin\Assetmgrstatus;

use Session;
use Ticket;
use Document;
use Document_Item;

if (!defined('GLPI_ROOT')) die("Sorry. You can't access directly to this file");

class Transfer
{
    const STATUS_PENDENTE    = 'pendente';
    const STATUS_MANUTENCAO  = 'em_manutencao';
    const STATUS_PRONTO      = 'pronto';
    const STATUS_FINALIZADO  = 'finalizado';
    const STATUS_CANCELADA   = 'cancelada';

    const TRANSFER_TYPE_URE    = 'ure';
    const TRANSFER_TYPE_ESCOLA = 'escola';

    const RIGHT_TRANSFER = 'plugin_assetmgrstatus_transfer';
    const RIGHT_TECNICO  = 'plugin_assetmgrstatus_tecnico';

    // Erro da última tentativa de abrir chamado/anexar PDF (vazio = sucesso)
    public static string $last_ticket_error = '';

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDENTE   => 'Pendente',
            self::STATUS_MANUTENCAO => 'Em Manutenção',
            self::STATUS_PRONTO     => 'Pronto',
            self::STATUS_FINALIZADO => 'Finalizado',
            self::STATUS_CANCELADA  => 'Cancelada',
        ];
    }

    public static function getStatusColor(string $status): string
    {
        return match($status) {
            self::STATUS_PENDENTE   => '#f59e0b',
            self::STATUS_MANUTENCAO => '#3b82f6',
            self::STATUS_PRONTO     => '#10b981',
            self::STATUS_FINALIZADO => '#6b7280',
            self::STATUS_CANCELADA  => '#ef4444',
            default                 => '#9ca3af',
        };
    }

    public static function getStatusBadgeClass(string $status): string
    {
        return match($status) {
            self::STATUS_PENDENTE   => 'am-badge-manutencao',
            self::STATUS_MANUTENCAO => 'am-badge-garantia',
            self::STATUS_PRONTO     => 'am-badge-ativo',
            self::STATUS_FINALIZADO => 'am-badge-inservivel',
            self::STATUS_CANCELADA  => 'am-badge-manutencao',
            default                 => '',
        };
    }

    // -------------------------------------------------------
    // Entidades: URE = entidade raiz (parent_id=0), Escola = demais
    // -------------------------------------------------------

    public static function getEntidades(string $type = 'ure'): array
    {
        global $DB;

        if ($type === 'ure') {
            // Entidades mãe (nível raiz ou primeiro nível)
            $iter = $DB->request([
                'SELECT' => ['id', 'completename', 'name'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['entities_id' => 0],
                'ORDER'  => ['name ASC'],
            ]);
        } else {
            // Entidades filho (escolas) — tudo que não é a raiz (id=0)
            $iter = $DB->request([
                'SELECT' => ['id', 'completename', 'name'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => ['<>', 0]],
                'ORDER'  => ['completename ASC'],
            ]);
        }

        $result = [];
        foreach ($iter as $row) {
            $result[] = $row;
        }
        return $result;
    }

    // Tipos de ativo aceitos (whitelist — espelha MaintenanceRecord::getAssetTypes)
    public static function getValidItemtypes(): array
    {
        $valid = [];
        foreach (array_keys(MaintenanceRecord::getAssetTypes()) as $system_name) {
            $valid[] = in_array($system_name, ['Desktop', 'Notebook', 'Celular', 'Tablet'])
                ? 'Glpi\\CustomAsset\\' . $system_name . 'Asset'
                : 'Glpi\\CustomAsset\\' . $system_name;
        }
        return $valid;
    }

    // -------------------------------------------------------
    // Criar transferência e marcar ativos como transferidos
    // -------------------------------------------------------

    public static function create(int $entity_dest, string $reason, array $items, string $transfer_type = 'ure', int $ticket_category_id = 0): int
    {
        global $DB;
        self::$last_ticket_error = '';
        $now = date('Y-m-d H:i:s');

        // ---- Validação server-side dos itens ----
        $valid_types = self::getValidItemtypes();
        $valid_items = [];
        $ids_by_type = [];
        foreach ($items as $item) {
            $itemtype = (string)($item['itemtype'] ?? '');
            $items_id = (int)($item['id'] ?? 0);
            if ($items_id <= 0 || !in_array($itemtype, $valid_types, true)) continue;
            $key = $itemtype . ':' . $items_id;
            if (isset($valid_items[$key])) continue; // remove duplicados
            $valid_items[$key] = ['id' => $items_id, 'itemtype' => $itemtype, 'name' => (string)($item['name'] ?? '')];
            $ids_by_type[$itemtype][] = $items_id;
        }
        if (empty($valid_items)) return 0;

        // Valida em lote: ativo existe, não deletado e pertence à entidade ativa
        $active_entity = (int)Session::getActiveEntity();
        $origin_entities = [];
        foreach ($ids_by_type as $itemtype => $ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'entities_id'],
                'FROM'   => 'glpi_assets_assets',
                'WHERE'  => ['id' => $ids, 'is_deleted' => 0],
            ]) as $asset) {
                if ((int)$asset['entities_id'] !== $active_entity) continue;
                $origin_entities[(int)$asset['id']] = (int)$asset['entities_id'];
            }
        }

        // Nomes das entidades de origem em lote (1 query)
        $origin_names = [];
        $origin_ids = array_values(array_unique($origin_entities));
        if ($origin_ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $origin_ids],
            ]) as $e) {
                $origin_names[(int)$e['id']] = $e['name'];
            }
        }

        $final_items = [];
        foreach ($valid_items as $key => $item) {
            if (!isset($origin_entities[$item['id']])) continue; // não existe/não é da entidade ativa
            // Bloqueia itens já em transferência (evita duplo envio mesmo burlando o disabled do front)
            if (self::isLocked($item['itemtype'], $item['id'])) continue;
            $final_items[$key] = $item;
        }
        if (empty($final_items)) return 0;

        $DB->insert('glpi_plugin_assetmgrstatus_transfers', [
            'entity_dest'      => $entity_dest,
            'reason'           => $reason,
            'status'           => self::STATUS_PENDENTE,
            'users_id_created' => Session::getLoginUserID(),
            'users_id_tech'    => 0,
            'date_pending'     => $now,
            'date_creation'    => $now,
        ]);

        $transfer_id = $DB->insertId();
        self::logStatus($transfer_id, self::STATUS_PENDENTE, 'Transferência criada');

        foreach ($final_items as $item) {
            $origin_entity_id = $origin_entities[$item['id']];

            $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                'transfers_id'       => $transfer_id,
                'items_id'           => $item['id'],
                'itemtype'           => $item['itemtype'],
                'item_name'          => $item['name'],
                'origin_entity_id'   => $origin_entity_id,
                'origin_entity_name' => $origin_names[$origin_entity_id] ?? '',
            ]);

            // Marca o ativo como transferido (bloqueia edição na manutenção)
            self::lockAsset($item['itemtype'], $item['id'], $transfer_id);
        }

        // ---- Histórico Manutenção: registra envio de cada ativo (transferência) ----
        $dest_name_for_hist = '';
        if ($entity_dest) {
            $ent_dest_hist = new \Entity();
            if ($ent_dest_hist->getFromDB($entity_dest)) $dest_name_for_hist = $ent_dest_hist->getName();
        }
        if ($dest_name_for_hist === '') $dest_name_for_hist = 'URE';
        foreach ($final_items as $item) {
            $origin_entity_id = $origin_entities[$item['id']];
            $origin_name_hist = $origin_names[$origin_entity_id] ?? '';
            try {
                MaintenanceRecord::logTransferEnvio($item['itemtype'], $item['id'], $transfer_id, $origin_name_hist, $dest_name_for_hist, $reason);
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] history transferencia envio: ' . $e->getMessage());
            }
        }

        // Abre chamado automático no GLPI (se categoria informada) e anexa o termo de retirada
        if ($ticket_category_id > 0) {
            $ticket_id = self::openTicketForTransfer($transfer_id, $entity_dest, $reason, $final_items, $origin_entities, $ticket_category_id);
            if ($ticket_id) {
                $DB->update('glpi_plugin_assetmgrstatus_transfers', ['tickets_id' => $ticket_id], ['id' => $transfer_id]);
                self::attachStageDoc($transfer_id, 'transfer');
            }
        }

        return $transfer_id;
    }

    // -------------------------------------------------------
    // Chamado automático (GLPI Ticket) + PDFs anexos
    // -------------------------------------------------------

    public static function openTicketForTransfer(int $transfer_id, int $entity_dest, string $reason, array $items, array $origin_entities, int $category_id): int
    {
        global $DB;
        $origin_entity_id = (int)(reset($origin_entities) ?: Session::getActiveEntity());

        $origin_name = '';
        $ent_orig = new \Entity();
        if ($origin_entity_id && $ent_orig->getFromDB($origin_entity_id)) $origin_name = $ent_orig->getName();
        $dest_name = $entity_dest ? (($ent_dest = new \Entity()) && $ent_dest->getFromDB($entity_dest) ? $ent_dest->getName() : '') : 'URE';

        $lines = [];
        $lines[] = "Motivo: " . $reason;
        $lines[] = "Origem: " . ($origin_name ?: '—');
        $lines[] = "Destino: " . ($dest_name ?: '—');
        $lines[] = "Criado por: " . self::getUserName(Session::getLoginUserID());
        $lines[] = "Data: " . date('d/m/Y H:i');
        $lines[] = "";

        // Busca detalhes dos ativos em lote (serial, patrimônio, estado) — query base separada do modelo
        $details = [];
        $by_type = [];
        foreach ($items as $item) $by_type[$item['itemtype']][] = (int)$item['id'];
        foreach ($by_type as $type => $ids) {
            try {
                $rows = $DB->request([
                    'SELECT'     => ['glpi_assets_assets.id', 'glpi_assets_assets.name', 'glpi_assets_assets.serial', 'glpi_assets_assets.otherserial', 'glpi_states.name AS state_name'],
                    'FROM'       => 'glpi_assets_assets',
                    'LEFT JOIN'  => ['glpi_states' => ['ON' => ['glpi_assets_assets' => 'states_id', 'glpi_states' => 'id']]],
                    'WHERE'      => ['glpi_assets_assets.id' => $ids],
                ]);
                foreach ($rows as $r) $details[$type][(int)$r['id']] = $r;
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] detalhes ativo (' . $type . '): ' . $e->getMessage());
            }
        }

        // Modelo em consulta separada (tabela pode não existir em todas versões)
        foreach ($by_type as $type => $ids) {
            try {
                $rows = $DB->request([
                    'SELECT'     => ['glpi_assets_assets.id', 'glpi_assets_assetmodels.name AS model_name'],
                    'FROM'       => 'glpi_assets_assets',
                    'LEFT JOIN'  => ['glpi_assets_assetmodels' => ['ON' => ['glpi_assets_assets' => 'assets_assetmodels_id', 'glpi_assets_assetmodels' => 'id']]],
                    'WHERE'      => ['glpi_assets_assets.id' => $ids],
                ]);
                foreach ($rows as $r) $details[$type][(int)$r['id']]['model_name'] = $r['model_name'];
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] modelo ativo (' . $type . '): ' . $e->getMessage());
            }
        }

        $lines[] = "Ativos (" . count($items) . "):";
        foreach ($items as $item) {
            $d      = $details[$item['itemtype']][(int)$item['id']] ?? [];
            $serial = trim((string)($d['serial'] ?? ''));
            $inv    = trim((string)($d['otherserial'] ?? ''));
            $state  = trim((string)($d['state_name'] ?? ''));
            $model  = trim((string)($d['model_name'] ?? ''));
            $extra  = trim(implode(' | ', array_filter([
                $model  ? "Modelo: $model" : '',
                $serial ? "Serial: $serial" : '',
                $inv    ? "Patrimônio: $inv" : '',
                $state  ? "Estado: $state" : '',
            ])));
            $label = str_replace(['Glpi\\CustomAsset\\', 'Asset'], '', $item['itemtype']);
            $lines[] = "  • " . $item['name'] . " (" . $label . ")" . ($extra ? " — " . $extra : '');
        }

        $ticket = new Ticket();
        // Constantes de tipo/prioridade/status variam entre versões do GLPI — usa fallback numérico
        $type     = defined('Ticket::DEMAND') ? Ticket::DEMAND : (defined('Ticket::REQUEST') ? Ticket::REQUEST : 2);
        $priority = defined('Ticket::PRIORITY_MEDIUM') ? Ticket::PRIORITY_MEDIUM : 3;
        $status   = defined('Ticket::INCOMING') ? Ticket::INCOMING : 1;
        try {
            $ticket_id = $ticket->add([
                'name'              => 'Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . ' — ' . ($origin_name ?: 'Origem') . ' → ' . ($dest_name ?: 'Destino'),
                'content'           => implode("\n", $lines),
                'entities_id'       => $origin_entity_id,
                'itilcategories_id' => $category_id,
                'type'              => $type,
                'priority'          => $priority,
                'status'            => $status,
                '_users_id_requester' => Session::getLoginUserID(),
                'date'              => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            self::$last_ticket_error = 'Falha ao abrir chamado: ' . $e->getMessage();
            return 0;
        }
        if (!$ticket_id) {
            self::$last_ticket_error = 'Falha ao abrir chamado: ' . $ticket->getError();
            return 0;
        }
        return (int)$ticket_id;
    }

    public static function getUserName(int $users_id): string
    {
        if ($users_id <= 0) return 'Sistema';
        $u = new \User();
        return $u->getFromDB($users_id) ? $u->getName() : 'Sistema';
    }

    // -------------------------------------------------------
    // Helpers do chamado (ciclo de vida da transferência)
    // -------------------------------------------------------

    public static function assignTicket(int $tickets_id, int $users_id): void
    {
        if ($tickets_id <= 0 || $users_id <= 0) return;
        try {
            $ticket = new Ticket();
            $ticket->update([
                'id'          => $tickets_id,
                '_itil_assign' => [['users_id' => $users_id]],
            ]);
            if ($ticket->getError() !== '') {
                self::$last_ticket_error = 'Falha ao atribuir técnico no chamado: ' . $ticket->getError();
            }
        } catch (\Throwable $e) {
            self::$last_ticket_error = 'Falha ao atribuir técnico no chamado: ' . $e->getMessage();
        }
    }

    public static function setTicketStatus(int $tickets_id, int $status): void
    {
        if ($tickets_id <= 0) return;
        try {
            $ticket = new Ticket();
            $ticket->update(['id' => $tickets_id, 'status' => $status]);
            if ($ticket->getError() !== '') {
                self::$last_ticket_error = 'Falha ao atualizar chamado: ' . $ticket->getError();
            }
        } catch (\Throwable $e) {
            self::$last_ticket_error = 'Falha ao atualizar chamado: ' . $e->getMessage();
        }
    }

    public static function addTicketFollowup(int $tickets_id, string $content): void
    {
        if ($tickets_id <= 0) return;
        try {
            $tf = new \ITILFollowup();
            $tf->add([
                'itemtype'      => 'Ticket',
                'items_id'      => $tickets_id,
                'content'       => $content,
                'users_id'      => Session::getLoginUserID(),
                'is_private'    => 0,
            ]);
            if ($tf->getError() !== '') {
                self::$last_ticket_error = 'Falha no acompanhamento do chamado: ' . $tf->getError();
            }
        } catch (\Throwable $e) {
            self::$last_ticket_error = 'Falha no acompanhamento do chamado: ' . $e->getMessage();
        }
    }

    // -------------------------------------------------------
    // Timeline de status da transferência
    // -------------------------------------------------------

    public static function logStatus(int $transfer_id, string $status, string $note = ''): void
    {
        global $DB;
        try {
            $DB->insert('glpi_plugin_assetmgrstatus_transfer_history', [
                'transfers_id'  => $transfer_id,
                'status'        => $status,
                'users_id'      => Session::getLoginUserID(),
                'note'          => $note !== '' ? $note : null,
                'date_creation' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('[assetmgrstatus] timeline: ' . $e->getMessage());
        }
    }

    public static function getTimeline(int $transfer_id): array
    {
        global $DB;
        $rows = iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_transfer_history',
            'WHERE' => ['transfers_id' => $transfer_id],
            'ORDER' => ['date_creation ASC', 'id ASC'],
        ]));
        if (empty($rows)) return [];

        $user_ids = array_filter(array_unique(array_column($rows, 'users_id')));
        $names = [];
        if ($user_ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $user_ids],
            ]) as $u) {
                $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                if ($full === '') $full = $u['name'];
                $names[(int)$u['id']] = $full;
            }
        }
        foreach ($rows as &$row) {
            $row['user_name'] = ($row['users_id'] && isset($names[(int)$row['users_id']]))
                ? $names[(int)$row['users_id']] : 'Sistema';
        }
        return $rows;
    }

    // -------------------------------------------------------
    // Cancelar transferência (libera ativos + aviso no chamado)
    // -------------------------------------------------------

    public static function cancelar(int $transfer_id, string $motivo = ''): bool
    {
        global $DB;
        self::$last_ticket_error = '';
        $row = self::getById($transfer_id);
        if (!$row) return false;
        if (!in_array($row['status'], [self::STATUS_PENDENTE, self::STATUS_MANUTENCAO], true)) return false;

        $motivo = trim($motivo);

        foreach (self::getItems($transfer_id) as $item) {
            self::unlockAsset($item['itemtype'], (int)$item['items_id']);
        }

        $DB->update('glpi_plugin_assetmgrstatus_transfers', [
            'status'         => self::STATUS_CANCELADA,
            'date_cancelado' => date('Y-m-d H:i:s'),
        ], ['id' => $transfer_id]);

        self::logStatus($transfer_id, self::STATUS_CANCELADA, 'Cancelada' . ($motivo !== '' ? ' — Motivo: ' . mb_substr($motivo, 0, 200) : ''));

        if ((int)$row['tickets_id'] > 0) {
            self::addTicketFollowup(
                (int)$row['tickets_id'],
                "⚠️ Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **cancelada** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\nMotivo do cancelamento: " . ($motivo !== '' ? $motivo : '—') . "\nOs ativos foram liberados e não farão parte desta transferência."
            );
        }

        return true;
    }

    // Anexa o PDF do termo (retirada ou devolução) ao chamado da transferência, se ainda não anexado
    public static function attachStageDoc(int $transfer_id, string $stage): bool
    {
        $transfer = self::getById($transfer_id);
        if (!$transfer || empty($transfer['tickets_id'])) return false;

        $stage_name = ($stage === 'pronto' || $stage === 'final') ? 'Termo de Devolução' : 'Termo de Retirada';
        $doc_name   = $stage_name . ' - Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . '.pdf';

        if (self::hasDocOnTicket((int)$transfer['tickets_id'], $doc_name)) return true;

        $pdf_path = self::generateDocPdf($transfer_id, $stage);
        if (!$pdf_path) return false;

        $items = self::getItems($transfer_id);
        $first = reset($items);
        $origin_entity_id = (int)($first['origin_entity_id'] ?? 0);

        $ok = self::attachDocToTicket((int)$transfer['tickets_id'], $origin_entity_id, $doc_name, $pdf_path);
        if (file_exists($pdf_path)) @unlink($pdf_path);
        return $ok;
    }

    public static function hasDocOnTicket(int $tickets_id, string $doc_name): bool
    {
        global $DB;
        $iter = $DB->request([
            'FROM'      => 'glpi_documents_items',
            'LEFT JOIN' => ['glpi_documents' => ['FKEY' => ['glpi_documents' => 'id', 'glpi_documents_items' => 'documents_id']]],
            'WHERE'     => [
                'glpi_documents_items.itemtype' => 'Ticket',
                'glpi_documents_items.items_id' => $tickets_id,
                'glpi_documents.name'           => $doc_name,
            ],
            'LIMIT' => 1,
        ]);
        return $iter->count() > 0;
    }

    public static function attachDocToTicket(int $tickets_id, int $entities_id, string $doc_name, string $pdf_path): bool
    {
        if (!file_exists($pdf_path) || !is_readable($pdf_path)) {
            self::$last_ticket_error = 'PDF do termo não pôde ser gerado.';
            return false;
        }

        $doc = new Document();
        $doc_id = $doc->add([
            'name'         => $doc_name,
            'filename'     => $pdf_path,
            'mime'         => 'application/pdf',
            'entities_id'  => max(0, $entities_id),
            'is_recursive' => 0,
            'users_id'     => Session::getLoginUserID(),
            'is_deleted'   => 0,
        ]);
        if (!$doc_id) {
            self::$last_ticket_error = 'Falha ao criar anexo: ' . $doc->getError();
            return false;
        }

        $di = new Document_Item();
        $di_id = $di->add([
            'documents_id' => $doc_id,
            'itemtype'     => 'Ticket',
            'items_id'     => $tickets_id,
        ]);
        if (!$di_id) {
            self::$last_ticket_error = 'Falha ao vincular anexo ao chamado: ' . $di->getError();
            return false;
        }
        return true;
    }

    // Gera o PDF do termo no servidor (mPDF do GLPI) e devolve o caminho do arquivo temporário
    public static function generateDocPdf(int $transfer_id, string $stage): ?string
    {
        $mpdf = null;
        if (class_exists('Mpdf\Mpdf')) {
            $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 16, 'tempDir' => sys_get_temp_dir()]);
        } elseif (file_exists(GLPI_ROOT . '/lib/mpdf/autoload.php')) {
            require_once GLPI_ROOT . '/lib/mpdf/autoload.php';
            if (class_exists('Mpdf\Mpdf')) $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 16, 'tempDir' => sys_get_temp_dir()]);
        }
        if (!$mpdf) {
            self::$last_ticket_error = 'mPDF indisponível — anexo não gerado.';
            return null;
        }

        $html = self::renderDocHtml($transfer_id, $stage);
        if ($html === '') return null;

        $path = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.pdf';
        try {
            $mpdf->WriteHTML($html);
            $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
        } catch (\Throwable $e) {
            self::$last_ticket_error = 'Falha ao gerar PDF: ' . $e->getMessage();
            return null;
        }
        return file_exists($path) ? $path : null;
    }

    // HTML do termo (versão impressa p/ mPDF — sem CSS grid, só tabelas)
    public static function renderDocHtml(int $transfer_id, string $stage): string
    {
        global $DB;
        $transfer = self::getById($transfer_id);
        if (!$transfer) return '';

        $items     = self::getItems($transfer_id);
        $comp_list = MaintenanceRecord::getComponents();
        $is_pronto = in_array($stage, ['pronto', 'final'], true);
        $doc_title = $is_pronto ? 'Termo de Devolução de Equipamento' : 'Termo de Retirada de Equipamento';

        $dest_name = ($transfer['entity_dest'] && (new \Entity())->getFromDB((int)$transfer['entity_dest'])) ? (new \Entity())->getName() : 'Unidade Regional de Ensino de Jales';
        $origin_name = '';
        if (!empty($items)) {
            $first = reset($items);
            $origin_name = $first['origin_entity_name'] ?? '';
            if ($origin_name === '' && !empty($first['origin_entity_id'])) {
                $eo = new \Entity();
                if ($eo->getFromDB((int)$first['origin_entity_id'])) $origin_name = $eo->getName();
            }
        }
        $tech_name    = self::getUserName((int)$transfer['users_id_tech']);
        $creator_name = self::getUserName((int)$transfer['users_id_created']);

        $logo_file = GLPI_ROOT . '/plugins/assetmgrstatus/img/logo_ure.png';
        $logo_b64  = file_exists($logo_file) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_file)) : '';

        $h = '<html><head><meta charset="utf-8"><style>';
        $h .= 'body{font-family:Arial,sans-serif;font-size:11px;color:#2d2d2d;line-height:1.4;}';
        $h .= '.hdr{width:100%;border-bottom:2px solid #1a73b5;padding-bottom:8px;margin-bottom:12px;}';
        $h .= '.hdr td{vertical-align:middle;}';
        $h .= '.t1{font-size:15px;font-weight:bold;color:#1a73b5;}';
        $h .= '.t2{font-size:9px;color:#9ca3af;text-align:right;}';
        $h .= '.decl{background:#f0f7ff;border-left:3px solid #1a73b5;padding:8px 12px;font-size:10px;color:#1e3a5f;margin-bottom:12px;}';
        $h .= 'table.info{width:100%;border-collapse:collapse;margin-bottom:12px;}';
        $h .= 'table.info td{border:1px solid #e2e8f0;background:#f8f9fb;padding:5px 8px;font-size:10px;}';
        $h .= 'table.info td b{display:block;font-size:8px;text-transform:uppercase;color:#9ca3af;margin-bottom:2px;}';
        $h .= 'h3{font-size:9.5px;color:#1a73b5;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:3px;margin:10px 0 6px;}';
        $h .= 'table.eq{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:12px;}';
        $h .= 'table.eq th{background:#1a73b5;color:#fff;padding:4px 6px;font-size:9px;text-align:left;}';
        $h .= 'table.eq td{border-bottom:1px solid #f0f2f8;padding:4px 6px;}';
        $h .= '.sign{width:100%;margin-top:16px;border-collapse:collapse;}';
        $h .= '.sign td{width:50%;padding:6px 10px;font-size:10px;}';
        $h .= '.sign .line{height:30px;border-bottom:1.5px solid #2d2d2d;margin-bottom:4px;}';
        $h .= '.ftr{width:100%;border-top:1px solid #e2e8f0;margin-top:12px;padding-top:6px;font-size:8.5px;color:#9ca3af;}';
        $h .= '</style></head><body>';

        $h .= '<table class="hdr"><tr><td>';
        $h .= $logo_b64
            ? '<img src="' . $logo_b64 . '" style="height:52px;">'
            : '<b style="font-size:13px;color:#1a73b5;">UNIDADE REGIONAL DE ENSINO — REGIÃO DE JALES</b>';
        $h .= '</td><td class="t2"><div class="t1">' . $doc_title . '</div>Nº ' . str_pad($transfer_id, 6, '0', STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . '</td></tr></table>';

        if (!$is_pronto) {
            $h .= '<div class="decl">A Unidade Regional de Ensino – Região de Jales declara que o(s) equipamento(s) abaixo mencionado(s) foi(ram) retirado(s) pelo responsável identificado abaixo. O responsável está ciente de que retirou exatamente o(s) equipamento(s) que foi(ram) apresentado(s) ao suporte técnico, conforme verificado no momento da retirada.</div>';
            $h .= '<p style="font-size:10.5px;">Eu, <b>' . htmlspecialchars($creator_name) . '</b>, portador(a) do documento de identidade, em cumprimento às normas e procedimentos da Unidade Regional de Ensino – Região de <b>JALES</b>, declaro para os devidos fins que realizei a retirada do(s) equipamento(s) descrito(s) abaixo:</p>';
            $h .= '<table class="info"><tr><td><b>Data de Retirada</b>' . date('d/m/Y', strtotime($transfer['date_creation'])) . '</td><td><b>Escola / URE de Destino</b>' . htmlspecialchars($dest_name) . '</td></tr>';
            $h .= '<tr><td colspan="2"><b>Motivo da Transferência</b>' . htmlspecialchars($transfer['reason']) . '</td></tr></table>';
            $h .= '<h3>Equipamento(s) Retirado(s)</h3><table class="eq"><tr><th>#</th><th>Nome do Equipamento</th><th>Tipo</th></tr>';
            foreach ($items as $i => $item) {
                $h .= '<tr><td>' . ($i + 1) . '</td><td><b>' . htmlspecialchars($item['item_name']) . '</b></td><td>' . htmlspecialchars(str_replace(['Glpi\\CustomAsset\\', 'Asset'], '', $item['itemtype'])) . '</td></tr>';
            }
            $h .= '</table>';
        } else {
            $h .= '<div class="decl">A Unidade Regional de Ensino – Região de Jales declara que o(s) equipamento(s) abaixo mencionado(s) foi(ram) devolvido(s) após a realização dos procedimentos de manutenção técnica. O responsável pelo recebimento está ciente das condições e do novo status de cada equipamento, conforme verificado no momento da devolução.</div>';
            $h .= '<p style="font-size:10.5px;">Eu, <b>' . htmlspecialchars($tech_name) . '</b>, técnico(a) responsável pelo atendimento, portador(a) do documento de identidade, em cumprimento às normas e procedimentos da Unidade Regional de Ensino – Região de <b>JALES</b>, declaro que os equipamentos abaixo foram submetidos ao suporte técnico e estão sendo devolvidos ao responsável identificado abaixo, conforme verificado no momento da entrega:</p>';
            $h .= '<table class="info"><tr><td><b>Data de Devolução</b>' . date('d/m/Y', strtotime($transfer['date_pronto'] ?: $transfer['date_creation'])) . '</td><td><b>Escola / URE de Destino</b>' . htmlspecialchars($dest_name) . '</td></tr>';
            $h .= '<tr><td><b>Técnico Responsável</b>' . htmlspecialchars($tech_name) . '</td><td><b>Responsável pela Retirada</b>' . htmlspecialchars($creator_name) . '</td></tr>';
            $h .= '<tr><td><b>Escola de Origem</b>' . htmlspecialchars($origin_name ?: 'Não informada') . '</td><td><b>Local da Manutenção</b>' . htmlspecialchars($dest_name) . '</td></tr>';
            $h .= '<tr><td colspan="2"><b>Retornando para</b>' . htmlspecialchars($origin_name ?: 'Escola de origem') . '</td></tr>';
            if ($transfer['reason']) $h .= '<tr><td colspan="2"><b>Motivo Original da Transferência</b>' . htmlspecialchars($transfer['reason']) . '</td></tr>';
            $h .= '</table>';
            $h .= '<h3>Equipamento(s) Devolvido(s)</h3><table class="eq"><tr><th>#</th><th>Nome</th><th>Tipo</th><th>Status Final</th><th>Motivo / Observação</th><th>Componentes</th><th>O Que Foi Feito</th></tr>';
            foreach ($items as $i => $item) {
                $wrow = $DB->request(['SELECT' => ['work_log', 'work_components'], 'FROM' => 'glpi_plugin_assetmgrstatus_transfer_items', 'WHERE' => ['transfers_id' => $transfer_id, 'items_id' => (int)$item['items_id']], 'LIMIT' => 1])->current();
                $wlog   = $wrow['work_log'] ?? '';
                $wcomps = ($wrow['work_components'] ?? '') ? json_decode($wrow['work_components'], true) : [];
                $resolved = [];
                foreach ($wcomps as $ck => $cs) if ($cs === 'resolved') $resolved[] = $comp_list[$ck] ?? $ck;

                $comp_txt = [];
                $fcomps = !empty($item['final_components']) ? json_decode($item['final_components'], true) : [];
                foreach ($fcomps as $ckey => $cdesc) $comp_txt[] = '◆ ' . ($comp_list[$ckey] ?? $ckey) . ($cdesc ? ': ' . $cdesc : '');
                foreach ($resolved as $rl) $comp_txt[] = '✓ ' . $rl . ' (resolvido)';

                $h .= '<tr><td>' . ($i + 1) . '</td><td><b>' . htmlspecialchars($item['item_name']) . '</b></td>'
                    . '<td>' . htmlspecialchars(str_replace(['Glpi\\CustomAsset\\', 'Asset'], '', $item['itemtype'])) . '</td>'
                    . '<td>' . ($item['final_status'] ? MaintenanceRecord::getStatusLabel($item['final_status']) : '—') . '</td>'
                    . '<td>' . htmlspecialchars($item['final_reason'] ?? '—') . '</td>'
                    . '<td>' . (!empty($comp_txt) ? htmlspecialchars(implode('; ', $comp_txt)) : '—') . '</td>'
                    . '<td>' . ($wlog ? nl2br(htmlspecialchars($wlog)) : '—') . '</td></tr>';
            }
            $h .= '</table>';
        }

        $h .= '<table class="sign"><tr><td><div class="line"></div><b>' . ($is_pronto ? 'Responsável pela Entrega (Técnico)' : 'Responsável pelo Envio') . '</b><br>' . htmlspecialchars($is_pronto ? $tech_name : $creator_name) . '<br><br>Documento: ____________________________<br>Data: ____/____/________</td>'
            . '<td><div class="line"></div><b>Responsável pelo Recebimento</b><br>Nome: ________________________________________<br><br>Documento: ____________________________<br>Data: ____/____/________</td></tr></table>';

        $h .= '<table class="ftr"><tr><td>Unidade Regional de Ensino — Região de Jales | Suporte Técnico</td><td style="text-align:right;">Gerado em ' . date('d/m/Y \à\s H:i') . ' | Transferência #' . str_pad($transfer_id, 6, '0', STR_PAD_LEFT) . '</td></tr></table>';
        $h .= '</body></html>';
        return $h;
    }

    // Bloqueia ativo: marca transfer_status = 'transferido'
    public static function lockAsset(string $itemtype, int $items_id, int $transfer_id): void
    {
        global $DB;
        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_records',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'LIMIT'  => 1,
        ])->count() > 0;

        $now = date('Y-m-d H:i:s');
        $data = ['transfer_status' => 'transferido', 'transfers_id' => $transfer_id, 'date_mod' => $now];

        if ($exists) {
            $DB->update('glpi_plugin_assetmgrstatus_records', $data, ['itemtype' => $itemtype, 'items_id' => $items_id]);
        } else {
            $DB->insert('glpi_plugin_assetmgrstatus_records', array_merge($data, [
                'itemtype'      => $itemtype,
                'items_id'      => $items_id,
                'am_status'     => MaintenanceRecord::STATUS_ESTOQUE,
                'users_id'      => Session::getLoginUserID(),
                'date_creation' => $now,
            ]));
        }
    }

    // Desbloqueia ativo ao finalizar
    public static function unlockAsset(string $itemtype, int $items_id): void
    {
        global $DB;
        $DB->update('glpi_plugin_assetmgrstatus_records', [
            'transfer_status' => null,
            'transfers_id'    => null,
            'date_mod'        => date('Y-m-d H:i:s'),
        ], ['itemtype' => $itemtype, 'items_id' => $items_id]);
    }

    // Verifica se um ativo está bloqueado por transferência
    public static function isLocked(string $itemtype, int $items_id): bool
    {
        global $DB;
        $iter = $DB->request([
            'SELECT' => ['transfer_status'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_records',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'LIMIT'  => 1,
        ]);
        if ($iter->count() === 0) return false;
        return $iter->current()['transfer_status'] === 'transferido';
    }

    // -------------------------------------------------------
    // Pegar (assumir manutenção)
    // -------------------------------------------------------

    public static function pegar(int $transfer_id): bool
    {
        global $DB;
        self::$last_ticket_error = '';
        $row = self::getById($transfer_id);
        if (!$row || $row['status'] !== self::STATUS_PENDENTE) return false;

        $DB->update('glpi_plugin_assetmgrstatus_transfers', [
            'status'          => self::STATUS_MANUTENCAO,
            'users_id_tech'   => Session::getLoginUserID(),
            'date_manutencao' => date('Y-m-d H:i:s'),
        ], ['id' => $transfer_id]);

        self::logStatus($transfer_id, self::STATUS_MANUTENCAO, 'Transferência assumida pelo técnico');

        // Chamado: atribui técnico e marca como "Em atribuição" (2)
        if ((int)$row['tickets_id'] > 0) {
            $tech_id = Session::getLoginUserID();
            self::assignTicket((int)$row['tickets_id'], $tech_id);
            self::setTicketStatus((int)$row['tickets_id'], defined('Ticket::ASSIGNED') ? Ticket::ASSIGNED : 2);
            self::addTicketFollowup(
                (int)$row['tickets_id'],
                "🔧 Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **assumida** pelo técnico " . self::getUserName($tech_id) . " em " . date('d/m/Y H:i') . "."
            );
        }

        return true;
    }

    // -------------------------------------------------------
    // Marcar como Pronto
    // -------------------------------------------------------

    public static function marcarPronto(int $transfer_id, array $final_items): bool
    {
        global $DB;
        self::$last_ticket_error = '';
        $row = self::getById($transfer_id);
        if (!$row || $row['status'] !== self::STATUS_MANUTENCAO) return false;

        foreach ($final_items as $item_id => $data) {
            $DB->update('glpi_plugin_assetmgrstatus_transfer_items', [
                'final_status'     => $data['status'] ?? '',
                'final_reason'     => $data['reason'] ?? null,
                'final_components' => isset($data['components']) ? json_encode($data['components']) : null,
            ], ['transfers_id' => $transfer_id, 'items_id' => (int)$item_id]);
        }

        $DB->update('glpi_plugin_assetmgrstatus_transfers', [
            'status'      => self::STATUS_PRONTO,
            'date_pronto' => date('Y-m-d H:i:s'),
        ], ['id' => $transfer_id]);

        self::logStatus($transfer_id, self::STATUS_PRONTO, 'Todos os itens concluídos — aguardando devolução');

        if ((int)$row['tickets_id'] > 0) {
            self::addTicketFollowup(
                (int)$row['tickets_id'],
                "✅ Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **marcada como Pronto** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\nTodos os itens concluídos — aguardando devolução."
            );
        }

        return true;
    }

    // -------------------------------------------------------
    // Finalizar — aplica status no inventário e desbloqueia
    // -------------------------------------------------------

    public static function finalizar(int $transfer_id): bool
    {
        global $DB;
        self::$last_ticket_error = '';
        $row = self::getById($transfer_id);
        if (!$row || $row['status'] !== self::STATUS_PRONTO) return false;

        $items_iter = $DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_transfer_items',
            'WHERE' => ['transfers_id' => $transfer_id],
        ]);

        $uid = Session::getLoginUserID();
        // Permite saveRecord mesmo com ativo bloqueado (finalização é exceção)
        global $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK;
        $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK = true;

        // Nomes para histórico de retorno
        $dest_name_hist = '';
        if (!empty($row['entity_dest'])) {
            $ent_dest_hist = new \Entity();
            if ($ent_dest_hist->getFromDB((int)$row['entity_dest'])) $dest_name_hist = $ent_dest_hist->getName();
        }
        if ($dest_name_hist === '') $dest_name_hist = 'URE';
        $tech_name_hist = self::getUserName($uid);
        // Se o técnico que assumiu é diferente de quem finaliza, prioriza quem está finalizando mas mantém referência
        if ($tech_name_hist === 'Sistema' && !empty($row['users_id_tech'])) {
            $tech_name_hist = self::getUserName((int)$row['users_id_tech']);
        }

        foreach ($items_iter as $item) {
            $origin_name_hist = $item['origin_entity_name'] ?? '';
            if ($origin_name_hist === '' && !empty($item['origin_entity_id'])) {
                $ent_orig_hist = new \Entity();
                if ($ent_orig_hist->getFromDB((int)$item['origin_entity_id'])) $origin_name_hist = $ent_orig_hist->getName();
            }

            if (empty($item['final_status'])) {
                self::unlockAsset($item['itemtype'], (int)$item['items_id']);
                // Ainda registra retorno mesmo sem status final (caso excepcional)
                try {
                    MaintenanceRecord::logTransferRetorno(
                        $item['itemtype'],
                        (int)$item['items_id'],
                        $transfer_id,
                        $tech_name_hist,
                        $origin_name_hist,
                        $dest_name_hist,
                        '',
                        $item['final_reason'] ?? ''
                    );
                } catch (\Throwable $e) {
                    error_log('[assetmgrstatus] history retorno (sem status): ' . $e->getMessage());
                }
                continue;
            }

            $final_comps = $item['final_components'] ? json_decode($item['final_components'], true) : [];

            MaintenanceRecord::saveRecord(
                $item['itemtype'],
                (int)$item['items_id'],
                $item['final_status'],
                $item['final_reason'] ?? '',
                $final_comps,
                [],
                $uid
            );

            // Desbloqueia após aplicar
            self::unlockAsset($item['itemtype'], (int)$item['items_id']);

            // ---- Histórico Manutenção: retorno à entidade de origem ----
            try {
                MaintenanceRecord::logTransferRetorno(
                    $item['itemtype'],
                    (int)$item['items_id'],
                    $transfer_id,
                    $tech_name_hist,
                    $origin_name_hist,
                    $dest_name_hist,
                    $item['final_status'],
                    $item['final_reason'] ?? ''
                );
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] history retorno: ' . $e->getMessage());
            }
        }

        $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK = false;

        $DB->update('glpi_plugin_assetmgrstatus_transfers', [
            'status'          => self::STATUS_FINALIZADO,
            'date_finalizado' => date('Y-m-d H:i:s'),
        ], ['id' => $transfer_id]);

        self::logStatus($transfer_id, self::STATUS_FINALIZADO, 'Transferência finalizada — status aplicados no inventário');

        // Chamado: fecha (CLOSED=6) e registra acompanhamento final
        if ((int)$row['tickets_id'] > 0) {
            self::setTicketStatus((int)$row['tickets_id'], defined('Ticket::CLOSED') ? Ticket::CLOSED : 6);
            self::addTicketFollowup(
                (int)$row['tickets_id'],
                "🏁 Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **finalizada** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\nStatus dos equipamentos aplicados no inventário. O termo de devolução foi anexado a este chamado.\nChamado fechado automaticamente."
            );
        }

        return true;
    }

    // -------------------------------------------------------
    // Listagem
    // -------------------------------------------------------

    public static function getAll(string $status_filter = ''): array
    {
        global $DB;
        $where = [];
        if ($status_filter) $where['status'] = $status_filter;

        $rows = iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_transfers',
            'WHERE' => $where,
            'ORDER' => ['date_creation DESC'],
        ]));
        if (empty($rows)) return [];

        // Batch 1: contagem de itens por transferência (1 query)
        $counts = [];
        foreach ($DB->request([
            'SELECT' => ['transfers_id', 'COUNT' => 'id AS total'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_transfer_items',
            'WHERE'  => ['transfers_id' => array_column($rows, 'id')],
            'GROUPBY'=> ['transfers_id'],
        ]) as $c) {
            $counts[(int)$c['transfers_id']] = (int)$c['total'];
        }

        // Batch 2: nomes das entidades de destino (1 query)
        $entity_ids = array_filter(array_unique(array_column($rows, 'entity_dest')));
        $entity_names = [];
        if ($entity_ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $entity_ids],
            ]) as $e) {
                $entity_names[(int)$e['id']] = $e['name'];
            }
        }

        // Batch 3: nomes dos usuários (técnico + criador) — nome completo (firstname + realname)
        $user_ids = array_filter(array_unique(array_merge(
            array_column($rows, 'users_id_tech'),
            array_column($rows, 'users_id_created')
        )));
        $user_names = [];
        if ($user_ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name', 'realname', 'firstname'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $user_ids],
            ]) as $u) {
                $full = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? ''));
                if ($full === '') $full = $u['name'];
                $user_names[(int)$u['id']] = $full;
            }
        }

        // Batch 4: entidade de ORIGEM por transferência (vem dos itens; 1 query)
        $origins = [];
        foreach ($DB->request([
            'SELECT' => ['transfers_id', 'origin_entity_id', 'origin_entity_name'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_transfer_items',
            'WHERE'  => ['transfers_id' => array_column($rows, 'id')],
            'ORDER'  => ['id ASC'],
        ]) as $oi) {
            $tid = (int)$oi['transfers_id'];
            if (isset($origins[$tid])) continue;
            $origins[$tid] = [
                'id'   => (int)$oi['origin_entity_id'],
                'name' => (string)($oi['origin_entity_name'] ?? ''),
            ];
        }
        // Preenche nome via glpi_entities quando o item não guardou o nome
        foreach ($origins as $tid => $o) {
            if ($o['name'] === '' && $o['id'] > 0 && isset($entity_names[$o['id']])) {
                $origins[$tid]['name'] = $entity_names[$o['id']];
            }
        }

        $result = [];
        foreach ($rows as $row) {
            $origin = $origins[(int)$row['id']] ?? null;
            $row['items_count']      = $counts[(int)$row['id']] ?? 0;
            $row['entity_dest_name'] = $entity_names[(int)$row['entity_dest']] ?? 'Desconhecida';
            $row['origin_entity_name'] = $origin
                ? ($origin['name'] !== '' ? $origin['name'] : 'Entidade #' . $origin['id'])
                : '-';
            $row['tech_name']        = ($row['users_id_tech'] && isset($user_names[(int)$row['users_id_tech']]))
                ? $user_names[(int)$row['users_id_tech']] : null;
            $row['creator_name']     = ($row['users_id_created'] && isset($user_names[(int)$row['users_id_created']]))
                ? $user_names[(int)$row['users_id_created']] : 'Sistema';
            $result[] = $row;
        }
        return $result;
    }

    public static function getById(int $id): ?array
    {
        global $DB;
        $iter = $DB->request(['FROM' => 'glpi_plugin_assetmgrstatus_transfers', 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
        if ($iter->count() === 0) return null;
        return $iter->current();
    }

    public static function getItems(int $transfer_id): array
    {
        global $DB;
        return iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_transfer_items',
            'WHERE' => ['transfers_id' => $transfer_id],
        ]));
    }

    public static function getElapsedTime(string $from_date, ?string $to_date = null): array
    {
        $start = strtotime($from_date);
        $end   = $to_date ? strtotime($to_date) : time();
        $diff  = max(0, $end - $start);
        $d = floor($diff/86400); $h = floor(($diff%86400)/3600); $m = floor(($diff%3600)/60); $s = $diff%60;
        return ['total_seconds' => $diff, 'days' => $d, 'hours' => $h, 'minutes' => $m, 'seconds' => $s, 'label' => ($d>0?"{$d}d ":''). "{$h}h {$m}m"];
    }
}
