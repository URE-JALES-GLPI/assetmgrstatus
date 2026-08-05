<?php

if (!defined('GLPI_ROOT')) die("Sorry. You can't access this file directly");

class PluginAssetmgrstatusProfile extends CommonDBTM
{
    public static $rightname = 'profile';

    public const RIGHT_MANUTENCAO = 'plugin_assetmgrstatus';
    public const RIGHT_TECNICO    = 'plugin_assetmgrstatus_tecnico';
    public const RIGHT_TRANSFER   = 'plugin_assetmgrstatus_transfer';

    public static function getAllRights(): array
    {
        return [
            ['field' => self::RIGHT_MANUTENCAO, 'default' => 0],
            ['field' => self::RIGHT_TECNICO,    'default' => 0],
            ['field' => self::RIGHT_TRANSFER,   'default' => 0],
        ];
    }

    public static function install(): bool
    {
        global $DB;
        foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_profiles']) as $profile) {
            self::addDefaultProfileInfos((int)$profile['id']);
        }
        self::changeProfile();
        return true;
    }

    public static function uninstall(): bool
    {
        $pr = new ProfileRight();
        foreach (self::getAllRights() as $right) {
            $pr->deleteByCriteria(['name' => $right['field']]);
            unset($_SESSION['glpiactiveprofile'][$right['field']]);
        }
        return true;
    }

    public static function addDefaultProfileInfos(int $profiles_id): void
    {
        global $DB;
        $pr = new ProfileRight();
        foreach (self::getAllRights() as $right) {
            if (!countElementsInTable(ProfileRight::getTable(), ['profiles_id' => $profiles_id, 'name' => $right['field']])) {
                $pr->add(['profiles_id' => $profiles_id, 'name' => $right['field'], 'rights' => $right['default']]);
            }
        }
    }

    public static function changeProfile(): void
    {
        global $DB;
        $pid = (int)($_SESSION['glpiactiveprofile']['id'] ?? 0);
        if ($pid <= 0) return;
        foreach (self::getAllRights() as $r) unset($_SESSION['glpiactiveprofile'][$r['field']]);
        $iter = $DB->request(['SELECT' => ['name','rights'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['profiles_id' => $pid, 'name' => array_column(self::getAllRights(), 'field')]]);
        foreach ($iter as $row) $_SESSION['glpiactiveprofile'][$row['name']] = (int)$row['rights'];
    }

    private static function getRightValue(int $profiles_id, string $field): int
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['rights'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['profiles_id' => $profiles_id, 'name' => $field]])->current();
        return (is_array($row) && isset($row['rights'])) ? (int)$row['rights'] : 0;
    }

    private static function saveRight(int $profiles_id, string $field, int $value): void
    {
        global $DB;
        $row = $DB->request(['SELECT' => ['id'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['profiles_id' => $profiles_id, 'name' => $field]])->current();
        $pr  = new ProfileRight();
        if (is_array($row) && isset($row['id'])) $pr->update(['id' => (int)$row['id'], 'rights' => $value]);
        else $pr->add(['profiles_id' => $profiles_id, 'name' => $field, 'rights' => $value]);
    }

    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        if ($item instanceof Profile && $item->getField('id')) {
            return "<span class='d-inline-flex align-items-center gap-1'><i class='ti ti-tool'></i><span>Manutenção</span></span>";
        }
        return '';
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        global $CFG_GLPI;
        if (!($item instanceof Profile)) return false;
        if (!$item->canView()) return false;

        $pid = (int)$item->getID();
        self::addDefaultProfileInfos($pid);

        $r_main     = self::getRightValue($pid, self::RIGHT_MANUTENCAO);
        $r_tecnico  = self::getRightValue($pid, self::RIGHT_TECNICO);
        $r_transfer = self::getRightValue($pid, self::RIGHT_TRANSFER);
        $canedit    = Session::haveRightsOr(self::$rightname, [CREATE, UPDATE, PURGE]);

        echo "<form name='assetmgrstatus_profile_form' method='post' action='" . $CFG_GLPI['root_doc'] . "/plugins/assetmgrstatus/front/profile.form.php'>";
        echo "<div class='spaced'><table class='tab_cadre_fixehov'>";
        echo "<tr class='headerRow'><th colspan='2'>🔧 Permissões — Manutenção de Ativos</th></tr>";

        // Manutenção principal
        echo "<tr class='tab_bg_1'><td width='65%'>
            <strong>Acesso à Manutenção de Ativos</strong><br>
            <small style='color:#6b7280;'>Permite acessar o menu Manutenção, visualizar e alterar status dos ativos</small>
            </td><td>";
        if ($canedit) {
            Dropdown::showFromArray('rights_main', [
                0                       => '— Sem acesso —',
                READ                    => '🔍 Visualizar',
                READ|UPDATE             => '✏️ Visualizar e Editar',
                READ|UPDATE|DELETE      => '🗑️ Visualizar, Editar e Apagar',
            ], ['value' => $r_main]);
        } else {
            $labels = [0 => 'Sem acesso', READ => 'Visualizar', READ|UPDATE => 'Visualizar e Editar', READ|UPDATE|DELETE => 'Visualizar, Editar e Apagar'];
            echo $labels[$r_main] ?? 'Sem acesso';
        }
        echo "</td></tr>";

        // Técnico (manutenção realizada e baixa)
        echo "<tr class='tab_bg_1'><td>
            <strong>Registrar Manutenção Realizada e Baixa</strong><br>
            <small style='color:#6b7280;'>Permite que técnicos registrem serviços realizados e deem baixa em ativos</small>
            </td><td>";
        if ($canedit) {
            Dropdown::showYesNo('rights_tecnico', ($r_tecnico & READ) ? 1 : 0);
        } else {
            echo ($r_tecnico & READ) ? '✅ Permitido' : '❌ Negado';
        }
        echo "</td></tr>";

        // Transferência
        echo "<tr class='tab_bg_1'><td>
            <strong>Acesso à aba Transferência</strong><br>
            <small style='color:#6b7280;'>Permite criar e visualizar transferências de ativos entre entidades (UREs e escolas)</small>
            </td><td>";
        if ($canedit) {
            Dropdown::showYesNo('rights_transfer', ($r_transfer & READ) ? 1 : 0);
        } else {
            echo ($r_transfer & READ) ? '✅ Permitido' : '❌ Negado';
        }
        echo "</td></tr>";

        // Técnico (painel técnico)
        echo "<tr class='tab_bg_1'><td>
            <strong>Acesso ao Painel Técnico</strong><br>
            <small style='color:#6b7280;'>Permite visualizar os cards de transferência, assumir manutenções e marcar como pronto/finalizado na aba Técnico</small>
            </td><td>";
        if ($canedit) {
            // Reutiliza o campo rights_tecnico para o painel técnico
            // Criamos um campo separado: rights_tecnico_panel
            $r_tec_panel = $r_tecnico; // mesmo campo por ora — exibido separado para clareza
            Dropdown::showYesNo('rights_tecnico_panel', ($r_tec_panel & READ) ? 1 : 0, ['on_change' => '']);
            echo "<br><small style='color:#9ca3af;'>⚠️ Ao habilitar, garanta também acesso à Manutenção acima.</small>";
        } else {
            echo ($r_tecnico & READ) ? '✅ Permitido' : '❌ Negado';
        }
        echo "</td></tr>";

        if ($canedit) {
            echo "<tr class='tab_bg_2'><td colspan='2' class='center' style='padding:12px;'>";
            echo Html::hidden('profiles_id', ['value' => $pid]);
            echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
            echo "<button type='submit' name='update' value='1' class='btn btn-primary'><i class='ti ti-device-floppy'></i> Salvar Permissões</button>";
            echo "</td></tr>";
        }

        echo "</table></div>";
        Html::closeForm();
        return true;
    }
}
