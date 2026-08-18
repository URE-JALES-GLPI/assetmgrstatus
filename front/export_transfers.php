<?php
include('../../../inc/includes.php');

use GlpiPlugin\Assetmgrstatus\Transfer;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

require_once GLPI_ROOT . '/vendor/autoload.php';

Session::checkLoginUser();
if (!Session::haveRight('plugin_assetmgrstatus_transfer', READ) && !Session::haveRight('plugin_assetmgrstatus', READ)) {
    Html::displayRightError(); exit;
}

global $DB, $CFG_GLPI;

$filter_status = (string)($_GET['status'] ?? '');
$transfers     = Transfer::getAll($filter_status);

$headers = ['ID', 'Data de Criação', 'Status', 'Origem', 'Destino', 'Motivo', 'Qtd. Ativos', 'Técnico', 'Criado por', 'Chamado', 'Data Pronto', 'Data Finalizado', 'Data Cancelado'];

$rows = [];
foreach ($transfers as $t) {
    $ticket_txt = '';
    if ((int)$t['tickets_id'] > 0) {
        $ticket_txt = '#' . (int)$t['tickets_id'] . ' — ' . $CFG_GLPI['root_doc'] . '/front/ticket.form.php?id=' . (int)$t['tickets_id'];
    }
    $rows[] = [
        '#' . str_pad((int)$t['id'], 4, '0', STR_PAD_LEFT),
        date('d/m/Y H:i', strtotime($t['date_creation'])),
        Transfer::getStatusOptions()[$t['status']] ?? $t['status'],
        $t['origin_entity_name'] ?? '',
        $t['entity_dest_name'] ?? '',
        $t['reason'] ?? '',
        $t['items_count'] ?? 0,
        $t['tech_name'] ?? '',
        $t['creator_name'] ?? '',
        $ticket_txt,
        $t['date_pronto'] ? date('d/m/Y H:i', strtotime($t['date_pronto'])) : '',
        $t['date_finalizado'] ? date('d/m/Y H:i', strtotime($t['date_finalizado'])) : '',
        $t['date_cancelado'] ? date('d/m/Y H:i', strtotime($t['date_cancelado'])) : '',
    ];
}

$spreadsheet = new Spreadsheet();
$ws = $spreadsheet->getActiveSheet();
$ws->setTitle('Transferências');

foreach ($headers as $ci => $h) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
    $ws->setCellValue($col . '1', $h);
    $ws->getColumnDimension($col)->setAutoSize(true);
}
$ws->getStyle('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1')->applyFromArray([
    'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A3A5C']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'FFFFFF']]],
]);

foreach ($rows as $ri => $row) {
    $rowNum = $ri + 2;
    foreach ($row as $ci => $val) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci + 1);
        $ws->setCellValue($col . $rowNum, $val);
    }
    $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers));
    $ws->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->applyFromArray([
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => false],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'DDDDDD']]],
    ]);
    if ($ri % 2 === 0) $ws->getStyle('A' . $rowNum . ':' . $lastCol . $rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7F9FC');
}
$ws->freezePane('A2');
$ws->setAutoFilter('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($headers)) . '1');

$fn = 'Transferencias_' . date('Y-m-d') . ($filter_status ? '_' . $filter_status : '') . '.xlsx';

while (ob_get_level()) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fn . '"');
header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: public');
header('Expires: 0');

$tmp_xlsx = tempnam(sys_get_temp_dir(), 'xlsx_');
$writer = new Xlsx($spreadsheet);
$writer->save($tmp_xlsx);

header('Content-Length: ' . filesize($tmp_xlsx));
readfile($tmp_xlsx);
unlink($tmp_xlsx);
exit;