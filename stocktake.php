<?php
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate");
header("Pragma: no-cache");
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/StockTake.php';
require_once __DIR__ . '/classes/Product.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

Auth::requireAuth();
$canWrite = Auth::canWrite();
$canAdmin = Auth::canAdmin();

$pageTitle   = 'Stock Take';
$currentPage = 'stocktake';
$action      = $_GET['action'] ?? 'list';
$id          = intval($_GET['id'] ?? 0) ?: null;

$message     = $_SESSION['message']     ?? '';
$messageType = $_SESSION['messageType'] ?? '';
unset($_SESSION['message'], $_SESSION['messageType']);

$db = db();

if ($action === 'export' && $id) {
    $st    = StockTake::getById($id);
    $items = $db->prepare("SELECT sti.*, p.product_code, p.product_name
        FROM stock_take_items sti LEFT JOIN products p ON sti.product_id=p.id
        WHERE sti.stock_take_id=? ORDER BY sti.location, p.product_code");
    $items->execute([$id]);
    $items = $items->fetchAll();

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="StockTake_'.$st['take_number'].'_'.date('Ymd').'.xls"');
    echo "<html><head><meta charset='UTF-8'></head><body>\n";
    echo "<table border='1' style='border-collapse:collapse;font-family:Arial;font-size:11px'>\n";
    
    echo "<tr><td colspan='11' style='background:#026766;color:#fff;font-size:13px;font-weight:bold;padding:8px'>Stock Take — ".$st['take_number']."</td></tr>\n";
    echo "<tr><td>Tanggal</td><td colspan='10'>".date('d F Y',strtotime($st['take_date']))."</td></tr>\n";
    echo "<tr><td>Status</td><td colspan='10'>".$st['status']."</td></tr>\n";
    echo "<tr><td colspan='11'></td></tr>\n";
    
    $hdrs = ['Lokasi','SKU','Nama Produk','Batch','UOM','Qty System','Counter 1','Counter 2','Counter 3','Different','Status','Remarks'];
    $thCells = '';
    foreach($hdrs as $h) $thCells .= "<th style='background:#e0f7f7;font-weight:bold;padding:6px 8px'>$h</th>";
    echo "<tr>".$thCells."</tr>\n";
    
    $clear=$plus=$minus=0;
    foreach ($items as $it) {
        $diff = floatval($it['difference']);
        $bg   = $diff!=0 ? "background:#fff7ed" : "";
        echo "<tr style='$bg'>";
        echo "<td style='font-family:monospace;color:#026766'>".$it['location']."</td>";
        echo "<td style='font-family:monospace'>".$it['product_code']."</td>";
        echo "<td>".$it['product_name']."</td>";
        echo "<td>".$it['batch_number']."</td>";
        echo "<td>".$it['uom']."</td>";
        echo "<td style='text-align:right'>".$it['qty_system']."</td>";
        echo "<td style='text-align:right;color:green'>".$it['counter_1']."</td>";
        echo "<td style='text-align:right;color:green'>".$it['counter_2']."</td>";
        echo "<td style='text-align:right'>".$it['counter_3']."</td>";
        $dc = $diff>0?'color:green':($diff<0?'color:red':'');
        echo "<td style='text-align:right;font-weight:bold;$dc'>".($diff>0?'+'.$diff:$diff)."</td>";
        echo "<td style='text-align:center'>".$it['status']."</td>";
        echo "<td>".$it['notes']."</td>";
        echo "</tr>\n";
        if($it['status']==='Plus') $plus+=abs($diff);
        elseif($it['status']==='Minus') $minus+=abs($diff);
        else $clear++;
    }
    
    $acc = count($items)>0 ? round($clear/count($items)*100,2) : 100;
    echo "<tr><td colspan='11'></td></tr>\n";
    echo "<tr style='background:#e0f7f7;font-weight:bold'>";
    echo "<td colspan='5'>TOTAL</td>";
    echo "<td style='text-align:right'>".array_sum(array_column($items,'qty_system'))."</td>";
    echo "<td style='text-align:right'>".array_sum(array_column($items,'counter_1'))."</td>";
    echo "<td style='text-align:right'>".array_sum(array_column($items,'counter_2'))."</td>";
    echo "<td></td>";
    $td=array_sum(array_column($items,'difference'));
    echo "<td style='text-align:right'>".($td>0?'+'.$td:$td)."</td>";
    echo "<td colspan='2'>Clear: $clear | +Plus: $plus | -Minus: $minus | Akurasi: $acc%</td>";
    echo "</tr>\n</table></body></html>";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::requireWrite();

    if (isset($_POST['create_stocktake'])) {
        $rawScope = trim($_POST['scope_locations'] ?? '');
        $scopeArr = ($rawScope !== '' && $rawScope !== '[]') ? json_decode($rawScope, true) : null;
        $scopeJson = ($scopeArr && count($scopeArr) > 0) ? json_encode(array_values($scopeArr)) : null;

        $newId = StockTake::create([
            'take_date'       => $_POST['take_date'],
            'status'          => 'Draft',
            'notes'           => $_POST['notes'] ?? null,
            'scope_locations' => $scopeJson,
        ]);
        if ($newId) {
            if (!empty($_POST['auto_load'])) {
                StockTake::autoLoadByLocations($newId, $scopeArr);
            }
            $scopeLabel = $scopeArr ? count($scopeArr).' lokasi' : 'full warehouse';
            ActivityLogger::log('CREATE_STOCKTAKE', 'stock', 'StockTake', $newId, null,
                "Buat stock take " . ($_POST['take_date'] ?? '') . " ($scopeLabel)" . (!empty($_POST['auto_load']) ? ' + auto-load' : ''));
            $_SESSION['message']='Stock Take berhasil dibuat!'; $_SESSION['messageType']='success';
            header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$newId); exit;
        }
        $_SESSION['message']='Gagal membuat stock take!'; $_SESSION['messageType']='error';
        header('Location: '.BASE_URL.'/stocktake.php?action=create'); exit;
    }

    if (isset($_POST['add_item'])) {
        // During Draft, counters are not filled — they are entered during Counting phase
        StockTake::addItemFull($_POST['stock_take_id'],[
            'product_id'   => $_POST['product_id'],
            'batch_number' => $_POST['batch_number'] ?? null,
            'location'     => $_POST['location'] ?? null,
            'uom'          => $_POST['uom'] ?? null,
            'qty_system'   => floatval($_POST['qty_system'] ?? 0),
            'qty_physical' => 0,
            'counter_1'    => null,
            'counter_2'    => null,
            'counter_3'    => null,
            'notes'        => $_POST['item_notes'] ?? null,
            'counter_by'   => null,
        ]);
        ActivityLogger::log('ADD_STOCKTAKE_ITEM', 'stock', 'StockTake', (int)$_POST['stock_take_id'],
            null, "Tambah item produk ID " . ($_POST['product_id'] ?? '?') . " lokasi " . ($_POST['location'] ?? '—'));
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$_POST['stock_take_id']); exit;
    }

    if (isset($_POST['update_stocktake'])) {
        StockTake::update($_POST['id'],['take_date'=>$_POST['take_date'],'status'=>$_POST['status'],'notes'=>$_POST['notes']??null]);
        ActivityLogger::log('UPDATE_STOCKTAKE', 'stock', 'StockTake', (int)$_POST['id'],
            null, "Update stock take → status " . ($_POST['status'] ?? '—'));
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$_POST['id']); exit;
    }

    if (isset($_POST['delete_item'])) {
        $db->prepare("DELETE FROM stock_take_items WHERE id=?")->execute([$_POST['item_id']]);
        ActivityLogger::log('DELETE_STOCKTAKE_ITEM', 'stock', 'StockTake', (int)$_POST['stock_take_id'],
            null, "Hapus item ID " . $_POST['item_id'] . " dari stock take");
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$_POST['stock_take_id']); exit;
    }

    if (isset($_POST['delete_stocktake'])) {
        ActivityLogger::log('DELETE_STOCKTAKE', 'stock', 'StockTake', (int)$_POST['id'],
            null, "Hapus stock take ID " . $_POST['id']);
        StockTake::delete($_POST['id']);
        header('Location: '.BASE_URL.'/stocktake.php'); exit;
    }

    if (isset($_POST['start_counting'])) {
        try {
            StockTake::startCounting((int)$_POST['id']);
            ActivityLogger::log('START_COUNTING', 'stock', 'StockTake', (int)$_POST['id'], null, "Mulai Counting");
            $_SESSION['message']='Counting dimulai!'; $_SESSION['messageType']='success';
        } catch (\Exception $e) {
            $_SESSION['message']=$e->getMessage(); $_SESSION['messageType']='error';
        }
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.(int)$_POST['id']); exit;
    }

    if (isset($_POST['save_c1'])) {
        $stId = (int)$_POST['stock_take_id'];
        try {
            StockTake::saveC1($stId, $_POST['c1'] ?? []);
            $_SESSION['message']='Counter 1 disimpan.'; $_SESSION['messageType']='success';
        } catch (\Exception $e) {
            $_SESSION['message']=$e->getMessage(); $_SESSION['messageType']='error';
        }
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$stId); exit;
    }

    if (isset($_POST['advance_to_c2'])) {
        $stId = (int)$_POST['stock_take_id'];
        try {
            StockTake::advanceToC2($stId, $_POST['c1'] ?? []);
            ActivityLogger::log('ADVANCE_C2', 'stock', 'StockTake', $stId, null, "Counter 1 selesai → giliran Counter 2");
            $_SESSION['message']='Counter 1 selesai. Giliran Counter 2 mengisi hitungan.'; $_SESSION['messageType']='success';
        } catch (\Exception $e) {
            $_SESSION['message']=$e->getMessage(); $_SESSION['messageType']='error';
        }
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$stId); exit;
    }

    if (isset($_POST['save_c2'])) {
        $stId = (int)$_POST['stock_take_id'];
        try {
            StockTake::saveC2($stId, $_POST['c2'] ?? []);
            $_SESSION['message']='Counter 2 disimpan.'; $_SESSION['messageType']='success';
        } catch (\Exception $e) {
            $_SESSION['message']=$e->getMessage(); $_SESSION['messageType']='error';
        }
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$stId); exit;
    }

    if (isset($_POST['finish_counting'])) {
        $stId = (int)$_POST['stock_take_id'];
        try {
            StockTake::finishCounting($stId, $_POST['c2'] ?? []);
            ActivityLogger::log('FINISH_COUNTING', 'stock', 'StockTake', $stId, null, "Counter 2 selesai → Review");
            $_SESSION['message']='Counting selesai! Silakan review hasil perbandingan C1 vs C2.'; $_SESSION['messageType']='success';
        } catch (\Exception $e) {
            $_SESSION['message']=$e->getMessage(); $_SESSION['messageType']='error';
        }
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$stId); exit;
    }

    if (isset($_POST['save_review'])) {
        $stId      = (int)$_POST['stock_take_id'];
        $physicals = $_POST['qty_physical'] ?? [];
        try {
            StockTake::saveReview($stId, $physicals);
            $_SESSION['message']='Qty fisik disimpan.'; $_SESSION['messageType']='success';
        } catch (\Exception $e) {
            $_SESSION['message']=$e->getMessage(); $_SESSION['messageType']='error';
        }
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$stId); exit;
    }

    if (isset($_POST['apply_adjustment'])) {
        $stId      = (int)$_POST['stock_take_id'];
        $physicals = $_POST['qty_physical'] ?? [];
        try {
            if (!empty($physicals)) StockTake::saveReview($stId, $physicals);
            StockTake::applyAdjustment($stId);
            ActivityLogger::log('APPLY_ADJUSTMENT', 'stock', 'StockTake', $stId, null, "Apply Stock Adjustment");
            $_SESSION['message']='Adjustment berhasil diterapkan ke stok!'; $_SESSION['messageType']='success';
        } catch (\Exception $e) {
            $_SESSION['message']=$e->getMessage(); $_SESSION['messageType']='error';
        }
        header('Location: '.BASE_URL.'/stocktake.php?action=view&id='.$stId); exit;
    }
}

