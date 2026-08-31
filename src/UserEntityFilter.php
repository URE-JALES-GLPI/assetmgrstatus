<?php

namespace GlpiPlugin\Assetmgrstatus;

use Session;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class UserEntityFilter
{
    public static function getTable(): string
    {
        return 'glpi_plugin_assetmgrstatus_user_filters';
    }

    /**
     * Salva filtro de entidade por usuário (persiste entre logouts).
     * @param int $users_id
     * @param array $entityIds  [] = Todas, [0] = URE Jales, [1,2] = multi
     * @param bool|int $recursive
     */
    public static function save(int $users_id, array $entityIds, $recursive = false): void
    {
        global $DB;
        if (!$users_id) return;
        $users_id = (int)$users_id;
        $entityIds = array_values(array_filter(array_map('intval', $entityIds), fn($v) => $v >= 0));
        $recursive = $recursive ? 1 : 0;
        $json = json_encode($entityIds);
        $now = date('Y-m-d H:i:s');

        // UPSERT via INSERT ON DUPLICATE ou DELETE+INSERT compatível
        $exists = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => self::getTable(),
            'WHERE'  => ['users_id' => $users_id],
            'LIMIT'  => 1,
        ])->count() > 0;

        if ($exists) {
            $DB->update(self::getTable(), [
                'entity_filter'    => $json,
                'entity_recursive' => $recursive,
                'date_mod'         => $now,
            ], ['users_id' => $users_id]);
        } else {
            $DB->insert(self::getTable(), [
                'users_id'         => $users_id,
                'entity_filter'    => $json,
                'entity_recursive' => $recursive,
                'date_creation'    => $now,
                'date_mod'         => $now,
            ]);
        }
    }

    /**
     * Carrega filtro salvo. Retorna null se nunca salvou.
     * @return array|null ['entities'=>[], 'recursive'=>bool]
     */
    public static function load(int $users_id): ?array
    {
        global $DB;
        if (!$users_id) return null;
        try {
            if (!$DB->tableExists(self::getTable())) return null;
            $iter = $DB->request([
                'SELECT' => ['entity_filter', 'entity_recursive'],
                'FROM'   => self::getTable(),
                'WHERE'  => ['users_id' => (int)$users_id],
                'LIMIT'  => 1,
            ]);
            if ($iter->count() === 0) return null;
            $row = $iter->current();
            $decoded = json_decode($row['entity_filter'] ?? '[]', true);
            if (!is_array($decoded)) $decoded = [];
            $decoded = array_values(array_filter(array_map('intval', $decoded), fn($v) => $v >= 0));
            return [
                'entities'  => $decoded,
                'recursive' => !empty($row['entity_recursive']),
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Limpa filtro do usuário (volta para Todas)
     */
    public static function clear(int $users_id): void
    {
        global $DB;
        if (!$users_id) return;
        try {
            $DB->delete(self::getTable(), ['users_id' => (int)$users_id]);
        } catch (\Throwable $e) {}
    }
}
