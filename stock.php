<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Product.php';

Auth::requireAuth();

$pageTitle = 'Stock';
$currentPage = 'stock';

$activeTab    = $_GET['tab']  ?? 'available'; // available | quarantine
$viewMode     = $_GET['view'] ?? 'product';   // product | location

$categoryId   = $_GET['category'] ?? null;
$location     = $_GET['location']  ?? null;
$status       = $_GET['status']    ?? null;
$search       = $_GET['search']    ?? null;
$searchOdNo   = $_GET['od_no']     ?? null;
$searchShipNo = $_GET['shipment']  ?? null;
$expiryFilter = $_GET['expiry']    ?? null;

// Tab WHERE fragment
if ($activeTab === 'quarantine') {
    $tabWhere = "(s.stock_status = 'Rejected' OR s.location = 'QUA_SHELL') AND s.quantity > 0";
} else {
    $activeTab = 'available';
    $tabWhere  = "s.stock_status IN ('Available','Reserved') AND (s.location IS NULL OR s.location != 'QUA_SHELL')";
}

$odNoFilter      = '';
$shipNoFilter    = '';
$odNoParam       = [];
$shipNoParam     = [];
if ($searchOdNo) {
    $odNoFilter  = " AND EXISTS (
        SELECT 1 FROM stock_locations sl2
        JOIN inbound_items ii2 ON ii2.id = sl2.inbound_item_id
        WHERE sl2.stock_id = s.id AND ii2.od_number LIKE ?)";
    $odNoParam[] = "%$searchOdNo%";
}
if ($searchShipNo) {
    $shipNoFilter = " AND EXISTS (
        SELECT 1 FROM stock_locations sl3
        JOIN inbound_items ii3 ON ii3.id = sl3.inbound_item_id
        JOIN inbound_orders io3 ON io3.id = ii3.inbound_order_id
        WHERE sl3.stock_id = s.id AND io3.shipment_no LIKE ?)";
    $shipNoParam[] = "%$searchShipNo%";
}

$sql = "SELECT
               p.id AS product_id, p.product_code, p.product_name, p.category,
               p.uom_type, p.uom_per_pallet, p.liters_per_unit,
               s.batch_number,
               COALESCE(
                 MAX(
                   (SELECT ii_exp.exp_date
                    FROM stock_locations sl_exp
                    JOIN inbound_items ii_exp ON ii_exp.id = sl_exp.inbound_item_id
                    WHERE sl_exp.stock_id = s.id
                    ORDER BY ii_exp.id DESC
                    LIMIT 1)
                 ),
                 MAX(s.expiry_date)
               ) AS expiry_date,
               s.stock_status,
               s.uom,
               SUM(s.quantity) AS quantity,
               SUM(CEIL(s.quantity / GREATEST(COALESCE(p.uom_per_pallet, 1), 1))) AS pallet,
               GROUP_CONCAT(DISTINCT NULLIF(s.location,'') ORDER BY s.location SEPARATOR ', ') AS locations,
               (SELECT GROUP_CONCAT(DISTINCT ii_sub.od_number ORDER BY ii_sub.id SEPARATOR ', ')
                FROM stock_locations sl_sub
                JOIN inbound_items ii_sub ON ii_sub.id = sl_sub.inbound_item_id
                WHERE sl_sub.stock_id IN (
                    SELECT s2.id FROM stock s2
                    WHERE s2.product_id = p.id
                      AND s2.batch_number <=> s.batch_number
                      AND s2.stock_status  = s.stock_status
                      AND s2.uom          = s.uom
                      AND s2.quantity > 0
                )) AS od_numbers,
               (SELECT GROUP_CONCAT(DISTINCT io_sub.shipment_no ORDER BY io_sub.id SEPARATOR ', ')
                FROM stock_locations sl_sub2
                JOIN inbound_items ii_sub2 ON ii_sub2.id = sl_sub2.inbound_item_id
                JOIN inbound_orders io_sub ON io_sub.id = ii_sub2.inbound_order_id
                WHERE sl_sub2.stock_id IN (
                    SELECT s3.id FROM stock s3
                    WHERE s3.product_id = p.id
                      AND s3.batch_number <=> s.batch_number
                      AND s3.stock_status  = s.stock_status
                      AND s3.uom          = s.uom
                      AND s3.quantity > 0
                )) AS shipment_nos
        FROM stock s
        JOIN products p ON s.product_id = p.id
        WHERE $tabWhere
          AND s.quantity > 0
          $odNoFilter
          $shipNoFilter";

$params = array_merge($odNoParam, $shipNoParam);

if ($categoryId)                             { $sql .= " AND p.category = ?";    $params[] = $categoryId; }
if ($location)                               { $sql .= " AND s.location LIKE ?";  $params[] = "%$location%"; }
if ($status && $activeTab === 'available')   { $sql .= " AND s.stock_status = ?"; $params[] = $status; }
if ($search) {
    $sql .= " AND (p.product_code LIKE ? OR p.product_name LIKE ? OR s.batch_number LIKE ?
                   OR s.location LIKE ?)";
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($expiryFilter === 'expiring')     { $sql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'critical') { $sql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'expired')  { $sql .= " AND s.expiry_date <= CURDATE()"; }

db()->exec("SET SESSION group_concat_max_len = 65536");

$_skPerPage = 100;
$_skPage    = max(1, (int)($_GET['page'] ?? 1));

$_cntSql = "SELECT COUNT(*) FROM (SELECT 1 FROM stock s JOIN products p ON s.product_id = p.id
    WHERE $tabWhere AND s.quantity > 0
    $odNoFilter $shipNoFilter";
$_cntParams = array_merge($odNoParam, $shipNoParam);
if ($categoryId)                             { $_cntSql .= " AND p.category = ?";    $_cntParams[] = $categoryId; }
if ($location)                               { $_cntSql .= " AND s.location LIKE ?";  $_cntParams[] = "%$location%"; }
if ($status && $activeTab === 'available')   { $_cntSql .= " AND s.stock_status = ?"; $_cntParams[] = $status; }
if ($search)     { $_cntSql .= " AND (p.product_code LIKE ? OR p.product_name LIKE ? OR s.batch_number LIKE ? OR s.location LIKE ?)";
                   $_cntParams[] = "%$search%"; $_cntParams[] = "%$search%"; $_cntParams[] = "%$search%"; $_cntParams[] = "%$search%"; }