$stockTakeList = StockTake::getAll();
$stats         = StockTake::getStats();
$products      = Product::getAll();
$stockTake     = null;
$stockTakeItems= [];

if ($id) {
    $stockTake = StockTake::getById($id);
    $stmt      = $db->prepare("SELECT sti.*, p.product_code, p.product_name
        FROM stock_take_items sti LEFT JOIN products p ON sti.product_id=p.id
        WHERE sti.stock_take_id=? ORDER BY sti.location, p.product_code");
    $stmt->execute([$id]);
    $stockTakeItems = $stmt->fetchAll();
}

$acc = ['total'=>0,'plus'=>0,'minus'=>0,'clear'=>0,'pct'=>100];
foreach ($stockTakeItems as $it) {
    if ($it['status']==='Plus') $acc['plus']+=abs($it['difference']);
    elseif ($it['status']==='Minus') $acc['minus']+=abs($it['difference']);
    else $acc['clear']+=$it['qty_physical'];
    $acc['total']+=$it['qty_physical'];
}
$acc['pct'] = $acc['total']>0 ? round($acc['clear']/$acc['total']*100,2) : 100;

/* ── Print count sheet — standalone page, auto-prints, exits before normal render ── */
if ($action === 'print' && $id && $stockTake):
    $printItems = $db->prepare("SELECT sti.location, sti.batch_number, sti.uom,
            p.product_code, p.product_name
            FROM stock_take_items sti
            LEFT JOIN products p ON p.id = sti.product_id
            WHERE sti.stock_take_id = ?
            ORDER BY sti.location, p.product_code");
    $printItems->execute([$id]);
    $printRows = $printItems->fetchAll();
    $byLoc = [];
    foreach ($printRows as $r) {
        $byLoc[$r['location'] ?? '—'][] = $r;
    }
    $scopeArr   = $stockTake['scope_locations'] ? json_decode($stockTake['scope_locations'], true) : null;
    $scopeLabel = $scopeArr ? count($scopeArr).' lokasi: '.implode(', ', array_slice($scopeArr,0,6)).(count($scopeArr)>6?' …':'') : 'Full Warehouse';
    $printedAt  = date('d/m/Y H:i');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Count Sheet — <?=htmlspecialchars($stockTake['take_number'])?></title>
<style>
/* Remove browser URL/date headers by setting page margin to 0 */
@page {
  size: A4 portrait;
  margin: 0;
}
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
body {
  font-family: 'Arial', Helvetica, sans-serif;
  font-size: 8.5pt;
  color: #1a1a1a;
  background: #f0f0f0;
  /* screen: center the A4 sheet */
}

/* ── A4 sheet container (screen view) ── */
.sheet {
  background: #fff;
  width: 210mm;
  min-height: 297mm;
  padding: 14mm 14mm 16mm;
  margin: 0 auto;
}

/* ── Screen-only toolbar ── */
#toolbar {
  position: sticky; top: 0; z-index: 9999;
  background: #111827; color: #f9fafb;
  padding: 10px 20px;
  display: flex; align-items: center; justify-content: space-between;
  font-size: 9.5pt;
  box-shadow: 0 2px 6px rgba(0,0,0,.35);
}
#toolbar .tb-info { display: flex; align-items: center; gap: 10px; }
#toolbar .tb-badge {
  background: rgba(255,255,255,.12); border-radius: 4px;
  padding: 2px 8px; font-size: 8pt;
}
#toolbar button {
  background: #fff; color: #111827; border: none;
  padding: 7px 22px; border-radius: 6px;
  font-size: 9pt; font-weight: 700; cursor: pointer;
  display: flex; align-items: center; gap: 6px;
}
#toolbar button:hover { background: #e5e7eb; }

