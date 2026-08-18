<?php
include('../../../inc/includes.php');
use GlpiPlugin\Assetmgrstatus\MaintenanceRecord;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

require_once GLPI_ROOT . '/vendor/autoload.php';

Session::checkLoginUser();
Session::checkRight(MaintenanceRecord::RIGHT_VIEW, READ);
global $DB, $CFG_GLPI;

$cie_escola_map = [
    'EE "Coripheu de Azevedo Marques"'              => '28435',
    'EE "José dos Santos"'                           => '27259',
    'EE Profª Maria Pereira de B. Benetoli'          => '30636',
    'EE "João Rodrigues Fernandes"'                  => '30624',
    'EE de Osvaldo Ramos'                            => '27170',
    'EE "Osvaldo Ramos"'                             => '27170',
    'EE "Baptista Dolci"'                            => '27108',
    'EE "Profª  Vanir Ferrero Moraes"'               => '30752',
    'EE "Profª Vanir Ferrero Moraes"'                => '30752',
    'EE "Dom Artur Horsthuis"'                       => '27224',
    'Cel de Jales – EE "Dom Artur Horsthuis"'        => '985715',
    'EE "Dr. Euphly Jalles"'                         => '27145',
    'EE "Prof. Carlos de Arnaldo Silva"'             => '27261',
    'EE "Profª Sueli da Silveira Marin Batista"'     => '49700',
    'EE "Juvenal Giraldelli"'                        => '27285',
    'EE "Profª Onélia Faggioni Moreira"'             => '906104',
    'EE "Antonio Marin Cruz"'                        => '28332',
    'EE "Adelino Bertani"'                           => '27054',
    'EE "Profª Maria Pilar Ortega Garcia"'           => '28320',
    'EE "Orestes Ferreira de Toledo"'                => '28344',
    'EE "Prefeito José Ribeiro"'                     => '27194',
    'EE "Profª Zélia de Lourdes Zaccarelli Lopes"'  => '27182',
    'EE "Rubens de Oliveira Camargo"'                => '28381',
    'EE "Carlos Celso Lenarduzzi"'                   => '27112',
    'EE "Prefeito Antonio Bezerra de Araújo"'        => '28393',
    'EE "Professor Itael de Mattos"'                 => '28400',
    'CEL de Santa Fé do Sul'                         => '985806',
    'EE "Profª Maria das Dores Ferreira Rocha"'      => '28289',
    'EE "Francisco Molina Molina"'                   => '27133',
    'EE "Domingos Donato Rivelli"'                   => '28290',
    'EE "Oscar Antônio da Costa"'                    => '27066',
    'EE "Coronel Ernesto Schmidt"'                   => '30582',
    'EE "Prof. José Joaquim dos Santos"'             => '28319',
    'EE "Professor Akio Satoru"'                     => '27248',
    'EE "Profª Elide Apparecida Carlos"'             => '909993',
    'EE "José Teixeira do Amaral"'                   => '27212',
    'EE "José Nogueira de Souza"'                    => '27169',
];