if ($expiryFilter === 'expiring')     { $_cntSql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'critical') { $_cntSql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'expired')  { $_cntSql .= " AND s.expiry_date <= CURDATE()"; }
$_cntSql .= " GROUP BY p.id, s.batch_number, s.stock_status, s.uom) AS _cnt";
$_cntStmt = db()->prepare($_cntSql);
$_cntStmt->execute($_cntParams);
$_skTotal      = (int)$_cntStmt->fetchColumn();
$_skTotalPages = max(1, (int)ceil($_skTotal / $_skPerPage));
$_skPage       = min($_skPage, $_skTotalPages);
$_skOffset     = ($_skPage - 1) * $_skPerPage;

$_aggSql = "SELECT SUM(s.quantity) as total_qty,
    SUM(CEIL(s.quantity / GREATEST(COALESCE(p.uom_per_pallet,1),1))) as total_pallet,
    SUM(CASE WHEN s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY) AND s.expiry_date > CURDATE() THEN 1 ELSE 0 END) as cnt_critical,
    SUM(CASE WHEN s.expiry_date <= CURDATE() THEN 1 ELSE 0 END) as cnt_expired
    FROM stock s JOIN products p ON s.product_id = p.id
    WHERE $tabWhere AND s.quantity > 0
    $odNoFilter $shipNoFilter";
$_aggParams = array_merge($odNoParam, $shipNoParam);
if ($categoryId)                             { $_aggSql .= " AND p.category = ?";    $_aggParams[] = $categoryId; }
if ($location)                               { $_aggSql .= " AND s.location LIKE ?";  $_aggParams[] = "%$location%"; }
if ($status && $activeTab === 'available')   { $_aggSql .= " AND s.stock_status = ?"; $_aggParams[] = $status; }
if ($search)     { $_aggSql .= " AND (p.product_code LIKE ? OR p.product_name LIKE ? OR s.batch_number LIKE ? OR s.location LIKE ?)";
                   $_aggParams[] = "%$search%"; $_aggParams[] = "%$search%"; $_aggParams[] = "%$search%"; $_aggParams[] = "%$search%"; }
if ($expiryFilter === 'expiring')     { $_aggSql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'critical') { $_aggSql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'expired')  { $_aggSql .= " AND s.expiry_date <= CURDATE()"; }
$_aggStmt = db()->prepare($_aggSql);
$_aggStmt->execute($_aggParams);
$_agg = $_aggStmt->fetch();

