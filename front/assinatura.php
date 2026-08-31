<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_assinatura', READ) && !Session::haveRight('plugin_assetmgrstatus_tecnico', READ) && !Session::haveRight('plugin_assetmgrstatus_admin', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    Html::displayRightError(); exit;
}

global $CFG_GLPI, $DB;

$filter = $_GET['f'] ?? 'pendente'; // pendente | assinado | todos
if (!in_array($filter, ['pendente','assinado','todos'], true)) $filter = 'pendente';

$all = Transfer::getAll();
$pendentes = array_values(array_filter($all, fn($t) => Transfer::precisaAssinatura($t)));
$assinados = array_values(array_filter($all, fn($t) => Transfer::isAssinado($t)));

if ($filter === 'pendente') $transfers = $pendentes;
elseif ($filter === 'assinado') $transfers = $assinados;
else $transfers = $all;

// Ordena por Mais recente (pedido: Assinatura filtrado por Mais recente)
if ($filter === 'pendente') {
    usort($transfers, fn($a,$b) => strtotime($b['date_creation']) <=> strtotime($a['date_creation']));
} else {
    usort($transfers, fn($a,$b) => strtotime($b['assinatura_data'] ?? $b['date_creation']) <=> strtotime($a['assinatura_data'] ?? $a['date_creation']));
}

Html::header('Assinatura', $_SERVER['PHP_SELF'], 'tools', 'assetmgrstatus', 'assinatura');
?>