$cie_ure_map = ['ADAMANTINA'=>'12','AMERICANA'=>'24','ANDRADINA'=>'36','APIAI'=>'48','ARACATUBA'=>'59','ARARAQUARA'=>'61','ASSIS'=>'73','AVARE'=>'85','BARRETOS'=>'97','BAURU'=>'103','BIRIGUI'=>'115','BOTUCATU'=>'127','BRAGANCA PAULISTA'=>'139','CAIEIRAS'=>'140','CAMPINAS LESTE'=>'152','CAMPINAS OESTE'=>'164','CAPIVARI'=>'176','CARAGUATATUBA'=>'188','CARAPICUIBA'=>'197','CATANDUVA'=>'206','CENTRO'=>'218','CENTRO OESTE'=>'224','CENTRO SUL'=>'231','DIADEMA'=>'243','FERNANDOPOLIS'=>'255','FRANCA'=>'267','GUARATINGUETA'=>'279','GUARULHOS NORTE'=>'280','GUARULHOS SUL'=>'309','ITAPECERICA DA SERRA'=>'310','ITAPETININGA'=>'322','ITAPEVA'=>'334','ITAPEVI'=>'346','ITAQUAQUECETUBA'=>'358','ITARARE'=>'361','ITU'=>'371','JABOTICABAL'=>'383','JACAREI'=>'395','JALES'=>'401','JAU'=>'413','JOSE BONIFACIO'=>'425','JUNDIAI'=>'437','LESTE 1'=>'450','LESTE 2'=>'462','LESTE 3'=>'474','LESTE 4'=>'498','LESTE 5'=>'504','LIMEIRA'=>'528','LINS'=>'536','MARILIA'=>'541','MAUA'=>'553','MIRACATU'=>'565','MIRANTE DO PARANAPANEMA'=>'577','MOGI DAS CRUZES'=>'589','MOGI MIRIM'=>'590','NORTE 1'=>'607','NORTE 2'=>'619','OSASCO'=>'620','OURINHOS'=>'632','PENAPOLIS'=>'656','PINDAMONHANGABA'=>'668','PIRACICABA'=>'673','PIRAJU'=>'681','PIRASSUNUNGA'=>'693','PRESIDENTE PRUDENTE'=>'700','REGISTRO'=>'711','RIBEIRAO PRETO'=>'723','SANTO ANASTACIO'=>'735','SANTO ANDRE'=>'747','SANTOS'=>'759','SAO BERNARDO DO CAMPO'=>'760','SAO CARLOS'=>'772','SAO JOAO DA BOA VISTA'=>'784','SAO JOAQUIM DA BARRA'=>'796','SAO JOSE DO RIO PRETO'=>'802','SAO JOSE DOS CAMPOS'=>'814','SAO ROQUE'=>'838','SAO VICENTE'=>'848','SERTAOZINHO'=>'863','SOROCABA'=>'875','SUL 1'=>'899','SUL 2'=>'905','SUL 3'=>'917','SUMARE'=>'929','SUZANO'=>'930','TABOAO DA SERRA'=>'942','TAQUARITINGA'=>'966','TAUBATE'=>'978','TUPA'=>'985','VOTORANTIM'=>'1004','VOTUPORANGA'=>'1016'];

$modelo_desktop_legado = ['OPTIPLEX 3010','OPTIPLEX 3020','OPTIPLEX 3030','OPTIPLEX 3040','OPTIPLEX 3050','OPTIPLEX 3060','OPTIPLEX 3070','OPTIPLEX 390','OPTIPLEX 5050','OPTIPLEX 5060','OPTIPLEX 7010','OPTIPLEX 7020','OPTIPLEX 7030','OPTIPLEX 7040','OPTIPLEX 7050','OPTIPLEX 7060','OPTIPLEX 7070','POWEREDGE R710','DIEBOLD TW9850','TW9850','HP ELITEDESK 800','ELITEDESK 800','HP PRODESK 400','PRODESK 400','INFOWAY ST-4272','ST-4272','INFOWAY ST-4273','ST-4273','22V280-L.BY31P1','22V280-L.BY42P2','22V30R-L.BY31P2','S460-L.BG22P1','P430-K.BE31P1','C1400 MINIPRO','D570','POSITIVO MASTER D380','ALL IN ONE'];
$modelo_desktop        = ['THINKCENTRE M75S'];
$modelo_nb_avancado    = ['N8440','THINKPAD L14'];
$modelo_nb_basico      = ['ULTRABOOK UL150','ULTRABOOK UL151','ULTRABOOK UL154'];
$modelo_nb_multiplica  = ['N6440'];
$modelo_nb_sala        = ['M11W PRO CL','N1110','N1210','CHROMEBOOK SAMSUNG 4','CHROMEBOOK SAMSUNG GO'];
$modelo_nb_legado      = ['ASPIRE 4739Z','ASPIRE A315-23','ASPIRE A315-34','ASPIRE A315-42G','ASPIRE A315-51','ASPIRE A315-53','ASPIRE A315-54','ASPIRE A315-54K','ASPIRE A315-56','ASPIRE A315-58','ASPIRE A514-54','ASPIRE A515-45','ASPIRE A515-51','ASPIRE A515-54','ASPIRE A515-57','ASPIRE AG15-51P','ASPIRE AG15-71P','ASPIRE E1-430','ASPIRE E1-471','ASPIRE E1-571','ASPIRE E1-572','ASPIRE E5-471','ASPIRE E5-573','ASPIRE ES1-411','ASPIRE ES1-533','ASPIRE ES1-572','ASPIRE V5-571','E1-510','NITRO AN515-51','ACER CHROMEBOOK 511','G3 3500','INSPIRON 15 3511','INSPIRON 15 3530','INSPIRON 15 3535','INSPIRON 15-3567','INSPIRON 24 5420 ALL-IN-ONE','INSPIRON 3268','INSPIRON 3480','INSPIRON 3501','INSPIRON 3502','INSPIRON 3542','INSPIRON 3583','INSPIRON 3584','INSPIRON 3647','INSPIRON 5458','INSPIRON 5558','INSPIRON 5566','INSPIRON 5567','INSPIRON 5570','LATITUDE 3400','LATITUDE 3410','LATITUDE 3490','LATITUDE 5400','LATITUDE 7480','LATITUDE 5480','LATITUDE 7490','LATITUDE E6420','LATITUDE E7240','LATITUDE E6430','LATITUDE E6440','LATITUDE E7440','LATITUDE E7470','VOSTRO 14-3468','VOSTRO 15 3510','VOSTRO 270S','VOSTRO 3250','VOSTRO 3470','VOSTRO 3480','VOSTRO 3681','W415','W550'];
$fab_nb_legado = ['ASUS','COMPAQ','SONY','SEMP','SEMP TOSHIBA'];

