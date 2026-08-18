<?php

include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use GlpiPlugin\Assetmgrstatus\Transfer;

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_transfer', CREATE) && !Session::haveRight('plugin_assetmgrstatus_transfer', UPDATE)
    && !Session::haveRight('plugin_assetmgrstatus', CREATE) && !Session::haveRight('plugin_assetmgrstatus', UPDATE)) {
    Html::displayRightError(); exit;
}

global $CFG_GLPI;

$entity_dest  = (int)($_POST['entity_dest'] ?? 0);
$reason       = trim($_POST['reason'] ?? '');
$selected     = $_POST['selected_assets'] ?? '';
$transfer_type = $_POST['transfer_type'] ?? 'ure';

if ($entity_dest < 0 || !$reason || !$selected) {
    Session::addMessageAfterRedirect('Dados inválidos para transferência.', false, ERROR);
    Html::back();
    exit;
}

$items = json_decode($selected, true);
if (!is_array($items) || empty($items)) {
    Session::addMessageAfterRedirect('Nenhum ativo selecionado.', false, ERROR);
    Html::back();
    exit;
}

$transfer_id = Transfer::create($entity_dest, $reason, $items, $transfer_type);

if (!$transfer_id) {
    Session::addMessageAfterRedirect('Nenhum ativo válido pôde ser transferido. Verifique se os ativos existem e estão na entidade ativa.', false, ERROR);
    Html::back();
    exit;
}

$pdf_url      = $CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/transfer_pdf.php?id=' . $transfer_id . '&stage=transfer';
$redirect_url = $CFG_GLPI['root_doc'] . '/plugins/assetmgrstatus/front/transfer.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Transferência realizada</title>
<style>
  body { font-family: Arial, sans-serif; display:flex; align-items:center; justify-content:center; height:100vh; margin:0; background:#f4f6fb; }
  .box { background:#fff; border-radius:16px; padding:40px 48px; text-align:center; box-shadow:0 4px 24px rgba(0,0,0,.1); max-width:420px; }
  .icon { font-size:3rem; margin-bottom:12px; }
  h2 { color:#1e40af; margin-bottom:8px; font-size:1.3rem; }
  p { color:#6b7280; font-size:.9rem; margin-bottom:24px; }
  .btn-pdf { display:inline-block; background:linear-gradient(135deg,#1e40af,#3b82f6); color:#fff; text-decoration:none; padding:12px 28px; border-radius:10px; font-weight:700; font-size:.95rem; margin-bottom:12px; }
  .btn-voltar { display:block; color:#6b7280; font-size:.85rem; text-decoration:none; margin-top:8px; }
  .btn-voltar:hover { color:#374151; }
</style>
</head>
<body>
<div class="box">
  <div class="icon">✅</div>
  <h2>Transferência realizada!</h2>
  <p>O card foi criado na aba Técnico.<br>Clique abaixo para abrir o PDF do termo de retirada.</p>
  <a href="<?= $pdf_url ?>" target="_blank" class="btn-pdf" id="btn-pdf">
    🖨️ Abrir PDF em nova aba
  </a>
  <a href="<?= $redirect_url ?>" class="btn-voltar">← Voltar para Transferência</a>
</div>
<script>
  // Tenta abrir automaticamente após 300ms (funciona na maioria dos navegadores pois é clique direto)
  setTimeout(function() {
    document.getElementById('btn-pdf').click();
  }, 400);
  // Redireciona após 4 segundos se o usuário não fizer nada
  setTimeout(function() {
    window.location.href = '<?= $redirect_url ?>';
  }, 8000);
</script>
</body>
</html>
<?php exit;
