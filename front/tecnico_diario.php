<?php
include('../../../inc/includes.php');
use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;
Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) { Html::displayRightError(); exit; }
global $DB, $CFG_GLPI;
$transfer_id = (int)($_GET['id'] ?? 0);
if (!$transfer_id) { Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php'); exit; }
$transfer = Transfer::getById($transfer_id);
if (!$transfer || $transfer['status'] !== Transfer::STATUS_MANUTENCAO) { Session::addMessageAfterRedirect('Transferência inválida.', false, ERROR); Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php'); exit; }
$items = Transfer::getItems($transfer_id);
$comp_list = MaintenanceRecord::getComponents();
$ent = new Entity(); $entity_name = ($ent->getFromDB((int)$transfer['entity_dest'])) ? $ent->getName() : '—';
$u = new User(); $tech_name = ($transfer['users_id_tech'] && $u->getFromDB($transfer['users_id_tech'])) ? $u->getName() : '—';
$total = count($items); $concluidos = 0;
foreach ($items as $item) { if (($item['work_status'] ?? 'pending') === 'done') $concluidos++; }
Html::header('Diário de Manutenção', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'tecnico');
?>
<div class="container-fluid am-page">
<div class="am-breadcrumb">
    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php">Técnico</a>
    <i class="ti ti-chevron-right"></i>
    <span>Diário — Transferência #<?= str_pad($transfer_id,4,'0',STR_PAD_LEFT) ?></span>
</div>
<div class="am-page-header">
    <div class="am-page-title"><i class="ti ti-clipboard-text"></i><h2>Diário de Manutenção</h2></div>
    <div style="display:flex;gap:8px;align-items:center;">
        <button id="am-theme-btn" onclick="amToggleTheme()" class="am-btn am-btn-secondary" style="padding:8px 12px;font-size:.82rem;" title="Alternar tema claro/escuro"><i class="ti ti-moon"></i></button>
        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-arrow-left"></i> Voltar</a>
    </div>
</div>
<div style="background:#fff;border:1.5px solid #e8eaf0;border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;gap:24px;flex-wrap:wrap;align-items:center;">
    <div><span style="font-size:.72rem;color:#9ca3af;text-transform:uppercase;font-weight:700;display:block;">Destino</span><strong><?= htmlspecialchars($entity_name) ?></strong></div>
    <div><span style="font-size:.72rem;color:#9ca3af;text-transform:uppercase;font-weight:700;display:block;">Técnico</span><strong><?= htmlspecialchars($tech_name) ?></strong></div>
    <div><span style="font-size:.72rem;color:#9ca3af;text-transform:uppercase;font-weight:700;display:block;">Progresso</span><strong><?= $concluidos ?>/<?= $total ?> concluídos</strong></div>
    <div style="flex:1;min-width:200px;">
        <div style="background:#f0f2f8;border-radius:99px;height:10px;overflow:hidden;">
            <div style="background:linear-gradient(90deg,#10b981,#059669);height:100%;border-radius:99px;width:<?= $total>0?round($concluidos/$total*100):0 ?>%;transition:width .4s;"></div>
        </div>
        <div style="font-size:.72rem;color:#9ca3af;margin-top:4px;"><?= $total>0?round($concluidos/$total*100):0 ?>% concluído</div>
    </div>
    <?php if ($concluidos === $total && $total > 0): ?>
    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_pronto.php?id=<?= $transfer_id ?>" class="am-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:10px 20px;"><i class="ti ti-check"></i> Todos concluídos — Marcar como Pronto</a>
    <?php endif; ?>
</div>
<div style="display:flex;flex-direction:column;gap:14px;">
<?php foreach ($items as $item):
    $work_status = $item['work_status'] ?? 'pending';
    $work_log    = $item['work_log'] ?? '';
    $work_comps  = $item['work_components'] ? json_decode($item['work_components'], true) : [];
    $orig_comps  = [];
    try { $rec = $DB->request(['SELECT'=>['components'],'FROM'=>'glpi_plugin_assetmgrstatus_records','WHERE'=>['itemtype'=>$item['itemtype'],'items_id'=>(int)$item['items_id']],'LIMIT'=>1])->current(); if ($rec && $rec['components']) $orig_comps = json_decode($rec['components'], true) ?? []; } catch(\Exception $e){}
    $has_problems = !empty($orig_comps);
    $is_done = $work_status === 'done';
?>
<div class="am-diario-card <?= $is_done ? 'am-diario-done' : '' ?>" id="diario-card-<?= (int)$item['id'] ?>">
    <div class="am-diario-header" onclick="amToggleDiarioCard(<?= (int)$item['id'] ?>)">
        <div style="display:flex;align-items:center;gap:12px;">
            <div class="am-diario-status-icon <?= $is_done ? 'done' : ($has_problems ? 'warning' : 'pending') ?>">
                <i class="ti <?= $is_done ? 'ti-circle-check' : ($has_problems ? 'ti-alert-triangle' : 'ti-circle') ?>"></i>
            </div>
            <div>
                <div style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($item['item_name']) ?></div>
                <div style="font-size:.75rem;color:#9ca3af;"><?= htmlspecialchars($item['origin_entity_name'] ?? '—') ?></div>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
            <?php
            // URL do ativo
            $tipo_url = str_replace('Glpi\\CustomAsset\\', '', str_replace('Asset', '', $item['itemtype']));
            $asset_url = $CFG_GLPI['root_doc'] . '/front/asset/asset.form.php?class=' . urlencode($tipo_url) . '&id=' . (int)$item['items_id'];
            ?>
            <a href="<?= $asset_url ?>" target="_blank" class="am-btn am-btn-secondary" style="padding:6px 10px;width:auto;" title="Ver ativo" onclick="event.stopPropagation()">
                <i class="ti ti-eye"></i>
            </a>
            <?php if ($has_problems && !$is_done): ?>
            <span style="background:#fff7ed;border:1px solid #fed7aa;color:#c2410c;border-radius:8px;padding:4px 10px;font-size:.72rem;font-weight:700;"><i class="ti ti-alert-triangle"></i> <?= count($orig_comps) ?> problema(s)</span>
            <?php endif; ?>
            <?php if ($is_done): ?><span style="color:#10b981;font-size:.78rem;font-weight:700;"><i class="ti ti-circle-check"></i> Concluído</span><?php endif; ?>
            <i class="ti ti-chevron-down" id="chevron-<?= (int)$item['id'] ?>" style="color:#9ca3af;transition:transform .2s;"></i>
        </div>
    </div>
    <div class="am-diario-body" id="body-<?= (int)$item['id'] ?>" style="display:none;">
        <?php if ($has_problems): ?>
        <div style="margin-bottom:14px;">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;"><i class="ti ti-cpu"></i> Componentes com Problema</div>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php foreach ($orig_comps as $ck => $cd): $resolved = isset($work_comps[$ck]) && $work_comps[$ck] === 'resolved'; ?>
            <label class="am-diario-comp-toggle <?= $resolved ? 'resolved' : 'problem' ?>" id="comp-label-<?= (int)$item['id'] ?>-<?= $ck ?>">
                <input type="checkbox" data-item-id="<?= (int)$item['id'] ?>" data-comp="<?= $ck ?>" class="am-comp-resolve-cb" <?= $resolved ? 'checked' : '' ?> onchange="amToggleComp(this)">
                <i class="ti <?= $resolved ? 'ti-circle-check' : 'ti-alert-triangle' ?>"></i>
                <?= htmlspecialchars($comp_list[$ck] ?? $ck) ?><?php if ($cd): ?> <small style="opacity:.7">(<?= htmlspecialchars($cd) ?>)</small><?php endif; ?>
            </label>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <div style="margin-bottom:14px;">
            <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;color:#9ca3af;margin-bottom:8px;"><i class="ti ti-notes"></i> O que foi feito</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
            <?php foreach (['Formatação realizada','Limpeza física','Troca de componente','Atualização de drivers','Reinstalação do SO','Configuração de rede','Teste de hardware','Sem necessidade de reparo'] as $acao): ?>
            <button class="am-diario-quick-btn" onclick="amAddQuickAction(<?= (int)$item['id'] ?>, '<?= addslashes($acao) ?>')"><?= htmlspecialchars($acao) ?></button>
            <?php endforeach; ?>
            </div>
            <textarea id="log-<?= (int)$item['id'] ?>" class="am-textarea" style="min-height:80px;font-size:.85rem;" placeholder="Descreva o que foi feito..."><?= htmlspecialchars($work_log) ?></textarea>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <button class="am-btn am-btn-secondary" style="padding:8px 16px;font-size:.82rem;" onclick="amSalvarParcial(<?= (int)$item['id'] ?>, <?= $transfer_id ?>)"><i class="ti ti-device-floppy"></i> Salvar</button>
            <?php if (!$is_done): ?>
            <button class="am-btn" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;padding:8px 16px;font-size:.82rem;" onclick="amMarcarConcluido(<?= (int)$item['id'] ?>, <?= $transfer_id ?>)"><i class="ti ti-circle-check"></i> Concluído</button>
            <?php else: ?>
            <button class="am-btn am-btn-secondary" style="padding:8px 16px;font-size:.82rem;" onclick="amReabrirItem(<?= (int)$item['id'] ?>, <?= $transfer_id ?>)"><i class="ti ti-rotate-clockwise"></i> Reabrir</button>
            <?php endif; ?>
            <span id="save-msg-<?= (int)$item['id'] ?>" style="font-size:.78rem;color:#10b981;display:none;"><i class="ti ti-check"></i> Salvo!</span>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>
<div style="margin-top:24px;display:flex;justify-content:flex-end;gap:12px;">
    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php" class="am-btn am-btn-secondary" style="padding:10px 20px;"><i class="ti ti-x"></i> Voltar</a>
    <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_pronto.php?id=<?= $transfer_id ?>" class="am-btn" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;padding:10px 24px;"><i class="ti ti-check"></i> Marcar como Pronto</a>
</div>
</div>
<style>
.am-diario-card{background:#fff;border:1.5px solid #e8eaf0;border-radius:14px;overflow:hidden;transition:all .2s;}
.am-diario-card.am-diario-done{border-color:#a7f3d0;background:#f0fdf4;}
.am-diario-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;cursor:pointer;flex-wrap:wrap;gap:10px;}
.am-diario-body{padding:16px 20px 20px;border-top:1.5px solid #f0f2f8;}
.am-diario-status-icon{width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0;}
.am-diario-status-icon.done{background:#d1fae5;color:#10b981;}
.am-diario-status-icon.warning{background:#fff7ed;color:#f97316;}
.am-diario-status-icon.pending{background:#f0f2f8;color:#9ca3af;}
.am-diario-comp-toggle{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;cursor:pointer;font-size:.82rem;font-weight:600;border:1.5px solid;transition:all .2s;}
.am-diario-comp-toggle input{display:none;}
.am-diario-comp-toggle.problem{background:#fff7ed;border-color:#fed7aa;color:#c2410c;}
.am-diario-comp-toggle.resolved{background:#d1fae5;border-color:#a7f3d0;color:#065f46;}
.am-diario-quick-btn{background:#f0f2f8;border:none;border-radius:8px;padding:5px 12px;font-size:.75rem;cursor:pointer;color:#374151;transition:background .15s;}
.am-diario-quick-btn:hover{background:#e0e7ff;color:#4f46e5;}
</style>
<script>
var _diarioBase='<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/ajax/diario_save.php';
var _csrf='<?= Session::getNewCSRFToken() ?>';
function amToggleDiarioCard(id){var b=document.getElementById('body-'+id),c=document.getElementById('chevron-'+id),h=b.style.display==='none';b.style.display=h?'block':'none';c.style.transform=h?'rotate(180deg)':'';}
function amAddQuickAction(id,txt){var ta=document.getElementById('log-'+id);if(ta.value&&!ta.value.endsWith('\n'))ta.value+='\n';ta.value+='• '+txt+'\n';}
function amToggleComp(cb){var id=cb.dataset.itemId,comp=cb.dataset.comp,lbl=document.getElementById('comp-label-'+id+'-'+comp),icon=lbl.querySelector('.ti');if(cb.checked){lbl.className='am-diario-comp-toggle resolved';icon.className='ti ti-circle-check';}else{lbl.className='am-diario-comp-toggle problem';icon.className='ti ti-alert-triangle';}}
function amGetComps(id){var c={};document.querySelectorAll('.am-comp-resolve-cb[data-item-id="'+id+'"]').forEach(function(cb){c[cb.dataset.comp]=cb.checked?'resolved':'problem';});return c;}
function amSalvarParcial(id,tid,cb){fetch(_diarioBase,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'save',item_id:id,transfer_id:tid,log:document.getElementById('log-'+id).value,components:amGetComps(id),_glpi_csrf_token:_csrf})}).then(function(r){return r.json();}).then(function(d){if(d.ok){var m=document.getElementById('save-msg-'+id);m.style.display='inline';setTimeout(function(){m.style.display='none';},2000);if(cb)cb();}}).catch(function(e){console.error('Erro salvar:',e);});}
function amMarcarConcluido(id,tid){var unresvd=document.querySelectorAll('.am-comp-resolve-cb[data-item-id="'+id+'"]:not(:checked)').length;if(unresvd>0&&!confirm('⚠️ Ainda há '+unresvd+' componente(s) com problema não resolvido(s).\n\nDeseja marcar como concluído mesmo assim?'))return;amSalvarParcial(id,tid,function(){fetch(_diarioBase,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'done',item_id:id,transfer_id:tid,log:document.getElementById('log-'+id).value,components:amGetComps(id),_glpi_csrf_token:_csrf})}).then(function(r){return r.json();}).then(function(d){if(d.ok)window.location.reload();}).catch(function(e){console.error('Erro done:',e);});});}
function amReabrirItem(id,tid){fetch(_diarioBase,{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'},body:JSON.stringify({action:'reopen',item_id:id,transfer_id:tid,_glpi_csrf_token:_csrf})}).then(function(r){return r.json();}).then(function(d){if(d.ok)window.location.reload();}).catch(function(e){console.error('Erro reopen:',e);});}
document.addEventListener('DOMContentLoaded',function(){var f=document.querySelector('.am-diario-card:not(.am-diario-done)');if(f){var id=f.id.replace('diario-card-','');document.getElementById('body-'+id).style.display='block';document.getElementById('chevron-'+id).style.transform='rotate(180deg)';}});
</script>
<?php Html::footer(); ?>
