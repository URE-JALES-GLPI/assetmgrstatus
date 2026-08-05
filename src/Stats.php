<?php

namespace GlpiPlugin\Assetmgrstatus;

use Session;
use User;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class Stats
{
    public static function getAll(int $entity_id): array
    {
        global $DB;

        // Busca IDs dos ativos da entidade
        $asset_ids = self::getEntityAssetIds($entity_id);

        $result = [
            'by_status'      => [],
            'alert_60'       => 0,
            'manutencoes_mes' => 0,
            'baixas_mes'     => 0,
            'total'          => 0,
        ];

        if (empty($asset_ids)) return $result;

        // Contagem por status (usa registro do plugin; sem registro = estoque)
        foreach (MaintenanceRecord::getStatusOptions() as $key => $label) {
            $result['by_status'][$key] = 0;
        }

        // Ativos com registro
        $iter = $DB->request([
            'SELECT' => ['items_id', 'am_status'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_records',
            'WHERE'  => ['items_id' => $asset_ids],
        ]);

        $registered = [];
        foreach ($iter as $row) {
            $registered[$row['items_id']] = $row['am_status'];
            $s = $row['am_status'];
            if (isset($result['by_status'][$s])) $result['by_status'][$s]++;
            else $result['by_status'][MaintenanceRecord::STATUS_ESTOQUE] = ($result['by_status'][MaintenanceRecord::STATUS_ESTOQUE] ?? 0);
        }

        // Ativos sem registro = estoque
        $unregistered = count($asset_ids) - count($registered);
        $result['by_status'][MaintenanceRecord::STATUS_ESTOQUE] =
            ($result['by_status'][MaintenanceRecord::STATUS_ESTOQUE] ?? 0) + $unregistered;

        $result['total'] = count($asset_ids);

        // Alerta 60 dias
        foreach ($asset_ids as $aid) {
            // Pega itemtype
            $def_iter = $DB->request([
                'SELECT' => ['assets_assetdefinitions_id'],
                'FROM'   => 'glpi_assets_assets',
                'WHERE'  => ['id' => $aid],
                'LIMIT'  => 1,
            ]);
            if ($def_iter->count() === 0) continue;
            $def_id = $def_iter->current()['assets_assetdefinitions_id'];

            $sn_iter = $DB->request([
                'SELECT' => ['system_name'],
                'FROM'   => 'glpi_assets_assetdefinitions',
                'WHERE'  => ['id' => $def_id],
                'LIMIT'  => 1,
            ]);
            if ($sn_iter->count() === 0) continue;
            $itemtype = 'Glpi\\CustomAsset\\' . $sn_iter->current()['system_name'] . 'Asset';

            $days = MaintenanceRecord::getDaysSinceLastMaintenance($itemtype, $aid);
            if ($days === null || $days > 60) $result['alert_60']++;
        }

        // Manutenções e baixas do mês atual
        $start_month = date('Y-m-01 00:00:00');
        $end_month   = date('Y-m-t 23:59:59');

        $iter_m = $DB->request([
            'SELECT' => ['COUNT' => 'id AS total'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => [
                'items_id'      => $asset_ids,
                'record_type'   => MaintenanceRecord::RECORD_MANUTENCAO,
                ['date_creation' => ['>', $start_month]],
                ['date_creation' => ['<', $end_month]],
            ],
        ]);
        $result['manutencoes_mes'] = (int)($iter_m->current()['total'] ?? 0);

        $iter_b = $DB->request([
            'SELECT' => ['COUNT' => 'id AS total'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => [
                'items_id'      => $asset_ids,
                'record_type'   => MaintenanceRecord::RECORD_BAIXA,
                ['date_creation' => ['>', $start_month]],
                ['date_creation' => ['<', $end_month]],
            ],
        ]);
        $result['baixas_mes'] = (int)($iter_b->current()['total'] ?? 0);

        return $result;
    }

    public static function getMonthlyHistory(int $entity_id): array
    {
        global $DB;

        $asset_ids = self::getEntityAssetIds($entity_id);
        if (empty($asset_ids)) return [];

        $result = [];
        // Últimos 6 meses
        for ($i = 5; $i >= 0; $i--) {
            $ts    = strtotime("-$i months");
            $month = (int)date('m', $ts);
            $year  = (int)date('Y', $ts);
            $start = date('Y-m-01', $ts);
            $end   = date('Y-m-t', $ts);

            $iter = $DB->request([
                'SELECT' => ['COUNT' => 'id AS total'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
                'WHERE'  => [
                    'items_id'    => $asset_ids,
                    'record_type' => MaintenanceRecord::RECORD_MANUTENCAO,
                    ['date_creation' => ['>=', $start . ' 00:00:00']],
                    ['date_creation' => ['<=', $end . ' 23:59:59']],
                ],
            ]);

            $result[] = [
                'month' => $month,
                'year'  => $year,
                'total' => (int)($iter->current()['total'] ?? 0),
            ];
        }

        return $result;
    }

    public static function getAlertAssets(int $entity_id): array
    {
        global $DB;

        $asset_ids = self::getEntityAssetIds($entity_id);
        if (empty($asset_ids)) return [];

        $result = [];

        foreach ($asset_ids as $aid) {
            $a_iter = $DB->request([
                'SELECT' => ['id', 'name', 'assets_assetdefinitions_id', 'entities_id'],
                'FROM'   => 'glpi_assets_assets',
                'WHERE'  => ['id' => $aid, 'is_deleted' => 0],
                'LIMIT'  => 1,
            ]);
            if ($a_iter->count() === 0) continue;
            $asset = $a_iter->current();

            $sn_iter = $DB->request([
                'SELECT' => ['system_name'],
                'FROM'   => 'glpi_assets_assetdefinitions',
                'WHERE'  => ['id' => $asset['assets_assetdefinitions_id']],
                'LIMIT'  => 1,
            ]);
            if ($sn_iter->count() === 0) continue;
            $sn       = $sn_iter->current()['system_name'];
            $itemtype = 'Glpi\\CustomAsset\\' . $sn . 'Asset';

            $days = MaintenanceRecord::getDaysSinceLastMaintenance($itemtype, $aid);
            if ($days !== null && $days <= 60) continue;

            $en_iter = $DB->request([
                'SELECT' => ['completename'],
                'FROM'   => 'glpi_entities',
                'WHERE'  => ['id' => $asset['entities_id']],
                'LIMIT'  => 1,
            ]);
            $entity_name = $en_iter->count() > 0 ? $en_iter->current()['completename'] : '';

            $result[] = [
                'id'          => $aid,
                'name'        => $asset['name'],
                'asset_type'  => $sn,
                'entity_name' => $entity_name,
                'days'        => $days,
            ];
        }

        // Ordena: nunca manutenidos primeiro, depois por mais dias
        usort($result, function ($a, $b) {
            if ($a['days'] === null && $b['days'] === null) return strcmp($a['name'], $b['name']);
            if ($a['days'] === null) return -1;
            if ($b['days'] === null) return 1;
            return $b['days'] - $a['days'];
        });

        return array_slice($result, 0, 20);
    }

    // -------------------------------------------------------
    // Relatório 1: Por Técnico
    // -------------------------------------------------------

    public static function getByTechnician(int $entity_id = 0, string $period_start = '', string $period_end = ''): array
    {
        global $DB;

        $where = [];
        if ($entity_id) {
            $asset_ids = self::getEntityAssetIds($entity_id);
            if (empty($asset_ids)) return [];
            $where['items_id'] = $asset_ids;
        }
        if ($period_start) $where[] = ['date_creation' => ['>=', $period_start . ' 00:00:00']];
        if ($period_end)   $where[] = ['date_creation' => ['<=', $period_end . ' 23:59:59']];

        $iter = $DB->request([
            'SELECT' => ['users_id', 'record_type'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => $where,
        ]);

        $by_user = [];
        foreach ($iter as $row) {
            $uid = (int)$row['users_id'];
            if (!isset($by_user[$uid])) {
                $by_user[$uid] = ['status_change' => 0, 'manutencao_realizada' => 0, 'baixa' => 0, 'total' => 0];
            }
            $rt = $row['record_type'] ?? 'status_change';
            if (isset($by_user[$uid][$rt])) $by_user[$uid][$rt]++;
            $by_user[$uid]['total']++;
        }

        $result = [];
        foreach ($by_user as $uid => $counts) {
            $u = new User();
            $name = ($uid && $u->getFromDB($uid)) ? $u->getName() : 'Sistema';
            $result[] = array_merge(['user_id' => $uid, 'user_name' => $name], $counts);
        }

        usort($result, fn($a, $b) => $b['total'] - $a['total']);
        return $result;
    }

    // -------------------------------------------------------
    // Relatório 2: Por Entidade (consolidado, todas entidades)
    // -------------------------------------------------------

    public static function getByEntity(): array
    {
        global $DB;

        $entities_iter = $DB->request([
            'SELECT' => ['id', 'completename'],
            'FROM'   => 'glpi_entities',
        ]);

        $result = [];
        foreach ($entities_iter as $entity) {
            $entity_id = (int)$entity['id'];
            $asset_ids = self::getEntityAssetIds($entity_id);
            if (empty($asset_ids)) continue;

            $row = [
                'entity_id'   => $entity_id,
                'entity_name' => $entity['completename'],
                'total'       => count($asset_ids),
                'by_status'   => [],
            ];

            foreach (MaintenanceRecord::getStatusOptions() as $key => $label) {
                $row['by_status'][$key] = 0;
            }

            $reg_iter = $DB->request([
                'SELECT' => ['items_id', 'am_status'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_records',
                'WHERE'  => ['items_id' => $asset_ids],
            ]);

            $registered = 0;
            foreach ($reg_iter as $r) {
                $registered++;
                if (isset($row['by_status'][$r['am_status']])) $row['by_status'][$r['am_status']]++;
            }
            $row['by_status'][MaintenanceRecord::STATUS_ESTOQUE] += (count($asset_ids) - $registered);

            $result[] = $row;
        }

        usort($result, fn($a, $b) => $b['total'] - $a['total']);
        return $result;
    }

    // -------------------------------------------------------
    // Relatório 3: Componentes mais problemáticos
    // -------------------------------------------------------

    public static function getComponentRanking(int $entity_id = 0, string $period_start = '', string $period_end = ''): array
    {
        global $DB;

        $where = ['components' => ['<>', null]];
        if ($entity_id) {
            $asset_ids = self::getEntityAssetIds($entity_id);
            if (empty($asset_ids)) return [];
            $where['items_id'] = $asset_ids;
        }
        if ($period_start) $where[] = ['date_creation' => ['>=', $period_start . ' 00:00:00']];
        if ($period_end)   $where[] = ['date_creation' => ['<=', $period_end . ' 23:59:59']];

        $iter = $DB->request([
            'SELECT' => ['components', 'itemtype'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => $where,
        ]);

        $comp_list = MaintenanceRecord::getComponents();
        $counts    = array_fill_keys(array_keys($comp_list), 0);

        foreach ($iter as $row) {
            $decoded = $row['components'] ? json_decode($row['components'], true) : [];
            if (!is_array($decoded)) continue;
            foreach (array_keys($decoded) as $ckey) {
                if (isset($counts[$ckey])) $counts[$ckey]++;
            }
        }

        $result = [];
        foreach ($counts as $ckey => $count) {
            if ($count === 0) continue;
            $result[] = ['key' => $ckey, 'label' => $comp_list[$ckey] ?? $ckey, 'count' => $count];
        }

        usort($result, fn($a, $b) => $b['count'] - $a['count']);
        return $result;
    }

    // -------------------------------------------------------
    // Relatório 4: Tempo médio em manutenção (por tipo de ativo)
    // -------------------------------------------------------

    public static function getAverageMaintenanceTime(int $entity_id = 0, string $period_start = '', string $period_end = ''): array
    {
        global $DB;

        $where = ['record_type' => 'status_change'];
        if ($entity_id) {
            $asset_ids = self::getEntityAssetIds($entity_id);
            if (empty($asset_ids)) return [];
            $where['items_id'] = $asset_ids;
        }
        if ($period_start) $where[] = ['date_creation' => ['>=', $period_start . ' 00:00:00']];
        if ($period_end)   $where[] = ['date_creation' => ['<=', $period_end . ' 23:59:59']];

        $iter = $DB->request([
            'SELECT' => ['items_id', 'itemtype', 'status_old', 'status_new', 'date_creation'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => $where,
            'ORDER'  => ['items_id ASC', 'date_creation ASC'],
        ]);

        // Agrupa por ativo, depois calcula intervalos onde status_new = manutencao
        // até a próxima mudança de status do mesmo ativo
        $by_asset = [];
        foreach ($iter as $row) {
            $key = $row['itemtype'] . '_' . $row['items_id'];
            $by_asset[$key][] = $row;
        }

        $durations_by_type = []; // itemtype => [durations em dias]

        foreach ($by_asset as $rows) {
            for ($i = 0; $i < count($rows); $i++) {
                if ($rows[$i]['status_new'] !== MaintenanceRecord::STATUS_MANUTENCAO) continue;

                $start = strtotime($rows[$i]['date_creation']);
                $end   = isset($rows[$i + 1]) ? strtotime($rows[$i + 1]['date_creation']) : time();

                $days = ($end - $start) / 86400;
                $itemtype = $rows[$i]['itemtype'];
                $durations_by_type[$itemtype][] = $days;
            }
        }

        $types_map = MaintenanceRecord::getAssetTypes();
        $result = [];
        foreach ($durations_by_type as $itemtype => $durations) {
            $system_name = '';
            foreach ($types_map as $sn => $def) {
                if ($itemtype === 'Glpi\\CustomAsset\\' . $sn . 'Asset') { $system_name = $sn; break; }
            }
            $label = $types_map[$system_name]['label'] ?? $itemtype;

            $result[] = [
                'asset_type' => $label,
                'count'      => count($durations),
                'avg_days'   => round(array_sum($durations) / count($durations), 1),
                'max_days'   => round(max($durations), 1),
                'min_days'   => round(min($durations), 1),
            ];
        }

        usort($result, fn($a, $b) => $b['avg_days'] <=> $a['avg_days']);
        return $result;
    }

    private static function getEntityAssetIds(int $entity_id): array
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_assets_assets',
            'WHERE'  => ['entities_id' => $entity_id, 'is_deleted' => 0],
        ]);

        return array_column(iterator_to_array($iter), 'id');
    }
}