/* ── Document header ── */
.doc-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding-bottom: 8px;
  border-bottom: 2pt solid #1a1a1a;
  margin-bottom: 8px;
}
.doc-brand {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.doc-title {
  font-size: 14pt;
  font-weight: 900;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  color: #1a1a1a;
}
.doc-subtitle {
  font-size: 7.5pt;
  color: #555;
}
.doc-right {
  text-align: right;
}
.doc-no {
  font-family: 'Courier New', monospace;
  font-size: 12pt;
  font-weight: 700;
  color: #1a1a1a;
}
.doc-ts {
  font-size: 7pt;
  color: #888;
  margin-top: 2px;
}

/* ── Meta info bar ── */
.doc-meta {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 3px 16px;
  font-size: 7.5pt;
  background: #f8f8f8;
  border: 0.5pt solid #ddd;
  border-radius: 3px;
  padding: 6px 10px;
  margin-bottom: 8px;
}
.doc-meta-item { display: flex; gap: 4px; }
.doc-meta-item .lbl { color: #666; white-space: nowrap; }
.doc-meta-item .val { font-weight: 700; color: #1a1a1a; }

/* ── Blind notice ── */
.blind-bar {
  background: #1a1a1a;
  color: #fff;
  text-align: center;
  padding: 5px 10px;
  font-size: 7.5pt;
  font-weight: 700;
  letter-spacing: 0.5px;
  border-radius: 2px;
  margin-bottom: 10px;
}

/* ── Location blocks ── */
.loc-block {
  margin-bottom: 10px;
  page-break-inside: avoid;
  border: 0.5pt solid #d0d0d0;
  border-radius: 2px;
  overflow: hidden;
}
.loc-bar {
  background: #2d3748;
  color: #fff;
  padding: 4px 10px;
  font-family: 'Courier New', monospace;
  font-size: 9.5pt;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: space-between;
  letter-spacing: 0.5px;
}
.loc-bar .loc-meta {
  font-family: Arial, sans-serif;
  font-size: 7pt;
  font-weight: 400;
  opacity: 0.7;
}

/* ── Count table ── */
table.ct {
  width: 100%;
  border-collapse: collapse;
  font-size: 8pt;
}
table.ct thead th {
  background: #f3f4f6;
  border-bottom: 1pt solid #aaa;
  border-right: 0.5pt solid #ccc;
  padding: 4px 6px;
  text-align: left;
  font-size: 7.5pt;
  font-weight: 700;
  white-space: nowrap;
  color: #374151;
}
table.ct thead th:last-child { border-right: none; }
table.ct tbody td {
  border-bottom: 0.5pt solid #e5e7eb;
  border-right: 0.5pt solid #e5e7eb;
  padding: 5px 6px;
  vertical-align: middle;
}
table.ct tbody td:last-child { border-right: none; }
table.ct tbody tr:last-child td { border-bottom: none; }
table.ct tbody tr:nth-child(odd) td { background: #fff; }
table.ct tbody tr:nth-child(even) td { background: #fafafa; }

/* Column widths */
.td-no  { width: 22px; text-align: center; color: #9ca3af; font-size: 7.5pt; }
.td-sku { width: 80px; font-family: 'Courier New', monospace; font-size: 7.5pt; font-weight: 700; color: #111; }
.td-bat { width: 72px; font-family: 'Courier New', monospace; font-size: 7.5pt; color: #374151; }
.td-uom { width: 38px; text-align: center; font-size: 7.5pt; color: #6b7280; }
.td-cnt {
  width: 58px; text-align: center; height: 24px;
  background: #fffbeb !important;
  border-right: 0.5pt solid #d4a017 !important;
}
.td-c3  { width: 50px; text-align: center; background: #f9fafb !important; color: #9ca3af; }
.td-ket { width: 80px; }

/* ── Signature block ── */
.sig-block {
  margin-top: 16px;
  padding-top: 10px;
  border-top: 1pt solid #aaa;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 20px;
}
.sig-box { }
.sig-role {
  font-size: 7.5pt;
  font-weight: 700;
  color: #374151;
  margin-bottom: 28px;
}
.sig-line {
  border-bottom: 0.75pt solid #555;
}
.sig-hint {
  font-size: 7pt;
  color: #9ca3af;
  margin-top: 4px;
}

/* ── Print mode ── */
@media print {
  body { background: #fff; }
  #toolbar { display: none !important; }
  .sheet {
    width: 100%;
    min-height: unset;
    padding: 14mm 14mm 16mm;
    margin: 0;
  }
  .loc-block { page-break-inside: avoid; }
}
@media screen {
  body { padding: 0 0 30px; }
}
</style>
</head>
<body>

<!-- Screen toolbar — hidden on print -->
<div id="toolbar">
  <div class="tb-info">
    <strong>Count Sheet</strong>
    <span class="tb-badge"><?=htmlspecialchars($stockTake['take_number'])?></span>
    <span class="tb-badge"><?=count($printRows)?> item &nbsp;/&nbsp; <?=count($byLoc)?> lokasi</span>
  </div>
  <button onclick="window.print()">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Cetak Sekarang
  </button>
</div>

<!-- A4 sheet -->
<div class="sheet">

  <!-- ── Header ── -->
  <div class="doc-header">
    <div class="doc-brand">
      <div class="doc-title">Stock Count Sheet</div>
      <div class="doc-subtitle">Shell CKB Warehouse &mdash; Physical Inventory Count</div>
    </div>
    <div class="doc-right">
      <div class="doc-no"><?=htmlspecialchars($stockTake['take_number'])?></div>
      <div class="doc-ts">Dicetak: <?=$printedAt?></div>
    </div>
  </div>

  <!-- ── Meta info ── -->
  <div class="doc-meta">
    <div class="doc-meta-item">
      <span class="lbl">Tanggal Hitung:</span>
      <span class="val"><?=date('d F Y', strtotime($stockTake['take_date']))?></span>
    </div>
    <div class="doc-meta-item">
      <span class="lbl">Scope:</span>
      <span class="val"><?=htmlspecialchars($scopeLabel)?></span>
    </div>
    <div class="doc-meta-item">
      <span class="lbl">Total:</span>
      <span class="val"><?=count($printRows)?> item / <?=count($byLoc)?> lokasi</span>
    </div>
    <div class="doc-meta-item">
      <span class="lbl">Dibuat Oleh:</span>
      <span class="val"><?=htmlspecialchars($stockTake['created_by_name'] ?? '—')?></span>
    </div>
    <div class="doc-meta-item">
      <span class="lbl">Status:</span>
      <span class="val"><?=htmlspecialchars($stockTake['status'])?></span>
    </div>
    <?php if($stockTake['notes']): ?>
    <div class="doc-meta-item">
      <span class="lbl">Catatan:</span>
      <span class="val"><?=htmlspecialchars($stockTake['notes'])?></span>
    </div>
    <?php endif; ?>
  </div>

  <!-- ── Blind notice ── -->
  <div class="blind-bar">
    &#9888;&nbsp; BLIND COUNT &mdash; JANGAN MELIHAT QTY SISTEM SEBELUM SEMUA BARIS TERISI &mdash; TULIS HITUNGAN FISIK SAJA
  </div>

  <!-- ── Location blocks ── -->
  <?php foreach ($byLoc as $loc => $locItems): ?>
  <div class="loc-block">
    <div class="loc-bar">
      <span><?=htmlspecialchars($loc)?></span>
      <span class="loc-meta"><?=count($locItems)?> item</span>
    </div>
    <table class="ct">
      <thead>
        <tr>
          <th class="td-no">No</th>
          <th class="td-sku">SKU</th>
          <th>Nama Produk</th>
          <th class="td-bat">Batch / Lot</th>
          <th class="td-uom">UOM</th>
          <th class="td-cnt">Counter 1</th>
          <th class="td-cnt">Counter 2</th>
          <th class="td-c3">C3</th>
          <th class="td-ket">Keterangan</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($locItems as $i => $it): ?>
      <tr>
        <td class="td-no"><?=$i+1?></td>
        <td class="td-sku"><?=htmlspecialchars($it['product_code']??'—')?></td>
        <td><?=htmlspecialchars($it['product_name']??'—')?></td>
        <td class="td-bat"><?=htmlspecialchars($it['batch_number']??'—')?></td>
        <td class="td-uom"><?=htmlspecialchars($it['uom']??'—')?></td>
        <td class="td-cnt"></td>
        <td class="td-cnt"></td>
        <td class="td-c3"></td>
        <td class="td-ket"></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endforeach; ?>

  <!-- ── Signature block ── -->
  <div class="sig-block">
    <div class="sig-box">
      <div class="sig-role">Counter 1</div>
      <div class="sig-line"></div>
      <div class="sig-hint">Nama &amp; Tanda Tangan</div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Counter 2</div>
      <div class="sig-line"></div>
      <div class="sig-hint">Nama &amp; Tanda Tangan</div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Supervisor / Approver</div>
      <div class="sig-line"></div>
      <div class="sig-hint">Nama &amp; Tanda Tangan</div>
    </div>
    <div class="sig-box">
      <div class="sig-role">Tanggal Hitung</div>
      <div class="sig-line"></div>
      <div class="sig-hint">DD / MM / YYYY</div>
    </div>
  </div>

</div><!-- /.sheet -->

<script>
window.addEventListener('load', function() {
  setTimeout(function() { window.print(); }, 400);
});
</script>
</body>
</html>
<?php
    exit;
endif;
/* ── end print ─────────────────────────────────────────────────────── */

require_once __DIR__ . '/includes/header.php';
?>
<style>
.gradient-purple{background:linear-gradient(135deg,#026766 0%,#014f4e 100%)}
.st-tbl{width:100%;border-collapse:collapse;font-size:.82rem}
.st-tbl th{background:#e6f7f7;padding:9px 12px;text-align:left;font-size:.71rem;font-weight:700;
           text-transform:uppercase;letter-spacing:.04em;color:#6b7280;white-space:nowrap}
.st-tbl td{padding:8px 12px;border-bottom:1px solid #f3f4f6;vertical-align:middle}
.st-tbl tr:hover td{background:#f0fbfb}
.st-tbl tfoot td{background:#e6f7f7;font-weight:700;border-top:2px solid #80d2d2}
.badge{display:inline-block;border-radius:20px;padding:2px 10px;font-size:.72rem;font-weight:700;white-space:nowrap}
.badge-plus{background:#dcfce7;color:#15803d}
.badge-minus{background:#fee2e2;color:#dc2626}
.badge-clear{background:#e0f7f7;color:#014f4e}
.badge-draft{background:#f3f4f6;color:#374151}
.badge-inprog{background:#fef9c3;color:#854d0e}
.badge-review{background:#fef3c7;color:#92400e}
.badge-done{background:#e0f7f7;color:#013d3c}
.st-lbl{display:block;font-size:.72rem;font-weight:700;color:#374151;margin-bottom:4px}
#stProdDropdown .prod-opt{padding:9px 12px;cursor:pointer;border-bottom:1px solid #f3f4f6;font-size:.8rem}
#stProdDropdown .prod-opt:hover{background:#e6f7f7}
#stProdDropdown .prod-code{font-family:monospace;font-weight:700;color:#026766;font-size:.78rem}
#stProdDropdown .prod-name{color:#374151;margin-top:1px}
#stProdDropdown .no-result{padding:12px;text-align:center;color:#9ca3af;font-size:.82rem}
</style>

<div class="space-y-6">

<?php if ($message): ?>
<div class="alert-auto-hide rounded-lg px-4 py-3 font-medium text-sm"
     style="background:<?=$messageType==='success'?'#e0f7f7':'#e0f7f7'?>;color:<?=$messageType==='success'?'#013d3c':'#013d3c'?>">
  <i class="fas fa-<?=$messageType==='success'?'check':'times'?>-circle mr-2"></i><?=htmlspecialchars($message)?>
</div>
<?php endif;?>

<?php if ($action === 'list'): ?>

<div class="gradient-purple no-print" style="border-radius:16px;padding:28px 32px;margin-bottom:20px;box-shadow:0 4px 20px rgba(2,103,102,.3)">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:1.8rem;font-weight:800;color:#fff;margin:0 0 4px">
        <i class="fas fa-clipboard-check" style="margin-right:10px"></i>Stock Take
      </h1>
    </div>
    <?php if($canWrite):?>
    <a href="?action=create"
       style="background:#fff;color:#026766;font-weight:700;padding:9px 20px;border-radius:10px;text-decoration:none;font-size:.85rem;display:flex;align-items:center;gap:6px;transition:all .15s"
       onmouseover="this.style.background='#e6f7f7'" onmouseout="this.style.background='#fff'">
      <i class="fas fa-plus"></i> Buat Stock Take
    </a>
    <?php endif;?>
  </div>
  
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:20px">
    <div style="background:rgba(255,255,255,.18);border-radius:12px;padding:16px">
      <div style="font-size:1.8rem;font-weight:800;color:#fff"><?=$stats['total']?></div>
      <div style="color:rgba(255,255,255,.75);font-size:.8rem;margin-top:2px">Total Stock Take</div>
    </div>
    <div style="background:rgba(255,255,255,.18);border-radius:12px;padding:16px">
      <div style="font-size:1.8rem;font-weight:800;color:#fff"><?=$stats['this_month']?></div>
      <div style="color:rgba(255,255,255,.75);font-size:.8rem;margin-top:2px">Bulan Ini</div>
    </div>
    <div style="background:rgba(255,255,255,.18);border-radius:12px;padding:16px">
      <div style="font-size:1.8rem;font-weight:800;color:#fff"><?=$stats['avg_accuracy']?>%</div>
      <div style="color:rgba(255,255,255,.75);font-size:.8rem;margin-top:2px">Rata-rata Akurasi</div>
    </div>
  </div>
</div>

<div style="background:#fff;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 1px 8px rgba(0,0,0,.06);overflow:hidden">
  <div style="padding:14px 18px;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;background:#fafafa">
    <h2 style="font-weight:700;color:#374151;margin:0;display:flex;align-items:center;gap:8px;font-size:.95rem">
      <i class="fas fa-list" style="color:#026766"></i> Daftar Stock Take
    </h2>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <div style="position:relative">
        <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.8rem"></i>
        <input type="text" id="stSearch" placeholder="Cari no. / dibuat oleh..." oninput="filterSt()"
               style="padding:7px 12px 7px 30px;border:1px solid #e5e7eb;border-radius:8px;font-size:.82rem;outline:none;width:220px">
      </div>
      <select id="stStatusF" onchange="filterSt()"
              style="padding:7px 12px;border:1px solid #e5e7eb;border-radius:8px;font-size:.82rem;color:#6b7280;background:#fff;outline:none">
        <option value="">Semua Status</option>
        <option>Draft</option><option>Counting</option>
        <option>Review</option><option>Adjusted</option><option>Cancelled</option>
      </select>
    </div>
  </div>
  <div class="overflow-x-auto">
    <table class="st-tbl">
      <thead><tr>
        <th>No. Stock Take</th><th>Tanggal</th><th>Status</th>
        <th class="text-right">Items</th>
        <th class="text-right" style="color:#026766">+Plus</th>
        <th class="text-right" style="color:#026766">-Minus</th>
        <th class="text-right" style="color:#026766">Clear</th>
        <th>Dibuat Oleh</th>
        <th class="text-center">Aksi</th>
      </tr></thead>
      <tbody id="stTbody">
      <?php if(empty($stockTakeList)):?>
      <tr><td colspan="9" class="text-center py-10 text-gray-400">Belum ada Stock Take</td></tr>
      <?php else: foreach($stockTakeList as $st):
        $sb='badge-draft';
        if($st['status']==='Counting') $sb='badge-inprog';
        elseif($st['status']==='Review') $sb='badge-review';
        elseif($st['status']==='Adjusted') $sb='badge-done';
        elseif($st['status']==='Cancelled') $sb='badge-minus';
      ?>
      <tr data-no="<?=strtolower($st['take_number'])?>" data-by="<?=strtolower($st['created_by_name']??'')?>" data-status="<?=$st['status']?>">
        <td><a href="?action=view&id=<?=$st['id']?>" class="font-bold" style="color:#026766" onmouseover="this.style.color='#014f4e'" onmouseout="this.style.color='#026766'">
          <?=htmlspecialchars($st['take_number'])?>
        </a></td>
        <td class="text-gray-600"><?=date('d M Y',strtotime($st['take_date']))?></td>
        <td><span class="badge <?=$sb?>"><?=$st['status']?></span></td>
        <td class="text-right"><?=$st['total_items']?></td>
        <td class="text-right font-semibold" style="color:#026766"><?=$st['plus_count']?></td>
        <td class="text-right font-semibold" style="color:#026766"><?=$st['minus_count']?></td>
        <td class="text-right" style="color:#026766"><?=$st['clear_count']?></td>
        <td class="text-sm text-gray-500"><?=htmlspecialchars($st['created_by_name']??'—')?></td>
        <td class="text-center">
          <a href="?action=view&id=<?=$st['id']?>" class="mr-3" style="color:#026766" onmouseover="this.style.color='#014f4e'" onmouseout="this.style.color='#026766'">
            <i class="fas fa-eye"></i></a>
          <a href="?action=export&id=<?=$st['id']?>" class="text-green-600 hover:text-green-800 mr-3" title="Export Excel">
            <i class="fas fa-file-excel"></i></a>
          <?php if($canWrite):?>
          <form method="POST" style="display:inline" onsubmit="return confirm('Hapus stock take ini?')">
            <input type="hidden" name="id" value="<?=$st['id']?>">
            <button type="submit" name="delete_stocktake" class="text-red-400 hover:text-red-600">
              <i class="fas fa-trash"></i></button>
          </form>
          <?php endif;?>
        </td>
      </tr>
      <?php endforeach; endif;?>
      </tbody>
    </table>
  </div>
</div>

<?php elseif ($action === 'create'): ?>

<div class="gradient-purple no-print" style="border-radius:16px;padding:22px 28px;margin-bottom:16px;box-shadow:0 4px 20px rgba(2,103,102,.3)">
  <h1 style="font-size:1.5rem;font-weight:800;color:#fff;margin:0"><i class="fas fa-plus mr-2"></i>Buat Stock Take Baru</h1>
</div>

<div style="background:#fff;border-radius:14px;border:1px solid #e5e7eb;box-shadow:0 1px 8px rgba(0,0,0,.06);padding:24px;max-width:760px">
  <form method="POST" id="createStForm" onsubmit="return stCreateValidate()">
    <input type="hidden" name="scope_locations" id="scopeLocJson" value="">

    <div class="grid grid-cols-2 gap-4 mb-4">
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Tanggal *</label>
        <input type="date" name="take_date" value="<?=date('Y-m-d')?>" required
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-teal-600">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Catatan</label>
        <input type="text" name="notes" placeholder="Misal: Cycle Count Aisle CA, Counter: Ilham & Diana"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:border-teal-600">
      </div>
    </div>

    <!-- Scope lokasi -->
    <div class="mb-4" style="border:1px solid #b2e0e0;border-radius:10px;overflow:hidden">
      <div style="background:#e0f7f7;padding:10px 14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <span style="font-weight:700;font-size:.83rem;color:#014f4e"><i class="fas fa-map-marker-alt mr-1"></i>Scope Lokasi</span>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.82rem">
          <input type="radio" name="scope_type" value="full" checked onchange="stScopeToggle('full')" style="accent-color:#026766">
          <span>Full Warehouse (semua lokasi aktif)</span>
        </label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:.82rem">
          <input type="radio" name="scope_type" value="location" onchange="stScopeToggle('location')" style="accent-color:#026766">
          <span>Pilih Lokasi Tertentu</span>
        </label>
      </div>

      <div id="stLocPickerWrap" style="display:none;padding:12px 14px">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap">
          <div style="position:relative;flex:1;min-width:180px">
            <i class="fas fa-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#9ca3af;font-size:.75rem"></i>
            <input type="text" id="stLocSearch" placeholder="Cari kode lokasi..."
                   oninput="stFilterLocs()"
                   style="width:100%;padding:6px 10px 6px 28px;border:1px solid #d1d5db;border-radius:7px;font-size:.8rem;outline:none">
          </div>
          <button type="button" onclick="stSelectAllLocs()" style="background:#026766;color:#fff;border:none;padding:6px 12px;border-radius:7px;font-size:.78rem;cursor:pointer;white-space:nowrap">
            Pilih Semua
          </button>
          <button type="button" onclick="stClearLocs()" style="background:#f3f4f6;color:#374151;border:none;padding:6px 12px;border-radius:7px;font-size:.78rem;cursor:pointer;white-space:nowrap">
            Hapus Semua
          </button>
          <span id="stLocCount" style="font-size:.78rem;font-weight:700;color:#026766;white-space:nowrap"></span>
        </div>

        <div id="stLocGroupsWrap" style="max-height:260px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:8px;padding:6px 8px;background:#fafafa">
          <div style="color:#9ca3af;font-size:.8rem;text-align:center;padding:20px">
            <i class="fas fa-spinner fa-spin mr-1"></i> Memuat lokasi...
          </div>
        </div>

        <!-- Selected chips -->
        <div id="stLocChips" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px;min-height:24px"></div>
        <div id="stLocError" style="display:none;color:#dc2626;font-size:.78rem;margin-top:4px">
          <i class="fas fa-exclamation-circle mr-1"></i>Pilih minimal 1 lokasi.
        </div>
      </div>
    </div>

    <!-- Auto-load -->
    <div class="mb-5 rounded-lg p-3" style="background:#f0f9f9;border:1px solid #b2e0e0">
      <label class="flex items-center gap-2 cursor-pointer text-sm font-semibold text-gray-700">
        <input type="checkbox" name="auto_load" value="1" class="w-4 h-4" style="accent-color:#026766">
        Auto-load stok dari lokasi terpilih sebagai item awal
      </label>
      <p class="text-xs text-gray-500 mt-1 ml-6">Qty System di-snapshot otomatis. Counter 1 &amp; 2 diisi manual saat Counting.</p>
    </div>

    <div class="flex gap-3">
      <button type="submit" name="create_stocktake"
              style="background:#026766;color:#fff;padding:9px 22px;border-radius:9px;font-weight:700;font-size:.85rem;border:none;cursor:pointer"
              onmouseover="this.style.background='#014f4e'" onmouseout="this.style.background='#026766'">
        <i class="fas fa-save mr-2"></i>Buat Stock Take
      </button>
      <a href="?action=list" style="background:#f3f4f6;color:#374151;padding:9px 18px;border-radius:9px;font-weight:600;font-size:.85rem;text-decoration:none">
        Batal
      </a>
    </div>
  </form>
</div>

<script>
let _stAllLocs = {}; // { aisle: [{code,qty,prods,...}] }
let _stSelected = new Set();

function stScopeToggle(type) {
  const wrap = document.getElementById('stLocPickerWrap');
  wrap.style.display = type === 'location' ? 'block' : 'none';
  if (type === 'location' && Object.keys(_stAllLocs).length === 0) stLoadLocs();
  stSyncJson();
}

function stLoadLocs() {
  const wrap = document.getElementById('stLocGroupsWrap');
  fetch('stocktake_api.php?action=get_scope_locations')
    .then(r => r.json())
    .then(d => {
      if (!d.success) { wrap.innerHTML = '<div style="color:#dc2626;padding:10px;font-size:.8rem">Gagal memuat lokasi</div>'; return; }
      _stAllLocs = d.grouped;
      stRenderGroups();
      document.getElementById('stLocCount').textContent = `${_stSelected.size} / ${d.total} dipilih`;
    });
}

function stRenderGroups(filter='') {
  const wrap  = document.getElementById('stLocGroupsWrap');
  const fLow  = filter.toLowerCase();
  let html = '';
  for (const [aisle, locs] of Object.entries(_stAllLocs)) {
    const visible = locs.filter(l => !fLow || l.code.toLowerCase().includes(fLow));
    if (!visible.length) continue;
    const allSel  = visible.every(l => _stSelected.has(l.code));
    html += `<div style="margin-bottom:6px">
      <div style="display:flex;align-items:center;gap:6px;padding:3px 4px;cursor:pointer;border-radius:4px"
           onclick="stToggleAisle('${aisle}',${JSON.stringify(visible.map(l=>l.code))})">
        <input type="checkbox" ${allSel?'checked':''} style="accent-color:#026766;pointer-events:none" onclick="return false">
        <span style="font-size:.75rem;font-weight:700;color:#026766;text-transform:uppercase">Aisle ${aisle}</span>
        <span style="font-size:.7rem;color:#9ca3af">(${visible.length} lok)</span>
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:3px;padding-left:18px">`;
    for (const l of visible) {
      const sel = _stSelected.has(l.code);
      html += `<div class="stloc-chip" data-code="${l.code}"
            onclick="stToggleLoc('${l.code}')"
            title="Qty:${l.qty} | ${l.prods} produk"
            style="padding:3px 8px;border-radius:5px;font-size:.72rem;font-weight:600;cursor:pointer;
                   font-family:monospace;border:1px solid ${sel?'#026766':'#d1d5db'};
                   background:${sel?'#e0f7f7':'#fff'};color:${sel?'#014f4e':'#374151'};
                   user-select:none">
          ${l.code}${l.qty>0?'<span style="font-size:.65rem;color:#9ca3af;margin-left:2px;font-family:sans-serif">'+Math.round(l.qty)+'</span>':''}
        </div>`;
    }
    html += `</div></div>`;
  }
  wrap.innerHTML = html || '<div style="color:#9ca3af;padding:10px;font-size:.8rem;text-align:center">Tidak ada lokasi ditemukan</div>';
}

function stToggleLoc(code) {
  if (_stSelected.has(code)) _stSelected.delete(code);
  else _stSelected.add(code);
  stAfterToggle();
}

function stToggleAisle(aisle, codes) {
  const allSel = codes.every(c => _stSelected.has(c));
  codes.forEach(c => allSel ? _stSelected.delete(c) : _stSelected.add(c));
  stAfterToggle();
}

function stSelectAllLocs() {
  for (const locs of Object.values(_stAllLocs)) locs.forEach(l => _stSelected.add(l.code));
  stAfterToggle();
}

function stClearLocs() {
  _stSelected.clear();
  stAfterToggle();
}

function stAfterToggle() {
  const filter = document.getElementById('stLocSearch')?.value || '';
  stRenderGroups(filter);
  stSyncChips();
  stSyncJson();
  // update count
  let total = 0;
  for (const l of Object.values(_stAllLocs)) total += l.length;
  document.getElementById('stLocCount').textContent = `${_stSelected.size} / ${total} dipilih`;
}

function stSyncChips() {
  const el = document.getElementById('stLocChips');
  if (!el) return;
  const codes = [..._stSelected].sort();
  el.innerHTML = codes.map(c =>
    `<span style="background:#026766;color:#fff;padding:2px 8px 2px 6px;border-radius:4px;font-size:.7rem;font-family:monospace;display:flex;align-items:center;gap:4px">
      ${c}<span onclick="stToggleLoc('${c}')" style="cursor:pointer;opacity:.7;font-family:sans-serif">×</span>
    </span>`
  ).join('');
}

function stSyncJson() {
  const type = document.querySelector('[name="scope_type"]:checked')?.value;
  document.getElementById('scopeLocJson').value = (type === 'location' && _stSelected.size > 0)
    ? JSON.stringify([..._stSelected].sort()) : '';
}

function stFilterLocs() {
  stRenderGroups(document.getElementById('stLocSearch').value);
}

function stCreateValidate() {
  const type = document.querySelector('[name="scope_type"]:checked')?.value;
  if (type === 'location' && _stSelected.size === 0) {
    document.getElementById('stLocError').style.display = 'block';
    document.getElementById('stLocGroupsWrap').scrollIntoView({behavior:'smooth'});
    return false;
  }
  document.getElementById('stLocError').style.display = 'none';
  stSyncJson();
  return true;
}
</script>

<?php elseif ($action === 'view' && $stockTake): ?>

<?php
$sb='badge-draft';
if($stockTake['status']==='Counting') $sb='badge-inprog';
elseif($stockTake['status']==='Review') $sb='badge-review';
elseif($stockTake['status']==='Adjusted') $sb='badge-done';
elseif($stockTake['status']==='Cancelled') $sb='badge-minus';
$totalItems = count($stockTakeItems);
$stStatus   = $stockTake['status'];
$steps = ['Draft'=>1,'Counting'=>2,'Review'=>3,'Adjusted'=>4];
$curStep = $steps[$stStatus] ?? 0;
?>

<div class="gradient-purple no-print" style="border-radius:16px;padding:22px 28px;margin-bottom:16px;box-shadow:0 4px 20px rgba(2,103,102,.3)">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-size:1.4rem;font-weight:800;color:#fff;margin:0;display:flex;align-items:center;gap:10px">
        <i class="fas fa-clipboard-check"></i>
        <?=htmlspecialchars($stockTake['take_number'])?>
        <span class="badge <?=$sb?>"><?=$stockTake['status']?></span>
      </h1>
      <p style="color:rgba(255,255,255,.8);font-size:.82rem;margin:4px 0 0">
        <?=date('d F Y',strtotime($stockTake['take_date']))?>
        <?php if($stockTake['notes']):?> &nbsp;·&nbsp; <?=htmlspecialchars($stockTake['notes'])?><?php endif;?>
      </p>
      <?php
        $viewScope = $stockTake['scope_locations'] ? json_decode($stockTake['scope_locations'], true) : null;
        $viewScopeType = $stockTake['scope_type'] ?? 'full';
      ?>
      <p style="color:rgba(255,255,255,.65);font-size:.75rem;margin:3px 0 0">
        <i class="fas fa-map-marker-alt mr-1"></i>
        <?php if($viewScope && count($viewScope)>0): ?>
          <?=count($viewScope)?> lokasi: <?=htmlspecialchars(implode(', ', array_slice($viewScope, 0, 6)) . (count($viewScope)>6?' ...':''))?>
        <?php else: ?>
          Full Warehouse
        <?php endif; ?>
      </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
      <?php if($canWrite && $stStatus==='Draft'):?>
      <form method="POST" style="display:inline" onsubmit="return confirm('Mulai Counting?')">
        <input type="hidden" name="id" value="<?=$stockTake['id']?>">
        <button type="submit" name="start_counting"
                style="background:#fff;color:#026766;font-weight:700;padding:8px 16px;border-radius:10px;border:none;cursor:pointer;font-size:.83rem;display:flex;align-items:center;gap:6px">
          <i class="fas fa-play"></i>Mulai Counting
        </button>
      </form>
      <?php endif;?>
      <?php if($canWrite && $stStatus==='Adjusted'):?>
      <a href="?action=export&id=<?=$stockTake['id']?>"
         style="background:#fff;color:#026766;font-weight:700;padding:8px 16px;border-radius:10px;text-decoration:none;font-size:.83rem;display:flex;align-items:center;gap:6px">
        <i class="fas fa-file-excel"></i>Export Excel
      </a>
      <?php endif;?>
      <?php if(in_array($stStatus,['Draft','Counting'])):?>
      <a href="?action=print&id=<?=$stockTake['id']?>" target="_blank"
         style="background:rgba(255,255,255,.15);color:#fff;font-weight:600;padding:8px 16px;border-radius:10px;text-decoration:none;font-size:.83rem;display:flex;align-items:center;gap:6px">
        <i class="fas fa-print"></i>Count Sheet
      </a>
      <?php endif;?>
      <?php if($stStatus!=='Adjusted'):?>
      <a href="?action=export&id=<?=$stockTake['id']?>"
         style="background:rgba(255,255,255,.15);color:#fff;font-weight:600;padding:8px 16px;border-radius:10px;text-decoration:none;font-size:.83rem;display:flex;align-items:center;gap:6px">
        <i class="fas fa-file-excel"></i>Export
      </a>
      <?php endif;?>
      <a href="?action=list"
         style="background:rgba(255,255,255,.2);color:#fff;font-weight:600;padding:8px 16px;border-radius:10px;text-decoration:none;font-size:.83rem">
        <i class="fas fa-arrow-left mr-1"></i>Kembali
      </a>
    </div>
  </div>
  
  <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-top:16px">
    <?php
    $kpis=[
      [$totalItems,'Total Lokasi'],
      [number_format($acc['total'],0),'Total Qty Fisik'],
      [$acc['clear'],'Clear ✓'],
      ['+'.$acc['plus'],'+Plus (Lebih)'],
      ['-'.$acc['minus'],'−Minus (Kurang)'],
      [$acc['pct'].'%','Akurasi'],
    ];
    foreach($kpis as $k):?>
    <div style="background:rgba(255,255,255,.18);border-radius:10px;padding:12px;text-align:center">
      <div style="font-size:1.3rem;font-weight:800;color:#fff"><?=$k[0]?></div>
      <div style="color:rgba(255,255,255,.75);font-size:.7rem;margin-top:2px"><?=$k[1]?></div>
    </div>
    <?php endforeach;?>
  </div>
</div>

<?php
/* ── Workflow stepper ──────────────────────────────────────────────── */
$stepDefs = [
    1 => ['label'=>'Draft',    'icon'=>'fa-pencil-alt',    'desc'=>'Siapkan daftar item'],
    2 => ['label'=>'Counting', 'icon'=>'fa-calculator',    'desc'=>'Input counter C1 & C2'],
    3 => ['label'=>'Review',   'icon'=>'fa-search',        'desc'=>'Verifikasi selisih'],
    4 => ['label'=>'Adjusted', 'icon'=>'fa-check-double',  'desc'=>'Stok diperbarui'],
];
?>
<div style="background:#fff;border-radius:14px;border:1px solid #e5e7eb;padding:16px 20px;box-shadow:0 1px 6px rgba(0,0,0,.05)">
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;position:relative">
    <?php foreach($stepDefs as $sn=>$sd):
      $done    = $curStep > $sn;
      $active  = $curStep === $sn;
      $pending = $curStep < $sn;
      $ic  = $done ? '#fff' : ($active ? '#fff' : '#9ca3af');
      $bg  = $done ? '#026766' : ($active ? '#014f4e' : '#f3f4f6');
      $tc  = $done ? '#026766' : ($active ? '#014f4e' : '#9ca3af');
    ?>
    <div style="display:flex;flex-direction:column;align-items:center;text-align:center;position:relative;<?=$sn<4?'padding-right:0':''?>">
      <?php if($sn<4):?>
      <div style="position:absolute;top:18px;left:50%;right:-50%;height:2px;background:<?=$done?'#026766':'#e5e7eb'?>;z-index:0"></div>
      <?php endif;?>
      <div style="width:36px;height:36px;border-radius:50%;background:<?=$bg?>;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;box-shadow:<?=$active?'0 0 0 3px rgba(2,103,102,.25)':''?>">
        <i class="fas <?=$done?'fa-check':$sd['icon']?>" style="color:<?=$ic?>;font-size:.75rem"></i>
      </div>
      <div style="margin-top:6px;font-size:.72rem;font-weight:<?=$active||$done?'700':'500'?>;color:<?=$tc?>"><?=$sd['label']?></div>
      <div style="font-size:.63rem;color:#9ca3af;margin-top:1px"><?=$sd['desc']?></div>
    </div>
    <?php endforeach;?>
  </div>
</div>

<?php if($canWrite && $stStatus==='Draft'):?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100">
  <div class="p-4 border-b border-gray-100 flex items-center justify-between">
    <h3 class="font-bold text-gray-700 flex items-center gap-2">
      <i class="fas fa-plus" style="color:#026766"></i>Tambah Item Hitungan
    </h3>
    <span class="text-xs text-gray-400">Cari produk → pilih lokasi → simpan daftar item (hitungan diisi saat Counting)</span>
  </div>
  <div class="p-5">
  <form method="POST" id="stAddForm">
    <input type="hidden" name="stock_take_id" value="<?=$stockTake['id']?>">
    <input type="hidden" name="product_id" id="stProductId">
    <input type="hidden" name="uom" id="stUomHidden">

    
    <div class="grid grid-cols-4 gap-3 mb-3">
      <div class="col-span-2">
        <label class="st-lbl">Produk *</label>
        <div class="relative">
          <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
          <input type="text" id="stProdSearch" placeholder="Ketik kode atau nama produk..."
                 autocomplete="off"
                 class="w-full pl-9 pr-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:border-teal-600"
                 oninput="stSearch(this.value)" onfocus="stSearch(this.value)">
          <div id="stProdDropdown"
               style="display:none;position:absolute;top:100%;left:0;right:0;z-index:9999;
                      background:#fff;border:1px solid #80d2d2;border-radius:10px;
                      box-shadow:0 8px 24px rgba(2,103,102,.15);max-height:250px;overflow-y:auto;margin-top:3px">
          </div>
        </div>
        <div id="stProdChip" style="display:none"
             class="mt-2 rounded-lg px-3 py-2 text-sm flex items-center justify-between" style="background:#f0f9f9;border:1px solid #b2e0e0">
          <span id="stProdChipLabel" class="font-semibold" style="color:#026766"></span>
          <button type="button" onclick="stClear()" class="text-gray-400 hover:text-red-500 ml-2 text-xs">✕ Ganti</button>
        </div>
      </div>
      <div>
        <label class="st-lbl">Lokasi</label>
        <input type="text" name="location" id="stLocInput" placeholder="CA01B01"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono uppercase outline-none focus:border-teal-600"
               oninput="this.value=this.value.toUpperCase()" onchange="stGetQty()">
        
        <div id="stLocSuggest" style="display:none;margin-top:4px;background:#e6f7f7;border:1px solid #80d2d2;
             border-radius:8px;padding:6px 8px;max-height:160px;overflow-y:auto">
          <div style="font-size:.68rem;font-weight:700;color:#026766;margin-bottom:4px">
            <i class="fas fa-map-marker-alt mr-1"></i>Lokasi dari sistem:
          </div>
          <div id="stLocList"></div>
        </div>
      </div>
      <div>
        <label class="st-lbl">
          Qty System
          <button type="button" onclick="stGetQty()" class="text-xs ml-1" style="color:#026766"><i class="fas fa-sync-alt"></i></button>
        </label>
        <div style="display:flex;align-items:center;gap:6px">
          <input type="number" name="qty_system" id="stQtySystem" value="0" step="0.01" readonly
                 class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm bg-gray-50 font-bold text-right outline-none"
                 style="min-width:0">
          <span id="stQtyUom" style="font-size:.78rem;font-weight:700;color:#026766;white-space:nowrap;min-width:36px"></span>
        </div>
      </div>
    </div>


    <div class="grid grid-cols-4 gap-3 items-end">
      <div>
        <label class="st-lbl">Batch No.</label>
        <input type="text" name="batch_number" id="stBatchInput" placeholder="22K25JJ"
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:border-teal-600"
               onchange="stAutoFillLocations()">
      </div>
      <div>
        <label class="st-lbl">Catatan Item <span class="text-xs text-gray-400">(opsional)</span></label>
        <input type="text" name="item_notes" placeholder="mis: lokasi titipan..."
               class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm outline-none focus:border-teal-600">
      </div>
      <div class="col-span-2 flex items-end gap-3">
        <button type="submit" name="add_item"
                class="text-white font-bold py-2 px-6 rounded-lg text-sm transition flex items-center gap-2" style="background:#026766" onmouseover="this.style.background='#014f4e'" onmouseout="this.style.background='#026766'">
          <i class="fas fa-plus"></i> Tambah ke Daftar
        </button>
        <span class="text-xs text-gray-400" style="padding-bottom:4px">
          <i class="fas fa-info-circle mr-1"></i>Counter C1/C2 diisi saat fase Counting
        </span>
      </div>
    </div>
  </form>
  </div>
</div>
<?php endif; /* end Draft add-item form */ ?>

<?php
$countingRound = $stockTake['counting_round'] ?? 'c1';
if($canWrite && $stStatus==='Counting'):
  if($countingRound === 'c1'): ?>
<div style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:10px;padding:9px 14px;display:flex;align-items:center;gap:10px">
  <div style="width:28px;height:28px;border-radius:50%;background:#f59e0b;display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <span style="color:#fff;font-weight:900;font-size:.8rem">C1</span>
  </div>
  <span style="font-weight:700;color:#92400e;font-size:.83rem">Counter 1</span>
</div>
<?php elseif($countingRound === 'c2'): ?>
<div style="background:#f0fdf4;border:1.5px solid #4ade80;border-radius:10px;padding:9px 14px;display:flex;align-items:center;gap:10px">
  <div style="width:28px;height:28px;border-radius:50%;background:#16a34a;display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <span style="color:#fff;font-weight:900;font-size:.8rem">C2</span>
  </div>
  <span style="font-weight:700;color:#14532d;font-size:.83rem">Counter 2</span>
</div>
<?php endif; endif; ?>

<?php if($canWrite && $stStatus==='Review'): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:9px 14px;display:flex;align-items:center;gap:8px">
  <i class="fas fa-search" style="color:#16a34a;font-size:.85rem"></i>
  <span style="font-weight:700;color:#14532d;font-size:.83rem">Review</span>
</div>
<?php endif; ?>

<?php if($stStatus==='Adjusted'): ?>
<div style="background:#e0f7f7;border:1px solid #80d2d2;border-radius:10px;padding:9px 14px;display:flex;align-items:center;gap:8px">
  <i class="fas fa-check-circle" style="color:#026766;font-size:.85rem"></i>
  <span style="font-weight:700;color:#014f4e;font-size:.83rem">Adjusted</span>
</div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm border border-gray-100">
  <div class="p-4 border-b border-gray-100 flex items-center justify-between flex-wrap gap-2">
    <h3 class="font-bold text-gray-700 flex items-center gap-2">
      <i class="fas fa-table" style="color:#026766"></i>
      Detail Hitungan
      <span class="text-xs font-bold px-2 py-0.5 rounded-full" style="background:#e0f7f7;color:#026766"><?=$totalItems?> lokasi</span>
    </h3>
    <div class="flex items-center gap-3 text-sm">
      <span class="badge badge-clear"><?=$acc['clear']?> Clear</span>
      <span class="badge badge-plus">+<?=$acc['plus']?> Plus</span>
      <span class="badge badge-minus">-<?=$acc['minus']?> Minus</span>
      <span class="font-bold" style="color:<?=$acc['pct']>=95?'#15803d':($acc['pct']>=85?'#f57c00':'#dc2626')?>">
        Akurasi: <?=$acc['pct']?>%
      </span>
    </div>
  </div>
  
  <div class="px-4 py-2 border-b border-gray-50 flex gap-2 flex-wrap">
    <div class="relative">
      <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
      <input type="text" id="stItemSearch" placeholder="Filter lokasi / produk..."
             oninput="filterItems()"
             class="pl-7 pr-3 py-1.5 border border-gray-200 rounded-lg text-xs outline-none focus:border-teal-600" style="width:200px">
    </div>
    <select id="stItemStatus" onchange="filterItems()"
            class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs text-gray-600 outline-none focus:border-teal-600">
      <option value="">Semua Status</option>
      <option>Clear</option><option>Plus</option><option>Minus</option>
    </select>
  </div>
<?php if($stStatus==='Counting'): ?>
  <form method="POST" id="countingForm">
    <input type="hidden" name="stock_take_id" value="<?=$stockTake['id']?>">
  <div class="overflow-x-auto">
    <table class="st-tbl">
      <thead><tr>
        <th>Lokasi</th>
        <th>SKU / Produk</th>
        <th>Batch</th>
        <th>UOM</th>
        <?php if($countingRound==='c1'): ?>
        <th class="text-center" style="background:#fffbeb;color:#92400e;min-width:110px">
          <i class="fas fa-user mr-1"></i>Counter 1 *
        </th>
        <?php else: ?>
        <th class="text-center" style="background:#f1f5f9;color:#94a3b8;min-width:80px">
          C1 <span style="font-size:.65rem;font-weight:400">(tersembunyi)</span>
        </th>
        <th class="text-center" style="background:#f0fdf4;color:#15803d;min-width:110px">
          <i class="fas fa-user mr-1"></i>Counter 2 *
        </th>
        <?php endif; ?>
        <th class="text-center" style="min-width:70px">Cek</th>
      </tr></thead>
      <tbody id="stItemsTbody">
      <?php if(empty($stockTakeItems)):?>
      <tr><td colspan="6" class="text-center py-10 text-gray-400">Belum ada item.</td></tr>
      <?php else:
        $prevLoc = null;
        foreach($stockTakeItems as $it):
          $locChanged = ($it['location'] !== $prevLoc);
          $prevLoc    = $it['location'];
      ?>
      <?php if($locChanged): ?>
      <tr style="background:#e6f7f7">
        <td colspan="<?=$countingRound==='c1'?6:7?>" style="padding:4px 12px;font-family:monospace;font-weight:800;font-size:.8rem;color:#014f4e;letter-spacing:.5px">
          <i class="fas fa-map-marker-alt mr-1" style="font-size:.7rem"></i><?=htmlspecialchars($it['location']??'—')?>
        </td>
      </tr>
      <?php endif; ?>
      <tr data-loc="<?=strtolower($it['location']??'')?>" data-prod="<?=strtolower($it['product_code']??'')?>" data-status="">
        <td style="color:#9ca3af;font-size:.75rem;padding-left:20px">↳</td>
        <td>
          <div class="font-semibold text-gray-800 text-sm"><?=htmlspecialchars($it['product_name']??'—')?></div>
          <div style="font-size:.68rem;color:#9ca3af;font-family:monospace"><?=htmlspecialchars($it['product_code']??'')?></div>
        </td>
        <td style="font-family:monospace;font-size:.78rem;color:#6b7280"><?=htmlspecialchars($it['batch_number']??'—')?></td>
        <td class="text-sm text-gray-500"><?=htmlspecialchars($it['uom']??'—')?></td>
        <?php if($countingRound==='c1'): ?>
        <td style="background:#fffbeb;padding:5px 8px;text-align:center">
          <input type="number" name="c1[<?=$it['id']?>]" step="0.01" min="0"
                 value="<?=htmlspecialchars($it['counter_1']??'')?>" placeholder="0"
                 oninput="ctCheck(<?=$it['id']?>,this.value,null)"
                 style="width:90px;text-align:center;padding:6px 4px;border:2px solid #fbbf24;border-radius:6px;font-weight:700;font-size:.85rem;outline:none;background:#fff"
                 onfocus="this.style.borderColor='#d97706'" onblur="this.style.borderColor='#fbbf24'">
        </td>
        <?php else: ?>
        <td style="background:#f1f5f9;padding:5px 8px;text-align:center">
          <span style="font-size:.78rem;color:#94a3b8;font-style:italic">●●●</span>
        </td>
        <td style="background:#f0fdf4;padding:5px 8px;text-align:center">
          <input type="number" name="c2[<?=$it['id']?>]" step="0.01" min="0"
                 value="<?=htmlspecialchars($it['counter_2']??'')?>" placeholder="0"
                 oninput="ctCheck(<?=$it['id']?>,null,this.value)"
                 style="width:90px;text-align:center;padding:6px 4px;border:2px solid #4ade80;border-radius:6px;font-weight:700;font-size:.85rem;outline:none;background:#fff"
                 onfocus="this.style.borderColor='#16a34a'" onblur="this.style.borderColor='#4ade80'">
        </td>
        <?php endif; ?>
        <td class="text-center" id="ctck_<?=$it['id']?>" style="font-size:.78rem;font-weight:700;color:#9ca3af">—</td>
      </tr>
      <?php endforeach; endif;?>
      </tbody>
      <?php if(!empty($stockTakeItems)):?>
      <tfoot>
        <tr>
          <td colspan="3"><strong>TOTAL <?=$totalItems?> item</strong></td>
          <td colspan="<?=$countingRound==='c1'?3:4?>" class="text-right" style="padding:8px">
            <?php if($countingRound==='c1'): ?>
            <button type="submit" name="save_c1" form="countingForm"
                    style="background:#f3f4f6;color:#374151;border:none;padding:7px 14px;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;margin-right:8px">
              <i class="fas fa-save mr-1"></i>Simpan C1
            </button>
            <button type="submit" name="advance_to_c2" form="countingForm"
                    onclick="return confirm('Selesaikan Counter 1 dan lanjut ke Counter 2?')"
                    style="background:#f59e0b;color:#fff;border:none;padding:7px 18px;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer">
              <i class="fas fa-arrow-right mr-1"></i>Selesai Counter 1 →
            </button>
            <?php else: ?>
            <button type="submit" name="save_c2" form="countingForm"
                    style="background:#f3f4f6;color:#374151;border:none;padding:7px 14px;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;margin-right:8px">
              <i class="fas fa-save mr-1"></i>Simpan C2
            </button>
            <button type="submit" name="finish_counting" form="countingForm"
                    onclick="return confirm('Finalisasi? Sistem akan membandingkan C1 vs C2 dan pindah ke Review.')"
                    style="background:#16a34a;color:#fff;border:none;padding:7px 18px;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer">
              <i class="fas fa-check mr-1"></i>Selesai Counter 2 → Review
            </button>
            <?php endif; ?>
          </td>
        </tr>
      </tfoot>
      <?php endif;?>
    </table>
  </div>
  </form>

<?php elseif($stStatus==='Review'): ?>
  <form method="POST" id="reviewForm">
    <input type="hidden" name="stock_take_id" value="<?=$stockTake['id']?>">
  <div class="overflow-x-auto">
    <table class="st-tbl">
      <thead><tr>
        <th>Lokasi</th>
        <th>SKU / Produk</th>
        <th>Batch</th>
        <th>UOM</th>
        <th class="text-right">Qty System</th>
        <th class="text-right" style="color:#6b7280">C1</th>
        <th class="text-right" style="color:#6b7280">C2</th>
        <th class="text-right" style="color:#6b7280">C3</th>
        <th class="text-right" style="color:#026766;min-width:100px">Qty Fisik</th>
        <th class="text-right">Selisih</th>
        <th class="text-center">Status</th>
      </tr></thead>
      <tbody id="stItemsTbody">
      <?php if(empty($stockTakeItems)):?>
      <tr><td colspan="11" class="text-center py-8 text-gray-400">Belum ada item.</td></tr>
      <?php else: foreach($stockTakeItems as $it):
        $bd='badge-clear';
        if($it['status']==='Plus') $bd='badge-plus';
        elseif($it['status']==='Minus') $bd='badge-minus';
        $diff    = floatval($it['difference']);
        $dc      = $diff>0?'#15803d':($diff<0?'#dc2626':'#6b7280');
        $rowbg   = $diff>0?'background:#f0fdf4':($diff<0?'background:#fef2f2':'');
        $needsC3 = ($it['counter_1']!==null && $it['counter_2']!==null && abs(floatval($it['counter_1'])-floatval($it['counter_2']))>0.001);
      ?>
      <tr style="<?=$rowbg?>" data-loc="<?=strtolower($it['location']??'')?>"
          data-prod="<?=strtolower($it['product_code']??'')?>" data-status="<?=$it['status']?>">
        <td style="font-family:monospace;font-weight:700;color:#026766"><?=htmlspecialchars($it['location']??'—')?></td>
        <td>
          <div class="font-semibold text-gray-800 text-sm"><?=htmlspecialchars($it['product_name']??'—')?></div>
          <div style="font-size:.68rem;color:#9ca3af;font-family:monospace"><?=htmlspecialchars($it['product_code']??'')?></div>
        </td>
        <td style="font-family:monospace;font-size:.78rem"><?=htmlspecialchars($it['batch_number']??'—')?></td>
        <td class="text-sm text-gray-500"><?=htmlspecialchars($it['uom']??'—')?></td>
        <td class="text-right text-gray-500"><?=number_format($it['qty_system'],0)?></td>
        <td class="text-right text-gray-400 text-xs"><?=$it['counter_1']!==null?number_format($it['counter_1'],0):'—'?></td>
        <td class="text-right text-gray-400 text-xs"><?=$it['counter_2']!==null?number_format($it['counter_2'],0):'—'?></td>
        <td class="text-right text-xs" style="color:<?=$needsC3?'#d97706':'#9ca3af'?>">
          <?php if($needsC3): ?>
          <span title="C1≠C2 — tiebreaker">⚠ <?=$it['counter_3']!==null?number_format($it['counter_3'],0):'—'?></span>
          <?php else: ?>
          <?=$it['counter_3']!==null?number_format($it['counter_3'],0):'—'?>
          <?php endif; ?>
        </td>
        <td style="background:#e0f7f7;padding:5px 8px">
          <input type="number" name="qty_physical[<?=$it['id']?>]"
                 value="<?=htmlspecialchars($it['qty_physical']??0)?>"
                 step="0.01" min="0"
                 style="width:80px;text-align:right;padding:4px 6px;border:2px solid #80d2d2;border-radius:6px;font-weight:700;font-size:.82rem;outline:none;background:#fff"
                 onfocus="this.style.borderColor='#026766'" onblur="this.style.borderColor='#80d2d2'">
        </td>
        <td class="text-right font-bold" style="color:<?=$dc?>">
          <?=($diff>0?'▲ +':($diff<0?'▼ ':'')).number_format($diff,0)?>
        </td>
        <td class="text-center"><span class="badge <?=$bd?>"><?=$it['status']?></span></td>
      </tr>
      <?php endforeach; endif;?>
      </tbody>
      <?php if(!empty($stockTakeItems)):
        $totSys  = array_sum(array_column($stockTakeItems,'qty_system'));
        $totPhys = array_sum(array_column($stockTakeItems,'qty_physical'));
        $totDiff = array_sum(array_column($stockTakeItems,'difference'));
      ?>
      <tfoot>
        <tr>
          <td colspan="4"><strong>TOTAL</strong></td>
          <td class="text-right"><?=number_format($totSys,0)?></td>
          <td colspan="3"></td>
          <td class="text-right" style="color:#026766"><?=number_format($totPhys,0)?></td>
          <td class="text-right font-bold" style="color:<?=$totDiff>0?'#15803d':($totDiff<0?'#dc2626':'#6b7280')?>">
            <?=($totDiff>0?'▲ +':($totDiff<0?'▼ ':'')).number_format($totDiff,0)?>
          </td>
          <td class="text-right">
            <button type="submit" name="save_review" form="reviewForm"
                    style="background:#f3f4f6;color:#374151;border:none;padding:7px 14px;border-radius:8px;font-size:.8rem;font-weight:600;cursor:pointer;margin-right:8px">
              <i class="fas fa-save mr-1"></i>Simpan
            </button>
            <button type="submit" name="apply_adjustment" form="reviewForm"
                    onclick="return confirm('Apply adjustment? Stok akan diperbarui.')"
                    style="background:#dc2626;color:#fff;border:none;padding:7px 18px;border-radius:8px;font-size:.8rem;font-weight:700;cursor:pointer">
              <i class="fas fa-check-double mr-1"></i>Apply Adjustment
            </button>
          </td>
        </tr>
      </tfoot>
      <?php endif;?>
    </table>
  </div>
  </form>

<?php else: /* Draft or Adjusted — read-only table */ ?>
  <div class="overflow-x-auto">
    <table class="st-tbl">
      <thead><tr>
        <th>Lokasi</th>
        <th>SKU / Produk</th>
        <th>Batch</th>
        <th>UOM</th>
        <th class="text-right">Qty System</th>
        <?php if($stStatus!=='Draft'):?>
        <th class="text-right" style="color:#026766">C1</th>
        <th class="text-right" style="color:#026766">C2</th>
        <th class="text-right" style="color:#6b7280">C3</th>
        <th class="text-right">Qty Fisik</th>
        <th class="text-right">Selisih</th>
        <th class="text-center">Status</th>
        <?php endif;?>
        <?php if($canWrite && $stStatus==='Draft'):?>
        <th class="text-center">Del</th>
        <?php endif;?>
      </tr></thead>
      <tbody id="stItemsTbody">
      <?php if(empty($stockTakeItems)):?>
      <tr><td colspan="<?=$stStatus==='Draft'?7:12?>" class="text-center py-10 text-gray-400">
        <i class="fas fa-clipboard text-4xl mb-3 block opacity-30"></i>
        Belum ada item. Tambah di atas atau gunakan Auto-load saat membuat.
      </td></tr>
      <?php else: foreach($stockTakeItems as $it):
        $bd='badge-clear';
        if($it['status']==='Plus') $bd='badge-plus';
        elseif($it['status']==='Minus') $bd='badge-minus';
        $diff  = floatval($it['difference']);
        $dc    = $diff>0?'#15803d':($diff<0?'#dc2626':'#6b7280');
        $rowbg = ($stStatus!=='Draft'&&$diff>0)?'background:#f0fdf4':(($stStatus!=='Draft'&&$diff<0)?'background:#fef2f2':'');
      ?>
      <tr style="<?=$rowbg?>" data-loc="<?=strtolower($it['location']??'')?>"
          data-prod="<?=strtolower($it['product_code']??'')?>" data-status="<?=$it['status']?>">
        <td style="font-family:monospace;font-weight:700;color:#026766"><?=htmlspecialchars($it['location']??'—')?></td>
        <td>
          <div class="font-semibold text-gray-800 text-sm"><?=htmlspecialchars($it['product_name']??'—')?></div>
          <div style="font-size:.68rem;color:#9ca3af;font-family:monospace"><?=htmlspecialchars($it['product_code']??'')?></div>
        </td>
        <td style="font-family:monospace;font-size:.78rem"><?=htmlspecialchars($it['batch_number']??'—')?></td>
        <td class="text-sm text-gray-500"><?=htmlspecialchars($it['uom']??'—')?></td>
        <td class="text-right text-gray-500"><?=number_format($it['qty_system'],0)?></td>
        <?php if($stStatus!=='Draft'):?>
        <td class="text-right text-gray-400 text-xs"><?=$it['counter_1']!==null?number_format($it['counter_1'],0):'—'?></td>
        <td class="text-right text-gray-400 text-xs"><?=$it['counter_2']!==null?number_format($it['counter_2'],0):'—'?></td>
        <td class="text-right text-gray-400 text-xs"><?=$it['counter_3']!==null?number_format($it['counter_3'],0):'—'?></td>
        <td class="text-right font-bold" style="color:#026766"><?=number_format($it['qty_physical'],0)?></td>
        <td class="text-right font-bold" style="color:<?=$dc?>"><?=($diff>0?'▲ +':($diff<0?'▼ ':'')).number_format($diff,0)?></td>
        <td class="text-center"><span class="badge <?=$bd?>"><?=$it['status']?></span></td>
        <?php endif;?>
        <?php if($canWrite && $stStatus==='Draft'):?>
        <td class="text-center">
          <form method="POST" style="display:inline" onsubmit="return confirm('Hapus?')">
            <input type="hidden" name="stock_take_id" value="<?=$stockTake['id']?>">
            <input type="hidden" name="item_id" value="<?=$it['id']?>">
            <button type="submit" name="delete_item" class="text-red-400 hover:text-red-600 text-sm">
              <i class="fas fa-times"></i></button>
          </form>
        </td>
        <?php endif;?>
      </tr>
      <?php endforeach; endif;?>
      </tbody>
      <?php if(!empty($stockTakeItems)&&$stStatus!=='Draft'):
        $totSys  = array_sum(array_column($stockTakeItems,'qty_system'));
        $totPhys = array_sum(array_column($stockTakeItems,'qty_physical'));
        $totDiff = array_sum(array_column($stockTakeItems,'difference'));
      ?>
      <tfoot>
        <tr>
          <td colspan="4"><strong>TOTAL</strong></td>
          <td class="text-right"><?=number_format($totSys,0)?></td>
          <td colspan="3"></td>
          <td class="text-right" style="color:#026766"><?=number_format($totPhys,0)?></td>
          <td class="text-right font-bold" style="color:<?=$totDiff>0?'#15803d':($totDiff<0?'#dc2626':'#6b7280')?>">
            <?=($totDiff>0?'▲ +':($totDiff<0?'▼ ':'')).number_format($totDiff,0)?>
          </td>
          <td>
            <span class="badge badge-clear mr-1"><?=$acc['clear']?> Clear</span>
            <span class="badge badge-plus mr-1">+<?=$acc['plus']?> Plus</span>
            <span class="badge badge-minus">-<?=$acc['minus']?> Minus</span>
          </td>
        </tr>
      </tfoot>
      <?php endif;?>
    </table>
  </div>
<?php endif; ?>
</div>

<?php endif;?>
</div>

<script>

const ST_PRODS = <?php echo json_encode(array_map(function($p){
  return ['id'=>$p['id'],'code'=>$p['product_code'],'name'=>$p['product_name'],'uom'=>$p['uom_type']??'Drum'];
}, $products), JSON_UNESCAPED_UNICODE);?>;

function stSearch(q) {
  const dd = document.getElementById('stProdDropdown');
  if (!dd) return;
  if (!q) { dd.style.display='none'; return; }
  const qL = q.toLowerCase();
  const m  = ST_PRODS.filter(p => p.code.toLowerCase().includes(qL)||p.name.toLowerCase().includes(qL)).slice(0,12);
  dd.innerHTML = m.length ? m.map(p =>
    `<div class="prod-opt" onclick="stSelect(${p.id},'${p.code.replace(/'/g,"\\'")}','${p.name.replace(/'/g,"\\'")}','${p.uom}')">
      <div class="prod-code">${p.code}</div>
      <div class="prod-name text-xs text-gray-600">${p.name}</div>
      <div class="text-xs text-gray-400 mt-0.5"><i class="fas fa-box mr-1"></i>${p.uom}</div>
    </div>`).join('') :
    '<div class="no-result"><i class="fas fa-search mr-1"></i>Tidak ditemukan</div>';
  dd.style.display = 'block';
}

function stSelect(id, code, name, uom) {
  document.getElementById('stProductId').value = id;
  document.getElementById('stUomHidden').value  = uom;
  document.getElementById('stQtyUom').textContent = uom;
  document.getElementById('stProdSearch').value = code+' — '+name;
  document.getElementById('stProdDropdown').style.display = 'none';
  const chip = document.getElementById('stProdChip');
  document.getElementById('stProdChipLabel').textContent = code+' · '+uom;
  chip.style.display = 'flex';
  // Reset location & batch so auto-fill can work fresh
  document.getElementById('stLocInput').value  = '';
  document.getElementById('stBatchInput').value = '';
  _stLocMap = {};
  stAutoFillLocations();
}

function stClear() {
  document.getElementById('stProductId').value  = '';
  document.getElementById('stProdSearch').value  = '';
  document.getElementById('stProdChip').style.display = 'none';
  document.getElementById('stLocInput').value    = '';
  document.getElementById('stBatchInput').value  = '';
  document.getElementById('stQtySystem').value   = 0;
  document.getElementById('stQtyUom').textContent = '';
  const se = document.getElementById('stLocSuggest');
  if (se) se.style.display = 'none';
  _stLocMap = {};
  stCalc();
}

document.addEventListener('click', function(e){
  if (!e.target.closest('#stProdSearch')&&!e.target.closest('#stProdDropdown'))
    { const d=document.getElementById('stProdDropdown'); if(d) d.style.display='none'; }
});

function stGetQty() {
  const pid=document.getElementById('stProductId')?.value;
  const loc=document.getElementById('stLocInput')?.value?.trim();
  const bat=document.getElementById('stBatchInput')?.value?.trim();
  if (!pid) return;
  fetch(`stocktake_api.php?action=get_stock&product_id=${pid}&location=${encodeURIComponent(loc||'')}&batch=${encodeURIComponent(bat||'')}`)
    .then(r=>r.json()).then(d=>{
      if(d.qty!==undefined){ document.getElementById('stQtySystem').value=d.qty; stCalc(); }
      if(d.uom){ document.getElementById('stQtyUom').textContent=d.uom; document.getElementById('stUomHidden').value=d.uom; }
    }).catch(()=>{});
}

let _stLocMap = {}; // location_code → { batch, uom, qty, expiry }

function stAutoFillLocations() {
  const pid = document.getElementById('stProductId')?.value;
  const bat = document.getElementById('stBatchInput')?.value?.trim();
  const suggestEl = document.getElementById('stLocSuggest');
  const listEl    = document.getElementById('stLocList');
  if (!pid || !suggestEl || !listEl) return;

  listEl.innerHTML = '<div style="color:#9ca3af;font-size:.75rem;padding:4px">Memuat...</div>';
  suggestEl.style.display = 'block';

  fetch(`stocktake_api.php?action=get_locations&product_id=${pid}&batch=${encodeURIComponent(bat||'')}`)
    .then(r => r.json())
    .then(d => {
      if (!d.success || !d.locations.length) {
        listEl.innerHTML = '<div style="color:#9ca3af;font-size:.75rem;padding:4px">Tidak ada lokasi ditemukan di sistem</div>';
        return;
      }

      // Build location map for auto-fill
      _stLocMap = {};
      d.locations.forEach(l => {
        // Keep first entry per location (FEFO order from API)
        if (!_stLocMap[l.location_code]) {
          _stLocMap[l.location_code] = { batch: l.batch||'', uom: l.uom||'', qty: l.qty, expiry: l.expiry };
        }
      });

      // Auto-select if only one unique location
      const locInput = document.getElementById('stLocInput');
      const uniqueLocs = Object.keys(_stLocMap);
      if (!locInput.value && uniqueLocs.length === 1) {
        stPickLoc(uniqueLocs[0]);
        return; // stPickLoc calls stAutoFillLocations again to re-render list
      }

      const curLoc = locInput.value;
      listEl.innerHTML = uniqueLocs.map(code => {
        const m    = _stLocMap[code];
        const sel  = curLoc === code;
        return `<div onclick="stPickLoc('${code}')"
              style="display:flex;align-items:center;justify-content:space-between;
                     padding:5px 7px;border-radius:5px;cursor:pointer;margin-bottom:2px;
                     background:${sel?'#e0f7f7':'#fff'};
                     border:1px solid ${sel?'#b2e5e5':'#80d2d2'}"
              onmouseover="this.style.background='#e0f7f7'" onmouseout="this.style.background='${sel?'#e0f7f7':'#fff'}'">
          <div style="display:flex;align-items:center;gap:8px">
            <span style="font-family:monospace;font-weight:700;color:#026766;font-size:.8rem">${code}</span>
            <span style="font-size:.7rem;color:#6b7280">${m.batch||'—'} · ${m.expiry}</span>
          </div>
          <span style="font-size:.78rem;font-weight:700;color:#374151">${m.qty} ${m.uom}</span>
        </div>`;
      }).join('');
    })
    .catch(() => {
      listEl.innerHTML = '<div style="color:#026766;font-size:.75rem;padding:4px">Gagal memuat lokasi</div>';
    });
}

function stPickLoc(loc) {
  document.getElementById('stLocInput').value = loc;
  // Auto-fill batch and UOM from map
  const m = _stLocMap[loc];
  if (m) {
    const batchInp = document.getElementById('stBatchInput');
    if (batchInp && !batchInp.value) batchInp.value = m.batch || '';
    if (m.uom) {
      document.getElementById('stQtyUom').textContent = m.uom;
      document.getElementById('stUomHidden').value = m.uom;
    }
  }
  stGetQty();
  stAutoFillLocations(); // re-render list to highlight selected
}

function stCalc() {
  const sys =parseFloat(document.getElementById('stQtySystem')?.value||0);
  const c1  =parseFloat(document.getElementById('stC1')?.value||NaN);
  const c2  =parseFloat(document.getElementById('stC2')?.value||NaN);
  const el  =document.getElementById('stDiffPrev');
  if (!el) return;
  if (isNaN(c2)) { el.textContent='—'; el.className='px-3 py-2 rounded-lg border text-sm font-bold text-center bg-gray-50 border-gray-200 text-gray-400'; return; }
  const diff = c2-sys;
  let cls, txt;
  if (!isNaN(c1)&&c1!==c2&&c1>0&&c2>0) {
    txt=`C1≠C2 ⚠`; cls='bg-yellow-50 border-yellow-300 text-yellow-700';
  } else if (diff>0) {
    txt=`+${diff} Plus`; cls='bg-green-50 border-green-300 text-green-700';
  } else if (diff<0) {
    txt=`${diff} Minus`; cls='bg-red-50 border-red-300 text-red-700';
  } else {
    txt=`0 · Clear`; cls='bg-blue-50 border-blue-300 text-blue-700';
  }
  el.textContent=txt;
  el.className=`px-3 py-2 rounded-lg border text-sm font-bold text-center ${cls}`;
}

document.getElementById('stAddForm')?.addEventListener('submit', function(e){
  if (!document.getElementById('stProductId')?.value) {
    e.preventDefault();
    document.getElementById('stProdSearch').focus();
    document.getElementById('stProdSearch').style.borderColor='#026766';
    alert('Pilih produk terlebih dahulu!');
  }
});

function filterSt() {
  const q=document.getElementById('stSearch')?.value?.toLowerCase()||'';
  const s=document.getElementById('stStatusF')?.value||'';
  document.querySelectorAll('#stTbody tr').forEach(r=>{
    const show=(!q||r.dataset.no?.includes(q)||r.dataset.by?.includes(q))&&(!s||r.dataset.status===s);
    r.style.display=show?'':'none';
  });
}

function filterItems() {
  const q=document.getElementById('stItemSearch')?.value?.toLowerCase()||'';
  const s=document.getElementById('stItemStatus')?.value||'';
  document.querySelectorAll('#stItemsTbody tr').forEach(r=>{
    const show=(!q||r.dataset.loc?.includes(q)||r.dataset.prod?.includes(q))&&(!s||r.dataset.status===s);
    r.style.display=show?'':'none';
  });
}

function ctCheck(itemId, c1val, c2val) {
  const el = document.getElementById(`ctck_${itemId}`);
  if (!el) return;
  // c1val or c2val will be null depending on which counter is active
  const val = c1val !== null ? c1val : c2val;
  const num = parseFloat(val);
  if (val === '' || val === null || isNaN(num)) {
    el.textContent = '—';
    el.style.color = '#9ca3af';
  } else {
    el.textContent = num % 1 === 0 ? num : num.toFixed(2);
    el.style.color = '#026766';
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