function resolveCategoria(string $tipo, string $modelo, string $fabricante): string {
    global $modelo_desktop_legado,$modelo_desktop,$modelo_nb_avancado,$modelo_nb_basico,$modelo_nb_multiplica,$modelo_nb_sala,$modelo_nb_legado,$fab_nb_legado;
    $m = strtoupper(trim($modelo)); $f = strtoupper(trim($fabricante));
    // Usa o system_name exato do GLPI (sem namespace, sem 'Asset')
    $t = strtolower($tipo);

    // Match exato pelos system_name do banco
    // system_names exatos do banco: Desktop, Notebook, Celular, Tablet,
    // Switch, Televisao, Firewall, RackdeRede, PlataformadeRecarga, AccessPoint
    if ($t === 'desktop') {
        if (in_array($m,array_map('strtoupper',$modelo_desktop))) return 'Desktop';
        foreach ($modelo_desktop_legado as $ml) { if (strpos($m,strtoupper($ml))!==false) return 'Desktop Legado'; }
        return 'Desktop';
    }
    if ($t === 'notebook') {
        foreach ($modelo_nb_avancado as $ml)   { if (strpos($m,strtoupper($ml))!==false) return 'Notebook Avançado'; }
        foreach ($modelo_nb_basico as $ml)     { if (strpos($m,strtoupper($ml))!==false) return 'Notebook Básico Educacional'; }
        foreach ($modelo_nb_multiplica as $ml) { if (strpos($m,strtoupper($ml))!==false) return 'Notebook Multiplica'; }
        foreach ($modelo_nb_sala as $ml)       { if (strpos($m,strtoupper($ml))!==false) return 'Notebook Sala de Aula'; }
        foreach ($fab_nb_legado as $fl)        { if (strpos($f,strtoupper($fl))!==false) return 'Notebook Legado'; }
        foreach ($modelo_nb_legado as $ml)     { if (strpos($m,strtoupper($ml))!==false) return 'Notebook Legado'; }
        return 'Notebook Sala de Aula';
    }
    if ($t === 'celular') return 'Smartphone';
    if ($t === 'tablet') return 'Tablet';
    if ($t === 'switch') return 'Switch';
    if ($t === 'firewall') return 'Firewall';
    if ($t === 'rackderede') return 'Rack de Rede';
    if ($t === 'televisao') return 'Televisão';
    if ($t === 'plataformaderecarga') return 'Plataforma de Recarga';
    if ($t === 'accesspoint') return 'Switch'; // Access Point vai junto com rede

    // Fallback por substring
    if (strpos($t,'desktop')!==false) return 'Desktop';
    if (strpos($t,'notebook')!==false||strpos($t,'laptop')!==false) return 'Notebook Sala de Aula';
    if (strpos($t,'celular')!==false||strpos($t,'phone')!==false) return 'Smartphone';
    if (strpos($t,'tablet')!==false)   return 'Tablet';
    if (strpos($t,'switch')!==false)   return 'Switch';
    if (strpos($t,'firewall')!==false) return 'Firewall';
    if (strpos($t,'rack')!==false)     return 'Rack de Rede';
    if (strpos($t,'televisao')!==false||strpos($t,'tv')!==false) return 'Televisão';
    if (strpos($t,'recarga')!==false||strpos($t,'plataforma')!==false) return 'Plataforma de Recarga';
    return ucfirst($tipo);
}

