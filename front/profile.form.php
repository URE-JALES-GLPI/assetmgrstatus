<?php

include('../../../inc/includes.php');

global $DB;

Session::checkLoginUser();
Session::checkRight('profile', UPDATE);

$pid              = (int)($_POST['profiles_id']        ?? 0);
$rights_main      = (int)($_POST['rights_main']        ?? 0);
$rights_tecnico   = ((int)($_POST['rights_tecnico']    ?? 0) === 1 ? READ : 0)
                  | ((int)($_POST['rights_tecnico_panel'] ?? 0) === 1 ? READ : 0);
$rights_transfer  = (int)($_POST['rights_transfer']    ?? 0);
$rights_delete   = ((int)($_POST['rights_delete']    ?? 0) === 1 ? DELETE : 0);
$rights_admin    = ((int)($_POST['rights_admin']     ?? 0) === 1 ? READ : 0);

if (!$pid) { Html::back(); exit; }

foreach ([
    'plugin_assetmgrstatus'          => $rights_main,
    'plugin_assetmgrstatus_tecnico'  => $rights_tecnico,
    'plugin_assetmgrstatus_transfer' => $rights_transfer,
    'plugin_assetmgrstatus_delete'   => $rights_delete,
    'plugin_assetmgrstatus_admin'    => $rights_admin,
] as $right_name => $right_value) {
    $row = $DB->request(['SELECT' => ['id'], 'FROM' => ProfileRight::getTable(), 'WHERE' => ['profiles_id' => $pid, 'name' => $right_name]])->current();
    $pr  = new ProfileRight();
    if (is_array($row) && isset($row['id'])) $pr->update(['id' => (int)$row['id'], 'rights' => $right_value]);
    else $pr->add(['profiles_id' => $pid, 'name' => $right_name, 'rights' => $right_value]);
}

if ((int)($_SESSION['glpiactiveprofile']['id'] ?? 0) === $pid) {
    PluginAssetmgrstatusProfile::changeProfile();
}

Session::addMessageAfterRedirect('Permissões salvas com sucesso!', false, INFO);
Html::back();
