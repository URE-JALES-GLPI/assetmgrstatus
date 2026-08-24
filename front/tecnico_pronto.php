<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
Session::checkRight('plugin_assetmgrstatus_tecnico', READ);

global $CFG_GLPI;

$transfer_id = (int)($_GET['id'] ?? 0);
if (!$transfer_id) {
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php'); exit;
}

$transfer = Transfer::getById($transfer_id);
if (!$transfer || $transfer['status'] !== Transfer::STATUS_MANUTENCAO) {
    Session::addMessageAfterRedirect('Transferência inválida ou não está em manutenção.', false, ERROR);
    Html::redirect($CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/tecnico.php'); exit;
}

$items     = Transfer::getItems($transfer_id);
$comp_list = MaintenanceRecord::getComponents();

$ent = new Entity();
$entity_name = ($transfer['entity_dest'] && $ent->getFromDB($transfer['entity_dest'])) ? $ent->getName() : '—';

Html::header('Marcar como Pronto', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'tecnico');
?>

<div class="container-fluid am-page">

    <div class="am-page-header">
        <div class="am-page-title">
            <i class="ti ti-check"></i>
            <h2>Marcar como Pronto — Transferência #<?= str_pad($transfer_id, 4, '0', STR_PAD_LEFT) ?></h2>
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <button id="am-theme-btn" onclick="amToggleTheme()"
                class="am-btn am-btn-secondary" style="padding:8px 12px;font-size:.82rem;" title="Alternar tema claro/escuro">
                <i class="ti ti-moon"></i>
            </button>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php"
               class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;">
                <i class="ti ti-arrow-left"></i> Voltar
            </a>
        </div>
    </div>

    <!-- Info da transferência -->
    <div style="background:#fff;border:1.5px solid #e8eaf0;border-radius:14px;padding:16px 20px;margin-bottom:20px;display:flex;gap:24px;flex-wrap:wrap;">
        <div><span style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;font-weight:700;">Destino</span><br><strong><?= htmlspecialchars($entity_name) ?></strong></div>
        <div><span style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;font-weight:700;">Ativos</span><br><strong><?= count($items) ?></strong></div>
        <div><span style="font-size:.75rem;color:#9ca3af;text-transform:uppercase;font-weight:700;">Motivo</span><br><span style="color:#6b7280;"><?= htmlspecialchars(mb_substr($transfer['reason'], 0, 80)) ?></span></div>
    </div>

    <form method="POST" action="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico_pronto.form.php" id="am-pronto-form">
        <input type="hidden" name="transfer_id" value="<?= $transfer_id ?>">
        <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>

        <!-- Classificação de cada ativo -->
        <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:24px;">
            <?php foreach ($items as $item): ?>
            <div class="am-pronto-card" id="pronto-card-<?= (int)$item['items_id'] ?>">
                <div class="am-pronto-card-header">
                    <div>
                        <div style="font-weight:700;font-size:.95rem;"><?= htmlspecialchars($item['item_name']) ?></div>
                        <div style="font-size:.76rem;color:#9ca3af;margin-top:2px;">
                            <?= htmlspecialchars(str_replace(['Glpi\\CustomAsset\\','Asset'], '', $item['itemtype'])) ?>
                        </div>
                    </div>
                    <div class="am-pronto-status-select">
                        <label class="am-pronto-radio">
                            <input type="radio" name="items[<?= (int)$item['items_id'] ?>][status]"
                                   value="<?= MaintenanceRecord::STATUS_ATIVO ?>" required
                                   onchange="amToggleProntoExtra(<?= (int)$item['items_id'] ?>, this.value)">
                            <span class="am-badge am-badge-ativo">Ativo</span>
                        </label>
                        <label class="am-pronto-radio">
                            <input type="radio" name="items[<?= (int)$item['items_id'] ?>][status]"
                                   value="<?= MaintenanceRecord::STATUS_GARANTIA ?>" required
                                   onchange="amToggleProntoExtra(<?= (int)$item['items_id'] ?>, this.value)">
                            <span class="am-badge am-badge-garantia">Garantia</span>
                        </label>
                        <label class="am-pronto-radio">
                            <input type="radio" name="items[<?= (int)$item['items_id'] ?>][status]"
                                   value="<?= MaintenanceRecord::STATUS_INSERVIVEL ?>" required
                                   onchange="amToggleProntoExtra(<?= (int)$item['items_id'] ?>, this.value)">
                            <span class="am-badge am-badge-inservivel">Inservível</span>
                        </label>
                    </div>
                </div>

                <div class="am-pronto-extra" id="pronto-extra-<?= (int)$item['items_id'] ?>" style="display:none;">
                    <div class="am-form-section" style="margin-top:14px;">
                        <label class="am-form-label">Motivo <span class="am-required">*</span></label>
                        <textarea name="items[<?= (int)$item['items_id'] ?>][reason]" class="am-textarea"
                                  style="min-height:65px;" placeholder="Descreva o motivo..."></textarea>
                    </div>
                    <div class="am-form-section">
                        <label class="am-form-label">Componentes Afetados</label>
                        <div class="am-components-list" style="max-height:180px;overflow-y:auto;">
                            <?php foreach ($comp_list as $ckey => $clabel): ?>
                            <div class="am-component-item">
                                <label class="am-comp-checkbox-label">
                                    <input type="checkbox"
                                           name="items[<?= (int)$item['items_id'] ?>][comp_check][]"
                                           value="<?= $ckey ?>"
                                           onchange="amToggleProntoComp(this,'<?= (int)$item['items_id'] ?>_<?= $ckey ?>')">
                                    <span><?= htmlspecialchars($clabel) ?></span>
                                </label>
                                <div class="am-comp-field" id="pronto-cf-<?= (int)$item['items_id'] ?>_<?= $ckey ?>" style="display:none">
                                    <input type="text"
                                           name="items[<?= (int)$item['items_id'] ?>][comp_desc][<?= $ckey ?>]"
                                           class="am-input"
                                           placeholder="<?= htmlspecialchars($clabel) ?>: descreva...">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Revisão e confirmação -->
        <div style="background:#fff;border:1.5px solid #e8eaf0;border-radius:14px;padding:20px;margin-bottom:20px;">
            <h3 style="font-size:.95rem;font-weight:700;color:#1f2937;margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                <i class="ti ti-clipboard-check" style="color:#4f46e5;"></i> Resumo da Classificação
            </h3>
            <div id="am-pronto-review" style="font-size:.85rem;color:#9ca3af;">Selecione o status de cada ativo acima.</div>

            <div style="margin-top:18px;">
                <label class="am-agree-check">
                    <input type="checkbox" id="am-pronto-agree" onchange="amToggleProntoSubmit()">
                    <span>Confirmo que a manutenção foi concluída e que as informações informadas nos ativos estão corretas.</span>
                </label>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:12px;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php"
               class="am-btn am-btn-secondary" style="padding:10px 20px;">
                <i class="ti ti-x"></i> Cancelar
            </a>
            <button type="submit" id="am-pronto-submit" class="am-btn"
                    style="padding:10px 24px;background:linear-gradient(135deg,#10b981,#059669);color:#fff;opacity:.4;cursor:not-allowed;" disabled>
                <i class="ti ti-check"></i> Confirmar — Marcar como Pronto
            </button>
        </div>
    </form>
</div>

<style>
.am-pronto-card{background:#fff;border:1.5px solid #e8eaf0;border-radius:14px;padding:18px 20px;}
.am-pronto-card-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.am-pronto-status-select{display:flex;gap:10px;flex-wrap:wrap;}
.am-pronto-radio{display:flex;align-items:center;gap:6px;cursor:pointer;}
.am-pronto-radio input{accent-color:#4f46e5;width:15px;height:15px;}
.am-pronto-extra{border-top:1.5px solid #f0f2f8;margin-top:14px;padding-top:4px;}
</style>

<script>
var _prontoItems = {};
var _statusLabels = {
    '<?= MaintenanceRecord::STATUS_ATIVO ?>':      'Ativo',
    '<?= MaintenanceRecord::STATUS_GARANTIA ?>':   'Garantia',
    '<?= MaintenanceRecord::STATUS_INSERVIVEL ?>': 'Inservível',
};
var _statusColors = {
    '<?= MaintenanceRecord::STATUS_ATIVO ?>':      '#10b981',
    '<?= MaintenanceRecord::STATUS_GARANTIA ?>':   '#3b82f6',
    '<?= MaintenanceRecord::STATUS_INSERVIVEL ?>': '#6b7280',
};

function amToggleProntoExtra(items_id, status) {
    var extra = document.getElementById('pronto-extra-' + items_id);
    var needs = (status === '<?= MaintenanceRecord::STATUS_GARANTIA ?>' || status === '<?= MaintenanceRecord::STATUS_INSERVIVEL ?>');
    extra.style.display = needs ? 'block' : 'none';
    _prontoItems[items_id] = status;
    amUpdateProntoReview();
    amToggleProntoSubmit();
}

function amToggleProntoComp(checkbox, fieldId) {
    var field = document.getElementById('pronto-cf-' + fieldId);
    if (field) field.style.display = checkbox.checked ? 'block' : 'none';
}

function amUpdateProntoReview() {
    var html = '<div style="display:flex;flex-direction:column;gap:6px;">';
    for (var id in _prontoItems) {
        var card = document.getElementById('pronto-card-' + id);
        var name = card ? card.querySelector('[style*="font-weight:700"]').textContent.trim() : 'Ativo ' + id;
        var st   = _prontoItems[id];
        html += '<div style="display:flex;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid #f0f2f8;">' +
                '<span style="color:#374151;">' + name + '</span>' +
                '<span style="font-weight:700;color:' + (_statusColors[st]||'#374151') + ';">' + (_statusLabels[st]||st) + '</span>' +
                '</div>';
    }
    html += '</div>';
    if (Object.keys(_prontoItems).length === 0) {
        html = '<span style="color:#9ca3af;">Selecione o status de cada ativo acima.</span>';
    }
    document.getElementById('am-pronto-review').innerHTML = html;
}

function amToggleProntoSubmit() {
    var agreed  = document.getElementById('am-pronto-agree').checked;
    var total   = document.querySelectorAll('[name$="][status]"]').length / 3;
    var checked = Object.keys(_prontoItems).length;
    var ok = agreed && checked >= total;
    var btn = document.getElementById('am-pronto-submit');
    btn.disabled = !ok;
    btn.style.opacity = ok ? '1' : '.4';
    btn.style.cursor  = ok ? 'pointer' : 'not-allowed';
}
</script>

<?php Html::footer(); ?>