function getAba(string $cat): string {
    $hw  = ['Desktop','Desktop Legado','Notebook Avançado','Notebook Básico Educacional','Notebook Multiplica','Notebook Sala de Aula','Notebook Legado','Smartphone','Tablet'];
    $net = ['Switch','Firewall','Rack de Rede'];
    if (in_array($cat,$hw))  return 'Equipamentos_de_Hardware';
    if (in_array($cat,$net)) return 'Equipamentos_de_Rede';
    if ($cat==='Televisão')  return 'TVs_e_Educatrons';
    if ($cat==='Plataforma de Recarga') return 'Plataforma_de_Recarga';
    return 'Equipamentos_de_Hardware';
}

$ure_name = 'Unidade Regional de Ensino de Jales';
$u = new User(); $u->getFromDB(Session::getLoginUserID());
$user_name = $u->getFriendlyName();
$today = date('d/m/Y');
$ent_atual = new Entity(); $ent_atual->getFromDB($_SESSION['glpiactive_entity']??0);
$ent_file = preg_replace('/[^A-Za-z0-9_\-]/','_',iconv('UTF-8','ASCII//TRANSLIT',$ent_atual->getName()??'GLPI'));

$status_map = ['ativo'=>'Disponível','garantia'=>'Chamado aberto','manutencao'=>'Em manutenção','inservivel'=>'Inservível','inativo'=>'Danificado','estoque'=>'Disponível','inactive'=>'Danificado','active'=>'Disponível'];
$aval_map   = ['ativo'=>'Bom','garantia'=>'Sem avaliação','manutencao'=>'Dano Físico','inservivel'=>'Obsoleto','inativo'=>'Obsoleto','estoque'=>'Bom','inactive'=>'Obsoleto','active'=>'Bom'];

$campo_ambiente=null; $campo_hostname=null;
try { foreach ($DB->request(['SELECT'=>['id','name'],'FROM'=>'glpi_plugin_fields_fields']) as $cf) { $n=strtolower($cf['name']); if(strpos($n,'mbiente')!==false) $campo_ambiente=$cf['id']; if(strpos($n,'ostname')!==false) $campo_hostname=$cf['id']; } } catch(\Exception $e){}

$assets = MaintenanceRecord::getAssets();
$abas = ['Equipamentos_de_Hardware'=>[],'TVs_e_Educatrons'=>[],'Equipamentos_de_Rede'=>[],'Plataforma_de_Recarga'=>[]];