<style>
.am-sig-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:18px;}
@media(max-width:768px){.am-sig-grid{grid-template-columns:1fr;}}
.am-sig-card{cursor:pointer;}
.am-sig-card:hover{border-color:#4f46e5;transform:translateY(-2px);}
.am-sig-badge-pend{background:#fef3c7;color:#92400e;border:1px solid #fde68a;}
.am-sig-badge-ok{background:#d1fae5;color:#065f46;border:1px solid #a7f3d0;}
/* Modal assinatura */
#am-modal-assinatura .am-modal{max-width:560px;}
.am-doc-choice{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:16px 0;}
.am-doc-btn{padding:18px;border:2px solid #e8eaf0;border-radius:14px;background:#f8f9fb;font-weight:800;font-size:1.05rem;cursor:pointer;display:flex;flex-direction:column;align-items:center;gap:6px;transition:all .15s;}
.am-doc-btn:hover{border-color:#4f46e5;background:#eef2ff;}
.am-doc-btn.active{border-color:#4f46e5;background:#4f46e5;color:#fff;box-shadow:0 6px 20px rgba(79,70,229,.25);}
.am-doc-btn small{font-weight:600;font-size:.75rem;opacity:.7;}
.am-numpad{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:14px 0;}
.am-numpad button{height:58px;border:none;border-radius:12px;background:#f3f4f6;font-size:1.4rem;font-weight:800;cursor:pointer;transition:all .12s;}
.am-numpad button:active{transform:scale(.96);background:#e5e7eb;}
.am-numpad button.am-numpad-action{background:#4f46e5;color:#fff;}
.am-numpad button.am-numpad-del{background:#fef2f2;color:#dc2626;}
.am-sig-display{background:#fff;border:2px solid #e8eaf0;border-radius:12px;padding:14px;text-align:center;font-size:1.6rem;font-weight:800;letter-spacing:.12em;min-height:58px;display:flex;align-items:center;justify-content:center;color:#1e1b4b;}
.am-sig-display.empty{color:#9ca3af;font-size:.95rem;letter-spacing:normal;font-weight:600;}
.am-sig-canvas-wrap{background:#fff;border:2px solid #e8eaf0;border-radius:12px;overflow:hidden;position:relative;touch-action:none;}
.am-sig-canvas{width:100%;height:220px;display:block;touch-action:none;cursor:crosshair;}
@media(max-width:768px){.am-sig-canvas{height:260px;}}
.am-sig-hint{font-size:.72rem;color:#9ca3af;text-align:center;margin-top:6px;}
</style>

<div class="container-fluid am-page">

    <div class="am-breadcrumb">
        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php">Inventário</a>
        <i class="ti ti-chevron-right"></i>
        <span>Assinatura</span>
    </div>

    <div class="am-page-header">
        <div class="am-page-title"><i class="ti ti-signature"></i><h2>Assinatura de Termos</h2></div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/maintenance.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-clipboard-list"></i> Inventário</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/tecnico.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-tools"></i> Técnico</a>
            <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/dashboard.php" class="am-btn am-btn-secondary" style="padding:8px 14px;font-size:.82rem;"><i class="ti ti-dashboard"></i> Dashboard</a>
        </div>
    </div>

    <div style="background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:12px;padding:12px 16px;margin-bottom:20px;display:flex;align-items:center;gap:12px;">
        <i class="ti ti-info-circle" style="font-size:1.5rem;color:#1a73b5;"></i>
        <div style="font-size:.85rem;color:#1e3a5f;line-height:1.4;">
            <strong>Tablet / Celular:</strong> toque no card <strong style="color:#92400e;">Pendente</strong> que falta assinar → escolha <strong>RG ou CPF</strong> → digite no <strong>teclado numérico</strong> → assine com <strong>dedo/caneta touch</strong>. A data/hora é preenchida automaticamente no termo.
        </div>
    </div>

    <div class="am-filters-bar" style="margin-bottom:20px;">
        <div class="am-filter-group">
            <label>FILTRO</label>
            <div class="am-type-tabs">
                <a href="?f=pendente" class="am-type-tab <?= $filter==='pendente'?'active':'' ?>">⏳ Pendentes <span class="am-type-count"><?= count($pendentes) ?></span></a>
                <a href="?f=assinado" class="am-type-tab <?= $filter==='assinado'?'active':'' ?>">✅ Assinados <span class="am-type-count"><?= count($assinados) ?></span></a>
                <a href="?f=todos" class="am-type-tab <?= $filter==='todos'?'active':'' ?>">Todos <span class="am-type-count"><?= count($all) ?></span></a>
            </div>
        </div>
    </div>

    <?php if (empty($transfers)): ?>
        <div class="am-empty-state"><i class="ti ti-signature-off"></i><p><?= $filter==='pendente' ? 'Nenhum termo pendente de assinatura. 🎉' : ($filter==='assinado' ? 'Nenhum termo assinado ainda.' : 'Nenhuma transferência encontrada.') ?></p></div>
    <?php else: ?>
        <div class="am-sig-grid">
            <?php foreach ($transfers as $t):
                $isAssinado = Transfer::isAssinado($t);
                $precisa   = Transfer::precisaAssinatura($t);
                $status_label = Transfer::getStatusOptions()[$t['status']] ?? $t['status'];
                $badgeClass = $isAssinado ? 'am-sig-badge-ok' : ($precisa ? 'am-sig-badge-pend' : Transfer::getStatusBadgeClass($t['status']));
                $badgeText  = $isAssinado ? '✍️ ASSINADO' : ($precisa ? '⏳ AGUARDANDO ASSINATURA' : $status_label);
                $docMasked = '';
                if ($isAssinado) {
                    $docMasked = Transfer::maskDocumento($t['assinatura_document_type'] ?? '', $t['assinatura_document'] ?? '');
                }
            ?>
            <div class="am-tc-card am-sig-card" onclick="<?= $precisa ? "amOpenAssinaturaModal(".(int)$t['id'].")" : "void(0)" ?>" style="<?= $precisa ? 'border-color:#f59e0b;' : '' ?>">
                <div class="am-tc-card-header" style="border-left:4px solid <?= $isAssinado ? '#10b981' : ($precisa ? '#f59e0b' : Transfer::getStatusColor($t['status'])) ?>;">
                    <div>
                        <div style="font-size:.72rem;color:#9ca3af;font-weight:600;text-transform:uppercase;">Transferência #<?= str_pad($t['id'],4,'0',STR_PAD_LEFT) ?></div>
                        <div style="font-weight:800;font-size:1rem;color:#1e2333;"><?= htmlspecialchars($t['origin_entity_name']) ?> → <?= htmlspecialchars($t['entity_dest_name']) ?></div>
                    </div>
                    <span class="am-badge <?= $badgeClass ?>"><?= $badgeText ?></span>
                </div>
                <div class="am-tc-card-body">
                    <div class="am-tc-info-row"><i class="ti ti-box"></i><span><?= (int)$t['items_count'] ?> ativo(s)</span></div>
                    <div class="am-tc-info-row"><i class="ti ti-calendar"></i><span>Criada em <?= date('d/m/Y H:i', strtotime($t['date_creation'])) ?></span></div>
                    <?php if (!empty($t['date_pronto'])): ?><div class="am-tc-info-row"><i class="ti ti-check"></i><span>Pronto em <?= date('d/m/Y H:i', strtotime($t['date_pronto'])) ?></span></div><?php endif; ?>
                    <?php if (!empty($t['date_finalizado'])): ?><div class="am-tc-info-row"><i class="ti ti-flag-check"></i><span>Finalizada em <?= date('d/m/Y H:i', strtotime($t['date_finalizado'])) ?></span></div><?php endif; ?>
                    <?php if ($isAssinado): ?>
                        <div style="margin-top:8px;background:#f0fdf4;border:1px solid #a7f3d0;border-radius:8px;padding:8px 10px;">
                            <div style="font-size:.75rem;font-weight:700;color:#065f46;">✍️ Assinado em <?= date('d/m/Y H:i', strtotime($t['assinatura_data'])) ?></div>
                            <div style="font-size:.78rem;color:#374151;"><?= htmlspecialchars($t['assinatura_nome'] ?: '—') ?> — <?= htmlspecialchars($t['assinatura_document_type'] ?? '') ?> <?= htmlspecialchars($docMasked) ?></div>
                            <div style="font-size:.70rem;color:#9ca3af;">por <?= htmlspecialchars(Transfer::getUserName((int)($t['assinatura_user_id'] ?? 0))) ?> • IP <?= htmlspecialchars($t['assinatura_ip'] ?? '') ?></div>
                        </div>
                    <?php elseif ($precisa): ?>
                        <div style="margin-top:8px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;font-size:.78rem;color:#92400e;text-align:center;font-weight:600;">
                            👆 Toque aqui para assinar no tablet
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($t['reason'])): ?><div class="am-tc-reason"><?= htmlspecialchars(mb_substr($t['reason'],0,90)) ?></div><?php endif; ?>
                </div>
                <div class="am-tc-card-footer">
                    <?php if ($precisa): ?>
                        <button class="am-btn" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;flex:1;" onclick="event.stopPropagation();amOpenAssinaturaModal(<?= (int)$t['id'] ?>)"><i class="ti ti-signature"></i> Assinar</button>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= (int)$t['id'] ?>&stage=pronto" target="_blank" class="am-btn am-btn-secondary" style="padding:8px 10px;width:auto;" title="Ver termo (ainda sem assinatura)"><i class="ti ti-file-type-pdf"></i></a>
                    <?php elseif ($isAssinado): ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= (int)$t['id'] ?>&stage=pronto" target="_blank" class="am-btn am-btn-secondary" style="flex:1;"><i class="ti ti-file-type-pdf"></i> PDF Assinado</a>
                        <button id="am-print-hp-<?= (int)$t['id'] ?>" class="am-btn" style="background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;flex:1;" onclick="event.stopPropagation();amPrintHP(<?= (int)$t['id'] ?>)"><i class="ti ti-printer"></i> Imprimir na HP</button>
                    <?php else: ?>
                        <a href="<?= $CFG_GLPI['root_doc'] ?>/plugins/assetmgrstatus/front/transfer_pdf.php?id=<?= (int)$t['id'] ?>&stage=<?= $t['status']==='finalizado'?'pronto':'transfer' ?>" target="_blank" class="am-btn am-btn-secondary" style="flex:1;"><i class="ti ti-file-type-pdf"></i> Ver Termo</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Modal Assinatura Wizard 7 telas -->
<div id="am-modal-assinatura" class="am-modal-overlay" style="z-index:10001;" onclick="if(event.target===this) amSigConfirmCancel()">
    <div class="am-modal" onclick="event.stopPropagation()" style="max-width:520px;max-height:92vh;display:flex;flex-direction:column;">
        <div class="am-modal-header" style="background:linear-gradient(135deg,#1a73b5,#4f46e5);">
            <div class="am-modal-title"><i class="ti ti-signature"></i><span id="am-sig-modal-title">Assinatura — Etapa 1 de 7</span></div>
            <button class="am-modal-close" onclick="amSigConfirmCancel()"><i class="ti ti-x"></i></button>
        </div>
        <div style="height:4px;background:#e8eaf0;"><div id="am-sig-progress" style="height:100%;width:14%;background:linear-gradient(90deg,#10b981,#059669);transition:width .25s;"></div></div>

        <!-- WIZ 1: Seleciona Tecnico -->
        <div id="am-wiz-1" class="am-modal-body" style="display:block;text-align:center;">
            <div style="width:64px;height:64px;background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;"><i class="ti ti-user-check" style="font-size:1.8rem;color:#fff;"></i></div>
            <div style="font-weight:800;font-size:1.1rem;color:#1e1b4b;">1. Selecione o Técnico</div>
            <div style="font-size:.85rem;color:#6b7280;margin:6px 0 14px;">Quem está entregando o equipamento?</div>
            <select id="am-sig-tec-select" class="am-input" style="background:#fff;font-size:1rem;padding:12px;">
                <option value="">Carregando técnicos...</option>
            </select>
            <div id="am-sig-tec-empty" style="display:none;margin-top:10px;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px;font-size:.85rem;color:#92400e;">Nenhum técnico cadastrado. Cadastre em Dashboard > Técnicos.</div>
            <button type="button" class="am-btn" style="width:100%;margin-top:16px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;" onclick="amWizNext(1)"><i class="ti ti-arrow-right"></i> Próximo</button>
        </div>

        <!-- WIZ 2: Aviso Recebedor -->
        <div id="am-wiz-2" class="am-modal-body" style="display:none;text-align:center;">
            <div style="width:64px;height:64px;background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px;"><i class="ti ti-user" style="font-size:1.8rem;color:#fff;"></i></div>
            <div style="font-weight:800;font-size:1.1rem;color:#1e1b4b;">2. Agora é a vez do Recebedor</div>
            <div style="font-size:.9rem;color:#6b7280;margin:10px 0 16px;line-height:1.5;">O próximo a assinar é o <strong style="color:#1e1b4b;">responsável da escola</strong> que está recebendo.<br>Tenha o documento dele em mãos.</div>
            <div style="background:#f0f7ff;border:1.5px solid #bfdbfe;border-radius:10px;padding:12px;font-size:.85rem;color:#1e3a5f;"><i class="ti ti-info-circle"></i> O técnico <strong id="am-wiz-tec-name">—</strong> já foi selecionado. Agora colete os dados do recebedor.</div>
            <div style="display:flex;gap:10px;margin-top:16px;"><button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amWizPrev(2)"><i class="ti ti-arrow-left"></i> Voltar</button><button type="button" class="am-btn" style="flex:1;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;" onclick="amWizNext(2)">Continuar <i class="ti ti-arrow-right"></i></button></div>
        </div>

        <!-- WIZ 3: Nome Recebedor -->
        <div id="am-wiz-3" class="am-modal-body" style="display:none;">
            <div style="text-align:center;margin-bottom:14px;"><div style="font-weight:800;font-size:1.05rem;color:#1e1b4b;">3. Nome do Recebedor</div><div style="font-size:.85rem;color:#6b7280;">Quem está recebendo? (opcional, mas recomendado)</div></div>
            <label class="am-form-label">Nome completo</label>
            <input type="text" id="am-sig-nome" class="am-input" placeholder="Ex: João da Silva" style="font-size:1.05rem;padding:14px;">
            <div style="display:flex;gap:10px;margin-top:16px;"><button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amWizPrev(3)"><i class="ti ti-arrow-left"></i> Voltar</button><button type="button" class="am-btn" style="flex:1;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;" onclick="amWizNext(3)">Próximo <i class="ti ti-arrow-right"></i></button></div>
            <button type="button" class="am-btn am-btn-secondary" style="width:100%;margin-top:8px;background:transparent;border:none;color:#6b7280;" onclick="document.getElementById('am-sig-nome').value=''; amWizNext(3)">Pular (deixar em branco)</button>
        </div>

        <!-- WIZ 4: RG ou CPF -->
        <div id="am-wiz-4" class="am-modal-body" style="display:none;">
            <div style="text-align:center;margin-bottom:14px;"><div style="font-weight:800;font-size:1.05rem;color:#1e1b4b;">4. Tipo de documento</div><div style="font-size:.85rem;color:#6b7280;">RG ou CPF do recebedor?</div></div>
            <div class="am-doc-choice">
                <button type="button" class="am-doc-btn" data-type="RG" onclick="amWizChooseDoc('RG')"><i class="ti ti-id" style="font-size:1.6rem;"></i> RG<small>5 a 12 dígitos</small></button>
                <button type="button" class="am-doc-btn" data-type="CPF" onclick="amWizChooseDoc('CPF')"><i class="ti ti-id-badge-2" style="font-size:1.6rem;"></i> CPF<small>11 dígitos</small></button>
            </div>
            <button type="button" class="am-btn am-btn-secondary" style="width:100%;margin-top:14px;" onclick="amWizPrev(4)"><i class="ti ti-arrow-left"></i> Voltar</button>
        </div>

        <!-- WIZ 5: Digita documento -->
        <div id="am-wiz-5" class="am-modal-body" style="display:none;">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;"><button type="button" class="am-btn am-btn-secondary" style="padding:6px 10px;font-size:.78rem;" onclick="amWizPrev(5)"><i class="ti ti-arrow-left"></i> Voltar</button><span id="am-sig-doc-badge" style="background:#4f46e5;color:#fff;padding:4px 10px;border-radius:8px;font-weight:700;font-size:.78rem;">CPF</span></div>
            <label class="am-form-label">Número do documento <span class="am-required">*</span> <small style="font-weight:400;text-transform:none;letter-spacing:0;">(<span id="am-sig-doc-hint">11 dígitos</span>)</small></label>
            <div id="am-sig-display" class="am-sig-display empty">Toque no teclado abaixo</div>
            <input type="hidden" id="am-sig-doc-value">
            <div class="am-numpad">
                <button type="button" onclick="amSigPress('1')">1</button><button type="button" onclick="amSigPress('2')">2</button><button type="button" onclick="amSigPress('3')">3</button>
                <button type="button" onclick="amSigPress('4')">4</button><button type="button" onclick="amSigPress('5')">5</button><button type="button" onclick="amSigPress('6')">6</button>
                <button type="button" onclick="amSigPress('7')">7</button><button type="button" onclick="amSigPress('8')">8</button><button type="button" onclick="amSigPress('9')">9</button>
                <button type="button" class="am-numpad-del" onclick="amSigPress('del')"><i class="ti ti-backspace"></i></button><button type="button" onclick="amSigPress('0')">0</button><button type="button" class="am-numpad-action" onclick="amWizNext(5)"><i class="ti ti-check"></i> Ok</button>
            </div>
            <div style="display:flex;gap:8px;margin-top:10px;"><button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigClear()"><i class="ti ti-trash"></i> Limpar</button><button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigBackspace()"><i class="ti ti-backspace"></i> Apagar</button></div>
        </div>

        <!-- WIZ 6: Assina -->
        <div id="am-wiz-6" class="am-modal-body" style="display:none;flex:1;overflow-y:auto;">
            <div style="text-align:center;margin-bottom:10px;"><div style="font-weight:800;font-size:1.05rem;color:#1e1b4b;">6. Assinatura do recebedor</div><div style="font-size:.85rem;color:#6b7280;">Use o dedo ou caneta touch no quadro abaixo</div></div>
            <div id="am-sig-already-receiver" style="display:none;margin-bottom:10px;background:#f0fdf4;border:1.5px solid #a7f3d0;border-radius:10px;padding:10px;text-align:center;color:#065f46;font-weight:700;"><i class="ti ti-check"></i> Recebedor já assinado</div>
            <div class="am-sig-canvas-wrap">
                <canvas id="am-sig-canvas" class="am-sig-canvas"></canvas>
            </div>
            <div class="am-sig-hint">Desenhe acima com o dedo/caneta. Use Limpar para refazer.</div>
            <div style="display:flex;gap:8px;margin-top:10px;">
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigClearCanvas()"><i class="ti ti-eraser"></i> Limpar assinatura</button>
                <button type="button" class="am-btn am-btn-secondary" style="flex:1;background:#fef2f2;color:#dc2626;border-color:#fecaca;" onclick="amSigConfirmCancel()"><i class="ti ti-x"></i> Cancelar</button>
            </div>
            <div style="display:flex;gap:10px;margin-top:14px;"><button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amWizPrev(6)"><i class="ti ti-arrow-left"></i> Voltar</button><button type="button" class="am-btn" style="flex:1;background:linear-gradient(135deg,#10b981,#059669);color:#fff;" onclick="amWizNext(6)"><i class="ti ti-check"></i> Assinar</button></div>
        </div>

        <!-- WIZ 7: Deu certo -->
        <div id="am-wiz-7" class="am-modal-body" style="display:none;text-align:center;">
            <div style="width:80px;height:80px;background:linear-gradient(135deg,#10b981,#059669);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin:16px auto;"><i class="ti ti-check" style="font-size:2.4rem;color:#fff;"></i></div>
            <div style="font-weight:800;font-size:1.3rem;color:#065f46;">Deu certo!</div>
            <div style="font-size:.9rem;color:#6b7280;margin:8px 0 16px;">Assinatura salva com sucesso.<br>O termo foi atualizado e já pode ser impresso.</div>
            <div id="am-wiz-7-info" style="background:#f0fdf4;border:1px solid #a7f3d0;border-radius:10px;padding:12px;font-size:.85rem;color:#065f46;margin-bottom:16px;"></div>
            <button type="button" class="am-btn" style="width:100%;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff;" onclick="location.reload()"><i class="ti ti-refresh"></i> Fechar e atualizar</button>
        </div>

        <div id="am-sig-footer" class="am-modal-footer" style="display:none;">
            <button type="button" id="am-sig-save-btn" class="am-btn" style="flex:1;background:linear-gradient(135deg,#10b981,#059669);color:#fff;" onclick="amSigSave()"><i class="ti ti-device-floppy"></i> Confirmar e Assinar</button>
        </div>
    </div>
</div>

<!-- Confirmacao 2 etapas para Cancelar -->
<div id="am-sig-cancel-confirm" class="am-modal-overlay" style="z-index:10002;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.85);" onclick="if(event.target===this) amSigCancelClose()">
    <div class="am-modal" style="max-width:420px;width:90%;text-align:center;" onclick="event.stopPropagation()">
        <div style="width:56px;height:56px;background:linear-gradient(135deg,#fef2f2,#fee2e2);border:2px solid #fecaca;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin:20px auto 12px;"><i class="ti ti-alert-triangle" style="font-size:1.8rem;color:#dc2626;"></i></div>
        <div style="font-weight:800;font-size:1.1rem;color:#991b1b;">Tem certeza que deseja cancelar?</div>
        <div style="font-size:.9rem;color:#6b7280;margin:8px 0 4px;">Todo o progresso da assinatura será perdido.</div>
        <div style="background:#fffbeb;border:1.5px solid #fde68a;border-radius:8px;padding:10px;font-size:.85rem;color:#92400e;margin:12px 0;">Etapa 1 de 2 — Confirme para continuar</div>
        <div style="display:flex;gap:10px;padding:16px;">
            <button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigCancelClose()"><i class="ti ti-arrow-left"></i> Voltar</button>
            <button type="button" class="am-btn" style="flex:1;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;" onclick="amSigCancelStep2()"><i class="ti ti-alert-circle"></i> Sim, cancelar</button>
        </div>
    </div>
</div>
<div id="am-sig-cancel-confirm2" class="am-modal-overlay" style="z-index:10003;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.85);" onclick="if(event.target===this) amSigCancelClose2()">
    <div class="am-modal" style="max-width:420px;width:90%;text-align:center;" onclick="event.stopPropagation()">
        <div style="width:56px;height:56px;background:linear-gradient(135deg,#dc2626,#ef4444);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin:20px auto 12px;"><i class="ti ti-x" style="font-size:1.8rem;color:#fff;"></i></div>
        <div style="font-weight:800;font-size:1.1rem;color:#dc2626;">Última confirmação</div>
        <div style="font-size:.9rem;color:#6b7280;margin:8px 0 4px;">Tem <strong>certeza mesmo</strong>? Essa ação não pode ser desfeita.</div>
        <div style="background:#fef2f2;border:1.5px solid #fecaca;border-radius:8px;padding:10px;font-size:.85rem;color:#991b1b;margin:12px 0;">Etapa 2 de 2 — Confirmação final</div>
        <div style="display:flex;gap:10px;padding:16px;">
            <button type="button" class="am-btn am-btn-secondary" style="flex:1;" onclick="amSigCancelClose2()"><i class="ti ti-arrow-left"></i> Voltar</button>
            <button type="button" class="am-btn" style="flex:1;background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;" onclick="amSigCancelFinal()"><i class="ti ti-trash"></i> Sim, cancelar tudo</button>
        </div>
    </div>
</div>
<style>#am-sig-toast{position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);z-index:10006;background:#fff;border:2px solid #fecaca;color:#991b1b;padding:16px 20px;border-radius:12px;box-shadow:0 12px 40px rgba(0,0,0,.25);font-weight:700;max-width:90vw;text-align:center;display:none;}#am-sig-toast.ok{border-color:#a7f3d0;color:#065f46;background:#f0fdf4;}</style>
<div id="am-sig-toast"></div>
<script>
function amSigToast(msg, ok){
  var t=document.getElementById('am-sig-toast'); if(!t) return alert(msg);
  t.textContent=msg; t.className=ok?'ok':''; t.style.display='block'; t.style.opacity='1';
  clearTimeout(window._amSigToastT);
  window._amSigToastT=setTimeout(function(){ t.style.transition='opacity .3s'; t.style.opacity='0'; setTimeout(function(){ t.style.display='none'; },300); }, ok?2500:3500);
}
const amCsrfToken = "<?= Session::getNewCSRFToken() ?>";
function amGetCsrfAssinatura(){
  try{
    var el=document.querySelector('#am-modal-assinatura input[name="_glpi_csrf_token"]')||document.querySelector('input[name="_glpi_csrf_token"]');
    if(el && el.value) return el.value;
    var m=document.querySelector('meta[name="glpi_csrf_token"]'); if(m && m.content) return m.content;
    if(window.CFG_GLPI && window.CFG_GLPI.csrf_token) return window.CFG_GLPI.csrf_token;
  }catch(e){}
  return amCsrfToken;
}
let amSigTransferId = 0;
let amSigHasReceiver = false;
let amSigHasTecnico = false;
let amSigTecnicosCache = null;
async function amLoadTecnicos(){
  var sel=document.getElementById('am-sig-tec-select'); if(!sel) return;
  if(amSigTecnicosCache && amSigTecnicosCache.length){ populateTecSelect(amSigTecnicosCache); return; }
  try{
    var base=(window.location.pathname.split('/plugins/assetmgrstatus')[0]||'')+'/plugins/assetmgrstatus';
    var r=await fetch(base+'/ajax/tecnico_signature.php?action=list', {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
    var t=await r.text(); var j; try{j=JSON.parse(t);}catch(e){ j={ok:false};}
    if(j.ok && Array.isArray(j.data) && j.data.length){
      amSigTecnicosCache=j.data;
      populateTecSelect(j.data);
    } else {
      sel.innerHTML='<option value="">Nenhum técnico cadastrado</option>';
      document.getElementById('am-sig-tec-empty').style.display='block';
      sel.style.display='none';
    }
  }catch(e){
    sel.innerHTML='<option value="">Erro ao carregar</option>';
  }
  function populateTecSelect(list){
    sel.innerHTML='<option value="">Selecione o técnico...</option>';
    list.forEach(function(te){
      var o=document.createElement('option');
      o.value=te.id;
      o.textContent=te.name + ' (' + te.document_type + ' ' + te.doc_masked + ')';
      o.dataset.docType=te.document_type; o.dataset.doc=te.document; o.dataset.name=te.name; o.dataset.image=te.image;
      sel.appendChild(o);
    });
    sel.style.display='block';
    document.getElementById('am-sig-tec-empty').style.display='none';
  }
}
let amSigDocType = '';
let amSigDocNumber = '';
let amSigCanvas = null, amSigCtx = null, amSigDrawing = false, amSigHasDrawn = false;

async function amOpenAssinaturaModal(transferId) {
    amSigTransferId = transferId;
    amSigDocType = '';
    amSigDocNumber = '';
    amSigHasDrawn = false;
    amSigHasReceiver = false;
    amSigHasTecnico = false;
    // tenta descobrir se já tem recebedor/tecnico para ajustar UI
    try{
        var base=(window.location.pathname.split('/plugins/assetmgrstatus')[0]||'')+'/plugins/assetmgrstatus';
        var r=await fetch(base+'/ajax/card_details.php?type=transfer&id='+encodeURIComponent(transferId), {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
        var j=await r.json();
        if(j.success && j.data){
            amSigHasReceiver = !!j.data.has_receiver;
            amSigHasTecnico = !!j.data.has_tecnico;
        }
    }catch(e){}
    var s1=document.getElementById('am-sig-step1'); if(s1) s1.style.display = amSigHasReceiver ? 'none' : 'block';
    var s2=document.getElementById('am-sig-step2'); if(s2) s2.style.display = amSigHasReceiver ? 'block' : 'none';
    var foot=document.getElementById('am-sig-footer'); if(foot) foot.style.display = amSigHasReceiver ? 'flex' : 'none';
    // wizard 7 telas
    var w1=document.getElementById('am-wiz-1'); if(w1) w1.style.display = 'block';
    for(var i=2;i<=7;i++){ var w=document.getElementById('am-wiz-'+i); if(w) w.style.display='none'; }
    var prog=document.getElementById('am-sig-progress'); if(prog) prog.style.width='14%';
    var title=document.getElementById('am-sig-modal-title'); if(title) title.textContent='Assinatura — Etapa 1 de 7';
    // mostra/esconde secao recebedor dentro do step2
    var recSec=document.getElementById('am-sig-receiver-section');
    var already=document.getElementById('am-sig-already-receiver');
    var tecWrap=document.getElementById('am-sig-tec-wrap');
    var canvasLabel=document.getElementById('am-sig-canvas-label');
    if(amSigHasReceiver){
        if(recSec) recSec.style.display='none';
        if(already) already.style.display='block';
        if(canvasLabel) canvasLabel.style.display='none';
        var tecW2=document.getElementById('am-sig-tec-wrap'); if(tecW2) tecW2.style.display='block';
        document.querySelectorAll('.am-doc-btn').forEach(b=>b.classList.remove('active'));
        var hint1=document.getElementById('am-sig-step1-hint'); if(hint1){ hint1.textContent='Recebedor já assinado — selecione o técnico'; hint1.style.color='#059669'; }
    } else {
        if(recSec) recSec.style.display='block';
        if(already) already.style.display='none';
        if(canvasLabel) canvasLabel.style.display='block';
        document.querySelectorAll('.am-doc-btn').forEach(b=>b.classList.remove('active'));
        var hint2=document.getElementById('am-sig-step1-hint'); if(hint2){ hint2.textContent='Selecione RG ou CPF para continuar'; hint2.style.color='#9ca3af'; }
        if(amSigHasTecnico){
            if(tecWrap) tecWrap.style.display='none';
        } else {
            if(tecWrap) tecWrap.style.display='block';
        }
    }
    var ttl=document.getElementById('am-sig-modal-title'); if(ttl) ttl.textContent='Assinatura — Transferência #' + String(transferId).padStart(4,'0') + (amSigHasReceiver ? ' (técnico)' : '');
    // limpa step2
    var dv=document.getElementById('am-sig-doc-value'); if(dv) dv.value='';
    var nm=document.getElementById('am-sig-nome'); if(nm) nm.value='';
    amSigUpdateDisplay();
    setTimeout(()=>amSigClearCanvas(), 80);
    var mod=document.getElementById('am-modal-assinatura'); if(mod) mod.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(amSigInitCanvas, 120);
    amLoadTecnicos();
    if(amSigHasReceiver){
        var s2b=document.getElementById('am-sig-step2'); if(s2b) s2b.style.display='block';
        var footb=document.getElementById('am-sig-footer'); if(footb) footb.style.display='flex';
        setTimeout(function(){ var s=document.getElementById('am-sig-tec-select'); if(s) s.focus(); }, 150);
    }
}
function amCloseAssinaturaModal() {
    document.getElementById('am-modal-assinatura').classList.remove('open');
    document.body.style.overflow = '';
}
function amWizGo(n){
  for(var i=1;i<=7;i++){ var w=document.getElementById('am-wiz-'+i); if(w) w.style.display = (i===n?'block':'none'); }
  var prog=document.getElementById('am-sig-progress'); if(prog) prog.style.width = Math.round(n/7*100)+'%';
  var title=document.getElementById('am-sig-modal-title'); if(title) title.textContent='Assinatura — Etapa '+n+' de 7';
  if(n===6) setTimeout(amSigInitCanvas, 120);
  if(n===5){
    var b=document.getElementById('am-sig-doc-badge'); if(b){ b.textContent=amSigDocType||'CPF'; b.style.background=amSigDocType==='CPF'?'#4f46e5':'#059669'; }
    var h=document.getElementById('am-sig-doc-hint'); if(h) h.textContent=amSigDocType==='CPF'?'11 dígitos':'5 a 12 dígitos';
  }
}
function amWizNext(n){
  if(n===1){
    var sel=document.getElementById('am-sig-tec-select'); if(!sel || !sel.value){ amSigToast('Selecione o técnico.'); return; }
    var opt=sel.options[sel.selectedIndex]; var nameEl=document.getElementById('am-wiz-tec-name'); if(nameEl && opt) nameEl.textContent=opt.textContent;
    amWizGo(2);
  } else if(n===2){ amWizGo(3); setTimeout(function(){ var e=document.getElementById('am-sig-nome'); if(e) e.focus(); },150); }
  else if(n===3){ amWizGo(4); }
  else if(n===5){
    if(amSigDocType==='CPF' && amSigDocNumber.length!==11){ amSigToast('CPF precisa de 11 d�gitos.'); return; }
    if(amSigDocType==='CPF' && !amIsValidCPF(amSigDocNumber)){ amSigToast('CPF inv�lido � d�gito verificador n�o confere.'); return; }
    if(amSigDocType==='RG' && (amSigDocNumber.length<5 || amSigDocNumber.length>12)){ amSigToast('RG precisa de 5 a 12 dígitos.'); return; }
    amWizGo(6);
  } else if(n===6){
    // valida assinatura antes de salvar
    if(!amSigHasDrawn){ amSigToast('Faça a assinatura do recebedor.'); return; }
    amSigSave();
  }
}
function amWizPrev(n){
  if(n===2) amWizGo(1);
  else if(n===3) amWizGo(2);
  else if(n===4) amWizGo(3);
  else if(n===5) amWizGo(4);
  else if(n===6) amWizGo(5);
}
function amWizChooseDoc(type){
  amSigDocType=type;
  document.querySelectorAll('#am-wiz-4 .am-doc-btn').forEach(b=>b.classList.toggle('active', b.dataset.type===type));
  setTimeout(function(){ amWizGo(5); amSigDocNumber=''; var v=document.getElementById('am-sig-doc-value'); if(v) v.value=''; amSigUpdateDisplay(); }, 180);
}
function amSigChooseDoc(type) { amWizChooseDoc(type); }
function amSigGoToKeypad() { amWizGo(5); }
function amSigBackToDoc() { amWizGo(4); }
function amSigPress(k) {
    if (k==='del') { amSigBackspace(); return; }
    if (k==='ok')  { amSigCheckDocComplete(); return; }
    if (amSigDocType==='CPF' && amSigDocNumber.length >= 11) return;
    if (amSigDocType==='RG' && amSigDocNumber.length >= 12) return;
    amSigDocNumber += k;
    document.getElementById('am-sig-doc-value').value = amSigDocNumber;
    amSigUpdateDisplay();
}
function amSigBackspace() {
    amSigDocNumber = amSigDocNumber.slice(0,-1);
    document.getElementById('am-sig-doc-value').value = amSigDocNumber;
    amSigUpdateDisplay();
}
function amSigClear() {
    amSigDocNumber = '';
    document.getElementById('am-sig-doc-value').value = '';
    amSigUpdateDisplay();
}
function amSigMaskDoc(raw, type) {
    const d = raw.replace(/\D/g,'');
    if (type==='CPF' && d.length===11) return d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9);
    if (type==='RG' && d.length>2) {
        // simples: 00.000.000-0
        if (d.length<=7) return d;
        return d.slice(0,2)+'.'+d.slice(2,5)+'.'+d.slice(5,8)+'-'+d.slice(8);
    }
    return d;
}
function amSigUpdateDisplay() {
    const el = document.getElementById('am-sig-display');
    if (!amSigDocNumber) {
        el.textContent = 'Toque no teclado abaixo';
        el.classList.add('empty');
        return;
    }
    el.classList.remove('empty');
    el.textContent = amSigMaskDoc(amSigDocNumber, amSigDocType) || amSigDocNumber;
    // validação visual
    const need = amSigDocType==='CPF' ? 11 : 5;
    const max = amSigDocType==='CPF' ? 11 : 12;
    if (amSigDocNumber.length < need) el.style.borderColor = '#f59e0b';
    else if (amSigDocNumber.length <= max) el.style.borderColor = '#10b981';
    else el.style.borderColor = '#e8eaf0';
    if (amSigDocType==='CPF') el.style.borderColor = amSigDocNumber.length===11 ? '#10b981' : '#f59e0b';
}
function amIsValidCPF(cpf){
  cpf=(cpf||'').replace(/\D/g,''); if(cpf.length!==11 || /^(\d)\1{10}$/.test(cpf)) return false;
  var d1=0; for(var i=0;i<9;i++) d1+=parseInt(cpf.charAt(i))*(10-i); var r1=(d1*10)%11; if(r1===10) r1=0; if(r1!==parseInt(cpf.charAt(9))) return false;
  var d2=0; for(var i=0;i<10;i++) d2+=parseInt(cpf.charAt(i))*(11-i); var r2=(d2*10)%11; if(r2===10) r2=0; return r2===parseInt(cpf.charAt(10));
}
async function amValidaCPFExiste(cpf){
  cpf=(cpf||'').replace(/\D/g,'');
  try{
    var r=await fetch('https://brasilapi.com.br/api/cpf/v1/'+encodeURIComponent(cpf), {method:'GET'});
    if(r.ok) return true;
    if(r.status===404) return false;
    // se API fora do ar, considera válido pelo dígito (fallback)
    return null;
  }catch(e){ return null; }
}
function amSigCheckDocComplete() {
    if (amSigDocType==='CPF' && amSigDocNumber.length!==11) { amSigToast('CPF precisa de 11 dígitos.'); return; }
    if (amSigDocType==='CPF' && !amIsValidCPF(amSigDocNumber)) { amSigToast('CPF inválido — dígito verificador não confere.'); return; }
    if (amSigDocType==='RG' && (amSigDocNumber.length<5 || amSigDocNumber.length>12)) { amSigToast('RG precisa de 5 a 12 dígitos.'); return; }
    document.getElementById('am-sig-nome').focus();
}
function amSigInitCanvas() {
    const c = document.getElementById('am-sig-canvas');
    if (!c) return;
    // ajusta para DPR (retina)
    const rect = c.getBoundingClientRect();
    const dpr = window.devicePixelRatio || 1;
    c.width = rect.width * dpr;
    c.height = rect.height * dpr;
    const ctx = c.getContext('2d');
    ctx.scale(dpr, dpr);
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.lineWidth = 2.2;
    ctx.strokeStyle = '#1e1b4b';
    amSigCanvas = c; amSigCtx = ctx;
    // limpa fundo branco
    ctx.fillStyle = '#fff';
    ctx.fillRect(0,0,rect.width,rect.height);

    let getPos = (e) => {
        const r = c.getBoundingClientRect();
        const t = e.touches ? e.touches[0] : e;
        return {x: t.clientX - r.left, y: t.clientY - r.top};
    };
    let last = null;
    const start = (e)=>{ e.preventDefault(); amSigDrawing=true; last=getPos(e); amSigHasDrawn=true; };
    const move = (e)=>{ if(!amSigDrawing) return; e.preventDefault(); const p=getPos(e); ctx.beginPath(); ctx.moveTo(last.x,last.y); ctx.lineTo(p.x,p.y); ctx.stroke(); last=p; };
    const end = ()=>{ amSigDrawing=false; last=null; };
    c.addEventListener('mousedown', start);
    c.addEventListener('mousemove', move);
    c.addEventListener('mouseup', end);
    c.addEventListener('mouseleave', end);
    c.addEventListener('touchstart', start, {passive:false});
    c.addEventListener('touchmove', move, {passive:false});
    c.addEventListener('touchend', end);
}
function amSigClearCanvas() {
    const c = document.getElementById('am-sig-canvas');
    if (!c || !amSigCtx) { setTimeout(amSigClearCanvas, 50); return; }
    const rect = c.getBoundingClientRect();
    amSigCtx.fillStyle = '#fff';
    amSigCtx.fillRect(0,0,rect.width,rect.height);
    amSigHasDrawn = false;
}
async function amSigSave() {
    if (!amSigTransferId) return amSigToast('Transferência inválida.');
    // Validação condicional: se já tem recebedor, não precisa validar doc/imagem do recebedor de novo
    if (!amSigHasReceiver) {
        if (amSigDocType==='CPF' && amSigDocNumber.length!==11) return amSigToast('CPF precisa de 11 dígitos.');
        if (amSigDocType==='RG' && (amSigDocNumber.length<5 || amSigDocNumber.length>12)) return amSigToast('RG precisa de 5 a 12 dígitos.');
        if (!amSigHasDrawn) return amSigToast('Faça a assinatura do recebedor com o dedo/caneta no quadro.');
    }
    // Técnico é obrigatório se ainda não tem técnico assinado
    var tecSel=document.getElementById('am-sig-tec-select');
    var tecId=tecSel?tecSel.value:'';
    var tecOpt=tecSel && tecSel.selectedIndex>=0 ? tecSel.options[tecSel.selectedIndex] : null;
    var needTec = !amSigHasTecnico;
    if(needTec && !tecId){
        amSigToast('Selecione o técnico responsável.');
        if(tecSel) tecSel.focus();
        return;
    }
    var tecDocType='', tecDoc='', tecNome='', tecImage='';
    if(tecId && tecOpt && tecOpt.dataset.docType){
        tecDocType=tecOpt.dataset.docType||'';
        tecDoc=tecOpt.dataset.doc||'';
        tecNome=tecOpt.dataset.name||'';
        tecImage=tecOpt.dataset.image||'';
    }
    const nome = document.getElementById('am-sig-nome').value.trim();
    const c = document.getElementById('am-sig-canvas');
    var dataUrl='';
    if(!amSigHasReceiver){
        dataUrl = c.toDataURL('image/png');
        if (!dataUrl || dataUrl.length < 500) return amSigToast('Assinatura vazia — desenhe novamente.');
    } else {
        // já tem recebedor, não precisa nova imagem (backend vai usar a existente), mas manda vazio que o backend preenche
        dataUrl='';
        // se já tem recebedor, garante que não vai validar imagem vazia no backend (backend já trata)
        // mas precisa mandar algo para não falhar no check de imagem quando hasRecAlready? o backend já permite vazio nesse caso
    }
    const btn = document.getElementById('am-sig-save-btn');
    const old = btn.innerHTML; btn.disabled=true; btn.innerHTML='<i class="ti ti-loader-2" style="animation:amSpin .8s linear infinite;display:inline-block;"></i> Salvando...';
    try {
        const base = (window.location.pathname.split('/plugins/assetmgrstatus')[0] || '') + '/plugins/assetmgrstatus';
        const tok = amGetCsrfAssinatura();
        var payload={transfer_id: amSigTransferId, _glpi_csrf_token: tok};
        if(!amSigHasReceiver){
            payload.doc_type=amSigDocType; payload.doc_number=amSigDocNumber; payload.nome=nome; payload.image=dataUrl;
        } else {
            // já tem recebedor, manda dummy que o backend vai ignorar e preencher com existente
            payload.doc_type='RG'; payload.doc_number='0'; payload.nome=''; payload.image='';
        }
        if(needTec){
            payload.tec_doc_type=tecDocType; payload.tec_doc_number=tecDoc; payload.tec_nome=tecNome; payload.tec_image=tecImage;
        }
        let res = await fetch(base + '/front/assinatura.form.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json', 'X-Glpi-Csrf-Token': tok, 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        if (!res.ok && (res.status===403 || res.status===404)) {
            console.warn('front 403, tentando ajax fallback', await res.clone().text().then(t=>t.slice(0,500)));
            res = await fetch(base + '/ajax/assinatura_save.php', {
                method: 'POST',
                headers: {'Content-Type':'application/json', 'X-Glpi-Csrf-Token': tok, 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
        }
        const text = await res.text();
        let j;
        try { j = JSON.parse(text); } catch(parseErr) {
            console.error('Resposta não-JSON:', text);
            amSigToast('❌ Erro servidor (HTTP ' + res.status + '): ' + text.slice(0,1500).replace(/<[^>]*>/g,' ').trim().substring(0,600));
            btn.disabled=false; btn.innerHTML=old;
            return;
        }
        if (j.ok) {
            amSigToast('✅ Assinatura salva! Termo atualizado.', true);
            setTimeout(function(){ location.reload(); }, 900);
        } else {
            amSigToast('❌ ' + (j.error || 'Falha ao salvar.'));
            console.error(j);
            btn.disabled=false; btn.innerHTML=old;
        }
    } catch(e) {
        amSigToast('Erro de rede: ' + e.message);
        console.error(e);
        btn.disabled=false; btn.innerHTML=old;
    }
}
function amSigConfirmCancel(){ var m=document.getElementById('am-sig-cancel-confirm'); if(m){ m.style.display='flex'; } }
function amSigCancelClose(){ var m=document.getElementById('am-sig-cancel-confirm'); if(m) m.style.display='none'; }
function amSigCancelStep2(){ var m1=document.getElementById('am-sig-cancel-confirm'); if(m1) m1.style.display='none'; var m2=document.getElementById('am-sig-cancel-confirm2'); if(m2) m2.style.display='flex'; }
function amSigCancelClose2(){ var m=document.getElementById('am-sig-cancel-confirm2'); if(m) m.style.display='none'; }
function amSigCancelFinal(){
    var m1=document.getElementById('am-sig-cancel-confirm'); if(m1) m1.style.display='none';
    var m2=document.getElementById('am-sig-cancel-confirm2'); if(m2) m2.style.display='none';
    amCloseAssinaturaModal();
    amSigToast('Cancelado — progresso perdido.', false);
}
async function amPrintHP(transferId) {
    const btn = document.getElementById('am-print-hp-' + transferId);
    const oldHtml = btn ? btn.innerHTML : '';
    if (!confirm('Enviar Termo #' + String(transferId).padStart(4,'0') + ' (PDF assinado) para impressão na HP?\n\nSerá impresso EXATAMENTE o mesmo arquivo que abre em "PDF Assinado" (1-2 páginas, A4, 1 cópia).\nO PDF é gerado no servidor Ubuntu (GLPI) e enviado para a fila CUPS da HP padrão.')) return;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader-2" style="animation:amSpin .8s linear infinite;display:inline-block;"></i> Enviando...'; }
    try {
        const base = (window.location.pathname.split('/plugins/assetmgrstatus')[0] || '') + '/plugins/assetmgrstatus';
        const formBody = new URLSearchParams({transfer_id: String(transferId), stage: 'pronto', _glpi_csrf_token: amCsrfToken});
        let res = await fetch(base + '/front/print_hp.form.php', {
            method: 'POST',
            headers: {'X-Glpi-Csrf-Token': amCsrfToken, 'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin',
            body: formBody
        });
        if (!res.ok && (res.status===403 || res.status===404)) {
            // fallback ajax com POST form-encoded
            const formBody2 = new URLSearchParams({transfer_id: String(transferId), stage: 'pronto', _glpi_csrf_token: amCsrfToken});
            res = await fetch(base + '/ajax/print_hp.php', {
                method: 'POST',
                headers: {'X-Glpi-Csrf-Token': amCsrfToken, 'X-Requested-With': 'XMLHttpRequest'},
                credentials: 'same-origin',
                body: formBody2
            });
        }
        if (!res.ok && (res.status===403 || res.status===404)) {
            // último fallback via GET (bypass CSRF POST)
            res = await fetch(base + '/front/print_hp.form.php?transfer_id=' + encodeURIComponent(String(transferId)) + '&stage=pronto&_glpi_csrf_token=' + encodeURIComponent(amCsrfToken), {
                credentials: 'same-origin',
                headers: {'X-Glpi-Csrf-Token': amCsrfToken, 'X-Requested-With': 'XMLHttpRequest'}
            });
        }
        const text = await res.text();
        let j;
        try { j = JSON.parse(text); } catch(e) {
            console.error('Resposta não-JSON:', text);
            alert('❌ Erro no servidor (HTTP ' + res.status + '):\n' + text.slice(0,1200).replace(/<[^>]*>/g,' ').trim().substring(0,500));
            if (btn) { btn.disabled=false; btn.innerHTML=oldHtml; }
            return;
        }
        if (j.ok) {
            const audit = j.audit || ('Transferência #' + String(transferId).padStart(4,'0') + ' | ' + new Date().toLocaleString('pt-BR') + ' | Impressora: ' + (j.printer || '-') + (j.request_id ? ' | Job: ' + j.request_id : ''));
            try { navigator.clipboard.writeText(audit); } catch(e) {}
            alert('✅ Impressão enviada!\n' + audit + '\n\n1 cópia, A4, 1-2 páginas.\nSe não sair, abra "PDF Assinado" (Ctrl+P).\n\n[Log auditoria copiado]');
            console.log('[AUDIT OK]', audit, j);
            if (btn) { btn.disabled=false; btn.innerHTML=oldHtml; }
        } else {
            console.error(j);
            const basePdf = (window.location.pathname.split('/plugins/assetmgrstatus')[0] || '') + '/plugins/assetmgrstatus';
            const pdfUrl = basePdf + '/front/transfer_pdf.php?id=' + encodeURIComponent(String(transferId)) + '&stage=pronto';
            const audit = j.audit || ('Transferência #' + String(transferId).padStart(4,'0') + ' | ' + new Date().toLocaleString('pt-BR') + ' | Erro: ' + (j.error || 'desconhecido'));
            try { navigator.clipboard.writeText(audit + ' | ' + (j.error||'')); } catch(e) {}
            alert('❌ Falha ao imprimir\n' + (j.error || 'Erro desconhecido') + '\n\nLog auditoria:\n' + audit + '\n\nSolução: abra o PDF manualmente:\n' + pdfUrl);
            console.log('[AUDIT FAIL]', audit, j);
            if (btn) { btn.disabled=false; btn.innerHTML=oldHtml; }
        }
    } catch(e) {
        alert('Erro de rede ao imprimir: ' + e.message);
        console.error(e);
        if (btn) { btn.disabled=false; btn.innerHTML=oldHtml; }
    }
}
document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') amCloseAssinaturaModal(); });
document.getElementById('am-modal-assinatura').addEventListener('click', (e)=>{ if(e.target.id==='am-modal-assinatura') amCloseAssinaturaModal(); });
</script>
<style>@keyframes amSpin{from{transform:rotate(0deg);}to{transform:rotate(360deg);}}</style>

<?php Html::footer(); ?>
