<?php

namespace GlpiPlugin\Assetmgrstatus;

use Session;
use User;
use Entity;

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

    // -------------------------------------------------------
    // Criar transferência e marcar ativos como transferidos
    // -------------------------------------------------------

    public static function create(int $entity_dest, string $reason, array $items, string $transfer_type = 'ure'): int
    {
        global $DB;
        $now = date('Y-m-d H:i:s');

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

        foreach ($items as $item) {
            // Busca entidade de origem do ativo antes de transferir
            $asset_iter = $DB->request([
                'SELECT' => ['entities_id'],
                'FROM'   => 'glpi_assets_assets',
                'WHERE'  => ['id' => (int)$item['id']],
                'LIMIT'  => 1,
            ]);
            $origin_entity_id   = 0;
            $origin_entity_name = '';
            if ($asset_iter->count() > 0) {
                $origin_entity_id = (int)$asset_iter->current()['entities_id'];
                $ent_iter = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_entities', 'WHERE' => ['id' => $origin_entity_id], 'LIMIT' => 1]);
                if ($ent_iter->count() > 0) $origin_entity_name = $ent_iter->current()['name'];
            }

            $DB->insert('glpi_plugin_assetmgrstatus_transfer_items', [
                'transfers_id'       => $transfer_id,
                'items_id'           => (int)$item['id'],
                'itemtype'           => $item['itemtype'],
                'item_name'          => $item['name'] ?? '',
                'origin_entity_id'   => $origin_entity_id,
                'origin_entity_name' => $origin_entity_name,
            ]);

            // Marca o ativo como transferido (bloqueia edição na manutenção)
            self::lockAsset($item['itemtype'], (int)$item['id'], $transfer_id);
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

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_transfers',
            'WHERE' => $where,
            'ORDER' => ['date_creation DESC'],
        ]);

        $result = [];
        foreach ($iter as $row) {
            $count_iter = $DB->request([
                'SELECT' => ['COUNT' => 'id AS total'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_transfer_items',
                'WHERE'  => ['transfers_id' => $row['id']],
            ]);
            $row['items_count'] = (int)($count_iter->current()['total'] ?? 0);

            $ent = new Entity();
            $row['entity_dest_name'] = ($ent->getFromDB((int)$row['entity_dest']))
                ? $ent->getName() : 'Desconhecida';

            $u = new User();
            $row['tech_name'] = ($row['users_id_tech'] && $u->getFromDB($row['users_id_tech']))
                ? $u->getName() : null;

            $u2 = new User();
            $row['creator_name'] = ($row['users_id_created'] && $u2->getFromDB($row['users_id_created']))
                ? $u2->getName() : 'Sistema';

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