$headers = [
    'Equipamentos_de_Hardware' => ['CIE','URE / DIRETORIA','AMBIENTE','CATEGORIA DO EQUIPAMENTO','FABRICANTE','MODELO','HOSTNAME','NÚMERO DE SÉRIE','ID DE CONTROLE INTERNO DA URE','STATUS DO EQUIPAMENTO','AVALIAÇÃO TÉCNICA','DESCRIÇÃO','DATA DE ABERTURA DO CHAMADO','NÚMERO DO CHAMADO','DATA DA VISITA','TÉCNICO RESPONSÁVEL','STATUS DA VISITA','OBSERVAÇÕES'],
    'TVs_e_Educatrons'         => ['CIE','URE / DIRETORIA','AMBIENTE','TIPO DE EQUIPAMENTO','MODELO','NÚMERO DE SÉRIE','STATUS DO EQUIPAMENTO','AVALIAÇÃO TÉCNICA','DESCRIÇÃO','DATA DE ABERTURA DO CHAMADO','NÚMERO DO CHAMADO','DATA DA VISITA','TÉCNICO RESPONSÁVEL','STATUS DA VISITA','OBSERVAÇÕES'],
    'Equipamentos_de_Rede'     => ['CIE','URE / DIRETORIA','AMBIENTE','TIPO DE EQUIPAMENTO','MARCA / FABRICANTE','TIPO / MODELO','NÚMERO DE SÉRIE','STATUS DO EQUIPAMENTO','AVALIAÇÃO TÉCNICA','DESCRIÇÃO','DATA DE ABERTURA DO CHAMADO','NÚMERO DO CHAMADO','DATA DA VISITA','TÉCNICO RESPONSÁVEL','STATUS DA VISITA','OBSERVAÇÕES'],
    'Plataforma_de_Recarga'    => ['CIE','URE / DIRETORIA','AMBIENTE','MARCA','NÚMERO DE SÉRIE','STATUS DO EQUIPAMENTO','AVALIAÇÃO TÉCNICA','DESCRIÇÃO','DATA DE ABERTURA DO CHAMADO','NÚMERO DO CHAMADO','DATA DA VISITA','TÉCNICO RESPONSÁVEL','STATUS DA VISITA','OBSERVAÇÕES'],
];

foreach ($assets as $asset) {
    $ps = $asset['plugin_status']??'estoque';
    // Extrai o system_name removendo namespace e sufixo Asset
    $tipo_raw = str_replace(['Glpi\\CustomAsset\\', 'Asset'], '', $asset['itemtype'] ?? '');
    // Remove sufixo Asset apenas dos tipos legados
    $tipo = str_replace('Glpi\\CustomAsset\\', '', $asset['itemtype'] ?? '');
    $tipo = preg_replace('/Asset$/', '', $tipo); // remove 'Asset' do final se existir
    $fabricante=''; $modelo='';
    try {
        $ar=$DB->request(['SELECT'=>['manufacturers_id','assets_assetmodels_id'],'FROM'=>'glpi_assets_assets','WHERE'=>['id'=>(int)$asset['id']],'LIMIT'=>1])->current();
        if ($ar) {
            if ($ar['manufacturers_id']) { $mf=$DB->request(['SELECT'=>['name'],'FROM'=>'glpi_manufacturers','WHERE'=>['id'=>$ar['manufacturers_id']],'LIMIT'=>1])->current(); if($mf) $fabricante=$mf['name']; }
            if ($ar['assets_assetmodels_id']) { foreach(['glpi_assets_assetmodels','glpi_computermodels','glpi_phonemodels','glpi_tabletmodels','glpi_networkequipmentmodels'] as $mt) { try { $md=$DB->request(['SELECT'=>['name'],'FROM'=>$mt,'WHERE'=>['id'=>$ar['assets_assetmodels_id']],'LIMIT'=>1])->current(); if($md){$modelo=$md['name'];break;} } catch(\Exception $e){} } }
        }
    } catch(\Exception $e){}
    $cat = resolveCategoria($tipo,$modelo,$fabricante);
    $aba = getAba($cat);
    $ename = $asset['entity_name']??'';
    $cie = $cie_escola_map[$ename]??'';
    if (!$cie) { foreach($cie_escola_map as $k=>$v){if(mb_strtolower(trim($k))===mb_strtolower(trim($ename))){$cie=$v;break;}}}
    if (!$cie) { $eu=strtoupper(iconv('UTF-8','ASCII//TRANSLIT',$ename)); foreach($cie_ure_map as $k=>$v){if(strpos($eu,$k)!==false){$cie=$v;break;}} }
    if (!$cie) $cie='401';
    $ambiente=''; $hostname='';
    foreach([[$campo_ambiente,&$ambiente],[$campo_hostname,&$hostname]] as [$fid,&$fval]) {
        if(!$fid) continue;
        foreach(['glpi_plugin_fields_assets_strings','glpi_plugin_fields_assets_dropdowns'] as $ft) { try { $fr=$DB->request(['SELECT'=>['value'],'FROM'=>$ft,'WHERE'=>['items_id'=>$asset['id'],'plugin_fields_fields_id'=>$fid],'LIMIT'=>1])->current(); if($fr){$fval=$fr['value'];break;} } catch(\Exception $e){} }
    }
    $st=$status_map[$ps]??'Disponível'; $av=$aval_map[$ps]??'Sem avaliação';
    $sn=$asset['serial']??''; $inv=$asset['otherserial']??'';
    if ($aba==='Equipamentos_de_Hardware') $abas[$aba][]=[$cie,$ure_name,$ambiente,$cat,$fabricante,$modelo,$hostname,$sn,$inv,$st,$av,'','','',$today,$user_name,'Concluída',''];
    elseif ($aba==='TVs_e_Educatrons')     $abas[$aba][]=[$cie,$ure_name,$ambiente,$cat,$modelo,$sn,$st,$av,'','','',$today,$user_name,'Concluída',''];
    elseif ($aba==='Equipamentos_de_Rede') $abas[$aba][]=[$cie,$ure_name,$ambiente,$cat,$fabricante,$modelo,$sn,$st,$av,'','','',$today,$user_name,'Concluída',''];
    elseif ($aba==='Plataforma_de_Recarga') $abas[$aba][]=[$cie,$ure_name,$ambiente,$fabricante,$sn,$st,$av,'','','',$today,$user_name,'Concluída',''];
}