// Tab counts for badges
$_tcRow = db()->query("SELECT
    SUM(CASE WHEN s.stock_status IN ('Available','Reserved') AND (s.location IS NULL OR s.location != 'QUA_SHELL') AND s.quantity > 0 THEN 1 ELSE 0 END) AS cnt_available,
    SUM(CASE WHEN (s.stock_status = 'Rejected' OR s.location = 'QUA_SHELL') AND s.quantity > 0 THEN 1 ELSE 0 END) AS cnt_quarantine
    FROM stock s")->fetch();

$sql .= " GROUP BY p.id, s.batch_number, s.stock_status, s.uom
          ORDER BY expiry_date ASC, p.product_name
          LIMIT $_skPerPage OFFSET $_skOffset";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$stockItems = $stmt->fetchAll();

foreach ($stockItems as &$item) { $item['expiry_info'] = calcExpiry($item['expiry_date']); }
unset($item);

$categories = db()->query("SELECT DISTINCT category FROM products WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

function calcExpiry($expiryDate) {
    if (empty($expiryDate)) {
        return ['days_left'=>null,'is_expired'=>false,'is_critical'=>false,'is_warning'=>false,
                'is_safe'=>true,'display_text'=>'No Expiry','display_class'=>'','full_date'=>null];
    }
    $today  = new DateTime('today');
    $expiry = new DateTime($expiryDate);
    $diff   = $today->diff($expiry);
    $daysLeft = (int)$diff->days;
    if ($expiry < $today) $daysLeft = -$daysLeft;

    $isExpired  = $daysLeft < 0;
    $isCritical = !$isExpired && $daysLeft <= 120;
    $isWarning  = !$isExpired && !$isCritical && $daysLeft <= 365;
    $isSafe     = !$isExpired && !$isCritical && !$isWarning;

    if ($isExpired) {
        $displayText = 'EXPIRED';
    } elseif ($daysLeft === 0) {
        $displayText = 'Expires Today!';
    } elseif ($daysLeft <= 30) {
        $displayText = $daysLeft . ' day' . ($daysLeft > 1 ? 's' : '') . ' left';
    } else {
        $months = $diff->y * 12 + $diff->m;
        $days   = $diff->d;
        if ($months > 0 && $days > 0)
            $displayText = $months . ' mo ' . $days . ' d left';
        elseif ($months > 0)
            $displayText = $months . ' month' . ($months > 1 ? 's' : '') . ' left';
        else
            $displayText = $days . ' day' . ($days > 1 ? 's' : '') . ' left';
    }

    if ($isExpired)       $cls = 'bg-red-100 text-red-800 border-red-300';
    elseif ($isCritical)  $cls = 'bg-orange-100 text-orange-800 border-orange-300';
    elseif ($isWarning)   $cls = 'bg-yellow-100 text-yellow-800 border-yellow-300';
    else                  $cls = 'bg-green-100 text-green-800 border-green-300';

    return ['days_left'=>$daysLeft,'is_expired'=>$isExpired,'is_critical'=>$isCritical,
            'is_warning'=>$isWarning,'is_safe'=>$isSafe,'display_text'=>$displayText,
            'display_class'=>$cls,'full_date'=>$expiryDate];
}

$detailSql = "SELECT
    p.id AS product_id, p.product_code, p.product_name, p.category,
    p.uom_type, p.uom_per_pallet, p.liters_per_unit,
    s.id AS stock_id, s.batch_number,
    COALESCE(
      (SELECT ii_exp.exp_date
       FROM stock_locations sl_exp
       JOIN inbound_items ii_exp ON ii_exp.id = sl_exp.inbound_item_id
       WHERE sl_exp.stock_id = s.id
       ORDER BY ii_exp.id DESC
       LIMIT 1),
      s.expiry_date
    ) AS expiry_date,
    s.stock_status, s.uom,
    COALESCE(s.location,'') AS location,
    s.quantity,
    CEIL(s.quantity / GREATEST(COALESCE(p.uom_per_pallet, 1), 1)) AS pallet,
    (SELECT GROUP_CONCAT(DISTINCT ii_s.od_number ORDER BY ii_s.id SEPARATOR ', ')
     FROM stock_locations sl_s
     JOIN inbound_items ii_s ON ii_s.id = sl_s.inbound_item_id
     WHERE sl_s.stock_id = s.id) AS od_numbers,
    (SELECT GROUP_CONCAT(DISTINCT io_s.shipment_no ORDER BY io_s.id SEPARATOR ', ')
     FROM stock_locations sl_s2
     JOIN inbound_items ii_s2 ON ii_s2.id = sl_s2.inbound_item_id
     JOIN inbound_orders io_s ON io_s.id = ii_s2.inbound_order_id
     WHERE sl_s2.stock_id = s.id) AS shipment_nos
FROM stock s
JOIN products p ON s.product_id = p.id
WHERE $tabWhere
  AND s.quantity > 0
  $odNoFilter
  $shipNoFilter";
$detailParams = array_merge($odNoParam, $shipNoParam);
if ($categoryId)                             { $detailSql .= " AND p.category = ?";    $detailParams[] = $categoryId; }
if ($location)                               { $detailSql .= " AND s.location LIKE ?";  $detailParams[] = "%$location%"; }
if ($status && $activeTab === 'available')   { $detailSql .= " AND s.stock_status = ?"; $detailParams[] = $status; }
if ($search) {
    $detailSql .= " AND (p.product_code LIKE ? OR p.product_name LIKE ? OR s.batch_number LIKE ? OR s.location LIKE ?)";
    $detailParams[] = "%$search%"; $detailParams[] = "%$search%"; $detailParams[] = "%$search%"; $detailParams[] = "%$search%";
}
if ($expiryFilter === 'expiring')     { $detailSql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'critical') { $detailSql .= " AND s.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 120 DAY) AND s.expiry_date > CURDATE()"; }
elseif ($expiryFilter === 'expired')  { $detailSql .= " AND s.expiry_date <= CURDATE()"; }
$detailSql .= " ORDER BY p.product_name, s.batch_number, s.location";
$detailStmt = db()->prepare($detailSql);
$detailStmt->execute($detailParams);
$detailRows = $detailStmt->fetchAll();
foreach ($detailRows as &$d) { $d['expiry_info'] = calcExpiry($d['expiry_date']); }
unset($d);

$detailByKey = [];
foreach ($detailRows as $d) {
    $key = $d['product_id'].'|'.($d['batch_number']??'').'|'.($d['expiry_date']??'').'|'.$d['stock_status'].'|'.$d['uom'];
    $detailByKey[$key][] = $d;
}

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    require_once __DIR__ . '/vendor/autoload.php';
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Stock Per Lokasi');
    $headers = ['No','Product Code','Product Name','Category','UOM','Batch','OD No','Shipment No','Location',
                'Quantity','Pallet','Liters (L)','Expiry Date','Days Left','Sisa Waktu','Status'];
    foreach ($headers as $c => $h) {
        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c+1).'1';
        $sheet->setCellValue($cell, $h);
        $sheet->getStyle($cell)->getFont()->setBold(true);
        $sheet->getStyle($cell)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF11998E');
        $sheet->getStyle($cell)->getFont()->getColor()->setARGB('FFFFFFFF');
    }
    $row = 2;
    $productNo      = 0;
    $lastProductCode = null;
    $totalQtyExp    = 0;
    $totalPalletExp = 0;
    foreach ($detailRows as $item) {
        $info = calcExpiry($item['expiry_date']);
        $liters = ($item['quantity']??0) * ($item['liters_per_unit']??209);
        $_expPlt = floatval($item['pallet'] ?? 0);
        if ($_expPlt <= 0) { $_expUp = max(1, intval($item['uom_per_pallet']??4)); $_expPlt = ceil(floatval($item['quantity']) / $_expUp); }
        $locDisplay = $item['location'] !== '' ? $item['location'] : 'UNALLOCATED';

        $isNewProduct = $item['product_code'] !== $lastProductCode;
        if ($isNewProduct) { $productNo++; $lastProductCode = $item['product_code']; }

        $qty    = floatval($item['quantity']);
        $pallet = (int)ceil($_expPlt);
        $totalQtyExp    += $qty;
        $totalPalletExp += $pallet;

        $rowData = [$isNewProduct ? $productNo : '',$item['product_code'],$item['product_name'],$item['category']??'',$item['uom_type'] ?? $item['uom'] ?? '',
                    $item['batch_number']??'',$item['od_numbers']??'',$item['shipment_nos']??'',
                    $locDisplay,$qty,$pallet,
                    round($liters),$item['expiry_date']??'',$info['days_left']??'',$info['display_text'],$item['stock_status']];
        foreach ($rowData as $c => $val) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c+1) . $row;
            $sheet->setCellValue($cell, $val);
        }
        if ($info['is_expired'])
            $sheet->getStyle("A{$row}:P{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFD7D7');
        elseif ($info['is_critical'])
            $sheet->getStyle("A{$row}:P{$row}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFF3CD');
        $row++;
    }

    $sheet->setCellValue("A{$row}", 'TOTAL');
    $sheet->setCellValue("J{$row}", $totalQtyExp);
    $sheet->setCellValue("K{$row}", $totalPalletExp);
    $sheet->getStyle("A{$row}:P{$row}")->applyFromArray([
        'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
        'fill' => ['fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF11998E']],
    ]);
    foreach (range('A','P') as $col) $sheet->getColumnDimension($col)->setAutoSize(true);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Stock_'.date('Ymd_His').'.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit;
}

require_once __DIR__ . '/includes/header.php';

