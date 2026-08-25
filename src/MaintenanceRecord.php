<?php

namespace GlpiPlugin\Assetmgrstatus;

use CommonDBTM;
use CommonGLPI;
use Html;
use Session;
use User;

if (!defined('GLPI_ROOT')) {
    die("Sorry. You can't access directly to this file");
}

class MaintenanceRecord extends CommonDBTM
{
    public static $rightname = 'plugin_assetmgrstatus';

    const STATUS_ESTOQUE    = 'estoque';
    const STATUS_ATIVO      = 'ativo';
    const STATUS_INATIVO    = 'inativo';
    const STATUS_GARANTIA   = 'garantia';
    const STATUS_INSERVIVEL = 'inservivel';
    const STATUS_ENTREGUE   = 'entregue';
    const STATUS_MANUTENCAO = 'manutencao';

    const RECORD_STATUS_CHANGE = 'status_change';
    const RECORD_MANUTENCAO    = 'manutencao_realizada';
    const RECORD_BAIXA         = 'baixa';
    const RECORD_NOTE          = 'note';
    const RECORD_TRANSFER      = 'transferencia';
    const RECORD_TRANSFER_RETURN = 'transferencia_retorno';

    const RIGHT_VIEW    = 'plugin_assetmgrstatus';
    const RIGHT_TECNICO = 'plugin_assetmgrstatus_tecnico';

    const GLPI_STATE_MAP = [
        self::STATUS_ESTOQUE    => 1,
        self::STATUS_ATIVO      => 9,
        self::STATUS_INATIVO    => 10,
        self::STATUS_GARANTIA   => 11,
        self::STATUS_INSERVIVEL => 12,
        self::STATUS_ENTREGUE   => 13,
        self::STATUS_MANUTENCAO => 16,
    ];