// ---- Gera XLSX com PhpSpreadsheet ----
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0);

$headerStyle = [
    'font'      => ['bold'=>true,'color'=>['rgb'=>'FFFFFF'],'size'=>10],
    'fill'      => ['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>'1A3A5C']],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>true],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'FFFFFF']]],
];
$cellStyle = [
    'alignment' => ['vertical'=>Alignment::VERTICAL_CENTER,'wrapText'=>false],
    'borders'   => ['allBorders'=>['borderStyle'=>Border::BORDER_THIN,'color'=>['rgb'=>'DDDDDD']]],
];

foreach ($abas as $nome_aba => $rows) {
    $ws = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $nome_aba);
    $spreadsheet->addSheet($ws);
    $hds = $headers[$nome_aba];
    // Header
    foreach ($hds as $ci => $h) {
        $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci+1);
        $ws->setCellValue($col.'1', $h);
        $ws->getColumnDimension($col)->setAutoSize(true);
    }
    $ws->getStyle('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($hds)).'1')->applyFromArray($headerStyle);
    $ws->getRowDimension(1)->setRowHeight(30);
    // Dados
    foreach ($rows as $ri => $row) {
        $rowNum = $ri + 2;
        foreach ($row as $ci => $val) {
            $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($ci+1);
            $ws->setCellValue($col.$rowNum, $val);
        }
        $lastCol = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($hds));
        $ws->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->applyFromArray($cellStyle);
        if ($ri%2===0) $ws->getStyle('A'.$rowNum.':'.$lastCol.$rowNum)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F7F9FC');
    }
    // Freeze header
    $ws->freezePane('A2');
    // AutoFilter
    $ws->setAutoFilter('A1:'.\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($hds)).'1');
}

// Envia como download
$fn = 'Relatorio_Mensal_'.$ent_file.'_'.date('Y-m').'.xlsx';

// Limpa qualquer output anterior (HTML do GLPI, warnings, etc.)
while (ob_get_level()) ob_end_clean();

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="'.$fn.'"');
header('Cache-Control: max-age=0, no-store, no-cache, must-revalidate');
header('Cache-Control: post-check=0, pre-check=0', false);
header('Pragma: public');
header('Expires: 0');

// Salva em arquivo temporário e envia
$tmp_xlsx = tempnam(sys_get_temp_dir(), 'xlsx_');
$writer = new Xlsx($spreadsheet);
$writer->save($tmp_xlsx);

header('Content-Length: '.filesize($tmp_xlsx));
readfile($tmp_xlsx);
unlink($tmp_xlsx);
exit;