// Build base URL (preserving filters, resetting page)
$_baseGet = array_diff_key($_GET, array_flip(['page','export']));
?>
<style>
:root {
  --sk-primary: #026766;
  --sk-dark:    #013d3c;
  --sk-light:   #e6f7f7;
  --sk-border:  #b2e5e5;
  --sk-muted:   #80b2b2;
}
.sk-card {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 1px 8px rgba(2,103,102,.08);
  padding: 20px 22px;
}
.sk-label {
  display: block;
  font-size: .75rem;
  font-weight: 600;
  color: var(--sk-dark);
  margin-bottom: 5px;
  letter-spacing: .02em;
  text-transform: uppercase;
}
.sk-input, .sk-select {
  width: 100%;
  padding: 8px 11px;
  border: 1.5px solid var(--sk-border);
  border-radius: 8px;
  font-size: .83rem;
  color: var(--sk-dark);
  background: #fff;
  outline: none;
  transition: border-color .15s;
  box-sizing: border-box;
}
.sk-input:focus, .sk-select:focus { border-color: var(--sk-primary); }
.sk-input::placeholder { color: var(--sk-muted); }
.sk-btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 16px;
  border-radius: 8px;
  font-size: .82rem;
  font-weight: 600;
  cursor: pointer;
  border: none;
  text-decoration: none;
  transition: opacity .15s;
  white-space: nowrap;
}
.sk-btn:hover { opacity: .88; }
.sk-btn-primary { background: var(--sk-primary); color: #fff; }
.sk-btn-ghost   { background: var(--sk-light); color: var(--sk-dark); }
.sk-btn-export  { background: var(--sk-dark); color: #fff; }
.sk-hero { background: linear-gradient(135deg, #013d3c 0%, #026766 55%, #02908f 100%); border-radius: 14px; padding: 28px; color: #fff; }
.sk-stat { background: rgba(255,255,255,.15); border-radius: 10px; padding: 14px 16px; }
.sk-stat .num { font-size: 1.5rem; font-weight: 700; line-height: 1.1; }
.sk-stat .lbl { font-size: .68rem; opacity: .75; margin-top: 3px; letter-spacing: .05em; text-transform: uppercase; }
.sk-table { width: 100%; font-size: .82rem; border-collapse: separate; border-spacing: 0; }
.sk-table thead tr { background: linear-gradient(90deg, #013d3c, #026766); color: #fff; }
.sk-table th { padding: 11px 13px; font-weight: 600; font-size: .78rem; letter-spacing: .03em; white-space: nowrap; }
.sk-table td { padding: 9px 13px; border-bottom: 1px solid #e6f7f7; vertical-align: middle; color: #013d3c; }
.sk-table tbody tr { transition: background .12s; }
.sk-table tbody tr:hover td { background: #f0fbfb; }
.sk-badge {
  display: inline-block; padding: 2px 9px; border-radius: 20px;
  font-size: .7rem; font-weight: 700; white-space: nowrap;
}
.sk-uom { display: inline-block; padding: 1px 6px; border-radius: 4px; font-size: .68rem; font-weight: 700; margin-left: 4px; background: var(--sk-light); color: var(--sk-dark); }
.sk-loc-detail { background: var(--sk-light); border-top: 2px solid var(--sk-border); }
/* Tabs */
.sk-tabs { display: flex; gap: 0; border-bottom: 2px solid var(--sk-border); }
.sk-tab {
  padding: 10px 20px; font-size: .83rem; font-weight: 600; cursor: pointer;
  border: none; background: none; color: var(--sk-muted);
  border-bottom: 2px solid transparent; margin-bottom: -2px;
  display: flex; align-items: center; gap: 7px; text-decoration: none;
  transition: color .15s;
}
.sk-tab:hover { color: var(--sk-primary); }
.sk-tab.active { color: var(--sk-primary); border-bottom-color: var(--sk-primary); }
.sk-tab-badge {
  background: var(--sk-light); color: var(--sk-primary);
  border-radius: 20px; padding: 1px 8px; font-size: .7rem; font-weight: 700;
}
.sk-tab.active .sk-tab-badge { background: var(--sk-primary); color: #fff; }
/* View toggle */
.sk-view-toggle { display: flex; border: 1.5px solid var(--sk-border); border-radius: 8px; overflow: hidden; }
.sk-view-btn {
  padding: 5px 13px; font-size: .78rem; font-weight: 600; cursor: pointer;
  border: none; background: #fff; color: var(--sk-muted); text-decoration: none;
  display: flex; align-items: center; gap: 5px; transition: background .12s, color .12s;
}
.sk-view-btn.active { background: var(--sk-primary); color: #fff; }
.sk-view-btn:not(:last-child) { border-right: 1.5px solid var(--sk-border); }
</style>

<div style="display:flex;flex-direction:column;gap:18px">

  <!-- Hero -->
  <div class="sk-hero">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
      <div>
        <h1 style="font-size:1.4rem;font-weight:700;margin:0 0 4px"><i class="fas fa-boxes mr-2"></i>Stock Management</h1>
      </div>
      <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])) ?>" class="sk-btn sk-btn-export">
        <i class="fas fa-file-excel"></i> Export Excel
      </a>
    </div>
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-top:20px">
      <div class="sk-stat"><div class="num"><?= number_format($_skTotal) ?></div><div class="lbl">Total Items</div></div>
      <div class="sk-stat"><div class="num"><?= number_format((float)($_agg['total_qty']??0),0) ?></div><div class="lbl">Total Units</div></div>
      <div class="sk-stat"><div class="num"><?= number_format((int)ceil($_agg['total_pallet']??0),0) ?></div><div class="lbl">Total Pallets</div></div>
      <div class="sk-stat"><div class="num" style="color:#fde68a"><?= (int)($_agg['cnt_critical']??0) ?></div><div class="lbl">Critical (≤120d)</div></div>
      <div class="sk-stat"><div class="num" style="color:#fca5a5"><?= (int)($_agg['cnt_expired']??0) ?></div><div class="lbl">Expired</div></div>
    </div>
  </div>

  <?php if ((int)($_agg['cnt_expired']??0) > 0 || (int)($_agg['cnt_critical']??0) > 0): ?>
  <div style="background:#fff9ed;border:1.5px solid #fcd34d;border-radius:10px;padding:11px 16px;display:flex;align-items:center;gap:10px">
    <i class="fas fa-exclamation-triangle" style="color:#d97706;font-size:1rem;flex-shrink:0"></i>
    <div style="font-size:.82rem;color:#92400e;font-weight:600">
      <?php if ((int)($_agg['cnt_expired']??0) > 0): ?>
        <span style="background:#fee2e2;color:#991b1b;border-radius:4px;padding:1px 8px;margin-right:6px"><?= (int)$_agg['cnt_expired'] ?> EXPIRED</span>
      <?php endif; ?>
      <?php if ((int)($_agg['cnt_critical']??0) > 0): ?>
        <span style="background:#fff3cd;color:#92400e;border-radius:4px;padding:1px 8px;margin-right:6px"><?= (int)$_agg['cnt_critical'] ?> kritis (≤120 hari)</span>
      <?php endif; ?>
      pada tab ini. Segera tindaklanjuti.
    </div>
  </div>
  <?php endif; ?>

  <!-- Filters -->
  <div class="sk-card">
    <form method="GET">
      <input type="hidden" name="tab"  value="<?= htmlspecialchars($activeTab) ?>">
      <input type="hidden" name="view" value="<?= htmlspecialchars($viewMode) ?>">
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;align-items:end">
        <div>
          <label class="sk-label"><i class="fas fa-hashtag mr-1" style="color:var(--sk-primary)"></i>OD No</label>
          <input type="text" name="od_no" value="<?= htmlspecialchars($searchOdNo??'') ?>" placeholder="Cari OD No..." class="sk-input" style="font-family:monospace">
        </div>
        <div>
          <label class="sk-label"><i class="fas fa-search mr-1" style="color:var(--sk-primary)"></i>Search</label>
          <input type="text" name="search" value="<?= htmlspecialchars($search??'') ?>" placeholder="Kode/nama/batch/lokasi" class="sk-input">
        </div>
        <div>
          <label class="sk-label"><i class="fas fa-ship mr-1" style="color:var(--sk-primary)"></i>Shipment No</label>
          <input type="text" name="shipment" value="<?= htmlspecialchars($searchShipNo??'') ?>" placeholder="Cari Shipment..." class="sk-input">
        </div>
        <div>
          <label class="sk-label">Category</label>
          <select name="category" class="sk-select">
            <option value="">Semua</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat) ?>" <?= ($categoryId??'')===$cat?'selected':'' ?>><?= htmlspecialchars($cat) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="sk-label"><i class="fas fa-map-marker-alt mr-1" style="color:var(--sk-primary)"></i>Location</label>
          <input type="text" name="location" value="<?= htmlspecialchars($location??'') ?>" placeholder="Kode lokasi" class="sk-input">
        </div>
        <?php if ($activeTab === 'available'): ?>
        <div>
          <label class="sk-label">Status</label>
          <select name="status" class="sk-select">
            <option value="">Semua</option>
            <?php foreach(['Available','Reserved'] as $s): ?>
            <option value="<?= $s ?>" <?= ($status??'')===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <div>
          <label class="sk-label">Expiry</label>
          <select name="expiry" class="sk-select">
            <option value="">Semua</option>
            <option value="expiring" <?= ($expiryFilter??'')==='expiring'?'selected':'' ?>>≤ 30 hari</option>
            <option value="critical" <?= ($expiryFilter??'')==='critical'?'selected':'' ?>>≤ 120 hari</option>
            <option value="expired"  <?= ($expiryFilter??'')==='expired' ?'selected':'' ?>>Expired</option>
          </select>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="sk-btn sk-btn-primary" style="flex:1"><i class="fas fa-filter"></i> Filter</button>
          <a href="stock.php?tab=<?= $activeTab ?>&view=<?= $viewMode ?>" class="sk-btn sk-btn-ghost"><i class="fas fa-times"></i></a>
        </div>
      </div>
    </form>
  </div>

  <!-- Pallet Calculator -->
  <div class="sk-card" style="border-left:3px solid var(--sk-primary)">
    <div style="font-size:.85rem;font-weight:700;color:var(--sk-dark);margin-bottom:12px">
      <i class="fas fa-calculator mr-2" style="color:var(--sk-primary)"></i>Pallet Calculator
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end">
      <div style="min-width:160px">
        <label class="sk-label">UOM</label>
        <select id="calcUom" onchange="calcPallet()" class="sk-select">
          <option value="drum">Drum (4/pallet)</option>
          <option value="carton">Carton (36/44/48/pallet)</option>
          <option value="pail">Pail (24/pallet)</option>
          <option value="ea">EA (4/pallet)</option>
          <option value="bags">Bags (1/pallet)</option>
          <option value="custom">Custom</option>
        </select>
      </div>
      <div id="cartonCapGroup" style="display:none;min-width:120px">
        <label class="sk-label">Carton/pallet</label>
        <select id="cartonCap" onchange="calcPallet()" class="sk-select">
          <option value="36">36</option><option value="44" selected>44</option><option value="48">48</option>
        </select>
      </div>
      <div id="customCapGroup" style="display:none;min-width:120px">
        <label class="sk-label">Unit/pallet</label>
        <input type="number" id="customCap" onchange="calcPallet()" min="1" placeholder="misal 20" class="sk-input">
      </div>
      <div style="min-width:140px">
        <label class="sk-label">Quantity</label>
        <input type="number" id="calcQty" onkeyup="calcPallet()" onchange="calcPallet()" min="1" placeholder="Jumlah unit" class="sk-input">
      </div>
      <div id="calcResult" style="display:none;background:var(--sk-light);border:1.5px solid var(--sk-border);border-radius:8px;padding:9px 14px;font-size:.83rem;color:var(--sk-dark);font-weight:600;min-width:200px">
        <i class="fas fa-pallet mr-1" style="color:var(--sk-primary)"></i><span id="calcResultText"></span>
      </div>
    </div>
  </div>

  <!-- Stock Table -->
  <div class="sk-card" style="padding:0;overflow:hidden">
    <!-- Tabs -->
    <div style="padding:16px 22px 0;border-bottom:2px solid var(--sk-border)">
      <div style="display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:10px;margin-bottom:0">
        <div class="sk-tabs" style="border-bottom:none">
          <a href="?<?= http_build_query(array_merge($_baseGet, ['tab'=>'available','view'=>$viewMode])) ?>"
             class="sk-tab <?= $activeTab==='available'?'active':'' ?>">
            <i class="fas fa-check-circle" style="font-size:.75rem"></i> Available
            <span class="sk-tab-badge"><?= number_format((int)($_tcRow['cnt_available']??0)) ?></span>
          </a>
          <a href="?<?= http_build_query(array_merge($_baseGet, ['tab'=>'quarantine','view'=>$viewMode])) ?>"
             class="sk-tab <?= $activeTab==='quarantine'?'active':'' ?>">
            <i class="fas fa-exclamation-triangle" style="font-size:.75rem"></i> Quarantine
            <span class="sk-tab-badge"><?= number_format((int)($_tcRow['cnt_quarantine']??0)) ?></span>
          </a>
        </div>
        <div style="display:flex;align-items:center;gap:10px;padding-bottom:14px">
          <span style="font-size:.75rem;color:var(--sk-muted);font-weight:600;text-transform:uppercase;letter-spacing:.04em">View</span>
          <div class="sk-view-toggle">
            <a href="?<?= http_build_query(array_merge($_baseGet, ['tab'=>$activeTab,'view'=>'product'])) ?>"
               class="sk-view-btn <?= $viewMode==='product'?'active':'' ?>">
              <i class="fas fa-layer-group"></i> Per Produk
            </a>
            <a href="?<?= http_build_query(array_merge($_baseGet, ['tab'=>$activeTab,'view'=>'location'])) ?>"
               class="sk-view-btn <?= $viewMode==='location'?'active':'' ?>">
              <i class="fas fa-map-marker-alt"></i> Per Lokasi
            </a>
          </div>
          <span style="background:var(--sk-light);color:var(--sk-primary);border-radius:20px;padding:3px 12px;font-size:.75rem;font-weight:700">
            <?= $viewMode === 'location' ? count($detailRows) : count($stockItems) ?>
          </span>
        </div>
      </div>
    </div>

    <div style="overflow-x:auto">
      <?php if ($viewMode === 'location'): ?>
      <!-- Per Lokasi View -->
      <table class="sk-table">
        <thead>
          <tr>
            <th style="text-align:left">#</th>
            <th style="text-align:left">Product</th>
            <th style="text-align:left">Batch</th>
            <th style="text-align:left">Lokasi</th>
            <th style="text-align:right">Qty</th>
            <th style="text-align:right">Pallet</th>
            <th style="text-align:right">Liters</th>
            <th style="text-align:left">OD No</th>
            <th style="text-align:left">Shipment No</th>
            <th style="text-align:center">Exp. Date</th>
            <th style="text-align:center">Sisa Waktu</th>
            <th style="text-align:center">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($detailRows as $_no => $d):
            $info   = $d['expiry_info'];
            $rowBg  = $info['is_expired'] ? '#fff5f5' : ($info['is_critical'] ? '#fffbf0' : ($info['is_warning'] ? '#fffef5' : '#fff'));
            $liters = ($d['quantity']??0) * ($d['liters_per_unit']??209);
            $_uom   = $d['uom_type'] ?? $d['uom'] ?? 'Drum';
            $_dName = preg_replace('/\s*\((Carton|Pail|Drum|EA|Bags)\)\s*$/i', '', $d['product_name']);
            $_dPlt  = floatval($d['pallet']??0);
            if ($_dPlt <= 0) { $_dUpp = max(1, intval($d['uom_per_pallet']??4)); $_dPlt = ceil(floatval($d['quantity'])/$_dUpp); }
            $_dLoc  = $d['location'] !== '' ? $d['location'] : 'UNALLOCATED';
          ?>
          <tr style="background:<?= $rowBg ?>">
            <td style="color:var(--sk-muted);font-size:.75rem"><?= $_no+1 ?></td>
            <td>
              <div style="font-weight:600;font-size:.84rem;color:#013d3c"><?= htmlspecialchars($_dName) ?></div>
              <div style="margin-top:2px">
                <span style="font-family:monospace;font-size:.75rem;color:var(--sk-muted)"><?= htmlspecialchars($d['product_code']) ?></span>
                <span class="sk-uom"><?= htmlspecialchars($_uom) ?></span>
              </div>
            </td>
            <td>
              <?php if (!empty($d['batch_number'])): ?>
              <span style="font-family:monospace;font-size:.78rem;background:#f1f5f9;padding:2px 7px;border-radius:4px;color:#334155"><?= htmlspecialchars($d['batch_number']) ?></span>
              <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td>
              <span style="background:var(--sk-light);color:var(--sk-primary);border-radius:4px;padding:2px 8px;font-family:monospace;font-weight:700;font-size:.78rem"><?= htmlspecialchars($_dLoc) ?></span>
            </td>
            <td style="text-align:right">
              <span style="font-weight:700;color:#013d3c"><?= number_format($d['quantity'],0) ?></span>
              <span style="font-size:.72rem;color:var(--sk-muted);margin-left:2px"><?= htmlspecialchars($_uom) ?></span>
            </td>
            <td style="text-align:right">
              <span style="font-weight:700;color:var(--sk-primary)"><?= (int)ceil($_dPlt) ?></span>
              <span style="font-size:.72rem;color:var(--sk-muted)"> plt</span>
            </td>
            <td style="text-align:right;font-size:.78rem;color:var(--sk-muted)"><?= number_format($liters,0) ?>L</td>
            <td style="font-family:monospace;font-size:.74rem;color:var(--sk-primary)"><?= htmlspecialchars($d['od_numbers']??'—') ?></td>
            <td style="font-family:monospace;font-size:.74rem;color:var(--sk-dark)"><?= htmlspecialchars($d['shipment_nos']??'—') ?></td>
            <td style="text-align:center;font-size:.78rem;color:#374151;white-space:nowrap">
              <?= $d['expiry_date'] ? date('d M Y', strtotime($d['expiry_date'])) : '<span style="color:#d1d5db">—</span>' ?>
            </td>
            <td style="text-align:center;white-space:nowrap">
              <?php
              if ($info['is_expired'])       $ec='background:#fee2e2;color:#991b1b;border:1px solid #fca5a5';
              elseif ($info['is_critical'])  $ec='background:#fff3cd;color:#92400e;border:1px solid #fcd34d';
              elseif ($info['is_warning'])   $ec='background:#fef9c3;color:#854d0e;border:1px solid #fde68a';
              else                           $ec='background:var(--sk-light);color:var(--sk-dark);border:1px solid var(--sk-border)';
              ?>
              <span style="<?= $ec ?>;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:3px">
                <?php if ($info['is_expired']): ?><i class="fas fa-exclamation-triangle"></i>
                <?php elseif ($info['is_critical']): ?><i class="fas fa-exclamation-circle"></i>
                <?php elseif ($info['is_warning']): ?><i class="fas fa-clock"></i>
                <?php else: ?><i class="fas fa-check-circle"></i><?php endif; ?>
                <?= $info['display_text'] ?>
              </span>
            </td>
            <td style="text-align:center">
              <?php
              $ss = $d['stock_status'];
              if ($ss==='Available')    echo '<span class="sk-badge" style="background:#e0f7f7;color:#013d3c">Available</span>';
              elseif ($ss==='Reserved') echo '<span class="sk-badge" style="background:#fef9c3;color:#854d0e">Reserved</span>';
              elseif ($ss==='Rejected') echo '<span class="sk-badge" style="background:#fee2e2;color:#991b1b">Rejected</span>';
              elseif ($ss==='Dues In')  echo '<span class="sk-badge" style="background:#e0f7f7;color:#026766;border:1px solid #b2e5e5">Dues In</span>';
              else echo '<span class="sk-badge" style="background:#f1f5f9;color:#475569">'.htmlspecialchars($ss).'</span>';
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($detailRows)): ?>
          <tr><td colspan="12" style="padding:48px;text-align:center;color:var(--sk-muted)">
            <i class="fas fa-box-open" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4"></i>
            <div style="font-weight:500">Tidak ada data stock</div>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>

      <?php else: ?>
      <!-- Per Produk View -->
      <table class="sk-table">
        <thead>
          <tr>
            <th style="width:36px;border-radius:0"></th>
            <th style="text-align:left">#</th>
            <th style="text-align:left">Product</th>
            <th style="text-align:left">Batch</th>
            <th style="text-align:left">OD No</th>
            <th style="text-align:left">Shipment No</th>
            <th style="text-align:right">Total Qty</th>
            <th style="text-align:right">Pallet</th>
            <th style="text-align:right">Liters</th>
            <th style="text-align:center">Exp. Date</th>
            <th style="text-align:center">Sisa Waktu</th>
            <th style="text-align:center">Status</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($stockItems as $_no => $item):
            $info   = $item['expiry_info'];
            $rowBg  = $info['is_expired'] ? '#fff5f5' : ($info['is_critical'] ? '#fffbf0' : ($info['is_warning'] ? '#fffef5' : '#fff'));
            $liters = ($item['quantity']??0) * ($item['liters_per_unit']??209);
            $_uom   = $item['uom_type'] ?? $item['uom'] ?? 'Drum';
            $_displayName = preg_replace('/\s*\((Carton|Pail|Drum|EA|Bags)\)\s*$/i', '', $item['product_name']);
            $_plt = floatval($item['pallet']??0);
            if ($_plt <= 0) { $_upp = max(1, intval($item['uom_per_pallet']??4)); $_plt = ceil(floatval($item['quantity'])/$_upp); }
            $_groupKey = $item['product_id'].'|'.($item['batch_number']??'').'|'.($item['expiry_date']??'').'|'.$item['stock_status'].'|'.$item['uom'];
            $_locDetails = $detailByKey[$_groupKey] ?? [];
            $_rowId = 'detail-'.md5($_groupKey);
          ?>
          <tr style="background:<?= $rowBg ?>">
            <td style="text-align:center;padding:6px 4px">
              <?php if (!empty($_locDetails)): ?>
              <button type="button" onclick="toggleDetail('<?= $_rowId ?>', this)"
                style="background:none;border:none;cursor:pointer;color:var(--sk-primary);font-size:.85rem;padding:3px 5px;border-radius:4px"
                title="Lihat per lokasi">
                <i class="fas fa-chevron-right" style="transition:transform .2s"></i>
              </button>
              <?php endif; ?>
            </td>
            <td style="color:var(--sk-muted);font-size:.75rem"><?= $_no+1 ?></td>
            <td>
              <div style="font-weight:600;font-size:.84rem;color:#013d3c"><?= htmlspecialchars($_displayName) ?></div>
              <div style="margin-top:2px">
                <span style="font-family:monospace;font-size:.75rem;color:var(--sk-muted)"><?= htmlspecialchars($item['product_code']) ?></span>
                <span class="sk-uom"><?= htmlspecialchars($_uom) ?></span>
              </div>
            </td>
            <td>
              <?php if (!empty($item['batch_number'])): ?>
              <span style="font-family:monospace;font-size:.78rem;background:#f1f5f9;padding:2px 7px;border-radius:4px;color:#334155"><?= htmlspecialchars($item['batch_number']) ?></span>
              <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (!empty($item['od_numbers'])): ?>
              <?php foreach (array_filter(array_map('trim', explode(',', $item['od_numbers']))) as $_od): ?>
              <div style="font-family:monospace;font-size:.74rem;color:var(--sk-primary);font-weight:600;white-space:nowrap"><?= htmlspecialchars($_od) ?></div>
              <?php endforeach; ?>
              <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (!empty($item['shipment_nos'])): ?>
              <?php foreach (array_filter(array_map('trim', explode(',', $item['shipment_nos']))) as $_sn): ?>
              <div style="font-family:monospace;font-size:.74rem;color:var(--sk-dark);font-weight:600;white-space:nowrap"><?= htmlspecialchars($_sn) ?></div>
              <?php endforeach; ?>
              <?php else: ?><span style="color:#d1d5db">—</span><?php endif; ?>
            </td>
            <td style="text-align:right">
              <span style="font-weight:700;color:#013d3c"><?= number_format($item['quantity'],0) ?></span>
              <span style="font-size:.72rem;color:var(--sk-muted);margin-left:2px"><?= htmlspecialchars($_uom) ?></span>
            </td>
            <td style="text-align:right">
              <span style="font-weight:700;color:var(--sk-primary)"><?= (int)$_plt ?></span>
              <span style="font-size:.72rem;color:var(--sk-muted)"> plt</span>
            </td>
            <td style="text-align:right;font-size:.78rem;color:var(--sk-muted)"><?= number_format($liters,0) ?>L</td>
            <td style="text-align:center;font-size:.78rem;color:#374151;white-space:nowrap">
              <?= $item['expiry_date'] ? date('d M Y', strtotime($item['expiry_date'])) : '<span style="color:#d1d5db">—</span>' ?>
            </td>
            <td style="text-align:center;white-space:nowrap">
              <?php
              if ($info['is_expired'])       $ec='background:#fee2e2;color:#991b1b;border:1px solid #fca5a5';
              elseif ($info['is_critical'])  $ec='background:#fff3cd;color:#92400e;border:1px solid #fcd34d';
              elseif ($info['is_warning'])   $ec='background:#fef9c3;color:#854d0e;border:1px solid #fde68a';
              else                           $ec='background:var(--sk-light);color:var(--sk-dark);border:1px solid var(--sk-border)';
              ?>
              <span style="<?= $ec ?>;padding:2px 8px;border-radius:20px;font-size:.7rem;font-weight:600;display:inline-flex;align-items:center;gap:3px">
                <?php if ($info['is_expired']): ?><i class="fas fa-exclamation-triangle"></i>
                <?php elseif ($info['is_critical']): ?><i class="fas fa-exclamation-circle"></i>
                <?php elseif ($info['is_warning']): ?><i class="fas fa-clock"></i>
                <?php else: ?><i class="fas fa-check-circle"></i><?php endif; ?>
                <?= $info['display_text'] ?>
              </span>
            </td>
            <td style="text-align:center">
              <?php
              $ss = $item['stock_status'];
              if ($ss==='Available')    echo '<span class="sk-badge" style="background:#e0f7f7;color:#013d3c">Available</span>';
              elseif ($ss==='Reserved') echo '<span class="sk-badge" style="background:#fef9c3;color:#854d0e">Reserved</span>';
              elseif ($ss==='Rejected') echo '<span class="sk-badge" style="background:#fee2e2;color:#991b1b">Rejected</span>';
              elseif ($ss==='Dues In')  echo '<span class="sk-badge" style="background:#e0f7f7;color:#026766;border:1px solid #b2e5e5">Dues In</span>';
              else echo '<span class="sk-badge" style="background:#f1f5f9;color:#475569">'.htmlspecialchars($ss).'</span>';
              ?>
            </td>
          </tr>
          <?php if (!empty($_locDetails)): ?>
          <tr id="<?= $_rowId ?>" style="display:none">
            <td colspan="12" style="padding:0">
              <div class="sk-loc-detail" style="padding:10px 16px 12px 52px">
                <div style="font-size:.7rem;font-weight:700;color:var(--sk-primary);margin-bottom:8px;letter-spacing:.05em;text-transform:uppercase">
                  <i class="fas fa-map-marker-alt mr-1"></i>Detail Per Lokasi & Pallet
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.76rem">
                  <thead>
                    <tr style="background:var(--sk-primary);color:#fff">
                      <th style="padding:5px 10px;text-align:left;border-radius:4px 0 0 4px;font-weight:600">Lokasi</th>
                      <th style="padding:5px 10px;text-align:right;font-weight:600">Qty</th>
                      <th style="padding:5px 10px;text-align:right;font-weight:600">Pallet</th>
                      <th style="padding:5px 10px;text-align:left;font-weight:600">OD No</th>
                      <th style="padding:5px 10px;text-align:left;font-weight:600;border-radius:0 4px 4px 0">Shipment No</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($_locDetails as $_d):
                      $_dPlt = floatval($_d['pallet']??0);
                      if ($_dPlt <= 0) { $_dUpp = max(1, intval($_d['uom_per_pallet']??4)); $_dPlt = ceil(floatval($_d['quantity'])/$_dUpp); }
                      $_dLoc = $_d['location'] !== '' ? $_d['location'] : 'UNALLOCATED';
                    ?>
                    <tr style="border-bottom:1px solid var(--sk-border)">
                      <td style="padding:5px 10px">
                        <span style="background:var(--sk-light);color:var(--sk-primary);border-radius:4px;padding:2px 8px;font-family:monospace;font-weight:700;font-size:.75rem"><?= htmlspecialchars($_dLoc) ?></span>
                      </td>
                      <td style="padding:5px 10px;text-align:right;font-weight:600;color:#013d3c">
                        <?= number_format($_d['quantity'],0) ?> <span style="color:var(--sk-muted);font-weight:400"><?= htmlspecialchars($_uom) ?></span>
                      </td>
                      <td style="padding:5px 10px;text-align:right;font-weight:700;color:var(--sk-primary)">
                        <?= (int)ceil($_dPlt) ?> <span style="color:var(--sk-muted);font-weight:400">plt</span>
                      </td>
                      <td style="padding:5px 10px;font-family:monospace;color:var(--sk-primary);font-size:.72rem"><?= htmlspecialchars($_d['od_numbers']??'—') ?></td>
                      <td style="padding:5px 10px;font-family:monospace;color:var(--sk-dark);font-size:.72rem"><?= htmlspecialchars($_d['shipment_nos']??'—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          <?php if (empty($stockItems)): ?>
          <tr><td colspan="12" style="padding:48px;text-align:center;color:var(--sk-muted)">
            <i class="fas fa-box-open" style="font-size:2.5rem;display:block;margin-bottom:10px;opacity:.4"></i>
            <div style="font-weight:500">Tidak ada data stock</div>
          </td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <?php if ($viewMode === 'product' && $_skTotalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-top:1px solid var(--sk-light);flex-wrap:wrap;gap:8px">
        <span style="font-size:.82rem;color:#90a4ae">
            Showing <?= number_format($_skOffset + 1) ?>–<?= number_format(min($_skOffset + $_skPerPage, $_skTotal)) ?> of <?= number_format($_skTotal) ?> items
        </span>
        <div style="display:flex;gap:4px">
            <?php if ($_skPage > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $_skPage - 1])) ?>"
               style="padding:5px 11px;background:var(--sk-light);color:var(--sk-dark);border-radius:6px;text-decoration:none;font-size:.82rem">&laquo; Prev</a>
            <?php endif; ?>
            <?php for ($p = max(1,$_skPage-2); $p <= min($_skTotalPages,$_skPage+2); $p++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
               style="padding:5px 9px;background:<?= $p===$_skPage?'var(--sk-primary)':'var(--sk-light)' ?>;color:<?= $p===$_skPage?'#fff':'var(--sk-dark)' ?>;border-radius:6px;text-decoration:none;font-size:.82rem;font-weight:<?= $p===$_skPage?'700':'400' ?>">
                <?= $p ?>
            </a>
            <?php endfor; ?>
            <?php if ($_skPage < $_skTotalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $_skPage + 1])) ?>"
               style="padding:5px 11px;background:var(--sk-light);color:var(--sk-dark);border-radius:6px;text-decoration:none;font-size:.82rem">Next &raquo;</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Expiry legend -->
  <div class="sk-card" style="padding:14px 22px">
    <div style="font-size:.75rem;font-weight:700;color:var(--sk-dark);margin-bottom:8px;text-transform:uppercase;letter-spacing:.04em">
      <i class="fas fa-info-circle mr-1" style="color:var(--sk-primary)"></i>Keterangan Expiry
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:.8rem;color:#374151">
      <span style="display:flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:50%;background:var(--sk-light);border:1.5px solid var(--sk-border);display:inline-block"></span>Aman (&gt;1 tahun)</span>
      <span style="display:flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:50%;background:#fef9c3;border:1.5px solid #fde68a;display:inline-block"></span>Perhatian (≤1 tahun)</span>
      <span style="display:flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:50%;background:#fff3cd;border:1.5px solid #fcd34d;display:inline-block"></span>Kritis (≤120 hari)</span>
      <span style="display:flex;align-items:center;gap:6px"><span style="width:10px;height:10px;border-radius:50%;background:#fee2e2;border:1.5px solid #fca5a5;display:inline-block"></span>Expired</span>
    </div>
  </div>

</div>

<script>
function toggleDetail(rowId, btn) {
  var row = document.getElementById(rowId);
  var icon = btn.querySelector('i');
  if (!row) return;
  if (row.style.display === 'none') {
    row.style.display = '';
    icon.style.transform = 'rotate(90deg)';
    btn.style.color = '#02908f';
  } else {
    row.style.display = 'none';
    icon.style.transform = 'rotate(0deg)';
    btn.style.color = '#026766';
  }
}

function calcPallet() {
  const uom = document.getElementById('calcUom').value;
  const qty = parseFloat(document.getElementById('calcQty').value) || 0;
  let perPallet = 0;
  document.getElementById('cartonCapGroup').style.display='none';
  document.getElementById('customCapGroup').style.display='none';
  if (uom==='drum')        perPallet = 4;
  else if (uom==='pail')   perPallet = 24;
  else if (uom==='ea')     perPallet = 4;
  else if (uom==='bags')   perPallet = 1;
  else if (uom==='carton') { document.getElementById('cartonCapGroup').style.display=''; perPallet = parseFloat(document.getElementById('cartonCap').value)||44; }
  else                     { document.getElementById('customCapGroup').style.display=''; perPallet = parseFloat(document.getElementById('customCap').value)||0; }

  const res = document.getElementById('calcResult');
  const txt = document.getElementById('calcResultText');
  if (qty<=0 || perPallet<=0) { res.style.display='none'; return; }

  const full = Math.floor(qty/perPallet);
  const rem  = qty % perPallet;
  const tot  = (qty/perPallet).toFixed(2);
  let msg = qty + ' unit ÷ ' + perPallet + '/pallet = ';
  if (rem===0) msg += full + ' pallet penuh';
  else msg += full + ' pallet penuh + sisa ' + rem.toFixed(2) + ' unit (' + tot + ' pallet total)';
  txt.textContent = msg;
  res.style.display='';
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
