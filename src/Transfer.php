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
    // ID da última transferência de pendência criada em finalizar() (0 = nenhuma)
    public static int $last_pending_transfer_id = 0;

    private static function getItemError($item): string
    {
        if (is_object($item)) {
            if (method_exists($item, 'getError')) {
                try { $e = $item->getError(); return is_string($e) ? $e : (is_array($e) ? json_encode($e) : ''); } catch (\Throwable $ex) {}
            }
            if (method_exists($item, 'getErrors')) {
                try { $errs = $item->getErrors(); if (!empty($errs)) return is_string($errs) ? $errs : json_encode($errs); } catch (\Throwable $ex) {}
            }
            if (method_exists($item, 'getErrorMessage')) {
                try { $m = $item->getErrorMessage(); return is_string($m) ? $m : ''; } catch (\Throwable $ex) {}
            }
        }
        return '';
    }

    private static function truncPdf(string $text, int $max = 120): string
    {
        $text = trim((string)$text);
        if ($text === '' || $text === '—') return '—';
        // Quebra palavras gigantes sem espaços: insere zero-width a cada 30 chars para garantir quebra mesmo sem CSS (fallback)
        // Mas CSS word-break já resolve; aqui apenas abrevia textos absurdamente longos
        if (mb_strlen($text) > $max) {
            return mb_substr($text, 0, $max) . '…';
        }
        return $text;
    }

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

        // Valida em lote: ativo existe, não deletado e pertence à entidade ativa (com suporte a recursividade e ADMIN)
        $active_entity = (int)Session::getActiveEntity();
        $is_recursive = !empty($_SESSION['glpiactiveentity_is_recursive']);
        $allowed_entities = null;
        if ($active_entity !== 0 && $is_recursive) {
            $allowed_entities = MaintenanceRecord::expandEntityIds($active_entity);
        } elseif ($active_entity !== 0) {
            $allowed_entities = [$active_entity];
        } else {
            // Entidade 0 (raiz) — considera recursivo implícito ou "Todas" para ADMIN: permite qualquer entidade com acesso
            if ($is_recursive) {
                $allowed_entities = MaintenanceRecord::expandEntityIds($active_entity);
                if (is_array($allowed_entities) && count($allowed_entities) <= 1) {
                    $allowed_entities = null;
                }
            } else {
                $allowed_entities = null;
            }
        }
        $origin_entities = [];
        foreach ($ids_by_type as $itemtype => $ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'entities_id'],
                'FROM'   => 'glpi_assets_assets',
                'WHERE'  => ['id' => $ids, 'is_deleted' => 0],
            ]) as $asset) {
                $eid = (int)$asset['entities_id'];
                if ($allowed_entities !== null) {
                    if (!in_array($eid, $allowed_entities, true)) {
                        // Fallback: se usuário tem acesso explícito à entidade, permite (caso de ADMIN com filtro multi-entidade)
                        if (method_exists('Session', 'haveAccessToEntity')) {
                            if (!Session::haveAccessToEntity($eid)) continue;
                        } else {
                            continue;
                        }
                    }
                } else {
                    // allowed null = todas permitidas, mas ainda verifica acesso geral
                    if (method_exists('Session', 'haveAccessToEntity') && !Session::haveAccessToEntity($eid)) {
                        // Permite se for admin (vê todas)
                        if (!Session::haveRight('plugin_assetmgrstatus_admin', READ)) continue;
                    }
                }
                $origin_entities[(int)$asset['id']] = $eid;
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

            // Ao transferir, muda o status do equipamento para Manutenção
            try {
                // Preserva componentes/motivo existentes para não apagar
                $curRec = $DB->request(['SELECT' => ['components','reason'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $item['itemtype'], 'items_id' => $item['id']], 'LIMIT' => 1])->current();
                $curComps = [];
                if ($curRec && !empty($curRec['components'])) {
                    $decoded = json_decode($curRec['components'], true);
                    if (is_array($decoded)) $curComps = $decoded;
                }
                $curReason = $curRec['reason'] ?? '';
                // Usa motivo da transferência como novo motivo, mas mantém componentes
                $newReason = $reason !== '' ? $reason : $curReason;
                global $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK;
                $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK = true;
                \GlpiPlugin\Assetmgrstatus\MaintenanceRecord::saveRecord($item['itemtype'], $item['id'], \GlpiPlugin\Assetmgrstatus\MaintenanceRecord::STATUS_MANUTENCAO, $newReason, $curComps, [], (int)Session::getLoginUserID());
                $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK = false;
            } catch (\Throwable $e) {
                // Fallback direto caso saveRecord falhe (ex: lock)
                try {
                    global $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK;
                    $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK = false;
                    $DB->update('glpi_plugin_assetmgrstatus_records', ['am_status' => \GlpiPlugin\Assetmgrstatus\MaintenanceRecord::STATUS_MANUTENCAO, 'reason' => $reason, 'date_mod' => $now], ['itemtype' => $item['itemtype'], 'items_id' => $item['id']]);
                    $state_id = \GlpiPlugin\Assetmgrstatus\MaintenanceRecord::GLPI_STATE_MAP[\GlpiPlugin\Assetmgrstatus\MaintenanceRecord::STATUS_MANUTENCAO] ?? null;
                    if ($state_id) $DB->update('glpi_assets_assets', ['states_id' => $state_id], ['id' => $item['id']]);
                } catch (\Throwable $e2) {}
            }
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
            $err = self::getItemError($ticket);
            self::$last_ticket_error = 'Falha ao abrir chamado' . ($err !== '' ? ': ' . $err : '');
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
            global $DB;
            // Evita duplicado
            $exists = $DB->request([
                'FROM'  => 'glpi_tickets_users',
                'WHERE' => ['tickets_id' => $tickets_id, 'users_id' => $users_id, 'type' => 2],
                'LIMIT' => 1,
            ])->count() > 0;
            if ($exists) return;

            $ticket = new Ticket();
            $ok = false;
            $errMsg = '';
            if ($ticket->getFromDB($tickets_id)) {
                // Tenta via API oficial com _type correto (GLPI 10/11)
                try {
                    $ok = $ticket->update([
                        'id'           => $tickets_id,
                        '_itil_assign' => ['_type' => 'user', 'users_id' => $users_id],
                        '_auto_update' => true,
                    ]);
                } catch (\Throwable $e2) {
                    $errMsg = $e2->getMessage();
                    $ok = false;
                }
                if (!$ok) {
                    $err = self::getItemError($ticket);
                    if ($err !== '' && $errMsg === '') $errMsg = $err;
                }
                // Verifica se inseriu
                $stillMissing = $DB->request([
                    'FROM'  => 'glpi_tickets_users',
                    'WHERE' => ['tickets_id' => $tickets_id, 'users_id' => $users_id, 'type' => 2],
                    'LIMIT' => 1,
                ])->count() === 0;

                if ($stillMissing) {
                    // Fallback: inserção direta via Ticket_User ou SQL
                    $inserted = false;
                    if (class_exists('Ticket_User')) {
                        try {
                            $tu = new \Ticket_User();
                            // Checa duplicado novamente antes
                            if ($DB->request(['FROM'=>'glpi_tickets_users','WHERE'=>['tickets_id'=>$tickets_id,'users_id'=>$users_id,'type'=>2]])->count()===0) {
                                $tid = $tu->add([
                                    'tickets_id'      => $tickets_id,
                                    'users_id'        => $users_id,
                                    'type'            => 2,
                                    'use_notification'=> 1,
                                ]);
                                if ($tid) $inserted = true;
                                else {
                                    $err2 = self::getItemError($tu);
                                    if ($err2 !== '') $errMsg = $err2;
                                }
                            } else {
                                $inserted = true;
                            }
                        } catch (\Throwable $e3) {
                            $errMsg = $e3->getMessage();
                        }
                    }
                    if (!$inserted) {
                        // Último fallback SQL direto
                        try {
                            $DB->insert('glpi_tickets_users', [
                                'tickets_id'       => $tickets_id,
                                'users_id'         => $users_id,
                                'type'             => 2,
                                'use_notification' => 1,
                            ]);
                            $inserted = true;
                        } catch (\Throwable $e4) {
                            if ($errMsg === '') $errMsg = $e4->getMessage();
                        }
                    }
                    if ($inserted) $ok = true;
                } else {
                    $ok = true;
                }

                // Garante status Em Atendimento se ainda Novo
                if ($ok && isset($ticket->fields['status']) && (int)$ticket->fields['status'] === 1) {
                    $t2 = new Ticket();
                    $t2->update(['id' => $tickets_id, 'status' => defined('Ticket::ASSIGNED') ? Ticket::ASSIGNED : 2]);
                }
            }

            if (!$ok && $errMsg !== '') {
                self::$last_ticket_error = 'Falha ao atribuir técnico no chamado: ' . $errMsg;
            } elseif (!$ok) {
                $err = self::getItemError($ticket);
                if ($err !== '') self::$last_ticket_error = 'Falha ao atribuir técnico no chamado: ' . $err;
            }
        } catch (\Throwable $e) {
            self::$last_ticket_error = 'Falha ao atribuir técnico no chamado: ' . $e->getMessage();
        }
    }

    public static function setTicketStatus(int $tickets_id, int $status): void
    {
        if ($tickets_id <= 0) return;
        // Para Solucionado, tenta via ITILSolution (forma oficial do GLPI); direto via update pode ser bloqueado sem solução
        $isSolved = $status === (defined('Ticket::SOLVED') ? Ticket::SOLVED : 5);
        if ($isSolved && class_exists('ITILSolution')) {
            try {
                $sol = new \ITILSolution();
                $sid = $sol->add([
                    'itemtype' => 'Ticket',
                    'items_id' => $tickets_id,
                    'content'  => 'Transferência finalizada — status dos equipamentos aplicados no inventário. Chamado solucionado automaticamente.',
                    'users_id' => Session::getLoginUserID(),
                ]);
                if ($sid) return;
                // Se falhou, tenta fallback via update
                $err = self::getItemError($sol);
                if ($err !== '') error_log('[assetmgrstatus] ITILSolution falhou: ' . $err);
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] ITILSolution exception: ' . $e->getMessage());
            }
        }
        try {
            $ticket = new Ticket();
            $ok = $ticket->update(['id' => $tickets_id, 'status' => $status]);
            if (!$ok) {
                $err = self::getItemError($ticket);
                if ($err !== '') self::$last_ticket_error = 'Falha ao atualizar chamado: ' . $err;
                else if (self::$last_ticket_error === '') self::$last_ticket_error = 'Falha ao atualizar chamado (status ' . $status . ')';
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
            $fid = $tf->add([
                'itemtype'      => 'Ticket',
                'items_id'      => $tickets_id,
                'content'       => $content,
                'users_id'      => Session::getLoginUserID(),
                'is_private'    => 0,
            ]);
            if (!$fid) {
                $err = self::getItemError($tf);
                if ($err !== '') self::$last_ticket_error = 'Falha no acompanhamento do chamado: ' . $err;
                else if (self::$last_ticket_error === '') self::$last_ticket_error = 'Falha no acompanhamento do chamado';
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
                "⚠️ Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **cancelada** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\nMotivo do cancelamento: " . ($motivo !== '' ? $motivo : '—') . "\nOs ativos foram liberados e não farão parte desta transferência.\n🔒 Chamado será **fechado** automaticamente."
            );
            // Ao cancelar, fecha o chamado (Fechado = 6) — deve ser a última ação para não reabrir com followup posterior
            self::setTicketStatus((int)$row['tickets_id'], defined('Ticket::CLOSED') ? Ticket::CLOSED : 6);
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
            $err = self::getItemError($doc);
            self::$last_ticket_error = 'Falha ao criar anexo' . ($err !== '' ? ': ' . $err : '');
            return false;
        }

        $di = new Document_Item();
        $di_id = $di->add([
            'documents_id' => $doc_id,
            'itemtype'     => 'Ticket',
            'items_id'     => $tickets_id,
        ]);
        if (!$di_id) {
            $err = self::getItemError($di);
            self::$last_ticket_error = 'Falha ao vincular anexo ao chamado' . ($err !== '' ? ': ' . $err : '');
            return false;
        }
        return true;
    }

    // Gera o PDF do termo no servidor (mPDF do GLPI) e devolve o caminho do arquivo temporário
    public static function generateDocPdf(int $transfer_id, string $stage): ?string
    {
        $html = self::renderDocHtml($transfer_id, $stage);
        if ($html === '') {
            self::$last_ticket_error = 'HTML do termo vazio.';
            return null;
        }
        // Tenta mPDF com múltiplos caminhos (GLPI 10/11, composer plugin, vendor local)
        $mpdf = null;
        $mpdfError = '';
        $tryPaths = [
            'class_exists' => class_exists('Mpdf\Mpdf'),
            GLPI_ROOT . '/vendor/mpdf/mpdf/src/Mpdf.php',
            GLPI_ROOT . '/vendor/mpdf/mpdf/autoload.php',
            GLPI_ROOT . '/vendor/autoload.php',
            GLPI_ROOT . '/lib/mpdf/autoload.php',
            GLPI_ROOT . '/lib/mpdf/src/Mpdf.php',
            __DIR__ . '/../vendor/mpdf/mpdf/src/Mpdf.php',
            __DIR__ . '/../vendor/mpdf/mpdf/autoload.php',
            __DIR__ . '/../vendor/autoload.php',
        ];
        if (class_exists('Mpdf\Mpdf')) {
            try {
                $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 16, 'tempDir' => sys_get_temp_dir()]);
            } catch (\Throwable $e) { $mpdfError = $e->getMessage(); $mpdf = null; }
        }
        if (!$mpdf) {
            foreach ($tryPaths as $key => $p) {
                if ($key === 'class_exists') continue;
                if (!is_string($p) || !file_exists($p)) continue;
                try {
                    // Para autoload.php, apenas require; para Mpdf.php, require e tenta classe
                    if (str_ends_with($p, 'autoload.php')) {
                        @require_once $p;
                    } else {
                        @require_once $p;
                    }
                    if (class_exists('Mpdf\Mpdf')) {
                        $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'margin_left' => 14, 'margin_right' => 14, 'margin_top' => 14, 'margin_bottom' => 16, 'tempDir' => sys_get_temp_dir()]);
                        break;
                    }
                } catch (\Throwable $e) { $mpdfError = $e->getMessage(); continue; }
            }
        }
        if ($mpdf) {
            $path = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.pdf';
            try {
                $mpdf->WriteHTML($html);
                $mpdf->Output($path, \Mpdf\Output\Destination::FILE);
                if (file_exists($path) && filesize($path) > 500) return $path;
                $mpdfError = 'mPDF gerou arquivo vazio.';
            } catch (\Throwable $e) {
                $mpdfError = 'Falha mPDF: ' . $e->getMessage();
            }
            // se falhou, tenta fallback wkhtmltopdf/dompdf antes de desistir
        }
        // Fallback 1: Dompdf (se disponível no GLPI/vendor)
        if (class_exists('Dompdf\Dompdf') || class_exists('Dompdf\Options')) {
            try {
                if (!class_exists('Dompdf\Dompdf') && file_exists(GLPI_ROOT . '/vendor/dompdf/dompdf/src/Dompdf.php')) {
                    @require_once GLPI_ROOT . '/vendor/autoload.php';
                }
                if (class_exists('Dompdf\Dompdf')) {
                    $opts = class_exists('Dompdf\Options') ? new \Dompdf\Options() : null;
                    if ($opts) {
                        $opts->set('isRemoteEnabled', true);
                        $opts->set('isHtml5ParserEnabled', true);
                        $dompdf = new \Dompdf\Dompdf($opts);
                    } else {
                        $dompdf = new \Dompdf\Dompdf();
                    }
                    $dompdf->loadHtml($html);
                    $dompdf->setPaper('A4');
                    $dompdf->render();
                    $out = $dompdf->output();
                    $path = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.pdf';
                    file_put_contents($path, $out);
                    if (file_exists($path) && filesize($path) > 500) return $path;
                }
            } catch (\Throwable $e) { $mpdfError .= ' | Dompdf: ' . $e->getMessage(); }
        }
        // Fallback 2: wkhtmltopdf (Ubuntu: sudo apt install wkhtmltopdf)
        $wk = trim((string)@shell_exec('which wkhtmltopdf 2>&1'));
        if ($wk && !str_contains($wk, 'not found') && file_exists(trim($wk))) {
            try {
                $htmlPath = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.html';
                $pdfPath = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.pdf';
                file_put_contents($htmlPath, $html);
                $cmd = escapeshellarg(trim($wk)) . ' --enable-local-file-access --encoding utf-8 --page-size A4 --margin-top 10mm --margin-bottom 10mm --margin-left 10mm --margin-right 10mm ' . escapeshellarg($htmlPath) . ' ' . escapeshellarg($pdfPath) . ' 2>&1';
                $out = [];
                $ret = 0;
                @exec($cmd, $out, $ret);
                @unlink($htmlPath);
                if ($ret === 0 && file_exists($pdfPath) && filesize($pdfPath) > 500) return $pdfPath;
                $mpdfError .= ' | wkhtmltopdf: ' . implode(' ', $out);
                @unlink($pdfPath);
            } catch (\Throwable $e) { $mpdfError .= ' | wkhtmltopdf exc: ' . $e->getMessage(); }
        }
        // Fallback 3: chromium --headless (se disponível)
        $chrome = trim((string)@shell_exec('which chromium-browser 2>&1'));
        if (!$chrome || str_contains($chrome, 'not found')) $chrome = trim((string)@shell_exec('which google-chrome 2>&1'));
        if (!$chrome || str_contains($chrome, 'not found')) $chrome = trim((string)@shell_exec('which chromium 2>&1'));
        if ($chrome && !str_contains($chrome, 'not found') && file_exists(trim(explode("\n", $chrome)[0]))) {
            try {
                $chromeBin = trim(explode("\n", $chrome)[0]);
                $htmlPath = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.html';
                $pdfPath = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.pdf';
                file_put_contents($htmlPath, $html);
                $cmd = escapeshellarg($chromeBin) . ' --headless --disable-gpu --no-sandbox --print-to-pdf=' . escapeshellarg($pdfPath) . ' ' . escapeshellarg('file://' . $htmlPath) . ' 2>&1';
                $out = [];
                $ret = 0;
                @exec($cmd, $out, $ret);
                @unlink($htmlPath);
                if (file_exists($pdfPath) && filesize($pdfPath) > 500) return $pdfPath;
                $mpdfError .= ' | chromium: ' . implode(' ', $out);
                @unlink($pdfPath);
            } catch (\Throwable $e) { $mpdfError .= ' | chromium exc: ' . $e->getMessage(); }
        }
        // Fallback 4: Simple PHP PDF sem dependências (garante 1-2 páginas mesmo sem mPDF/wkhtmltopdf)
        $simplePath = sys_get_temp_dir() . '/am_doc_' . $transfer_id . '_' . uniqid() . '.pdf';
        if (self::generateSimpleFallbackPdf($transfer_id, $stage, $simplePath)) {
            if (file_exists($simplePath) && filesize($simplePath) > 800) {
                error_log('[assetmgrstatus] generateDocPdf simple fallback ok transfer=' . $transfer_id . ' stage=' . $stage . ' size=' . filesize($simplePath));
                return $simplePath;
            }
            @unlink($simplePath);
            $mpdfError .= ' | simple fallback: arquivo vazio';
        } else {
            $mpdfError .= ' | simple fallback falhou';
        }
        // Se tudo falhou, loga detalhe no servidor e retorna erro curto para UI (1 confirm + 1 resultado)
        $checked = implode(', ', array_filter($tryPaths, 'is_string'));
        $detailed = $mpdfError ?: 'mPDF não encontrado em: ' . $checked;
        error_log('[assetmgrstatus] generateDocPdf falhou transfer=' . $transfer_id . ' stage=' . $stage . ' checked=' . $checked . ' err=' . $detailed);
        // Erro curto para usuário + código de auditoria (log completo fica no error_log e timeline)
        self::$last_ticket_error = 'PDF não gerado (mPDF/Dompdf/wkhtmltopdf não instalados no servidor). Código: MPDF_MISSING_' . $transfer_id;
        return null;
    }

    private static function generateSimpleFallbackPdf(int $transfer_id, string $stage, string $outPath): bool
    {
        // Tenta FPDF bonito (com logo e assinatura) se lib/fpdf disponível, senão cai para texto puro
        $fpdfPath = __DIR__ . '/../lib/fpdf/fpdf.php';
        if (file_exists($fpdfPath)) {
            try {
                require_once $fpdfPath;
                if (class_exists('FPDF')) {
                    return self::generateFpdfBonito($transfer_id, $stage, $outPath);
                }
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] FPDF fallback fail: ' . $e->getMessage());
            }
        }
        // Fallback texto puro (sem logo/assinatura imagem) — garante 1-2 páginas mesmo sem libs
        try {
            $transfer = self::getById($transfer_id);
            if (!$transfer) return false;
            $items = self::getItems($transfer_id);
            $is_pronto = in_array($stage, ['pronto','final'], true);
            $title = $is_pronto ? 'TERMO DE DEVOLUCAO DE EQUIPAMENTO' : 'TERMO DE RETIRADA DE EQUIPAMENTO';
            $dest_name = ($transfer['entity_dest'] && (new \Entity())->getFromDB((int)$transfer['entity_dest'])) ? (new \Entity())->getName() : 'URE Jales';
            $origin_name = '';
            if (!empty($items)) { $first = reset($items); $origin_name = $first['origin_entity_name'] ?? ''; if ($origin_name === '' && !empty($first['origin_entity_id'])) { $eo = new \Entity(); if ($eo->getFromDB((int)$first['origin_entity_id'])) $origin_name = $eo->getName(); } }
            $tech_name = self::getUserName((int)($transfer['users_id_tech'] ?? 0));
            $creator_name = self::getUserName((int)($transfer['users_id_created'] ?? 0));
            $lines = [];
            $lines[] = 'UNIDADE REGIONAL DE ENSINO - REGIAO DE JALES';
            $lines[] = $title . '  -  #' . str_pad($transfer_id, 6, '0', STR_PAD_LEFT) . '  -  ' . date('d/m/Y H:i');
            $lines[] = str_repeat('=', 85);
            $lines[] = '';
            if (!$is_pronto) {
                $lines[] = 'Declaracao: equipamentos retirados pelo responsavel. Retirada verificada no suporte tecnico.';
                $lines[] = '';
                $lines[] = 'Eu, ' . $creator_name . ', declaro retirada dos equipamentos abaixo:';
                $lines[] = '';
                $lines[] = 'Data Retirada: ' . date('d/m/Y', strtotime($transfer['date_creation'])) . '  |  Destino: ' . $dest_name;
                $lines[] = 'Motivo: ' . ($transfer['reason'] ?? '-');
                $lines[] = '';
                $lines[] = 'Equipamentos Retirados:';
                $lines[] = str_repeat('-', 85);
                foreach ($items as $i => $it) { $lines[] = ($i+1) . '. ' . $it['item_name'] . '  [' . str_replace(['Glpi\\CustomAsset\\','Asset'],'',$it['itemtype']) . ']'; }
            } else {
                $lines[] = 'Declaracao: equipamentos devolvidos apos manutencao. Condicoes verificadas na devolucao.';
                $lines[] = '';
                $lines[] = 'Eu, ' . $tech_name . ', tecnico responsavel, declaro devolucao dos equipamentos abaixo:';
                $lines[] = '';
                $lines[] = 'Data Devolucao: ' . date('d/m/Y', strtotime($transfer['date_pronto'] ?: $transfer['date_creation'])) . '  |  Destino: ' . $dest_name;
                $lines[] = 'Tecnico: ' . $tech_name . '  |  Solicitante: ' . $creator_name;
                $lines[] = 'Origem: ' . ($origin_name ?: 'Nao informada') . '  |  Retornando para: ' . ($origin_name ?: 'Escola de origem');
                if (!empty($transfer['reason'])) $lines[] = 'Motivo original: ' . $transfer['reason'];
                $lines[] = '';
                $lines[] = 'Equipamentos Devolvidos:';
                $lines[] = str_repeat('-', 85);
                foreach ($items as $i => $it) {
                    $lines[] = ($i+1) . '. ' . $it['item_name'] . '  [' . str_replace(['Glpi\\CustomAsset\\','Asset'],'',$it['itemtype']) . ']  Status: ' . ($it['final_status'] ? \GlpiPlugin\Assetmgrstatus\MaintenanceRecord::getStatusLabel($it['final_status']) : '-') ;
                    if (!empty($it['final_reason'])) $lines[] = '   Motivo: ' . $it['final_reason'];
                    global $DB;
                    try {
                        $wrow = $DB->request(['SELECT'=>['work_log'],'FROM'=>'glpi_plugin_assetmgrstatus_transfer_items','WHERE'=>['transfers_id'=>$transfer_id,'items_id'=>(int)$it['items_id']],'LIMIT'=>1])->current();
                        $wlog = trim($wrow['work_log'] ?? '');
                        if ($wlog !== '') $lines[] = '   Feito: ' . mb_substr($wlog,0,200);
                    } catch (\Throwable $e) {}
                }
            }
            $lines[] = '';
            $lines[] = str_repeat('-', 85);
            $lines[] = 'Assinaturas: ___________________________      Recebimento: ___________________________';
            $lines[] = 'Gerado em ' . date('d/m/Y H:i') . ' | Transferencia #' . str_pad($transfer_id,6,'0',STR_PAD_LEFT) . ' | URE Jales';
            return self::buildSimplePdfFromLines($lines, $outPath);
        } catch (\Throwable $e) {
            error_log('[assetmgrstatus] simple fallback exception: ' . $e->getMessage());
            return false;
        }
    }

    private static function generateFpdfBonito(int $transfer_id, string $stage, string $outPath): bool
    {
        $transfer = self::getById($transfer_id);
        if (!$transfer) return false;
        $items = self::getItems($transfer_id);
        $is_pronto = in_array($stage, ['pronto','final'], true);
        $title = $is_pronto ? 'Termo de Devolucao de Equipamento' : 'Termo de Retirada de Equipamento';
        $dest_name = ($transfer['entity_dest'] && (new \Entity())->getFromDB((int)$transfer['entity_dest'])) ? (new \Entity())->getName() : 'URE Jales';
        $origin_name = '';
        if (!empty($items)) { $first = reset($items); $origin_name = $first['origin_entity_name'] ?? ''; if ($origin_name === '' && !empty($first['origin_entity_id'])) { $eo = new \Entity(); if ($eo->getFromDB((int)$first['origin_entity_id'])) $origin_name = $eo->getName(); } }
        $tech_name = self::getUserName((int)($transfer['users_id_tech'] ?? 0));
        $creator_name = self::getUserName((int)($transfer['users_id_created'] ?? 0));
        $logoFile = GLPI_ROOT . '/plugins/assetmgrstatus/img/logo_ure.png';
        // dados assinatura
        $sigImage = $transfer['assinatura_image'] ?? '';
        $sigTecImage = $transfer['assinatura_tecnico_image'] ?? '';
        $sigNome = trim($transfer['assinatura_nome'] ?? '');
        $sigDoc = self::maskDocumento($transfer['assinatura_document_type'] ?? '', $transfer['assinatura_document'] ?? '');
        $sigData = !empty($transfer['assinatura_data']) ? date('d/m/Y H:i', strtotime($transfer['assinatura_data'])) : '';
        $tecNome = trim($transfer['assinatura_tecnico_nome'] ?? '');
        $tecDoc = self::maskDocumento($transfer['assinatura_tecnico_document_type'] ?? '', $transfer['assinatura_tecnico_document'] ?? '');
        $tecData = !empty($transfer['assinatura_tecnico_data']) ? date('d/m/Y H:i', strtotime($transfer['assinatura_tecnico_data'])) : '';

        $toIso = fn($s) => @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $s) ?: $s;

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        // Header com logo
        if (file_exists($logoFile)) {
            try { $pdf->Image($logoFile, 10, 8, 28); } catch (\Throwable $e) {}
        }
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(26, 115, 181);
        $pdf->SetXY(42, 10);
        $pdf->Cell(0, 5, $toIso('UNIDADE REGIONAL DE ENSINO - REGIAO DE JALES'), 0, 1, 'L');
        $pdf->SetFont('Helvetica', 'B', 13);
        $pdf->SetXY(42, 16);
        $pdf->Cell(0, 6, $toIso($title), 0, 1, 'L');
        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->SetXY(42, 22);
        $pdf->Cell(0, 4, $toIso('N ' . str_pad($transfer_id, 6, '0', STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i')), 0, 1, 'L');
        $pdf->SetDrawColor(26, 115, 181);
        $pdf->Line(10, 28, 200, 28);
        $pdf->Ln(8);
        // Declaracao
        $pdf->SetFillColor(240, 247, 255);
        $pdf->SetDrawColor(26, 115, 181);
        $pdf->SetTextColor(30, 58, 95);
        $pdf->SetFont('Helvetica', '', 7);
        $decl = $is_pronto
            ? 'A Unidade Regional de Ensino - Regiao de Jales declara que os equipamentos abaixo foram devolvidos apos manutencao. O responsavel pelo recebimento esta ciente das condicoes e do novo status de cada equipamento.'
            : 'A Unidade Regional de Ensino - Regiao de Jales declara que os equipamentos abaixo foram retirados pelo responsavel. Retirada verificada no suporte tecnico.';
        $pdf->Cell(0, 6, $toIso($decl), 1, 1, 'L', true);
        $pdf->Ln(3);
        // Corpo
        $pdf->SetTextColor(45, 45, 45);
        $pdf->SetFont('Helvetica', '', 8);
        $who = $is_pronto ? $tech_name : $creator_name;
        $body = 'Eu, ' . $who . ', declaro ' . ($is_pronto ? 'devolucao' : 'retirada') . ' dos equipamentos abaixo:';
        $pdf->MultiCell(0, 4, $toIso($body), 0, 'L');
        $pdf->Ln(2);
        // Info grid
        $pdf->SetFont('Helvetica', 'B', 6);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->SetFillColor(248, 249, 251);
        $pdf->SetDrawColor(226, 232, 240);
        $info = [
            [$is_pronto ? 'Data Devolucao' : 'Data Retirada', date('d/m/Y', strtotime($is_pronto ? ($transfer['date_pronto'] ?: $transfer['date_creation']) : $transfer['date_creation']))],
            ['Destino', $dest_name],
        ];
        if ($is_pronto) {
            $info[] = ['Tecnico', $tech_name];
            $info[] = ['Solicitante', $creator_name];
            $info[] = ['Origem', $origin_name ?: 'Nao informada'];
            $info[] = ['Retornando para', $origin_name ?: 'Escola de origem'];
        }
        if (!empty($transfer['reason'])) $info[] = ['Motivo', mb_substr($transfer['reason'], 0, 120)];
        foreach (array_chunk($info, 2) as $row) {
            $x = $pdf->GetX(); $y = $pdf->GetY();
            foreach ($row as $col) {
                $pdf->SetFont('Helvetica', 'B', 6);
                $pdf->Cell(95, 4, $toIso($col[0]), 1, 0, 'L', true);
            }
            $pdf->Ln();
            foreach ($row as $col) {
                $pdf->SetFont('Helvetica', '', 7);
                $pdf->Cell(95, 5, $toIso(mb_substr($col[1], 0, 60)), 1, 0, 'L');
            }
            $pdf->Ln(6);
        }
        // Controle de Tempo (igual ao PDF Assinado - transfer_pdf.php)
        $time_pending = ($transfer['date_pending'] && $transfer['date_manutencao']) ? self::getElapsedTime($transfer['date_pending'], $transfer['date_manutencao']) : null;
        $time_manut = ($transfer['date_manutencao'] && $transfer['date_pronto']) ? self::getElapsedTime($transfer['date_manutencao'], $transfer['date_pronto']) : null;
        $time_total = self::getElapsedTime($transfer['date_creation'], $transfer['date_finalizado'] ?: ($transfer['date_pronto'] ?: null));
        if ($time_pending || $time_manut) {
            $pdf->SetFont('Helvetica', 'B', 7);
            $pdf->SetTextColor(26, 115, 181);
            $pdf->Cell(0, 5, $toIso('CONTROLE DE TEMPO'), 0, 1, 'L');
            $pdf->SetDrawColor(226, 232, 240);
            $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
            $pdf->Ln(2);
            $pdf->SetFont('Helvetica', '', 7);
            $pdf->SetFillColor(240, 247, 255);
            $pdf->SetDrawColor(191, 219, 254);
            $pdf->SetTextColor(26, 115, 181);
            if ($time_pending) {
                $pdf->Cell(63, 8, $toIso('Pendente: ' . $time_pending['label']), 1, 0, 'C', true);
            } else {
                $pdf->Cell(63, 8, '', 0, 0, 'C');
            }
            if ($time_manut) {
                $pdf->Cell(64, 8, $toIso('Manutencao: ' . $time_manut['label']), 1, 0, 'C', true);
            } else {
                $pdf->Cell(64, 8, '', 0, 0, 'C');
            }
            $pdf->Cell(63, 8, $toIso('Total: ' . $time_total['label']), 1, 1, 'C', true);
            $pdf->Ln(3);
        }
        // Tabela equipamentos
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetFillColor(26, 115, 181);
        $pdf->SetTextColor(255, 255, 255);
        if (!$is_pronto) {
            $pdf->Cell(10, 6, '#', 1, 0, 'C', true);
            $pdf->Cell(110, 6, 'Equipamento', 1, 0, 'L', true);
            $pdf->Cell(70, 6, 'Tipo', 1, 1, 'L', true);
            $pdf->SetTextColor(45, 45, 45);
            $pdf->SetFont('Helvetica', '', 7);
            foreach ($items as $i => $it) {
                $pdf->Cell(10, 5, (string)($i+1), 1, 0, 'C');
                $pdf->Cell(110, 5, $toIso(mb_substr($it['item_name'], 0, 50)), 1, 0, 'L');
                $pdf->Cell(70, 5, $toIso(mb_substr(str_replace(['Glpi\\CustomAsset\\','Asset'],'',$it['itemtype']),0,30)), 1, 1, 'L');
            }
        } else {
            // 7 colunas idênticas ao transfer_pdf.php (pronto): #, Nome, Tipo, Status, Motivo, Componentes, Feito
            $pdf->Cell(7, 6, '#', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Equipamento', 1, 0, 'L', true);
            $pdf->Cell(18, 6, 'Tipo', 1, 0, 'L', true);
            $pdf->Cell(22, 6, 'Status', 1, 0, 'L', true);
            $pdf->Cell(30, 6, 'Motivo', 1, 0, 'L', true);
            $pdf->Cell(35, 6, 'Componentes', 1, 0, 'L', true);
            $pdf->Cell(48, 6, 'O Que Foi Feito', 1, 1, 'L', true);
            $pdf->SetTextColor(45, 45, 45);
            $pdf->SetFont('Helvetica', '', 5);
            $compList = \GlpiPlugin\Assetmgrstatus\MaintenanceRecord::getComponents();
            foreach ($items as $i => $it) {
                $typeShort = mb_substr(str_replace(['Glpi\\CustomAsset\\','Asset'],'',$it['itemtype']),0,12);
                $statusLabel = $it['final_status'] ? \GlpiPlugin\Assetmgrstatus\MaintenanceRecord::getStatusLabel($it['final_status']) : '-';
                $reason = mb_substr($it['final_reason'] ?? '-',0,28);
                // Componentes: final_components + work_components resolved
                global $DB;
                $wrow = null; $wlog = ''; $wcomps = [];
                try {
                    $witer = $DB->request(['SELECT'=>['work_log','work_components'],'FROM'=>'glpi_plugin_assetmgrstatus_transfer_items','WHERE'=>['transfers_id'=>$transfer_id,'items_id'=>(int)$it['items_id']],'LIMIT'=>1]);
                    $wrow = $witer->count() > 0 ? $witer->current() : null;
                    $wlog = trim($wrow['work_log'] ?? '');
                    $wcomps = !empty($wrow['work_components']) ? json_decode($wrow['work_components'], true) : [];
                    if (!is_array($wcomps)) $wcomps = [];
                } catch (\Throwable $e) {}
                $resolved = [];
                foreach ($wcomps as $ck => $cs) { if ($cs === 'resolved') $resolved[] = $compList[$ck] ?? $ck; }
                $fcomps = !empty($it['final_components']) ? json_decode($it['final_components'], true) : [];
                if (!is_array($fcomps)) $fcomps = [];
                $compTxt = [];
                foreach ($fcomps as $ckey => $cdesc) { $clabel = $compList[$ckey] ?? $ckey; $compTxt[] = $clabel . ($cdesc ? ':'.mb_substr($cdesc,0,12) : ''); }
                foreach ($resolved as $rl) { $compTxt[] = $rl . '(ok)'; }
                $compStr = !empty($compTxt) ? mb_substr(implode('; ', $compTxt),0,32) : '-';
                $wlogShort = $wlog !== '' ? mb_substr($wlog,0,35) : '-';
                // Evita overflow vertical: se Y > 265, nova página e reimprime cabeçalho
                if ($pdf->GetY() > 265) {
                    $pdf->AddPage();
                    $pdf->SetFont('Helvetica', 'B', 5);
                    $pdf->SetFillColor(26, 115, 181); $pdf->SetTextColor(255,255,255);
                    $pdf->Cell(7, 6, '#', 1, 0, 'C', true);
                    $pdf->Cell(30, 6, 'Equipamento', 1, 0, 'L', true);
                    $pdf->Cell(18, 6, 'Tipo', 1, 0, 'L', true);
                    $pdf->Cell(22, 6, 'Status', 1, 0, 'L', true);
                    $pdf->Cell(30, 6, 'Motivo', 1, 0, 'L', true);
                    $pdf->Cell(35, 6, 'Componentes', 1, 0, 'L', true);
                    $pdf->Cell(48, 6, 'O Que Foi Feito', 1, 1, 'L', true);
                    $pdf->SetTextColor(45,45,45); $pdf->SetFont('Helvetica','',5);
                }
                $pdf->Cell(7, 5, (string)($i+1), 1, 0, 'C');
                $pdf->Cell(30, 5, $toIso(mb_substr($it['item_name'],0,22)), 1, 0, 'L');
                $pdf->Cell(18, 5, $toIso($typeShort), 1, 0, 'L');
                $pdf->Cell(22, 5, $toIso(mb_substr($statusLabel,0,13)), 1, 0, 'L');
                $pdf->Cell(30, 5, $toIso($reason), 1, 0, 'L');
                $pdf->Cell(35, 5, $toIso($compStr), 1, 0, 'L');
                $pdf->Cell(48, 5, $toIso($wlogShort), 1, 1, 'L');
            }
        }
        $pdf->Ln(4);
        // Assinaturas com imagem
        $pdf->SetFont('Helvetica', 'B', 7);
        $pdf->SetTextColor(30, 58, 95);
        $ySig = $pdf->GetY();
        if ($pdf->GetY() > 240) { $pdf->AddPage(); $ySig = $pdf->GetY(); }
        // Caixa entrega (tecnico)
        $pdf->SetDrawColor(180, 180, 180);
        $pdf->Rect(10, $ySig, 92, 35);
        $pdf->SetXY(10, $ySig+2);
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->Cell(92, 4, $toIso('Entrega (Tecnico): ' . $tech_name), 0, 1, 'C');
        // tenta imagem tecnico
        $tmpTec = null;
        if (!empty($sigTecImage) && strpos($sigTecImage, 'data:image') === 0) {
            try {
                $parts = explode(',', $sigTecImage, 2);
                $data = base64_decode($parts[1] ?? '');
                if ($data) { $tmpTec = sys_get_temp_dir() . '/sigtec_' . uniqid() . '.png'; file_put_contents($tmpTec, $data); $pdf->Image($tmpTec, 22, $ySig+8, 68, 14); }
            } catch (\Throwable $e) {}
        }
        $pdf->Line(15, $ySig+24, 97, $ySig+24);
        $pdf->SetXY(10, $ySig+26);
        $pdf->Cell(92, 4, $toIso($tecNome ? $tecNome . ' - ' . $tecDoc : 'Documento: __________________'), 0, 1, 'C');
        // Caixa recebimento
        $pdf->Rect(108, $ySig, 92, 35);
        $pdf->SetXY(108, $ySig+2);
        $pdf->Cell(92, 4, $toIso('Recebimento: ' . ($sigNome ?: '__________________')), 0, 1, 'C');
        $tmpRec = null;
        if (!empty($sigImage) && strpos($sigImage, 'data:image') === 0) {
            try {
                $parts = explode(',', $sigImage, 2);
                $data = base64_decode($parts[1] ?? '');
                if ($data) { $tmpRec = sys_get_temp_dir() . '/sigrec_' . uniqid() . '.png'; file_put_contents($tmpRec, $data); $pdf->Image($tmpRec, 120, $ySig+8, 68, 14); }
            } catch (\Throwable $e) {}
        }
        $pdf->Line(113, $ySig+24, 195, $ySig+24);
        $pdf->SetXY(108, $ySig+26);
        $pdf->Cell(92, 4, $toIso($sigNome ? $sigNome . ' - ' . $sigDoc : 'Documento: __________________'), 0, 1, 'C');
        $pdf->SetXY(108, $ySig+30);
        $pdf->SetFont('Helvetica', '', 5);
        $pdf->Cell(92, 3, $toIso($sigData ? $sigData : 'Data: ____/____/______'), 0, 1, 'C');
        $pdf->Ln(6);
        // Rodape
        $pdf->SetFont('Helvetica', '', 6);
        $pdf->SetTextColor(156, 163, 175);
        $pdf->Cell(0, 4, $toIso('Gerado em ' . date('d/m/Y H:i') . ' | Transferencia #' . str_pad($transfer_id,6,'0',STR_PAD_LEFT) . ' | URE Jales - Suporte Tecnico'), 0, 1, 'C');
        if ($tmpTec && file_exists($tmpTec)) @unlink($tmpTec);
        if ($tmpRec && file_exists($tmpRec)) @unlink($tmpRec);
        try { $pdf->Output('F', $outPath); return file_exists($outPath) && filesize($outPath) > 800; } catch (\Throwable $e) { error_log('[assetmgrstatus] FPDF Output fail: ' . $e->getMessage()); return false; }
    }

    private static function buildSimplePdfFromLines(array $lines, string $outPath): bool
    {
        // Translitera UTF-8 -> ISO-8859-1 para Helvetica core
        $clean = [];
        foreach ($lines as $l) {
            $l = (string)$l;
            // quebra linhas muito longas em 85 chars
            $wrapped = [];
            $l = str_replace("\r", '', $l);
            foreach (explode("\n", $l) as $part) {
                $part = trim($part);
                if ($part === '') { $wrapped[] = ''; continue; }
                // iconv
                $partIso = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $part);
                if ($partIso === false) $partIso = $part;
                // word wrap 85
                $words = explode(' ', $partIso);
                $cur = '';
                foreach ($words as $w) {
                    if (strlen($cur . ' ' . $w) > 85) { $wrapped[] = trim($cur); $cur = $w; } else { $cur = $cur === '' ? $w : $cur . ' ' . $w; }
                }
                if ($cur !== '') $wrapped[] = trim($cur);
                if (empty($words)) $wrapped[] = '';
            }
            foreach ($wrapped as $wl) $clean[] = $wl;
        }
        $pages = array_chunk($clean, 45);
        if (empty($pages)) $pages = [[]];
        // PDF em memoria
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        $objects = [];
        // Objetos: 1 Catalog, 2 Pages, 3 Font, depois Page+Content por pagina
        $fontObjNum = 3;
        $catalogNum = 1;
        $pagesNum = 2;
        // Reserva numeros
        $pageObjNums = [];
        $contentObjNums = [];
        $nextNum = 4;
        foreach ($pages as $i => $pg) {
            $pageObjNums[$i] = $nextNum++;
            $contentObjNums[$i] = $nextNum++;
        }
        $totalObjs = $nextNum - 1;
        // Helper para escapar texto PDF
        $esc = function($s) { return str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)','\\r'], $s); };
        // Constroi objetos em ordem numerica
        $objs = [];
        // 1 Catalog
        $objs[$catalogNum] = "<< /Type /Catalog /Pages $pagesNum 0 R >>";
        // 2 Pages
        $kids = implode(' ', array_map(fn($n) => "$n 0 R", $pageObjNums));
        $objs[$pagesNum] = "<< /Type /Pages /Kids [$kids] /Count " . count($pages) . " >>";
        // 3 Font
        $objs[$fontObjNum] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        // Paginas e conteudos
        foreach ($pages as $idx => $pgLines) {
            $pNum = $pageObjNums[$idx];
            $cNum = $contentObjNums[$idx];
            // Conteudo
            $content = "BT\n/F1 9 Tf\n";
            $y = 800;
            foreach ($pgLines as $line) {
                $content .= sprintf("1 0 0 1 40 %.2F Tm (%s) Tj\n", $y, $esc($line));
                $y -= 13;
                if ($y < 40) break;
            }
            // numeracao pagina
            $content .= sprintf("1 0 0 1 500 20 Tm (%d/%d) Tj\n", $idx+1, count($pages));
            $content .= "ET\n";
            $objs[$cNum] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
            $objs[$pNum] = "<< /Type /Page /Parent $pagesNum 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 $fontObjNum 0 R >> >> /Contents $cNum 0 R >>";
        }
        // Escreve PDF com offsets
        $pdf = "%PDF-1.4\n";
        $offsets[0] = 0;
        for ($i=1; $i<=$totalObjs; $i++) {
            $offsets[$i] = strlen($pdf);
            $pdf .= $i . " 0 obj\n" . $objs[$i] . "\nendobj\n";
        }
        $xrefPos = strlen($pdf);
        $pdf .= "xref\n0 " . ($totalObjs+1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i=1; $i<=$totalObjs; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size " . ($totalObjs+1) . " /Root $catalogNum 0 R >>\n";
        $pdf .= "startxref\n$xrefPos\n%%EOF\n";
        return @file_put_contents($outPath, $pdf) !== false;
    }

    // -------------------------------------------------------
    // Impressão no servidor (CUPS — Ubuntu) — fila da HP
    // -------------------------------------------------------

    /**
     * Lista impressoras disponíveis no CUPS (lpstat -p)
     * @return string[] nomes das impressoras
     */
    public static function getAvailablePrinters(): array
    {
        $out = [];
        // tenta lpstat -p
        @exec('lpstat -p 2>&1', $out, $ret);
        $printers = [];
        foreach ($out as $line) {
            // formato: "printer HP_LaserJet_1020 is idle..."
            if (preg_match('/^printer\s+(\S+)/i', trim($line), $m)) {
                $printers[] = $m[1];
            }
        }
        // também tenta cupsctl? fallback via lpstat -e (lista destinos)
        if (empty($printers)) {
            $out2 = [];
            @exec('lpstat -e 2>&1', $out2, $ret2);
            foreach ($out2 as $line) {
                $name = trim($line);
                if ($name !== '' && !str_starts_with($name, ' ') && stripos($name, 'error') === false && stripos($name, 'bash') === false) {
                    // cada linha é um nome
                    $printers[] = $name;
                }
            }
        }
        return array_values(array_unique($printers));
    }

    public static function getDefaultPrinter(): ?string
    {
        $out = [];
        @exec('lpstat -d 2>&1', $out, $ret);
        foreach ($out as $line) {
            // "system default destination: HP_LaserJet_1020"
            if (preg_match('/system default destination:\s*(\S+)/i', $line, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    /**
     * Encontra impressora HP preferencial.
     * Prioridade: 1) nome contém HP, 2) default, 3) primeira disponível
     */
    public static function findHpPrinter(): ?string
    {
        // 1) Verifica config específica do plugin se existir (glpi_configs plugin:assetmgrstatus)
        try {
            if (class_exists('Config')) {
                // GLPI 10+ usa Config::getConfigurationValues('plugin:assetmgrstatus')
                if (method_exists('Config', 'getConfigurationValues')) {
                    $vals = \Config::getConfigurationValues('plugin:assetmgrstatus');
                    if (!empty($vals['hp_printer'])) {
                        $cfg = trim((string)$vals['hp_printer']);
                        if ($cfg !== '') return $cfg;
                    }
                    // legacy key
                    if (!empty($vals['printer_name'])) {
                        $cfg = trim((string)$vals['printer_name']);
                        if ($cfg !== '') return $cfg;
                    }
                }
            }
        } catch (\Throwable $e) {}

        // 2) env var
        $env = getenv('ASSETMGR_HP_PRINTER');
        if ($env && trim($env) !== '') return trim($env);

        $available = self::getAvailablePrinters();
        // procura HP (case-insensitive)
        foreach ($available as $p) {
            if (stripos($p, 'hp') !== false) return $p;
        }
        // fallback: default
        $def = self::getDefaultPrinter();
        if ($def) return $def;
        // último fallback: primeira disponível
        return $available[0] ?? null;
    }

    public static function isPrintCommandAvailable(): array
    {
        $lp = trim((string)@shell_exec('which lp 2>&1'));
        $lpr = trim((string)@shell_exec('which lpr 2>&1'));
        // fallback para command -v
        if ($lp === '' || str_contains($lp, 'not found')) {
            $tmp = trim((string)@shell_exec('command -v lp 2>&1'));
            if ($tmp !== '' && !str_contains($tmp, 'not found')) $lp = $tmp;
        }
        if ($lpr === '' || str_contains($lpr, 'not found')) {
            $tmp = trim((string)@shell_exec('command -v lpr 2>&1'));
            if ($tmp !== '' && !str_contains($tmp, 'not found')) $lpr = $tmp;
        }
        return ['lp' => $lp, 'lpr' => $lpr];
    }

    /**
     * Envia o PDF assinado para fila de impressão CUPS do servidor (Ubuntu).
     * Gera o PDF via mPDF e executa `lp`/`lpr`.
     * @return array{ok:bool, printer?:string, output?:string, error?:string, request_id?:string}
     */
    public static function printOnServer(int $transfer_id, string $stage = 'pronto', ?string $preferred_printer = null, ?string $pdfBase64 = null): array
    {
        global $DB;
        $transfer = self::getById($transfer_id);
        if (!$transfer) {
            return ['ok' => false, 'error' => 'Transferência não encontrada'];
        }
        // Valida stage
        if (!in_array($stage, ['transfer','pronto','final'], true)) $stage = 'pronto';

        // Se cliente enviou PDF base64 (gerado via html2pdf no navegador - exatamente o que vê no PDF Assinado), usa ele
        // Isso garante impressão idêntica à página, sem depender de mPDF/wkhtmltopdf no servidor
        $pdf_path = null;
        $isClientPdf = false;
        if (!empty($pdfBase64)) {
            try {
                $raw = base64_decode($pdfBase64, true);
                if ($raw !== false && strlen($raw) > 800 && substr($raw, 0, 5) === '%PDF-') {
                    $pdf_path = sys_get_temp_dir() . '/am_client_' . $transfer_id . '_' . uniqid() . '.pdf';
                    if (@file_put_contents($pdf_path, $raw) !== false && file_exists($pdf_path) && filesize($pdf_path) > 800) {
                        $isClientPdf = true;
                        error_log('[assetmgrstatus] printOnServer: PDF cliente usado transfer=' . $transfer_id . ' size=' . filesize($pdf_path));
                    } else {
                        $pdf_path = null;
                        error_log('[assetmgrstatus] printOnServer: falha ao salvar PDF cliente transfer=' . $transfer_id);
                    }
                } else {
                    error_log('[assetmgrstatus] printOnServer: pdfBase64 inválido transfer=' . $transfer_id . ' len=' . strlen($pdfBase64 ?? '') . ' header=' . json_encode(substr($raw ?? '',0,10)));
                }
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] printOnServer pdfBase64 exception: ' . $e->getMessage());
                $pdf_path = null;
            }
        }
        // Fallback: gera PDF no servidor (mPDF/FPDF) se cliente não enviou
        if (!$pdf_path) {
            // Gera PDF temporário (usa mPDF — mesmo HTML do "PDF Assinado" em transfer_pdf.php / renderDocHtml)
            // CORREÇÃO 60 folhas: NUNCA imprimir HTML puro no CUPS. Se o PDF não for gerado, retorna erro
            // em vez de enviar .html para "lp" — CUPS trataria HTML como texto puro (source code) e
            // imprimiria 60+ folhas com o código HTML, não o termo renderizado.
            $pdf_path = self::generateDocPdf($transfer_id, $stage);
        }
        if (!$pdf_path || !file_exists($pdf_path)) {
            $err = self::$last_ticket_error ?: 'Falha ao gerar PDF (dependência não instalada)';
            error_log('[assetmgrstatus] printOnServer: PDF não gerado transfer=' . $transfer_id . ' stage=' . $stage . ' err=' . $err);
            // Auditoria: registra tentativa falha na timeline
            try { self::logStatus($transfer_id, $transfer['status'], '❌ Falha ao imprimir na HP — PDF não gerado (' . $err . ') por ' . self::getUserName(\Session::getLoginUserID())); } catch (\Throwable $e) {}
            // Retorno curto para UI (1 confirm + 1 resultado). Detalhe completo fica no error_log/timeline.
            return ['ok' => false, 'error' => $err, 'audit' => 'Transferência #' . str_pad($transfer_id,4,'0',STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . ' | Usuário: ' . self::getUserName(\Session::getLoginUserID()) . ' | Código: MPDF_MISSING'];
        }
        // Valida que é um PDF real (não HTML renomeado) — evita 60 folhas
        $fh = @fopen($pdf_path, 'rb');
        $header = $fh ? @fread($fh, 5) : '';
        if ($fh) @fclose($fh);
        if ($header !== '%PDF-') {
            $size = @filesize($pdf_path);
            @unlink($pdf_path);
            error_log('[assetmgrstatus] printOnServer: arquivo não é PDF header=' . json_encode($header) . ' size=' . $size . ' transfer=' . $transfer_id);
            try { self::logStatus($transfer_id, $transfer['status'], '❌ Falha ao imprimir — arquivo não é PDF válido por ' . self::getUserName(\Session::getLoginUserID())); } catch (\Throwable $e) {}
            return ['ok' => false, 'error' => 'Arquivo não é PDF válido — impressão bloqueada.', 'audit' => 'Transferência #' . str_pad($transfer_id,4,'0',STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . ' | Código: INVALID_PDF'];
        }
        // Valida tamanho razoável
        $fsize = @filesize($pdf_path);
        if ($fsize !== false && $fsize < 800) {
            @unlink($pdf_path);
            try { self::logStatus($transfer_id, $transfer['status'], '❌ Falha ao imprimir — PDF vazio/corrompido por ' . self::getUserName(\Session::getLoginUserID())); } catch (\Throwable $e) {}
            return ['ok' => false, 'error' => 'PDF vazio/corrompido — impressão bloqueada.', 'audit' => 'Transferência #' . str_pad($transfer_id,4,'0',STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . ' | Código: EMPTY_PDF'];
        }
        if ($fsize !== false && $fsize > 15 * 1024 * 1024) {
            @unlink($pdf_path);
            try { self::logStatus($transfer_id, $transfer['status'], '❌ Falha ao imprimir — PDF muito grande por ' . self::getUserName(\Session::getLoginUserID())); } catch (\Throwable $e) {}
            return ['ok' => false, 'error' => 'PDF muito grande — impressão bloqueada.', 'audit' => 'Transferência #' . str_pad($transfer_id,4,'0',STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . ' | Código: HUGE_PDF'];
        }
        // Conta páginas de forma leve (busca "/Type /Page" no PDF) — aborta se > 10 páginas
        try {
            $raw = @file_get_contents($pdf_path);
            if ($raw !== false) {
                $pages = substr_count($raw, '/Type /Page') - substr_count($raw, '/Type /Pages');
                // Fallback: conta "/Page" isolado se técnica acima falhar
                if ($pages <= 0) {
                    $pages = preg_match_all('/\/Type\s*\/Page[^s]/', $raw);
                }
                if ($pages > 10) {
                    @unlink($pdf_path);
                    error_log('[assetmgrstatus] printOnServer: PDF com ' . $pages . ' páginas bloqueado transfer=' . $transfer_id);
                    try { self::logStatus($transfer_id, $transfer['status'], '❌ Bloqueado: PDF com ' . $pages . ' páginas por ' . self::getUserName(\Session::getLoginUserID())); } catch (\Throwable $e) {}
                    return ['ok' => false, 'error' => 'PDF com ' . $pages . ' páginas — bloqueado (esperado 1-2).', 'audit' => 'Transferência #' . str_pad($transfer_id,4,'0',STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . ' | Páginas: ' . $pages . ' | Código: TOO_MANY_PAGES'];
                }
                // Log informativo
                error_log('[assetmgrstatus] printOnServer: PDF ok transfer=' . $transfer_id . ' stage=' . $stage . ' size=' . $fsize . ' pages~' . $pages);
            }
        } catch (\Throwable $e) { /* ignora contagem, segue impressão */ }

        // Escolhe impressora
        $printer = null;
        if ($preferred_printer !== null && trim($preferred_printer) !== '') {
            $printer = trim($preferred_printer);
        } else {
            $printer = self::findHpPrinter();
        }

        // Verifica se exec/shell_exec estão disponíveis (podem estar em disable_functions)
        $execDisabled = !function_exists('exec') || !is_callable('exec');
        $shellDisabled = !function_exists('shell_exec') || !is_callable('shell_exec');
        if ($execDisabled && $shellDisabled) {
            @unlink($pdf_path);
            return ['ok' => false, 'error' => 'Funções exec/shell_exec desabilitadas no PHP (disable_functions). Habilite exec no php.ini do servidor Ubuntu para imprimir via CUPS.'];
        }

        $available = self::getAvailablePrinters();
        $default = self::getDefaultPrinter();
        $cmds = self::isPrintCommandAvailable();
        $hasLp = !empty($cmds['lp']) && !str_contains($cmds['lp'], 'not found');
        $hasLpr = !empty($cmds['lpr']) && !str_contains($cmds['lpr'], 'not found');

        if (!$hasLp && !$hasLpr) {
            @unlink($pdf_path);
            $diag = 'lpstat -p: ' . implode('; ', $available) . ' | default: ' . ($default ?? 'nenhum') . ' | which lp: ' . ($cmds['lp'] ?: 'não encontrado');
            return ['ok' => false, 'error' => 'Serviço de impressão CUPS não encontrado no servidor (comandos lp/lpr ausentes). Instale cups: sudo apt install cups && sudo systemctl enable --now cups. Detalhe: ' . $diag];
        }

        if ($printer === null || $printer === '') {
            @unlink($pdf_path);
            return ['ok' => false, 'error' => 'Nenhuma impressora encontrada no servidor CUPS. Configure a impressora HP no Ubuntu (Configurações > Impressoras ou lpadmin) e defina como padrão. Impressoras detectadas: ' . (empty($available) ? 'nenhuma' : implode(', ', $available)) . '. Dica: sudo lpstat -p -d'];
        }

        // Garante que CUPS (usuário lp) consegue ler o arquivo (www-data cria com 600)
        @chmod($pdf_path, 0644);

        // Se impressora escolhida não está na lista mas há impressoras, avisa mas tenta mesmo assim (pode ser nome com alias)
        // CORREÇÃO 60 folhas: sempre forçar 1 cópia, A4 e fit-to-page para não imprimir 60 páginas/cópias
        $output = [];
        $ret = -1;
        $cmd = '';
        $printed = false;
        $lastOut = '';
        $title = 'Termo-' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . '-' . $stage;

        // Tenta lp primeiro — com opções explícitas de página (evita 60 cópias/páginas por padrão CUPS)
        if ($hasLp) {
            $cmd = 'lp';
            if ($printer) $cmd .= ' -d ' . escapeshellarg($printer);
            $cmd .= ' -t ' . escapeshellarg($title);
            $cmd .= ' -n 1 -o media=A4 -o fit-to-page -o sides=one-sided';
            $cmd .= ' ' . escapeshellarg($pdf_path) . ' 2>&1';
            @exec($cmd, $output, $ret);
            $lastOut = implode("\n", $output);
            if ($ret === 0) {
                $printed = true;
            } else {
                // fallback com título e fit-to-page se simples falhar
                if (stripos($lastOut, 'Unknown destination') !== false || stripos($lastOut, 'unknown printer') !== false) {
                    $out2 = [];
                    $ret2 = -1;
                    $cmd2 = 'lp -t ' . escapeshellarg($title) . ' -n 1 -o media=A4 -o fit-to-page -o sides=one-sided ' . escapeshellarg($pdf_path) . ' 2>&1';
                    @exec($cmd2, $out2, $ret2);
                    if ($ret2 === 0) {
                        $printed = true;
                        $lastOut = implode("\n", $out2) . " (fallback sem -d)";
                        $printer = $default ?? 'default';
                    }
                } else {
                    // tenta sem fit-to-page como último recurso
                    $out3 = [];
                    $ret3 = -1;
                    $cmd3 = 'lp -d ' . escapeshellarg($printer) . ' -t ' . escapeshellarg($title) . ' ' . escapeshellarg($pdf_path) . ' 2>&1';
                    @exec($cmd3, $out3, $ret3);
                    if ($ret3 === 0) {
                        $printed = true;
                        $lastOut = implode("\n", $out3) . " (fallback simples)";
                    }
                }
            }
        }

        // Se ainda não imprimiu e lpr disponível, tenta lpr com 1 cópia
        if (!$printed && $hasLpr) {
            $output = [];
            $cmd = 'lpr';
            if ($printer) $cmd .= ' -P ' . escapeshellarg($printer);
            $cmd .= ' -# 1 -o media=A4 -o fit-to-page -o sides=one-sided';
            $cmd .= ' ' . escapeshellarg($pdf_path) . ' 2>&1';
            @exec($cmd, $output, $ret);
            $lastOut = implode("\n", $output);
            if ($ret === 0) $printed = true;
        }
        // Debug: verifica fila após envio (lpstat -o)
        if ($printed) {
            $qOut = [];
            @exec('lpstat -o ' . escapeshellarg($printer) . ' 2>&1', $qOut, $qRet);
            $lastOut .= ($lastOut ? "\n" : "") . "Fila: " . implode('; ', $qOut);
        }

        // Log e cleanup
        $request_id = '';
        if ($printed && preg_match('/request id is\s+(\S+)/i', $lastOut, $m)) {
            $request_id = $m[1];
        } elseif ($printed && preg_match('/(\S+-\d+)/', $lastOut, $m)) {
            $request_id = $m[1];
        }

        // Remove PDF temporário após envio (CUPS já copiou)
        @unlink($pdf_path);

        if (!$printed) {
            $msgShort = 'Falha ao enviar para HP (' . $printer . ')';
            $auditFail = 'Transferência #' . str_pad($transfer_id,4,'0',STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . ' | Usuário: ' . self::getUserName(\Session::getLoginUserID()) . ' | Impressora: ' . $printer . ' | Código: CUPS_FAIL';
            error_log('[assetmgrstatus] printOnServer fail transfer=' . $transfer_id . ' printer=' . $printer . ' out=' . $lastOut . ' ret=' . $ret);
            try { self::logStatus($transfer_id, $transfer['status'], '❌ Falha ao imprimir na HP (' . $printer . ') por ' . self::getUserName(\Session::getLoginUserID()) . ' — ' . $msgShort); } catch (\Throwable $e) {}
            // Detalhe completo vai para error_log/timeline; UI recebe só curto + audit
            return ['ok' => false, 'printer' => $printer, 'error' => $msgShort, 'output' => $lastOut, 'audit' => $auditFail, 'detail' => 'Saída CUPS: ' . ($lastOut ?: '(vazia)') . ' | Disponíveis: ' . (empty($available) ? 'nenhuma' : implode(', ', $available))];
        }

        // Sucesso: registra no timeline e no chamado
        try {
            self::logStatus($transfer_id, $transfer['status'], '🖨️ Impresso na HP (' . $printer . ') — fila CUPS' . ($request_id ? ' ' . $request_id : '') . ' por ' . self::getUserName(\Session::getLoginUserID()));
        } catch (\Throwable $e) {}
        if (!empty($transfer['tickets_id'])) {
            try {
                self::addTicketFollowup((int)$transfer['tickets_id'],
                    "🖨️ Termo da Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " enviado para **impressão na HP** (fila CUPS do servidor).\n"
                    . "Impressora: `" . $printer . "`" . ($request_id ? " — Job: $request_id" : "") . "\n"
                    . "Por: " . self::getUserName(\Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . " — IP " . ($_SERVER['REMOTE_ADDR'] ?? '')
                );
            } catch (\Throwable $e) {}
        }

        $auditOk = 'Transferência #' . str_pad($transfer_id,4,'0',STR_PAD_LEFT) . ' | ' . date('d/m/Y H:i') . ' | Usuário: ' . self::getUserName(\Session::getLoginUserID()) . ' | Impressora: ' . $printer . ($request_id ? ' | Job: ' . $request_id : '');
        return ['ok' => true, 'printer' => $printer, 'output' => $lastOut, 'request_id' => $request_id, 'audit' => $auditOk];
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

        // Assinatura digital via tablet
        $sig_image      = $transfer['assinatura_image'] ?? '';
        $sig_type       = $transfer['assinatura_document_type'] ?? '';
        $sig_doc_raw    = $transfer['assinatura_document'] ?? '';
        $sig_nome       = trim($transfer['assinatura_nome'] ?? '');
        $sig_data       = $transfer['assinatura_data'] ?? '';
        $sig_masked     = $sig_doc_raw ? self::maskDocumento($sig_type, $sig_doc_raw) : '';
        $sig_data_fmt   = $sig_data ? date('d/m/Y H:i', strtotime($sig_data)) : '';
        $is_assinado    = !empty($sig_image);

        $logo_file = GLPI_ROOT . '/plugins/assetmgrstatus/img/logo_ure.png';
        $logo_b64  = file_exists($logo_file) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logo_file)) : '';

        $h = '<html><head><meta charset="utf-8"><style>';
        $h .= 'body{font-family:Arial,sans-serif;font-size:11px;color:#2d2d2d;line-height:1.4;word-wrap:break-word;overflow-wrap:break-word;}';
        $h .= '.hdr{width:100%;border-bottom:2px solid #1a73b5;padding-bottom:8px;margin-bottom:12px;}';
        $h .= '.hdr td{vertical-align:middle;}';
        $h .= '.t1{font-size:15px;font-weight:bold;color:#1a73b5;}';
        $h .= '.t2{font-size:9px;color:#9ca3af;text-align:right;}';
        $h .= '.decl{background:#f0f7ff;border-left:3px solid #1a73b5;padding:8px 12px;font-size:10px;color:#1e3a5f;margin-bottom:12px;word-break:break-word;overflow-wrap:break-word;}';
        $h .= 'table.info{width:100%;border-collapse:collapse;margin-bottom:12px;table-layout:fixed;word-wrap:break-word;}';
        $h .= 'table.info td{border:1px solid #e2e8f0;background:#f8f9fb;padding:5px 8px;font-size:10px;word-break:break-all;word-wrap:break-word;overflow-wrap:break-word;vertical-align:top;}';
        $h .= 'table.info td b{display:block;font-size:8px;text-transform:uppercase;color:#9ca3af;margin-bottom:2px;}';
        $h .= 'h3{font-size:9.5px;color:#1a73b5;text-transform:uppercase;border-bottom:1px solid #e2e8f0;padding-bottom:3px;margin:10px 0 6px;}';
        $h .= 'table.eq{width:100%;border-collapse:collapse;font-size:10px;margin-bottom:12px;table-layout:fixed;word-wrap:break-word;}';
        $h .= 'table.eq th{background:#1a73b5;color:#fff;padding:4px 6px;font-size:9px;text-align:left;word-break:break-word;}';
        $h .= 'table.eq td{border-bottom:1px solid #f0f2f8;padding:4px 6px;word-break:break-all;word-wrap:break-word;overflow-wrap:break-word;overflow-wrap:anywhere;vertical-align:top;}';
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
            $h .= '<table class="info"><tr><td><b>Data de Retirada</b>' . date('d/m/Y', strtotime($transfer['date_creation'])) . '</td><td><b>Escola / URE de Destino</b>' . htmlspecialchars(self::truncPdf($dest_name, 50)) . '</td></tr>';
            $h .= '<tr><td colspan="2"><b>Motivo da Transferência</b>' . htmlspecialchars(self::truncPdf($transfer['reason'] ?? '', 200)) . '</td></tr></table>';
            $h .= '<h3>Equipamento(s) Retirado(s)</h3><table class="eq"><tr><th style="width:6%">#</th><th style="width:62%">Nome do Equipamento</th><th style="width:32%">Tipo</th></tr>';
            foreach ($items as $i => $item) {
                $h .= '<tr><td>' . ($i + 1) . '</td><td><b>' . htmlspecialchars(self::truncPdf($item['item_name'], 60)) . '</b></td><td>' . htmlspecialchars(self::truncPdf(str_replace(['Glpi\\CustomAsset\\', 'Asset'], '', $item['itemtype']), 20)) . '</td></tr>';
            }
            $h .= '</table>';
        } else {
            $h .= '<div class="decl">A Unidade Regional de Ensino – Região de Jales declara que o(s) equipamento(s) abaixo mencionado(s) foi(ram) devolvido(s) após a realização dos procedimentos de manutenção técnica. O responsável pelo recebimento está ciente das condições e do novo status de cada equipamento, conforme verificado no momento da devolução.</div>';
            $h .= '<p style="font-size:10.5px;">Eu, <b>' . htmlspecialchars($tech_name) . '</b>, técnico(a) responsável pelo atendimento, portador(a) do documento de identidade, em cumprimento às normas e procedimentos da Unidade Regional de Ensino – Região de <b>JALES</b>, declaro que os equipamentos abaixo foram submetidos ao suporte técnico e estão sendo devolvidos ao responsável identificado abaixo, conforme verificado no momento da entrega:</p>';
            $h .= '<table class="info"><tr><td><b>Data de Devolução</b>' . date('d/m/Y', strtotime($transfer['date_pronto'] ?: $transfer['date_creation'])) . '</td><td><b>Escola / URE de Destino</b>' . htmlspecialchars(self::truncPdf($dest_name, 50)) . '</td></tr>';
            $h .= '<tr><td><b>Técnico Responsável</b>' . htmlspecialchars(self::truncPdf($tech_name, 40)) . '</td><td><b>Responsável pela Retirada</b>' . htmlspecialchars(self::truncPdf($creator_name, 40)) . '</td></tr>';
            $h .= '<tr><td><b>Escola de Origem</b>' . htmlspecialchars(self::truncPdf($origin_name ?: 'Não informada', 50)) . '</td><td><b>Local da Manutenção</b>' . htmlspecialchars(self::truncPdf($dest_name, 50)) . '</td></tr>';
            $h .= '<tr><td colspan="2"><b>Retornando para</b>' . htmlspecialchars(self::truncPdf($origin_name ?: 'Escola de origem', 60)) . '</td></tr>';
            if ($transfer['reason']) $h .= '<tr><td colspan="2"><b>Motivo Original da Transferência</b>' . htmlspecialchars(self::truncPdf($transfer['reason'], 200)) . '</td></tr>';
            $h .= '</table>';
            $h .= '<h3>Equipamento(s) Devolvido(s)</h3><table class="eq"><tr><th style="width:4%">#</th><th style="width:16%">Nome</th><th style="width:10%">Tipo</th><th style="width:11%">Status Final</th><th style="width:20%">Motivo / Observação</th><th style="width:19%">Componentes</th><th style="width:20%">O Que Foi Feito</th></tr>';
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
                $comp_str = !empty($comp_txt) ? implode('; ', $comp_txt) : '—';
                $final_reason_raw = $item['final_reason'] ?? '—';
                $final_reason_trunc = ($final_reason_raw === '—' || $final_reason_raw === '') ? '—' : self::truncPdf($final_reason_raw, 90);
                $comp_trunc = ($comp_str === '—') ? '—' : self::truncPdf($comp_str, 90);
                $wlog_trunc = ($wlog === '' ? '—' : self::truncPdf($wlog, 110));

                $h .= '<tr><td>' . ($i + 1) . '</td><td><b>' . htmlspecialchars(self::truncPdf($item['item_name'], 40)) . '</b></td>'
                    . '<td>' . htmlspecialchars(self::truncPdf(str_replace(['Glpi\\CustomAsset\\', 'Asset'], '', $item['itemtype']), 15)) . '</td>'
                    . '<td>' . ($item['final_status'] ? htmlspecialchars(MaintenanceRecord::getStatusLabel($item['final_status'])) : '—') . '</td>'
                    . '<td>' . htmlspecialchars($final_reason_trunc) . '</td>'
                    . '<td>' . htmlspecialchars($comp_trunc) . '</td>'
                    . '<td>' . ($wlog_trunc !== '—' ? nl2br(htmlspecialchars($wlog_trunc)) : '—') . '</td></tr>';
            }
            $h .= '</table>';
        }

        if ($is_assinado) {
            $sig_img_html = '<img src="' . $sig_image . '" style="height:55px;max-width:190px;display:block;margin:0 auto 4px;">';
            $receb_nome = $sig_nome !== '' ? htmlspecialchars($sig_nome) : '________________________________________';
            $receb_doc  = htmlspecialchars($sig_type . ' ' . $sig_masked);
            $receb_data = htmlspecialchars($sig_data_fmt);
            $h .= '<table class="sign"><tr><td><div class="line"></div><b>' . ($is_pronto ? 'Responsável pela Entrega (Técnico)' : 'Responsável pelo Envio') . '</b><br>' . htmlspecialchars($is_pronto ? $tech_name : $creator_name) . '<br><br>Documento: ____________________________<br>Data: ____/____/________</td>'
                . '<td style="background:#f0fdf4;border:1.5px solid #a7f3d0;">' . $sig_img_html . '<div class="line" style="border-color:#065f46;height:2px;margin-bottom:4px;"></div><b>Responsável pelo Recebimento <span style="color:#059669;font-size:8px;">● ASSINADO</span></b><br>Nome: ' . $receb_nome . '<br><br>Documento: ' . $receb_doc . '<br>Data: ' . $receb_data . '<br><span style="font-size:7px;color:#6b7280;">via tablet — IP ' . htmlspecialchars($transfer['assinatura_ip'] ?? '') . '</span></td></tr></table>'
                . '<div style="margin-top:8px;background:#f0fdf4;border:1px solid #a7f3d0;border-radius:6px;padding:6px;text-align:center;font-size:8px;color:#065f46;">✍️ Assinado digitalmente em ' . htmlspecialchars($sig_data_fmt) . ' — ' . htmlspecialchars($sig_type . ' ' . $sig_masked) . '</div>';
        } else {
            $h .= '<table class="sign"><tr><td><div class="line"></div><b>' . ($is_pronto ? 'Responsável pela Entrega (Técnico)' : 'Responsável pelo Envio') . '</b><br>' . htmlspecialchars($is_pronto ? $tech_name : $creator_name) . '<br><br>Documento: ____________________________<br>Data: ____/____/________</td>'
                . '<td><div class="line"></div><b>Responsável pelo Recebimento</b><br>Nome: ________________________________________<br><br>Documento: ____________________________<br>Data: ____/____/________</td></tr></table>'
                . '<div style="margin-top:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px;text-align:center;font-size:8px;color:#92400e;">⚠️ Termo ainda não assinado — colete na aba Assinatura (RG/CPF + assinatura).</div>';
        }

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
        self::$last_pending_transfer_id = 0;
        $row = self::getById($transfer_id);
        if (!$row || $row['status'] !== self::STATUS_MANUTENCAO) return false;

        // Separa Não Pronto (pendentes) dos demais
        $prontoItems = [];
        $naoProntoItems = [];
        foreach ($final_items as $item_id => $data) {
            $st = $data['status'] ?? '';
            if ($st === 'nao_pronto') {
                $naoProntoItems[(int)$item_id] = $data;
            } else {
                $prontoItems[(int)$item_id] = $data;
            }
        }

        // Validação: precisa ter ao menos 1 pronto
        if (empty($prontoItems) && !empty($naoProntoItems)) {
            self::$last_ticket_error = 'É necessário marcar ao menos 1 equipamento como Ativo/Garantia/Inservível. Não é permitido deixar todos como Não Pronto.';
            return false;
        }
        // Se não houver item algum (caso $final_items vazio ou IDs inválidos)
        if (empty($prontoItems) && empty($naoProntoItems)) return false;

        $hasNaoPronto = !empty($naoProntoItems);
        $newTransferId = 0;
        $newTicketId = 0;

        // Se houve Não Pronto, cria nova transferência pendente ANTES de marcar como pronto
        if ($hasNaoPronto) {
            $nowPend = date('Y-m-d H:i:s');
            // Monta motivo agregado dos Não Pronto
            $orig_reason = trim($row['reason'] ?? '');
            $pendReasons = [];
            foreach ($naoProntoItems as $nid => $ndata) {
                $r = trim($ndata['reason'] ?? '');
                if ($r !== '') $pendReasons[] = $ndata['item_name'] ?? ("Ativo #$nid") . ': ' . $r;
                // $ndata não tem item_name, vamos buscar depois; por enquanto usa motivo puro
                if ($r !== '' && !isset($pendReasons[count($pendReasons)-1])) $pendReasons[] = $r;
            }
            // Busca nomes dos itens pendentes para motivo
            $pendItemRows = [];
            if (!empty($naoProntoItems)) {
                $pendIds = array_keys($naoProntoItems);
                foreach ($DB->request(['FROM' => 'glpi_plugin_assetmgrstatus_transfer_items', 'WHERE' => ['transfers_id' => $transfer_id, 'items_id' => $pendIds]]) as $pr) {
                    $pendItemRows[(int)$pr['items_id']] = $pr;
                }
            }
            // Re-monta pendReasons com nomes
            $pendReasons = [];
            foreach ($naoProntoItems as $nid => $ndata) {
                $r = trim($ndata['reason'] ?? '');
                $iname = $pendItemRows[$nid]['item_name'] ?? "Ativo #$nid";
                if ($r !== '') $pendReasons[] = $iname . ' — ' . mb_substr($r, 0, 80);
                else $pendReasons[] = $iname;
            }
            $new_reason = 'Pendência da Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . ' — ' . count($naoProntoItems) . ' item(ns) Não Pronto';
            if ($orig_reason !== '') $new_reason .= ' | Origem: ' . mb_substr($orig_reason, 0, 100);
            if (!empty($pendReasons)) $new_reason .= ' | Motivos: ' . mb_substr(implode('; ', $pendReasons), 0, 300);

            $DB->insert('glpi_plugin_assetmgrstatus_transfers', [
                'entity_dest'      => $row['entity_dest'],
                'reason'           => $new_reason,
                'status'           => self::STATUS_PENDENTE,
                'users_id_created' => Session::getLoginUserID(),
                'users_id_tech'    => 0,
                'tickets_id'       => 0,
                'date_pending'     => $nowPend,
                'date_creation'    => $nowPend,
            ]);
            $newTransferId = (int)$DB->insertId();
            self::$last_pending_transfer_id = $newTransferId;

            self::logStatus($newTransferId, self::STATUS_PENDENTE, 'Criada automaticamente a partir de Não Pronto da Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . ' — ' . count($naoProntoItems) . ' item(ns)');

            $origin_entities_for_ticket = [];
            foreach ($naoProntoItems as $nid => $ndata) {
                $prow = $pendItemRows[$nid] ?? null;
                if (!$prow) continue;
                $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                    'transfers_id'       => $newTransferId,
                    'items_id'           => $prow['items_id'],
                    'itemtype'           => $prow['itemtype'],
                    'item_name'          => $prow['item_name'],
                    'origin_entity_id'   => $prow['origin_entity_id'],
                    'origin_entity_name' => $prow['origin_entity_name'],
                    'work_status'        => 'pending',
                    'work_log'           => $ndata['reason'] ?? null,
                    'work_components'    => isset($ndata['components']) ? json_encode($ndata['components']) : null,
                    'final_status'       => null,
                    'final_reason'       => null,
                    'final_components'   => null,
                ]);
                $origin_entities_for_ticket[(int)$prow['items_id']] = (int)$prow['origin_entity_id'];
                // Mantém ativo bloqueado mas aponta para nova pendente
                $DB->update('glpi_plugin_assetmgrstatus_records', [
                    'transfers_id'    => $newTransferId,
                    'transfer_status' => 'transferido',
                    'date_mod'        => $nowPend,
                ], ['itemtype' => $prow['itemtype'], 'items_id' => (int)$prow['items_id']]);
                // Remove do vínculo original
                $DB->delete('glpi_plugin_assetmgrstatus_transfer_items', ['id' => $prow['id']]);
            }

            // Tenta criar novo chamado para a pendência
            $catId = 0;
            if ((int)$row['tickets_id'] > 0) {
                try {
                    $trow = $DB->request(['SELECT' => ['itilcategories_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int)$row['tickets_id']], 'LIMIT' => 1])->current();
                    $catId = (int)($trow['itilcategories_id'] ?? 0);
                } catch (\Throwable $e) {}
            }
            if ($catId > 0) {
                $ticketItems = [];
                foreach ($naoProntoItems as $nid => $ndata) {
                    $prow = $pendItemRows[$nid] ?? null;
                    if ($prow) $ticketItems[] = ['id' => (int)$prow['items_id'], 'itemtype' => $prow['itemtype'], 'name' => $prow['item_name']];
                }
                try {
                    $newTicketId = self::openTicketForTransfer($newTransferId, (int)$row['entity_dest'], $new_reason, $ticketItems, $origin_entities_for_ticket, $catId);
                    if ($newTicketId) {
                        $DB->update('glpi_plugin_assetmgrstatus_transfers', ['tickets_id' => $newTicketId], ['id' => $newTransferId]);
                        self::attachStageDoc($newTransferId, 'transfer');
                    }
                } catch (\Throwable $e) {
                    error_log('[assetmgrstatus] ticket nao_pronto: ' . $e->getMessage());
                }
            }

            // Guarda para notificação pós-pronto
            $GLOBALS['_am_nao_pronto_new_reason'] = $new_reason;
            $GLOBALS['_am_nao_pronto_new_ticket'] = $newTicketId;
            $GLOBALS['_am_nao_pronto_pending_rows'] = $pendItemRows;
        }

        // Atualiza apenas os prontos no vínculo original
        foreach ($prontoItems as $item_id => $data) {
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

        if ($hasNaoPronto) {
            self::logStatus($transfer_id, self::STATUS_PRONTO, 'Marcada como Pronto parcialmente — ' . count($prontoItems) . ' pronto(s), ' . count($naoProntoItems) . ' Não Pronto movido(s) para Transferência #' . str_pad($newTransferId, 4, '0', STR_PAD_LEFT));
        } else {
            self::logStatus($transfer_id, self::STATUS_PRONTO, 'Todos os itens concluídos — aguardando devolução');
        }

        if ((int)$row['tickets_id'] > 0) {
            if ($hasNaoPronto) {
                $pendNames = implode(', ', array_map(fn($r) => $r['item_name'] ?? '', $GLOBALS['_am_nao_pronto_pending_rows'] ?? []));
                if ($pendNames === '') $pendNames = implode(', ', array_keys($naoProntoItems));
                $newTicketIdTmp = $GLOBALS['_am_nao_pronto_new_ticket'] ?? 0;
                self::addTicketFollowup(
                    (int)$row['tickets_id'],
                    "✅ Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **marcada como Pronto (parcial)** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\n"
                    . count($prontoItems) . " item(ns) pronto(s) — aguardando devolução. " . count($naoProntoItems) . " Não Pronto movido(s) para nova **Transferência #" . str_pad($newTransferId, 4, '0', STR_PAD_LEFT) . "**.\n"
                    . ($newTicketIdTmp ? "Novo chamado: #$newTicketIdTmp\n" : "Nova pendência: #$newTransferId\n")
                    . "Itens não prontos: $pendNames"
                );
                if ($newTicketId) {
                    self::addTicketFollowup(
                        $newTicketId,
                        "🔁 Pendência criada a partir da Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " (marcada como Pronto em " . date('d/m/Y H:i') . " por " . self::getUserName(Session::getLoginUserID()) . ").\n"
                        . "Itens Não Pronto: $pendNames\nChamado de origem: #" . (int)$row['tickets_id']
                    );
                }
                unset($GLOBALS['_am_nao_pronto_new_reason'], $GLOBALS['_am_nao_pronto_new_ticket'], $GLOBALS['_am_nao_pronto_pending_rows']);
            } else {
                self::addTicketFollowup(
                    (int)$row['tickets_id'],
                    "✅ Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **marcada como Pronto** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\nTodos os itens concluídos — aguardando devolução."
                );
            }
        }

        return true;
    }

    // -------------------------------------------------------
    // Finalizar — aplica status no inventário e desbloqueia
    // Suporta pendências: $pending_item_ids = ids que ficaram pendentes (serão movidos p/ nova transferência pendente)
    // -------------------------------------------------------

    public static function finalizar(int $transfer_id, array $pending_item_ids = [], string $pending_reason = ''): bool
    {
        global $DB;
        self::$last_ticket_error = '';
        self::$last_pending_transfer_id = 0;
        $row = self::getById($transfer_id);
        if (!$row || $row['status'] !== self::STATUS_PRONTO) return false;

        $items_all = iterator_to_array($DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_transfer_items',
            'WHERE' => ['transfers_id' => $transfer_id],
        ]));
        if (empty($items_all)) return false;

        // Separa pendentes vs a finalizar
        $pending_item_ids = array_map('intval', $pending_item_ids);
        $pending_map = array_flip($pending_item_ids);
        $toFinalize = [];
        $toPending  = [];
        foreach ($items_all as $it) {
            $iid = (int)$it['items_id'];
            if (isset($pending_map[$iid])) {
                $toPending[] = $it;
            } else {
                $toFinalize[] = $it;
            }
        }

        // Se houve seleção de pendentes mas nenhum pertence à transferência, trata como finalize normal
        if (!empty($pending_item_ids) && empty($toPending)) {
            // nenhum pending válido -> finaliza tudo
            $toFinalize = $items_all;
            $toPending  = [];
        }

        // Validação: precisa sobrar ao menos 1 para finalizar
        if (!empty($toPending) && empty($toFinalize)) {
            self::$last_ticket_error = 'Selecione ao menos um equipamento para finalizar; os marcados como pendentes ficarão para novo chamado.';
            return false;
        }

        $items_iter = $toFinalize;
        $hasPending = !empty($toPending);
        $newTransferId = 0;
        $newTicketId = 0;

        // ---- Se houve pendentes, cria nova transferência pendente ANTES de finalizar os demais ----
        // (evita deixar pendentes travados com transferência finalizada em caso de falha)
        if ($hasPending) {
            $nowPend = date('Y-m-d H:i:s');
            $pending_reason_trim = trim($pending_reason);
            $orig_reason_trim = trim($row['reason'] ?? '');
            $new_reason_tmp = 'Pendência da Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT);
            if ($orig_reason_trim !== '') $new_reason_tmp .= ' — ' . mb_substr($orig_reason_trim, 0, 120);
            if ($pending_reason_trim !== '') $new_reason_tmp .= ' | Motivo pendência: ' . mb_substr($pending_reason_trim, 0, 200);

            $DB->insert('glpi_plugin_assetmgrstatus_transfers', [
                'entity_dest'      => $row['entity_dest'],
                'reason'           => $new_reason_tmp,
                'status'           => self::STATUS_PENDENTE,
                'users_id_created' => Session::getLoginUserID(),
                'users_id_tech'    => 0,
                'tickets_id'       => 0,
                'date_pending'     => $nowPend,
                'date_creation'    => $nowPend,
            ]);
            $newTransferId = (int)$DB->insertId();
            self::$last_pending_transfer_id = $newTransferId;

            self::logStatus($newTransferId, self::STATUS_PENDENTE, 'Criada automaticamente a partir de pendências da Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . ' — ' . count($toPending) . ' item(ns) pendente(s)' . ($pending_reason_trim !== '' ? ' — ' . mb_substr($pending_reason_trim, 0, 120) : ''));

            $origin_entities_for_ticket_tmp = [];
            foreach ($toPending as $pitem) {
                $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                    'transfers_id'       => $newTransferId,
                    'items_id'           => $pitem['items_id'],
                    'itemtype'           => $pitem['itemtype'],
                    'item_name'          => $pitem['item_name'],
                    'origin_entity_id'   => $pitem['origin_entity_id'],
                    'origin_entity_name' => $pitem['origin_entity_name'],
                    'work_status'        => 'pending',
                    'work_log'           => null,
                    'work_components'    => null,
                    'final_status'       => null,
                    'final_reason'       => null,
                    'final_components'   => null,
                ]);
                $origin_entities_for_ticket_tmp[(int)$pitem['items_id']] = (int)$pitem['origin_entity_id'];
                $DB->update('glpi_plugin_assetmgrstatus_records', [
                    'transfers_id'    => $newTransferId,
                    'transfer_status' => 'transferido',
                    'date_mod'        => $nowPend,
                ], ['itemtype' => $pitem['itemtype'], 'items_id' => (int)$pitem['items_id']]);
                $DB->delete('glpi_plugin_assetmgrstatus_transfer_items', ['id' => $pitem['id']]);
            }
            // Guarda para uso posterior no fechamento do chamado
            $GLOBALS['_am_pending_ticket_origins'] = $origin_entities_for_ticket_tmp;
            $GLOBALS['_am_pending_new_reason'] = $new_reason_tmp;
        }

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
                // Zera alerta se houve manutenção registrada no Diário
                $workDoneEmpty = (($item['work_status'] ?? 'pending') === 'done') || trim((string)($item['work_log'] ?? '')) !== '';
                if ($workDoneEmpty) {
                    $manutDescEmpty = trim((string)($item['work_log'] ?? ''));
                    if ($manutDescEmpty === '') $manutDescEmpty = trim((string)($item['final_reason'] ?? ''));
                    if ($manutDescEmpty === '') $manutDescEmpty = 'Manutenção realizada via Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . ' — ' . $tech_name_hist;
                    try {
                        MaintenanceRecord::saveManutencaoRealizada($item['itemtype'], (int)$item['items_id'], $manutDescEmpty, []);
                    } catch (\Throwable $e) {
                        error_log('[assetmgrstatus] saveManutencao via finalizar (sem status): ' . $e->getMessage());
                    }
                }
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

            self::unlockAsset($item['itemtype'], (int)$item['items_id']);

            // Zera alerta de +60 dias: registra manutenção realizada se houve trabalho no Diário ou status final saudável
            $workDone = (($item['work_status'] ?? 'pending') === 'done') || trim((string)($item['work_log'] ?? '')) !== '';
            if (!$workDone && in_array($item['final_status'], [MaintenanceRecord::STATUS_ATIVO, MaintenanceRecord::STATUS_GARANTIA, MaintenanceRecord::STATUS_INSERVIVEL], true)) {
                $workDone = true;
            }
            // Também considera componentes resolvidos no Diário como manutenção
            if (!$workDone && !empty($item['work_components'])) {
                $wc = is_string($item['work_components']) ? json_decode($item['work_components'], true) : $item['work_components'];
                if (is_array($wc)) {
                    foreach ($wc as $v) { if ($v === 'resolved') { $workDone = true; break; } }
                }
            }
            if ($workDone) {
                $manutDesc = trim((string)($item['work_log'] ?? ''));
                if ($manutDesc === '') $manutDesc = trim((string)($item['final_reason'] ?? ''));
                if ($manutDesc === '') $manutDesc = 'Manutenção realizada via Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . ' — ' . $tech_name_hist;
                try {
                    MaintenanceRecord::saveManutencaoRealizada($item['itemtype'], (int)$item['items_id'], $manutDesc, []);
                } catch (\Throwable $e) {
                    error_log('[assetmgrstatus] saveManutencao via finalizar: ' . $e->getMessage());
                }
            }

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

        // ---- Pendentes: cria chamado para nova transferência (já criada antes do loop) ----
        if ($hasPending) {
            $origin_entities_for_ticket = $GLOBALS['_am_pending_ticket_origins'] ?? [];
            $new_reason = $GLOBALS['_am_pending_new_reason'] ?? ('Pendência da Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT));
            if (empty($origin_entities_for_ticket)) {
                foreach ($toPending as $pitem) $origin_entities_for_ticket[(int)$pitem['items_id']] = (int)$pitem['origin_entity_id'];
            }
            // Tenta criar novo chamado para a pendência reaproveitando categoria do chamado original
            $newTicketId = 0;
            $catId = 0;
            if ((int)$row['tickets_id'] > 0) {
                try {
                    $trow = $DB->request(['SELECT' => ['itilcategories_id'], 'FROM' => 'glpi_tickets', 'WHERE' => ['id' => (int)$row['tickets_id']], 'LIMIT' => 1])->current();
                    $catId = (int)($trow['itilcategories_id'] ?? 0);
                } catch (\Throwable $e) {}
            }
            if ($catId > 0) {
                $ticketItems = [];
                foreach ($toPending as $p) {
                    $ticketItems[] = ['id' => (int)$p['items_id'], 'itemtype' => $p['itemtype'], 'name' => $p['item_name']];
                }
                try {
                    $newTicketId = self::openTicketForTransfer($newTransferId, (int)$row['entity_dest'], $new_reason, $ticketItems, $origin_entities_for_ticket, $catId);
                    if ($newTicketId) {
                        $DB->update('glpi_plugin_assetmgrstatus_transfers', ['tickets_id' => $newTicketId], ['id' => $newTransferId]);
                        self::attachStageDoc($newTransferId, 'transfer');
                    }
                } catch (\Throwable $e) {
                    error_log('[assetmgrstatus] ticket pendencia: ' . $e->getMessage());
                }
            }

            // Notifica chamado original sobre a pendência
            if ((int)$row['tickets_id'] > 0) {
                $pendNames = implode(', ', array_map(fn($p) => $p['item_name'], $toPending));
                $msg = "⚠️ Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **finalizada parcialmente** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\n"
                     . count($toPending) . " item(ns) ficou(aram) pendente(s) e foi(ram) movido(s) para a nova **Transferência #" . str_pad($newTransferId, 4, '0', STR_PAD_LEFT) . "**.\n"
                     . ($pending_reason !== '' ? "Motivo da pendência: $pending_reason\n" : "")
                     . ($newTicketId ? "Novo chamado criado: #$newTicketId\n" : "Nova transferência pendente: #$newTransferId\n")
                     . "Itens pendentes: $pendNames";
                self::addTicketFollowup((int)$row['tickets_id'], $msg);
                if ($newTicketId) {
                    self::addTicketFollowup($newTicketId,
                        "🔁 Esta transferência foi criada automaticamente a partir de pendências da **Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . "** finalizada em " . date('d/m/Y H:i') . ".\n"
                        . ($pending_reason !== '' ? "Motivo da pendência: $pending_reason\n" : "")
                        . "Chamado de origem: #" . (int)$row['tickets_id']
                    );
                }
            }
            unset($GLOBALS['_am_pending_ticket_origins'], $GLOBALS['_am_pending_new_reason']);
        }

        $DB->update('glpi_plugin_assetmgrstatus_transfers', [
            'status'          => self::STATUS_FINALIZADO,
            'date_finalizado' => date('Y-m-d H:i:s'),
        ], ['id' => $transfer_id]);

        if ($hasPending) {
            self::logStatus($transfer_id, self::STATUS_FINALIZADO, 'Transferência finalizada parcialmente — ' . count($toFinalize) . ' finalizado(s), ' . count($toPending) . ' pendente(s) movido(s) para Transferência #' . str_pad($newTransferId, 4, '0', STR_PAD_LEFT));
        } else {
            self::logStatus($transfer_id, self::STATUS_FINALIZADO, 'Transferência finalizada — status aplicados no inventário');
        }

        // Chamado: registra acompanhamento final e depois solucionado (SOLVED=5) — followup antes do status para não reabrir
        if ((int)$row['tickets_id'] > 0) {
            if ($hasPending) {
                self::addTicketFollowup(
                    (int)$row['tickets_id'],
                    "🏁 Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **finalizada (parcial)** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\n"
                    . count($toFinalize) . " equipamento(s) finalizado(s) com status aplicados no inventário. " . count($toPending) . " pendente(s) movido(s) para Transferência #" . str_pad($newTransferId, 4, '0', STR_PAD_LEFT) . ".\nChamado será solucionado automaticamente."
                );
            } else {
                self::addTicketFollowup(
                    (int)$row['tickets_id'],
                    "🏁 Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " **finalizada** por " . self::getUserName(Session::getLoginUserID()) . " em " . date('d/m/Y H:i') . ".\nStatus dos equipamentos aplicados no inventário. O termo de devolução foi anexado a este chamado.\nChamado será **solucionado** automaticamente."
                );
            }
            self::setTicketStatus((int)$row['tickets_id'], defined('Ticket::SOLVED') ? Ticket::SOLVED : 5);
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

        // Batch 1b: contagem de itens concluídos (work_status = 'done') — para barra de progresso
        $doneCounts = [];
        if (!empty($rows)) {
            foreach ($DB->request([
                'SELECT' => ['transfers_id', 'COUNT' => 'id AS total'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_transfer_items',
                'WHERE'  => ['transfers_id' => array_column($rows, 'id'), 'work_status' => 'done'],
                'GROUPBY'=> ['transfers_id'],
            ]) as $c) {
                $doneCounts[(int)$c['transfers_id']] = (int)$c['total'];
            }
        }

        // Batch 1c: contagem por final_status preenchido (fallback para transferências já em Pronto/Finalizado caso work_status ainda não tenha migrado)
        $finalCounts = [];
        if (!empty($rows)) {
            foreach ($DB->request([
                'SELECT' => ['transfers_id', 'COUNT' => 'id AS total'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_transfer_items',
                'WHERE'  => ['transfers_id' => array_column($rows, 'id'), 'final_status' => ['<>', '']],
                'GROUPBY'=> ['transfers_id'],
            ]) as $c) {
                $finalCounts[(int)$c['transfers_id']] = (int)$c['total'];
            }
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
            // Progresso: concluídos = work_status='done' (diário) ou, se já houver final_status, usa o maior entre os dois
            $done_by_work  = $doneCounts[(int)$row['id']] ?? 0;
            $done_by_final = $finalCounts[(int)$row['id']] ?? 0;
            $items_done    = max($done_by_work, $done_by_final);
            // Para status Pronto/Finalizado assume 100% quando não houver work_status mas houver itens
            if (in_array($row['status'], [self::STATUS_PRONTO, self::STATUS_FINALIZADO], true) && $row['items_count'] > 0 && $items_done < $row['items_count']) {
                // Se já foi para pronto, todos devem estar concluídos; garante pelo menos finalCounts ou total
                if ($done_by_final === $row['items_count']) $items_done = $row['items_count'];
            }
            if ($items_done > $row['items_count']) $items_done = $row['items_count'];
            $row['items_done']   = $items_done;
            $row['progress_pct'] = $row['items_count'] > 0 ? (int)round($items_done / $row['items_count'] * 100) : 0;
            if ($row['progress_pct'] > 100) $row['progress_pct'] = 100;
            if ($row['progress_pct'] < 0) $row['progress_pct'] = 0;
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

    // -------------------------------------------------------
    // Limpeza automática de PDFs após 7 dias (mantém dados)
    // -------------------------------------------------------

    public static function cleanupOldPdfs(int $days = 7): int
    {
        global $DB;
        $cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));
        $count  = 0;

        try {
            $iter = $DB->request([
                'SELECT' => ['id', 'filepath', 'name'],
                'FROM'   => 'glpi_documents',
                'WHERE'  => [
                    ['name' => ['LIKE', 'Termo de % - Transferência #%']],
                    ['date_creation' => ['<', $cutoff]],
                    ['is_deleted' => 0],
                ],
            ]);
        } catch (\Throwable $e) {
            error_log('[assetmgrstatus] cleanupOldPdfs query: ' . $e->getMessage());
            return 0;
        }

        foreach ($iter as $doc) {
            $docId = (int)$doc['id'];
            try {
                $document = new Document();
                if ($document->getFromDB($docId)) {
                    // delete(true) = purge + unlink file + Document_Item
                    if ($document->delete(['id' => $docId], 1)) {
                        $count++;
                        continue;
                    }
                }
                // Fallback direto caso Document::delete falhe
                $DB->delete('glpi_documents_items', ['documents_id' => $docId]);
                $DB->delete('glpi_documents', ['id' => $docId]);
                if (!empty($doc['filepath'])) {
                    $file = (defined('GLPI_DOC_DIR') ? GLPI_DOC_DIR : GLPI_ROOT . '/files') . '/' . $doc['filepath'];
                    if (file_exists($file)) @unlink($file);
                }
                $count++;
            } catch (\Throwable $e) {
                error_log('[assetmgrstatus] cleanupOldPdfs delete id ' . $docId . ': ' . $e->getMessage());
            }
        }

        if ($count > 0) {
            error_log("[assetmgrstatus] cleanupOldPdfs: $count PDFs removidos (>$days dias)");
        }

        return $count;
    }

    public static function cronCleanupPdfs($task = null): int
    {
        $count = self::cleanupOldPdfs(7);
        if ($task && is_object($task) && method_exists($task, 'log')) {
            $task->log("AssetMgrStatus: $count PDFs antigos removidos (>7 dias)");
            if (method_exists($task, 'addVolume')) $task->addVolume($count);
        }
        return 1;
    }

    public static function maybeRunCleanup(): void
    {
        try {
            $tmp = defined('GLPI_TMP_DIR') ? GLPI_TMP_DIR : sys_get_temp_dir();
            $file = rtrim($tmp, '/\\') . '/assetmgrstatus_last_cleanup';
            $now = time();
            if (is_file($file) && ($now - @filemtime($file) < 86400)) {
                return;
            }
            @touch($file);
            @file_put_contents($file, (string)$now);
            self::cleanupOldPdfs(7);
        } catch (\Throwable $e) {
            error_log('[assetmgrstatus] maybeRunCleanup: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------
    // Assinatura digital (RG/CPF + caneta/dedo)
    // -------------------------------------------------------

    public static function isAssinado(array $transfer): bool
    {
        return !empty($transfer['assinatura_image']);
    }

    public static function precisaAssinatura(array $transfer): bool
    {
        // Apenas Pronto ou Finalizado ainda sem assinatura precisam assinar
        if (in_array($transfer['status'], [self::STATUS_CANCELADA], true)) return false;
        if (!in_array($transfer['status'], [self::STATUS_PRONTO, self::STATUS_FINALIZADO], true)) return false;
        return empty($transfer['assinatura_image']);
    }

    public static function salvarAssinatura(int $transfer_id, string $doc_type, string $doc_number, string $nome, string $image_base64): bool
    {
        global $DB;
        try {
            $transfer = self::getById($transfer_id);
            if (!$transfer) {
                self::$last_ticket_error = 'Transferência não encontrada';
                return false;
            }
            if (self::isAssinado($transfer)) {
                self::$last_ticket_error = 'Termo já assinado';
                return false;
            }
            $doc_type = strtoupper(trim($doc_type));
            if (!in_array($doc_type, ['RG','CPF'], true)) {
                self::$last_ticket_error = 'Tipo de documento inválido';
                return false;
            }
            // Normaliza documento: só números
            $doc_number = preg_replace('/\D+/', '', $doc_number);
            if ($doc_type === 'CPF' && strlen($doc_number) !== 11) {
                self::$last_ticket_error = 'CPF deve ter 11 dígitos';
                return false;
            }
            if ($doc_type === 'RG' && (strlen($doc_number) < 5 || strlen($doc_number) > 12)) {
                self::$last_ticket_error = 'RG deve ter entre 5 e 12 dígitos';
                return false;
            }
            $nome = trim($nome);
            // Valida imagem base64: deve começar com data:image/
            if (strpos($image_base64, 'data:image/') !== 0) {
                self::$last_ticket_error = 'Assinatura inválida (formato)';
                return false;
            }
            if (strlen($image_base64) < 100) {
                self::$last_ticket_error = 'Assinatura vazia';
                return false;
            }
            if (strlen($image_base64) > 500000) {
                self::$last_ticket_error = 'Assinatura muito grande (limite 500kb)';
                return false;
            }
            $now = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
            $uid = Session::getLoginUserID();

            // Garante colunas de assinatura existam (auto-migração se plugin não foi atualizado no banco)
            try {
                $sign = \DBConnection::getDefaultPrimaryKeySignOption();
                if (function_exists('plugin_assetmgrstatus_add_columns')) {
                    plugin_assetmgrstatus_add_columns('glpi_plugin_assetmgrstatus_transfers', [
                        'assinatura_document_type' => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `assinatura_document_type` VARCHAR(10) DEFAULT NULL",
                        'assinatura_document'      => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `assinatura_document` VARCHAR(20) DEFAULT NULL",
                        'assinatura_nome'          => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `assinatura_nome` VARCHAR(255) DEFAULT NULL",
                        'assinatura_data'          => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `assinatura_data` DATETIME DEFAULT NULL",
                        'assinatura_user_id'       => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `assinatura_user_id` INT {$sign} DEFAULT NULL",
                        'assinatura_ip'            => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `assinatura_ip` VARCHAR(45) DEFAULT NULL",
                        'assinatura_image'         => "ALTER TABLE `glpi_plugin_assetmgrstatus_transfers` ADD COLUMN `assinatura_image` LONGTEXT DEFAULT NULL",
                    ]);
                }
            } catch (Throwable $e) {
                error_log('[assetmgrstatus] ensure assinatura columns: ' . $e->getMessage());
            }

            $ok = $DB->update('glpi_plugin_assetmgrstatus_transfers', [
                'assinatura_document_type' => $doc_type,
                'assinatura_document'      => $doc_number,
                'assinatura_nome'          => $nome !== '' ? mb_substr($nome, 0, 255) : null,
                'assinatura_data'          => $now,
                'assinatura_user_id'       => $uid,
                'assinatura_ip'            => $ip,
                'assinatura_image'         => $image_base64,
            ], ['id' => $transfer_id]);
            if (!$ok) {
                $dbErr = $DB->error();
                if ($dbErr) {
                    self::$last_ticket_error = 'DB: ' . $dbErr;
                    error_log('[assetmgrstatus] salvarAssinatura DB error: ' . $dbErr);
                }
                return false;
            }
        // Timeline
        $label = $nome !== '' ? $nome . ' (' . $doc_type . ' ' . self::maskDocumento($doc_type, $doc_number) . ')' : ($doc_type . ' ' . self::maskDocumento($doc_type, $doc_number));
        self::logStatus($transfer_id, $transfer['status'], '✍️ Assinado por ' . $label . ' em ' . date('d/m/Y H:i', strtotime($now)));
        // Followup no chamado se houver
        if (!empty($transfer['tickets_id'])) {
            try {
                self::addTicketFollowup((int)$transfer['tickets_id'],
                    "✍️ **Termo assinado** da Transferência #" . str_pad($transfer_id, 4, '0', STR_PAD_LEFT) . " por **" . $label . "** em " . date('d/m/Y H:i', strtotime($now)) . " (IP $ip, GLPI user #" . $uid . ").\nO termo atualizado com assinatura está disponível para impressão.");
            } catch (\Throwable $e) {}
        }
        return true;
        } catch (Throwable $e) {
            error_log('[assetmgrstatus] salvarAssinatura: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            self::$last_ticket_error = 'Exceção: ' . $e->getMessage();
            return false;
        }
    }

    public static function maskDocumento(string $type, string $raw): string
    {
        $raw = preg_replace('/\D+/', '', $raw);
        if ($type === 'CPF' && strlen($raw) === 11) {
            return substr($raw,0,3) . '.' . substr($raw,3,3) . '.' . substr($raw,6,3) . '-' . substr($raw,9,2);
        }
        if ($type === 'RG') {
            // RG varia por estado; mostra com pontos simples
            if (strlen($raw) > 7) {
                return substr($raw,0,2) . '.' . substr($raw,2,3) . '.' . substr($raw,5,3) . '-' . substr($raw,8);
            }
            return $raw;
        }
        return $raw;
    }

    public static function getAssinaturasPendentes(): array
    {
        $all = self::getAll();
        return array_values(array_filter($all, fn($t) => self::precisaAssinatura($t)));
    }

    public static function getAssinaturasConcluidas(): array
    {
        $all = self::getAll();
        return array_values(array_filter($all, fn($t) => self::isAssinado($t)));
    }
}