    public static function getAssetTypes(): array
    {
        return [
            'Desktop'             => ['label' => 'Desktop',              'icon' => 'ti-device-desktop'],
            'Notebook'            => ['label' => 'Notebook',             'icon' => 'ti-device-laptop'],
            'Celular'             => ['label' => 'Celular',              'icon' => 'ti-device-mobile'],
            'Tablet'              => ['label' => 'Tablet',               'icon' => 'ti-device-tablet'],
            'Switch'              => ['label' => 'Switch',               'icon' => 'ti-network'],
            'Televisao'           => ['label' => 'Televisão',            'icon' => 'ti-device-tv'],
            'Firewall'            => ['label' => 'Firewall',             'icon' => 'ti-shield-lock'],
            'RackdeRede'          => ['label' => 'Rack de Rede',         'icon' => 'ti-server'],
            'PlataformadeRecarga' => ['label' => 'Plataforma de Recarga','icon' => 'ti-battery-charging'],
            'AccessPoint'         => ['label' => 'Access Point',         'icon' => 'ti-wifi'],
        ];
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_ESTOQUE    => 'Estoque',
            self::STATUS_ATIVO      => 'Ativo',
            self::STATUS_INATIVO    => 'Inativo',
            self::STATUS_GARANTIA   => 'Garantia',
            self::STATUS_INSERVIVEL => 'Inservível',
            self::STATUS_MANUTENCAO => 'Manutenção',
        ];
    }

    public static function getStatusLabel(string $status): string
    {
        return array_merge(self::getStatusOptions(), [
            'deleted'               => 'Removido',
            self::RECORD_MANUTENCAO => 'Manutenção Realizada',
            self::RECORD_BAIXA      => 'Baixa',
            self::RECORD_NOTE       => 'Observação',
        ])[$status] ?? $status;
    }

    public static function getStatusBadgeClass(string $status): string
    {
        return match ($status) {
            self::STATUS_ATIVO      => 'am-badge-ativo',
            self::STATUS_ESTOQUE    => 'am-badge-estoque',
            self::STATUS_INATIVO    => 'am-badge-inativo',
            self::STATUS_GARANTIA   => 'am-badge-garantia',
            self::STATUS_INSERVIVEL => 'am-badge-inservivel',
            self::STATUS_ENTREGUE   => 'am-badge-entregue',
            self::STATUS_MANUTENCAO => 'am-badge-manutencao',
            self::RECORD_MANUTENCAO => 'am-badge-realizada',
            self::RECORD_BAIXA      => 'am-badge-baixa',
            self::RECORD_NOTE       => 'am-badge-note',
            self::RECORD_TRANSFER   => 'am-badge-transfer',
            self::RECORD_TRANSFER_RETURN => 'am-badge-transfer-return',
            default                 => 'am-badge-estoque',
        };
    }

    public static function getRecordTypeLabel(string $record_type): string
    {
        return match ($record_type) {
            self::RECORD_MANUTENCAO      => '🔧 Manutenção Realizada',
            self::RECORD_BAIXA           => '📦 Baixa',
            self::RECORD_NOTE            => '📝 Observação',
            self::RECORD_TRANSFER        => '🚚 Transferência',
            self::RECORD_TRANSFER_RETURN => '↩️ Retorno de Transferência',
            default                      => '🔄 Alteração de Status',
        };
    }

    public static function getRecordTypeColor(string $record_type): string
    {
        return match ($record_type) {
            self::RECORD_MANUTENCAO      => '#10b981',
            self::RECORD_BAIXA           => '#ef4444',
            self::RECORD_NOTE            => '#f59e0b',
            self::RECORD_TRANSFER        => '#3b82f6',
            self::RECORD_TRANSFER_RETURN => '#8b5cf6',
            default                      => '#4f46e5',
        };
    }

    public static function getRecordTypeIcon(string $record_type): string
    {
        return match ($record_type) {
            self::RECORD_MANUTENCAO      => 'tools',
            self::RECORD_BAIXA           => 'package-off',
            self::RECORD_NOTE            => 'note',
            self::RECORD_TRANSFER        => 'transfer',
            self::RECORD_TRANSFER_RETURN => 'arrow-back-up',
            default                      => 'refresh',
        };
    }

    public static function getComponents(): array
    {
        return [
            'storage'      => 'Armazenamento',
            'battery'      => 'Bateria',
            'power_button' => 'Botão Liga/Desliga',
            'case'         => 'Carcaça',
            'charger_port' => 'Entrada de Carregamento',
            'usb_ports'    => 'Entradas USB',
            'video_port'   => 'Entrada de Vídeo',
            'screen'       => 'Tela',
            'keyboard'     => 'Teclado',
            'touchpad'     => 'Touchpad (Mouse)',
            'bluetooth'    => 'Placa Bluetooth',
            'wifi'         => 'Placa Wi-Fi',
            'motherboard'  => 'Placa-mãe',
        ];
    }

    /**
     * Expande lista de entidades para incluir todas as sub-entidades (filhos recursivos).
     * GLPI armazena entidades em árvore (entities_id = parent). Selecionar a Entidade MÃE
     * deve trazer também ativos das escolas filhas — comportamento recursivo.
     * Se $ids for 0 (Todas) ou vazio, retorna 0.
     * Se for int>0 ou array de ids, retorna array expandido com filhos.
     */
    public static function expandEntityIds(int|array $ids): int|array
    {
        global $DB;
        if ($ids === 0 || $ids === null) return 0;
        $flat = is_array($ids) ? $ids : [$ids];
        $flat = array_values(array_unique(array_filter(array_map('intval', $flat), fn($v) => $v > 0)));
        if (empty($flat)) return 0;
        try {
            // Carrega árvore completa de entidades uma vez
            $childrenMap = [];
            $entities = [];
            foreach ($DB->request(['SELECT' => ['id', 'entities_id'], 'FROM' => 'glpi_entities']) as $row) {
                $eid = (int)$row['id'];
                $pid = (int)$row['entities_id'];
                $entities[$eid] = $pid;
                $childrenMap[$pid][] = $eid;
            }
            $expanded = [];
            $stack = $flat;
            $visited = [];
            while (!empty($stack)) {
                $cur = array_pop($stack);
                if (isset($visited[$cur])) continue;
                $visited[$cur] = true;
                $expanded[] = $cur;
                if (isset($childrenMap[$cur])) {
                    foreach ($childrenMap[$cur] as $child) {
                        if (!isset($visited[$child])) $stack[] = $child;
                    }
                }
            }
            return array_values(array_unique($expanded));
        } catch (\Throwable $e) {
            return $flat;
        }
    }

    public static function getManufacturers(string $type_filter = 'Notebook', int|array|null $entity_filter = null): array
    {
        global $DB;
        $types = self::getAssetTypes();
        if (!isset($types[$type_filter])) return [];
        $def_iter = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_assets_assetdefinitions', 'WHERE' => ['system_name' => $type_filter], 'LIMIT' => 1]);
        if ($def_iter->count() === 0) return [];
        $def_id = $def_iter->current()['id'];
        $has_admin = Session::haveRight('plugin_assetmgrstatus_admin', READ);
        if ($entity_filter !== null && $has_admin) {
            if (is_array($entity_filter)) {
                $entity_filter = array_values(array_filter(array_map('intval', $entity_filter), fn($v) => $v > 0));
                $entity_id = empty($entity_filter) ? 0 : $entity_filter;
            } else {
                $entity_id = (int)$entity_filter;
            }
        } else {
            $entity_id = Session::getActiveEntity();
        }
        // Expande só se entity_recursive=1 (ADMIN) ou flag recursiva GLPI
        $do_expand_manu = !empty($_GET['entity_recursive']);
        if ($entity_id !== 0 && $entity_id !== null) {
            $is_admin_filter = ($entity_filter !== null && $has_admin);
            if (($is_admin_filter && $do_expand_manu) || (!empty($_SESSION['glpiactiveentity_is_recursive']))) {
                $entity_id = self::expandEntityIds($entity_id);
            }
        }
        try {
            $where_m = [
                'glpi_assets_assets.assets_assetdefinitions_id' => $def_id,
                'glpi_assets_assets.is_deleted' => 0,
                'glpi_assets_assets.manufacturers_id' => ['>', 0],
            ];
            if ($entity_id !== 0 && $entity_id !== null) {
                $where_m['glpi_assets_assets.entities_id'] = $entity_id;
            }
            $iter = $DB->request([
                'SELECT' => ['glpi_assets_assets.manufacturers_id AS mid', 'glpi_manufacturers.name AS mname'],
                'FROM'   => 'glpi_assets_assets',
                'LEFT JOIN' => ['glpi_manufacturers' => ['ON' => ['glpi_assets_assets' => 'manufacturers_id', 'glpi_manufacturers' => 'id']]],
                'WHERE'  => $where_m,
                'GROUPBY' => ['glpi_assets_assets.manufacturers_id'],
                'ORDER'   => ['glpi_manufacturers.name ASC'],
            ]);
            $result = [];
            foreach ($iter as $row) {
                $mid = (int)($row['mid'] ?? 0);
                $mname = $row['mname'] ?? '';
                if ($mid && $mname) $result[$mid] = $mname;
            }
            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function getDaysSinceLastMaintenance(string $itemtype, int $items_id): ?int
    {
        global $DB;
        // Tenta com itemtype para precisão, fallback sem itemtype para compatibilidade (caso histórico tenha sido gravado com tipo diferente)
        $iter = $DB->request([
            'SELECT' => ['date_creation'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => ['items_id' => $items_id, 'record_type' => self::RECORD_MANUTENCAO],
            'ORDER'  => ['date_creation DESC'],
            'LIMIT'  => 1,
        ]);
        if ($iter->count() === 0) return null;
        $last = new \DateTime($iter->current()['date_creation']);
        return (int)(new \DateTime())->diff($last)->days;
    }

    // ---- Aba no ativo ----

    public static function getTypeName($nb = 0): string { return 'Histórico Manutenção'; }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        $supported = ['Glpi\\CustomAsset\\DesktopAsset','Glpi\\CustomAsset\\NotebookAsset','Glpi\\CustomAsset\\CelularAsset','Glpi\\CustomAsset\\TabletAsset'];
        if (in_array($item->getType(), $supported)) {
            global $DB;
            $count_hist = count(self::getHistory($item->getType(), (int)$item->getID()));
            $rec = $DB->request(['SELECT' => ['components'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $item->getType(), 'items_id' => (int)$item->getID()], 'LIMIT' => 1]);
            $comp_count = 0;
            if ($rec->count() > 0) {
                $row = $rec->current();
                $comps = $row['components'] ? json_decode($row['components'], true) : [];
                $comp_count = is_array($comps) ? count($comps) : 0;
            }
            return [
                1 => self::createTabEntry('Histórico Manutenção', $count_hist, $item->getType(), 'ti ti-history'),
                2 => self::createTabEntry('Componentes Afetados', $comp_count, $item->getType(), 'ti ti-cpu'),
            ];
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        global $CFG_GLPI, $DB;
        $itemtype   = $item->getType();
        $items_id   = (int)$item->getID();
        $comp_list  = self::getComponents();

        // ---- ABA 2: Componentes Afetados ----
        if ($tabnum == 2) {
            // Carrega registro atual
            $rec = $DB->request(['SELECT' => ['components','am_status','reason'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
            $current_comps = [];
            $current_status = '';
            $current_reason = '';
            if ($rec->count() > 0) {
                $row = $rec->current();
                $current_comps  = $row['components'] ? json_decode($row['components'], true) : [];
                $current_status = $row['am_status'] ?? '';
                $current_reason = $row['reason'] ?? '';
            }
            $can_edit = Session::haveRight(self::RIGHT_VIEW, UPDATE);
            echo '<div class="am-tab-content" style="font-family:Inter,sans-serif;padding:8px 0;">';

            // Status atual + motivo
            if ($current_status) {
                echo '<div style="background:#f8f9fb;border:1.5px solid #e8eaf0;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;gap:12px;align-items:center;">';
                $badge_class = self::getStatusBadgeClass($current_status);
                echo '<span class="am-badge ' . $badge_class . '">' . htmlspecialchars(self::getStatusLabel($current_status)) . '</span>';
                if ($current_reason) echo '<span style="font-size:.85rem;color:#6b7280;">' . htmlspecialchars($current_reason) . '</span>';
                echo '</div>';
            }

            // Grid de componentes (apenas leitura, sem formulário)
            echo '<div style="margin-bottom:20px;">';
            echo '<div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;letter-spacing:.06em;margin-bottom:10px;"><i class="ti ti-cpu"></i> Componentes com Problema</div>';
            echo '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;">';
            if (!empty($current_comps)) {
                foreach ($comp_list as $ckey => $clabel) {
                    if (array_key_exists($ckey, $current_comps)) {
                        $desc = $current_comps[$ckey];
                        echo '<div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:8px;padding:10px 12px;">';
                        echo '<div style="font-weight:700;font-size:.82rem;color:#dc2626;display:flex;align-items:center;gap:5px;"><i class="ti ti-alert-triangle" style="font-size:.85rem;"></i>' . htmlspecialchars($clabel) . '</div>';
                        if ($desc) echo '<div style="font-size:.75rem;color:#9ca3af;margin-top:3px;">' . htmlspecialchars($desc) . '</div>';
                        echo '</div>';
                    }
                }
            } else {
                echo '<div style="grid-column:1/-1;text-align:center;color:#9ca3af;padding:20px;font-size:.85rem;"><i class="ti ti-circle-check" style="font-size:1.5rem;display:block;margin-bottom:6px;color:#10b981;"></i>Nenhum componente marcado como afetado.</div>';
            }
            echo '</div></div>';

            // Botão para abrir o modal do plugin
            echo '<div style="margin-bottom:20px;">';
            echo '<a href="' . $CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/maintenance.php" ';
            echo 'class="am-btn am-btn-primary" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;font-size:.82rem;text-decoration:none;">';
            echo '<i class="ti ti-edit"></i> Editar no Plugin de Manutenção</a>';
            echo '</div>';

            // Mini histórico dos últimos 10 registros com componentes
            $hist = $DB->request([
                'SELECT' => ['components', 'status_new', 'record_type', 'date_creation', 'users_id'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
                'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id, 'components' => ['<>', null]],
                'ORDER'  => ['date_creation DESC'],
                'LIMIT'  => 10,
            ]);

            if ($hist->count() > 0) {
                echo '<div style="font-size:.75rem;font-weight:700;text-transform:uppercase;color:#9ca3af;letter-spacing:.06em;margin-bottom:10px;"><i class="ti ti-history"></i> Histórico de Componentes (últimos 10)</div>';
                echo '<div style="display:flex;flex-direction:column;gap:8px;">';
                foreach ($hist as $h) {
                    $comps = $h['components'] ? json_decode($h['components'], true) : [];
                    if (empty($comps)) continue;
                    $u = new User();
                    $uname = ($h['users_id'] && $u->getFromDB($h['users_id'])) ? $u->getName() : 'Sistema';
                    echo '<div style="background:#f8f9fb;border:1px solid #e8eaf0;border-radius:8px;padding:10px 14px;">';
                    echo '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">';
                    echo '<span style="font-size:.75rem;color:#9ca3af;">' . date('d/m/Y H:i', strtotime($h['date_creation'])) . ' — ' . htmlspecialchars($uname) . '</span>';
                    $badge = self::getStatusBadgeClass($h['status_new']);
                    echo '<span class="am-badge ' . $badge . '">' . htmlspecialchars(self::getStatusLabel($h['status_new'])) . '</span>';
                    echo '</div>';
                    $comp_labels = array_map(fn($k) => $comp_list[$k] ?? $k, array_keys($comps));
                    echo '<div style="display:flex;flex-wrap:wrap;gap:4px;">';
                    foreach ($comp_labels as $cl) {
                        echo '<span style="background:#fee2e2;color:#dc2626;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:600;">' . htmlspecialchars($cl) . '</span>';
                    }
                    echo '</div></div>';
                }
                echo '</div>';
            }

            echo '</div>';
            return true;
        }

        // ---- ABA 1: Histórico Manutenção (código original) ----
        $history    = self::getHistory($itemtype, $items_id, 100);
        $comp_list  = self::getComponents();
        $upload_url = $CFG_GLPI['root_doc'] . '/files/uploads/plugin_assetmgrstatus/';

        $days = self::getDaysSinceLastMaintenance($itemtype, $items_id);
        if ($days === null) {
            echo '<div style="background:#fff7ed;border:1.5px solid #fed7aa;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:.88rem;color:#c2410c;"><i class="ti ti-alert-triangle" style="font-size:1.2rem;flex-shrink:0;"></i><strong>Nenhuma manutenção realizada foi registrada neste ativo.</strong></div>';
        } elseif ($days > 60) {
            echo '<div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:.88rem;color:#dc2626;"><i class="ti ti-alert-circle" style="font-size:1.2rem;flex-shrink:0;"></i><strong>Última manutenção há ' . $days . ' dias — acima do limite de 60 dias!</strong></div>';
        } else {
            echo '<div style="background:#d1fae5;border:1.5px solid #a7f3d0;border-radius:10px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:.88rem;color:#065f46;"><i class="ti ti-circle-check" style="font-size:1.2rem;flex-shrink:0;"></i><strong>Última manutenção há ' . $days . ' dias — dentro do prazo.</strong></div>';
        }

        echo '<div class="am-tab-content" style="padding:0 0 16px;font-family:\'Inter\',\'Segoe UI\',sans-serif;">';
        if (empty($history)) {
            echo '<div style="text-align:center;padding:40px;color:#9ca3af;"><i class="ti ti-clipboard-off" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4;"></i><p>Nenhum registro para este ativo.</p></div>';
        } else {
            echo '<div style="display:flex;flex-direction:column;gap:12px;">';
            foreach ($history as $h) {
                $comps       = $h['components'] ? json_decode($h['components'], true) : [];
                $photos      = $h['photos']     ? json_decode($h['photos'], true)     : [];
                $u           = new User();
                $uname       = ($h['users_id'] && $u->getFromDB($h['users_id'])) ? $u->getName() : 'Sistema';
                $record_type = $h['record_type'] ?? self::RECORD_STATUS_CHANGE;
                $border      = self::getRecordTypeColor($record_type);
                $type_label  = self::getRecordTypeLabel($record_type);

                echo '<div style="background:#fff;border:1.5px solid #e8eaf0;border-left:4px solid '.$border.';border-radius:12px;overflow:hidden;">';
                echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#fafbff;border-bottom:1px solid #f0f2f8;flex-wrap:wrap;gap:6px;">';
                echo '<div style="display:flex;align-items:center;gap:10px;"><span style="font-size:.8rem;font-weight:700;color:#6b7280;">'.$type_label.'</span>';
                if ($record_type === self::RECORD_STATUS_CHANGE || $record_type === self::RECORD_TRANSFER_RETURN) {
                    if (!empty($h['status_old']) && $h['status_old'] !== $h['status_new']) echo '<span class="am-badge '.self::getStatusBadgeClass($h['status_old']).'">'.self::getStatusLabel($h['status_old']).'</span><i class="ti ti-arrow-right" style="color:#9ca3af;font-size:.85rem;"></i>';
                    echo '<span class="am-badge '.self::getStatusBadgeClass($h['status_new']).'">'.self::getStatusLabel($h['status_new']).'</span>';
                } elseif ($record_type === self::RECORD_TRANSFER) {
                    // Transferência de envio: mostra apenas o status atual (sem transição)
                    echo '<span class="am-badge '.self::getStatusBadgeClass($h['status_new']).'">'.self::getStatusLabel($h['status_new']).'</span>';
                }
                echo '</div>';
                echo '<div style="display:flex;gap:14px;font-size:.78rem;color:#9ca3af;"><span><i class="ti ti-calendar"></i> '.Html::convDateTime($h['date_creation']).'</span><span><i class="ti ti-user"></i> '.htmlspecialchars($uname).'</span></div>';
                echo '</div><div style="padding:12px 16px;">';

                if (!empty($h['action_description'])) {
                    $ic = self::getRecordTypeColor($record_type);
                    $ii = self::getRecordTypeIcon($record_type);
                    echo '<div style="display:flex;gap:7px;font-size:.88rem;color:#1f2937;margin-bottom:10px;background:#f9fafb;padding:10px 12px;border-radius:8px;border-left:3px solid '.$ic.';">';
                    echo '<i class="ti ti-'.$ii.'" style="flex-shrink:0;margin-top:2px;color:'.$ic.';"></i><span>'.htmlspecialchars($h['action_description']).'</span></div>';
                }
                if ($record_type === self::RECORD_BAIXA && !empty($h['action_date'])) {
                    echo '<div style="font-size:.82rem;color:#6b7280;margin-bottom:8px;"><i class="ti ti-calendar-event"></i> Data da baixa: <strong>'.date('d/m/Y', strtotime($h['action_date'])).'</strong></div>';
                }
                if ($h['reason'] && $record_type === self::RECORD_STATUS_CHANGE) {
                    echo '<div style="display:flex;gap:7px;font-size:.88rem;color:#4b5563;margin-bottom:10px;background:#f9fafb;padding:10px 12px;border-radius:8px;border-left:3px solid #4f46e5;">';
                    echo '<i class="ti ti-notes" style="flex-shrink:0;margin-top:2px;color:#4f46e5;"></i><span>'.htmlspecialchars($h['reason']).'</span></div>';
                }
                if (!empty($comps)) {
                    echo '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;">';
                    foreach ($comps as $ck => $cd) echo '<span style="background:#f0f0ff;border:1px solid #c7d2fe;border-radius:6px;padding:3px 10px;font-size:.78rem;color:#3730a3;font-weight:500;"><strong>'.htmlspecialchars($comp_list[$ck] ?? $ck).'</strong>'.($cd?': '.htmlspecialchars($cd):'').'</span>';
                    echo '</div>';
                }
                if (!empty($photos)) {
                    echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">';
                    foreach ($photos as $photo) echo '<a href="'.$upload_url.htmlspecialchars($photo).'" target="_blank"><img src="'.$upload_url.htmlspecialchars($photo).'" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1.5px solid #e8eaf0;" alt="Foto"></a>';
                    echo '</div>';
                }
                echo '</div></div>';
            }
            echo '</div>';
        }
        echo '</div>';
        return true;
    }

    // ---- Busca ativos ----

    public static function getAssets(string $type_filter = '', string $search = '', string $status_filter = '', array $component_filters = [], array $fabricante_filter = [], int|array|null $entity_filter = null): array
    {
        global $DB;
        $types     = self::getAssetTypes();
        $results   = [];
        $type_keys = $type_filter && isset($types[$type_filter]) ? [$type_filter] : array_keys($types);

        // Determina entidade efetiva: se ADMIN tem filtro solicitado respeita, senão usa ativa
        $effective_entity = null;
        $has_admin = Session::haveRight('plugin_assetmgrstatus_admin', READ);
        if ($entity_filter !== null && $has_admin) {
            // Normaliza: [] ou 0 ou [0] = todas
            if (is_array($entity_filter)) {
                $entity_filter = array_values(array_filter(array_map('intval', $entity_filter), fn($v) => $v > 0));
                $effective_entity = empty($entity_filter) ? 0 : $entity_filter;
            } else {
                $effective_entity = (int)$entity_filter; // 0 = todas
            }
            // ADMIN com filtro explícito: por enquanto SEM expansão automática (filtro exato MAE + outra)
            // Para incluir filhas, use entity_recursive=1 (ver abaixo)
            $do_expand = !empty($_GET['entity_recursive']);
            if ($do_expand && $effective_entity !== 0 && $effective_entity !== null) {
                $effective_entity = self::expandEntityIds($effective_entity);
            }
        } else {
            $effective_entity = Session::getActiveEntity();
            // Não-ADMIN: respeita flag recursiva do GLPI
            if (!empty($_SESSION['glpiactiveentity_is_recursive']) && $effective_entity !== 0 && $effective_entity !== null) {
                $effective_entity = self::expandEntityIds($effective_entity);
            }
        }

        foreach ($type_keys as $system_name) {
            $def      = $types[$system_name];
            $def_iter = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_assets_assetdefinitions', 'WHERE' => ['system_name' => $system_name], 'LIMIT' => 1]);
            if ($def_iter->count() === 0) continue;
            $def_id   = $def_iter->current()['id'];
            // Tipos antigos têm sufixo 'Asset', novos não
            $legacy_types = ['Desktop','Notebook','Celular','Tablet'];
            $itemtype = in_array($system_name, $legacy_types)
                ? 'Glpi\\CustomAsset\\' . $system_name . 'Asset'
                : 'Glpi\\CustomAsset\\' . $system_name;

            $where = [
                'glpi_assets_assets.assets_assetdefinitions_id' => $def_id,
                'glpi_assets_assets.is_deleted'                 => 0,
            ];
            // Filtro por entidade: 0 = todas (apenas ADMIN)
            if ($effective_entity !== 0 && $effective_entity !== null) {
                $where['glpi_assets_assets.entities_id'] = $effective_entity;
            }

            if ($search) $where[] = ['OR' => [['glpi_assets_assets.name' => ['LIKE', "%$search%"]], ['glpi_assets_assets.serial' => ['LIKE', "%$search%"]], ['glpi_assets_assets.otherserial' => ['LIKE', "%$search%"]]]];

            if ($status_filter) {
                if ($status_filter === self::STATUS_ESTOQUE) {
                    $where[] = ['OR' => [['glpi_plugin_assetmgrstatus_records.am_status' => self::STATUS_ESTOQUE], ['glpi_plugin_assetmgrstatus_records.id' => null]]];
                } else {
                    $where['glpi_plugin_assetmgrstatus_records.am_status'] = $status_filter;
                }
            }

            // Filtro por múltiplos componentes: $component_filters = ['motherboard' => 'has', 'keyboard' => 'not', ...]
            // 'has' = precisa ter o JSON contendo essa chave (LIKE no SQL, filtramos com AND via múltiplos LIKE)
            // 'not' = precisa NÃO ter essa chave (filtrado depois em PHP)
            $not_filters = [];
            if (!empty($component_filters)) {
                foreach ($component_filters as $ckey => $mode) {
                    if ($mode === 'has') {
                        $where[] = ['glpi_plugin_assetmgrstatus_records.components' => ['LIKE', '%"' . $ckey . '"%']];
                    } elseif ($mode === 'not') {
                        $not_filters[] = $ckey;
                    }
                }
            }

            // Filtro por fabricante (usado na aba Notebook)
            if (!empty($fabricante_filter)) {
                $fab_ids = array_map('intval', $fabricante_filter);
                $fab_ids = array_filter($fab_ids);
                if (!empty($fab_ids)) {
                    $where['glpi_assets_assets.manufacturers_id'] = $fab_ids;
                }
            }

            $criteria = [
                'SELECT' => ['glpi_assets_assets.id','glpi_assets_assets.name','glpi_assets_assets.serial','glpi_assets_assets.otherserial','glpi_assets_assets.states_id','glpi_assets_assets.entities_id','glpi_assets_assets.manufacturers_id','glpi_states.name AS state_name','glpi_entities.name AS entity_name','glpi_entities.completename AS entity_completename','glpi_manufacturers.name AS manufacturer_name','glpi_plugin_assetmgrstatus_records.am_status AS plugin_status','glpi_plugin_assetmgrstatus_records.id AS record_id','glpi_plugin_assetmgrstatus_records.reason AS last_reason','glpi_plugin_assetmgrstatus_records.components AS last_components','glpi_plugin_assetmgrstatus_records.expected_return_date AS expected_return_date','glpi_plugin_assetmgrstatus_records.transfer_status AS transfer_status'],
                'FROM'      => 'glpi_assets_assets',
                'LEFT JOIN' => [
                    'glpi_states'   => ['ON' => ['glpi_assets_assets' => 'states_id', 'glpi_states' => 'id']],
                    'glpi_entities' => ['ON' => ['glpi_assets_assets' => 'entities_id', 'glpi_entities' => 'id']],
                    'glpi_manufacturers' => ['ON' => ['glpi_assets_assets' => 'manufacturers_id', 'glpi_manufacturers' => 'id']],
                    'glpi_plugin_assetmgrstatus_records' => ['ON' => ['glpi_plugin_assetmgrstatus_records' => 'items_id', 'glpi_assets_assets' => 'id', ['AND' => ['glpi_plugin_assetmgrstatus_records.itemtype' => $itemtype]]]],
                ],
                'WHERE' => $where,
                'ORDER' => ['glpi_assets_assets.name ASC'],
            ];

            $rows = iterator_to_array($DB->request($criteria));
            if (empty($rows)) continue;

            // Filtro fino "sem problema" feito em PHP (precisa NÃO ter cada componente em $not_filters)
            $filtered = [];
            foreach ($rows as $row) {
                if (!empty($not_filters)) {
                    $comps_decoded = $row['last_components'] ? json_decode($row['last_components'], true) : [];
                    $skip = false;
                    foreach ($not_filters as $nf_key) {
                        if (is_array($comps_decoded) && array_key_exists($nf_key, $comps_decoded)) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip) continue;
                }
                $filtered[] = $row;
            }
            if (empty($filtered)) continue;

            $ids = array_map('intval', array_column($filtered, 'id'));

            // Batch: última manutenção realizada por ativo (1 query por tipo) — sem MAX/GROUPBY para compatibilidade GLPI
            $last_maintenance = [];
            $iter_m = $DB->request([
                'SELECT' => ['items_id', 'date_creation'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
                'WHERE'  => ['items_id' => $ids, 'record_type' => self::RECORD_MANUTENCAO],
                'ORDER'  => ['date_creation DESC'],
            ]);
            foreach ($iter_m as $m) {
                $mid = (int)$m['items_id'];
                if (!isset($last_maintenance[$mid])) $last_maintenance[$mid] = $m['date_creation'];
            }

            // Batch: última mudança de status não desfeita por ativo (1 query por tipo)
            $last_status_change = [];
            $iter_s = $DB->request([
                'SELECT' => ['items_id', 'date_creation'],
                'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
                'WHERE'  => ['items_id' => $ids, 'record_type' => self::RECORD_STATUS_CHANGE, 'is_undone' => 0],
                'ORDER'  => ['date_creation DESC'],
            ]);
            foreach ($iter_s as $s) {
                $sid = (int)$s['items_id'];
                if (!isset($last_status_change[$sid])) $last_status_change[$sid] = $s['date_creation'];
            }

            $now_ts = time();
            foreach ($filtered as $row) {
                $last_date = $last_maintenance[(int)$row['id']] ?? null;
                $days      = ($last_date === null) ? null : (int)(new \DateTime($last_date))->diff(new \DateTime())->days;
                $row['alert_60days']           = ($days === null || $days > 60);
                $row['days_since_maintenance'] = $days;
                $row['asset_type_key']         = $system_name;
                $row['asset_type_label']       = $def['label'];
                $row['asset_icon']             = $def['icon'];
                $row['itemtype']               = $itemtype;

                $undo_date = $last_status_change[(int)$row['id']] ?? null;
                $row['can_undo'] = ($undo_date !== null && (($now_ts - strtotime($undo_date)) / 3600) <= 48);

                // Prazo de retorno previsto (só relevante se status = manutencao)
                $row['expected_return_overdue'] = false;
                $row['expected_return_days']    = null;
                if (($row['plugin_status'] ?? '') === self::STATUS_MANUTENCAO && !empty($row['expected_return_date'])) {
                    $diff = (int)round((strtotime($row['expected_return_date']) - strtotime(date('Y-m-d'))) / 86400);
                    $row['expected_return_days']    = $diff;
                    $row['expected_return_overdue'] = $diff < 0;
                }

                $results[] = $row;
            }
        }
        return $results;
    }

    public static function getAssetsPaged(string $type_filter = '', string $search = '', string $status_filter = '', array $component_filters = [], $fabricante_filter = [], int $page = 1, int $per_page = 24, int|array|null $entity_filter = null): array
    {
        // Compatibilidade: chamada antiga passava $page como 5º param (int) ou $fabricante_filter como int
        if (is_int($fabricante_filter)) {
            // Detecta se o 5º param é na verdade $page (legado) ou $entity_filter
            // Se per_page == 24 default e page é int grande, trata como compat
            $per_page = $page;
            $page = $fabricante_filter;
            $fabricante_filter = [];
        }
        if (!is_array($fabricante_filter)) $fabricante_filter = [];
        $all      = self::getAssets($type_filter, $search, $status_filter, $component_filters, $fabricante_filter, $entity_filter);
        $total    = count($all);
        $per_page = max(1, $per_page);
        $pages    = max(1, (int)ceil($total / $per_page));
        $page     = max(1, min($page, $pages));

        return [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => $pages,
            'rows'     => array_slice($all, ($page - 1) * $per_page, $per_page),
        ];
    }

    // ---- Salva registros ----

    public static function saveRecord(string $itemtype, int $items_id, string $status, string $reason, array $components, array $photos, int $users_id_tech = 0, ?string $expected_return_date = null): bool
    {
        global $DB, $PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK;
        // Bloqueia alteração se ativo estiver em transferência (exceto quando bypass ativo, ex: finalizar)
        if (empty($PLUGIN_ASSETMGRSTATUS_BYPASS_LOCK)) {
            // Verifica transfer_status diretamente (não depende de Transfer::isLocked para evitar loop)
            $lock_iter = $DB->request(['SELECT' => ['transfer_status'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
            if ($lock_iter->count() > 0 && $lock_iter->current()['transfer_status'] === 'transferido') {
                return false;
            }
        }
        $now = date('Y-m-d H:i:s');
        $old_iter   = $DB->request(['FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
        $old_status     = $old_iter->count() > 0 ? $old_iter->current()['am_status']  : null;
        $old_reason     = $old_iter->count() > 0 ? $old_iter->current()['reason']     : null;
        $old_components = $old_iter->count() > 0 ? $old_iter->current()['components'] : null;

        $row = ['am_status' => $status, 'reason' => $reason, 'expected_return_date' => $expected_return_date ?: null, 'components' => json_encode($components), 'users_id_tech' => $users_id_tech, 'users_id' => Session::getLoginUserID(), 'date_mod' => $now];
        if ($old_iter->count() > 0) {
            $DB->update('glpi_plugin_assetmgrstatus_records', $row, ['itemtype' => $itemtype, 'items_id' => $items_id]);
        } else {
            $DB->insert('glpi_plugin_assetmgrstatus_records', array_merge($row, ['itemtype' => $itemtype, 'items_id' => $items_id, 'date_creation' => $now]));
        }
        $DB->insert('glpi_plugin_assetmgrstatus_histories', [
            'items_id'           => $items_id,
            'itemtype'           => $itemtype,
            'item_name'          => self::getItemName($items_id),
            'status_old'         => $old_status,
            'status_new'         => $status,
            'record_type'        => self::RECORD_STATUS_CHANGE,
            'reason'             => $reason,
            'action_description' => null,
            'action_date'        => null,
            'components'         => json_encode($components),
            'prev_reason'        => $old_reason,
            'prev_components'    => $old_components,
            'is_undone'          => 0,
            'photos'             => json_encode($photos),
            'users_id'           => Session::getLoginUserID(),
            'date_creation'      => $now,
        ]);
        $state_id = self::GLPI_STATE_MAP[$status] ?? null;
        if ($state_id) $DB->update('glpi_assets_assets', ['states_id' => $state_id], ['id' => $items_id]);
        return true;
    }

    // -------------------------------------------------------
    // Desfazer última mudança de status (até 48h)
    // -------------------------------------------------------

    public static function getUndoableChange(string $itemtype, int $items_id): ?array
    {
        global $DB;

        $iter = $DB->request([
            'FROM'  => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE' => [
                'itemtype'    => $itemtype,
                'items_id'    => $items_id,
                'record_type' => self::RECORD_STATUS_CHANGE,
                'is_undone'   => 0,
            ],
            'ORDER' => ['date_creation DESC'],
            'LIMIT' => 1,
        ]);

        if ($iter->count() === 0) return null;
        $row = $iter->current();

        $hours = (time() - strtotime($row['date_creation'])) / 3600;
        if ($hours > 48) return null;

        return $row;
    }

    public static function undoLastChange(string $itemtype, int $items_id): bool
    {
        global $DB;

        $history_row = self::getUndoableChange($itemtype, $items_id);
        if (!$history_row) return false;

        $prev_status     = $history_row['status_old'];
        $prev_reason     = $history_row['prev_reason'];
        $prev_components = $history_row['prev_components'];

        // Se não havia status anterior (era o primeiro registro), remove o registro do plugin inteiramente
        if ($prev_status === null) {
            $DB->delete('glpi_plugin_assetmgrstatus_records', ['itemtype' => $itemtype, 'items_id' => $items_id]);
            // Volta o estado GLPI para Estoque (padrão)
            $DB->update('glpi_assets_assets', ['states_id' => self::GLPI_STATE_MAP[self::STATUS_ESTOQUE]], ['id' => $items_id]);
        } else {
            $DB->update('glpi_plugin_assetmgrstatus_records', [
                'am_status'  => $prev_status,
                'reason'     => $prev_reason,
                'components' => $prev_components,
                'date_mod'   => date('Y-m-d H:i:s'),
            ], ['itemtype' => $itemtype, 'items_id' => $items_id]);

            $state_id = self::GLPI_STATE_MAP[$prev_status] ?? null;
            if ($state_id) $DB->update('glpi_assets_assets', ['states_id' => $state_id], ['id' => $items_id]);
        }

        // Marca o registro de histórico como desfeito
        $DB->update('glpi_plugin_assetmgrstatus_histories', ['is_undone' => 1], ['id' => $history_row['id']]);

        // Registra a própria ação de desfazer no histórico
        $DB->insert('glpi_plugin_assetmgrstatus_histories', [
            'items_id'           => $items_id,
            'itemtype'           => $itemtype,
            'item_name'          => self::getItemName($items_id),
            'status_old'         => $history_row['status_new'],
            'status_new'         => $prev_status ?? self::STATUS_ESTOQUE,
            'record_type'        => self::RECORD_STATUS_CHANGE,
            'reason'             => '↩️ Status revertido pelo usuário',
            'action_description' => null,
            'action_date'        => null,
            'components'         => $prev_components,
            'prev_reason'        => null,
            'prev_components'    => null,
            'is_undone'          => 0,
            'photos'             => null,
            'users_id'           => Session::getLoginUserID(),
            'date_creation'      => date('Y-m-d H:i:s'),
        ]);

        return true;
    }


    public static function saveManutencaoRealizada(string $itemtype, int $items_id, string $description, array $photos): bool
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $cur = $DB->request(['SELECT' => ['am_status'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
        $current_status = $cur->count() > 0 ? $cur->current()['am_status'] : self::STATUS_ATIVO;
        $DB->insert('glpi_plugin_assetmgrstatus_histories', ['items_id' => $items_id, 'itemtype' => $itemtype, 'item_name' => self::getItemName($items_id), 'status_old' => $current_status, 'status_new' => $current_status, 'record_type' => self::RECORD_MANUTENCAO, 'reason' => null, 'action_description' => $description, 'action_date' => null, 'components' => null, 'photos' => json_encode($photos), 'users_id' => Session::getLoginUserID(), 'date_creation' => $now]);
        return true;
    }

    public static function saveBaixa(string $itemtype, int $items_id, string $motivo, string $data_baixa, array $photos): bool
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $cur = $DB->request(['SELECT' => ['am_status'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
        $old_status = $cur->count() > 0 ? $cur->current()['am_status'] : null;
        $row = ['am_status' => self::STATUS_INSERVIVEL, 'reason' => $motivo, 'date_mod' => $now, 'users_id' => Session::getLoginUserID()];
        if ($cur->count() > 0) { $DB->update('glpi_plugin_assetmgrstatus_records', $row, ['itemtype' => $itemtype, 'items_id' => $items_id]); }
        else { $DB->insert('glpi_plugin_assetmgrstatus_records', array_merge($row, ['itemtype' => $itemtype, 'items_id' => $items_id, 'date_creation' => $now])); }
        $DB->insert('glpi_plugin_assetmgrstatus_histories', ['items_id' => $items_id, 'itemtype' => $itemtype, 'item_name' => self::getItemName($items_id), 'status_old' => $old_status, 'status_new' => self::RECORD_BAIXA, 'record_type' => self::RECORD_BAIXA, 'reason' => null, 'action_description' => $motivo, 'action_date' => $data_baixa ?: null, 'components' => null, 'photos' => json_encode($photos), 'users_id' => Session::getLoginUserID(), 'date_creation' => $now]);
        $DB->update('glpi_assets_assets', ['states_id' => self::GLPI_STATE_MAP[self::STATUS_INSERVIVEL]], ['id' => $items_id]);
        return true;
    }

    // -------------------------------------------------------
    // Observação avulsa (não altera status)
    // -------------------------------------------------------

    public static function saveNote(string $itemtype, int $items_id, string $note): bool
    {
        global $DB;
        $now = date('Y-m-d H:i:s');

        $cur = $DB->request(['SELECT' => ['am_status'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
        $current_status = $cur->count() > 0 ? $cur->current()['am_status'] : self::STATUS_ESTOQUE;

        $DB->insert('glpi_plugin_assetmgrstatus_histories', [
            'items_id'           => $items_id,
            'itemtype'           => $itemtype,
            'item_name'          => self::getItemName($items_id),
            'status_old'         => $current_status,
            'status_new'         => $current_status,
            'record_type'        => self::RECORD_NOTE,
            'reason'             => null,
            'action_description' => $note,
            'action_date'        => null,
            'components'         => null,
            'photos'             => null,
            'users_id'           => Session::getLoginUserID(),
            'date_creation'      => $now,
        ]);

        return true;
    }

    // -------------------------------------------------------
    // Transferência — histórico no "Histórico Manutenção"
    // -------------------------------------------------------

    /**
     * Registra no histórico de manutenção que o ativo foi enviado em transferência.
     * Chamado em Transfer::create() para cada ativo transferido.
     */
    public static function logTransferEnvio(string $itemtype, int $items_id, int $transfer_id, string $origin_name, string $dest_name, string $reason): bool
    {
        global $DB;
        $now = date('Y-m-d H:i:s');

        // Status atual do ativo (para manter referência)
        $cur = $DB->request(['SELECT' => ['am_status'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
        $current_status = $cur->count() > 0 ? $cur->current()['am_status'] : self::STATUS_ESTOQUE;

        $origin = $origin_name !== '' ? $origin_name : 'Origem';
        $dest   = $dest_name !== ''   ? $dest_name   : 'Destino';
        $uid = (int)Session::getLoginUserID();
        $u = new User();
        $creator = ($uid && $u->getFromDB($uid)) ? $u->getName() : 'Sistema';

        $desc = '🚚 Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT)
              . ' — Enviado de "' . $origin . '" para "' . $dest . '" por ' . $creator
              . ($reason !== '' ? ' — Motivo: ' . $reason : '');

        try {
            $DB->insert('glpi_plugin_assetmgrstatus_histories', [
                'items_id'           => $items_id,
                'itemtype'           => $itemtype,
                'item_name'          => self::getItemName($items_id),
                'status_old'         => $current_status,
                'status_new'         => $current_status,
                'record_type'        => self::RECORD_TRANSFER,
                'reason'             => null,
                'action_description' => $desc,
                'action_date'        => null,
                'components'         => null,
                'photos'             => null,
                'users_id'           => $uid,
                'date_creation'      => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[assetmgrstatus] logTransferEnvio: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Registra no histórico de manutenção que o técnico devolveu o ativo à entidade de origem.
     * Chamado em Transfer::finalizar() para cada ativo.
     */
    public static function logTransferRetorno(string $itemtype, int $items_id, int $transfer_id, string $tech_name, string $origin_name, string $dest_name, string $final_status = '', string $final_reason = ''): bool
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $uid = (int)Session::getLoginUserID();

        // Para retorno, status_new é o status final aplicado no inventário
        if ($final_status !== '') {
            $status_new = $final_status;
        } else {
            $cur = $DB->request(['SELECT' => ['am_status'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
            $status_new = $cur->count() > 0 ? $cur->current()['am_status'] : self::STATUS_ESTOQUE;
        }
        // Tenta buscar o status antigo (antes do saveRecord) via último histórico de status_change
        $cur_hist = $DB->request([
            'SELECT' => ['status_old'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id, 'record_type' => self::RECORD_STATUS_CHANGE],
            'ORDER'  => ['date_creation DESC'],
            'LIMIT'  => 1,
        ]);
        $status_old = $cur_hist->count() > 0 ? $cur_hist->current()['status_old'] : $status_new;

        $origin = $origin_name !== '' ? $origin_name : 'Entidade de origem';
        $dest   = $dest_name !== ''   ? $dest_name   : 'URE';
        $tech   = $tech_name !== ''   ? $tech_name   : 'Técnico';
        $label  = $final_status !== '' ? (self::getStatusLabel($final_status)) : $status_new;

        $desc = '↩️ Transferência #' . str_pad($transfer_id, 4, '0', STR_PAD_LEFT)
              . ' — Técnico ' . $tech . ' devolveu de "' . $dest . '" para "' . $origin . '"'
              . ' — Status final: ' . $label
              . ($final_reason !== '' ? ' — Motivo: ' . $final_reason : '');

        try {
            $DB->insert('glpi_plugin_assetmgrstatus_histories', [
                'items_id'           => $items_id,
                'itemtype'           => $itemtype,
                'item_name'          => self::getItemName($items_id),
                'status_old'         => $status_old,
                'status_new'         => $status_new,
                'record_type'        => self::RECORD_TRANSFER_RETURN,
                'reason'             => $final_reason ?: null,
                'action_description' => $desc,
                'action_date'        => null,
                'components'         => null,
                'photos'             => null,
                'users_id'           => $uid,
                'date_creation'      => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[assetmgrstatus] logTransferRetorno: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Wrapper genérico para tipos futuros de transferência.
     */
    public static function logTransferGeneric(string $itemtype, int $items_id, string $record_type, string $description, ?string $status_old = null, ?string $status_new = null): bool
    {
        global $DB;
        $now = date('Y-m-d H:i:s');
        $uid = (int)Session::getLoginUserID();
        if ($status_old === null || $status_new === null) {
            $cur = $DB->request(['SELECT' => ['am_status'], 'FROM' => 'glpi_plugin_assetmgrstatus_records', 'WHERE' => ['itemtype' => $itemtype, 'items_id' => $items_id], 'LIMIT' => 1]);
            $cur_status = $cur->count() > 0 ? $cur->current()['am_status'] : self::STATUS_ESTOQUE;
            $status_old = $status_old ?? $cur_status;
            $status_new = $status_new ?? $cur_status;
        }
        try {
            $DB->insert('glpi_plugin_assetmgrstatus_histories', [
                'items_id'           => $items_id,
                'itemtype'           => $itemtype,
                'item_name'          => self::getItemName($items_id),
                'status_old'         => $status_old,
                'status_new'         => $status_new,
                'record_type'        => $record_type,
                'reason'             => null,
                'action_description' => $description,
                'action_date'        => null,
                'components'         => null,
                'photos'             => null,
                'users_id'           => $uid,
                'date_creation'      => $now,
            ]);
        } catch (\Throwable $e) {
            error_log('[assetmgrstatus] logTransferGeneric: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    // -------------------------------------------------------
    // Prazo de retorno previsto (status Manutenção)
    // -------------------------------------------------------

    public static function getExpectedReturnInfo(string $itemtype, int $items_id): ?array
    {
        global $DB;

        $iter = $DB->request([
            'SELECT' => ['am_status', 'expected_return_date'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_records',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'LIMIT'  => 1,
        ]);

        if ($iter->count() === 0) return null;
        $row = $iter->current();

        if ($row['am_status'] !== self::STATUS_MANUTENCAO || !$row['expected_return_date']) return null;

        $today    = strtotime(date('Y-m-d'));
        $expected = strtotime($row['expected_return_date']);
        $days_diff = (int)round(($expected - $today) / 86400);

        return [
            'date'      => $row['expected_return_date'],
            'days_diff' => $days_diff, // negativo = atrasado
            'overdue'   => $days_diff < 0,
        ];
    }

    public static function deleteAsset(string $itemtype, int $items_id): bool
    {
        global $DB;
        $DB->update('glpi_assets_assets', ['is_deleted' => 1], ['id' => $items_id]);
        // Remove do plugin (records, views e itens de transferência pendentes)
        $DB->delete('glpi_plugin_assetmgrstatus_records', ['itemtype' => $itemtype, 'items_id' => $items_id]);
        $DB->delete('glpi_plugin_assetmgrstatus_views', ['itemtype' => $itemtype, 'items_id' => $items_id]);
        $DB->insert('glpi_plugin_assetmgrstatus_histories', ['items_id' => $items_id, 'itemtype' => $itemtype, 'item_name' => self::getItemName($items_id), 'status_old' => null, 'status_new' => 'deleted', 'record_type' => 'deleted', 'reason' => 'Ativo removido via plugin (GLPI + Plugin)', 'action_description' => null, 'action_date' => null, 'components' => null, 'photos' => null, 'users_id' => Session::getLoginUserID(), 'date_creation' => date('Y-m-d H:i:s')]);
        return true;
    }

    // -------------------------------------------------------
    // Log de visualizações
    // -------------------------------------------------------

    public static function logView(string $itemtype, int $items_id): void
    {
        global $DB;
        $uid = (int)Session::getLoginUserID();
        if (!$uid) return;

        // Evita duplicar dentro de 5 minutos pelo mesmo usuário
        $recent = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_views',
            'WHERE'  => [
                'itemtype' => $itemtype,
                'items_id' => $items_id,
                'users_id' => $uid,
                ['date_creation' => ['>=', date('Y-m-d H:i:s', strtotime('-5 minutes'))]],
            ],
            'LIMIT'  => 1,
        ]);
        if ($recent->count() > 0) return;

        $DB->insert('glpi_plugin_assetmgrstatus_views', [
            'itemtype'      => $itemtype,
            'items_id'      => $items_id,
            'users_id'      => $uid,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function getRecentViews(string $itemtype, int $items_id, int $limit = 5): array
    {
        global $DB;
        $iter = $DB->request([
            'SELECT' => ['users_id', 'date_creation'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_views',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id],
            'ORDER'  => ['date_creation DESC'],
            'LIMIT'  => $limit,
        ]);
        $result = [];
        foreach ($iter as $row) {
            $u = new User();
            $name = ($row['users_id'] && $u->getFromDB($row['users_id'])) ? $u->getName() : 'Desconhecido';
            $result[] = ['user' => $name, 'date' => $row['date_creation']];
        }
        return $result;
    }

    // -------------------------------------------------------
    // Foto do ativo (via glpi_documents vinculados)
    // -------------------------------------------------------

    public static function getAssetPhotoUrl(string $itemtype, int $items_id): ?string
    {
        global $DB, $CFG_GLPI;
        $iter = $DB->request([
            'SELECT' => ['d.filename', 'd.filepath'],
            'FROM'   => 'glpi_documents AS d',
            'LEFT JOIN' => [
                'glpi_documents_items AS di' => [
                    'ON' => ['d' => 'id', 'di' => 'documents_id'],
                ],
            ],
            'WHERE'  => [
                'di.items_id'  => $items_id,
                'di.itemtype'  => $itemtype,
                'di.timeline_position' => 0,
                ['d.mime' => ['LIKE', 'image/%']],
            ],
            'ORDER'  => ['d.date_creation DESC'],
            'LIMIT'  => 1,
        ]);
        if ($iter->count() === 0) return null;
        $row = $iter->current();
        return $CFG_GLPI['root_doc'] . '/front/document.send.php?docid=' . urlencode($row['filepath']);
    }

    // -------------------------------------------------------
    // Mini timeline dos últimos status
    // -------------------------------------------------------

    public static function getMiniTimeline(string $itemtype, int $items_id, int $limit = 6): array
    {
        global $DB;
        $iter = $DB->request([
            'SELECT' => ['status_new', 'record_type', 'date_creation'],
            'FROM'   => 'glpi_plugin_assetmgrstatus_histories',
            'WHERE'  => ['itemtype' => $itemtype, 'items_id' => $items_id, 'record_type' => self::RECORD_STATUS_CHANGE],
            'ORDER'  => ['date_creation DESC'],
            'LIMIT'  => $limit,
        ]);
        $result = [];
        foreach ($iter as $row) {
            $result[] = [
                'status'  => $row['status_new'],
                'label'   => self::getStatusLabel($row['status_new']),
                'color'   => self::getStatusColor($row['status_new']),
                'date'    => $row['date_creation'],
            ];
        }
        return array_reverse($result);
    }

    public static function getStatusColor(string $status): string
    {
        return match($status) {
            self::STATUS_ATIVO       => '#10b981',
            self::STATUS_ESTOQUE     => '#8b5cf6',
            self::STATUS_INATIVO     => '#ef4444',
            self::STATUS_GARANTIA    => '#3b82f6',
            self::STATUS_INSERVIVEL  => '#6b7280',
            self::STATUS_MANUTENCAO  => '#f59e0b',
            default                  => '#9ca3af',
        };
    }

    public static function getHistory(string $itemtype = '', int $items_id = 0, int $limit = 50): array
    {
        global $DB;
        $asset_iter = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_assets_assets', 'WHERE' => ['entities_id' => Session::getActiveEntity(), 'is_deleted' => 0]]);
        $asset_ids  = array_column(iterator_to_array($asset_iter), 'id');
        if (empty($asset_ids)) return [];
        $where = ['glpi_plugin_assetmgrstatus_histories.items_id' => $asset_ids];
        if ($itemtype) $where['glpi_plugin_assetmgrstatus_histories.itemtype'] = $itemtype;
        if ($items_id) $where['glpi_plugin_assetmgrstatus_histories.items_id'] = $items_id;
        return iterator_to_array($DB->request(['FROM' => 'glpi_plugin_assetmgrstatus_histories', 'WHERE' => $where, 'ORDER' => ['date_creation DESC'], 'LIMIT' => $limit]));
    }

    public static function handlePhotoUpload(array $files): array
    {
        $upload_dir = GLPI_UPLOAD_DIR . '/plugin_assetmgrstatus/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $saved = []; $allowed_ext = ['jpg', 'jpeg', 'png'];
        foreach ($files as $file) {
            if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) continue;
            if (count($saved) >= 3) break;
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_ext)) continue;
            $filename = 'photo_' . uniqid() . '.' . $ext;
            if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) $saved[] = $filename;
        }
        return $saved;
    }

    private static function getItemName(int $items_id): string
    {
        global $DB;
        $iter = $DB->request(['SELECT' => ['name'], 'FROM' => 'glpi_assets_assets', 'WHERE' => ['id' => $items_id], 'LIMIT' => 1]);
        if ($iter->count() > 0) {
            $row = $iter->current();
            return (string)($row['name'] ?? "Ativo #$items_id");
        }
        return "Ativo #$items_id";
    }
}
