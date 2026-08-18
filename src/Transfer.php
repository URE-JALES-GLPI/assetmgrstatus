<?php

namespace GlpiPlugin\Assetmgrstatus;

use Session;

if (!defined('GLPI_ROOT')) die("Sorry. You can't access directly to this file");

class Transfer
{
    const STATUS_PENDENTE    = 'pendente';
    const STATUS_MANUTENCAO  = 'em_manutencao';
    const STATUS_PRONTO      = 'pronto';
    const STATUS_FINALIZADO  = 'finalizado';

    const TRANSFER_TYPE_URE    = 'ure';
    const TRANSFER_TYPE_ESCOLA = 'escola';

    const RIGHT_TRANSFER = 'plugin_assetmgrstatus_transfer';
    const RIGHT_TECNICO  = 'plugin_assetmgrstatus_tecnico';

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_PENDENTE   => 'Pendente',
            self::STATUS_MANUTENCAO => 'Em Manutenção',
            self::STATUS_PRONTO     => 'Pronto',
            self::STATUS_FINALIZADO => 'Finalizado',
        ];
    }

    public static function getStatusColor(string $status): string
    {
        return match($status) {
            self::STATUS_PENDENTE   => '#f59e0b',
            self::STATUS_MANUTENCAO => '#3b82f6',
            self::STATUS_PRONTO     => '#10b981',
            self::STATUS_FINALIZADO => '#6b7280',
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

    public static function create(int $entity_dest, string $reason, array $items, string $transfer_type = 'ure'): int
    {
        global $DB;
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

        return $transfer_id;
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
        $row = self::getById($transfer_id);
        if (!$row || $row['status'] !== self::STATUS_PENDENTE) return false;

        $DB->update('glpi_plugin_assetmgrstatus_transfers', [
            'status'          => self::STATUS_MANUTENCAO,
            'users_id_tech'   => Session::getLoginUserID(),
            'date_manutencao' => date('Y-m-d H:i:s'),
        ], ['id' => $transfer_id]);

        return true;
    }

    // -------------------------------------------------------
    // Marcar como Pronto
    // -------------------------------------------------------

    public static function marcarPronto(int $transfer_id, array $final_items): bool
    {
        global $DB;
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

        return true;
    }

    // -------------------------------------------------------
    // Finalizar — aplica status no inventário e desbloqueia
    // -------------------------------------------------------

    public static function finalizar(int $transfer_id): bool
    {
        global $DB;
        $row = self::getById($transfer_id);
        if (!$row || $row['status'] !== self::STATUS_PRONTO) return false;

        $items_iter = $DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_transfer_items',
            'WHERE' => ['transfers_id' => $transfer_id],
        ]);

        $uid = Session::getLoginUserID();

        foreach ($items_iter as $item) {
            if (empty($item['final_status'])) {
                self::unlockAsset($item['itemtype'], (int)$item['items_id']);
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
        }

        $DB->update('glpi_plugin_assetmgrstatus_transfers', [
            'status'          => self::STATUS_FINALIZADO,
            'date_finalizado' => date('Y-m-d H:i:s'),
        ], ['id' => $transfer_id]);

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

        // Batch 3: nomes dos usuários (técnico + criador) (1 query)
        $user_ids = array_filter(array_unique(array_merge(
            array_column($rows, 'users_id_tech'),
            array_column($rows, 'users_id_created')
        )));
        $user_names = [];
        if ($user_ids) {
            foreach ($DB->request([
                'SELECT' => ['id', 'name'],
                'FROM'   => 'glpi_users',
                'WHERE'  => ['id' => $user_ids],
            ]) as $u) {
                $user_names[(int)$u['id']] = $u['name'];
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
