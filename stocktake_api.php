<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
if (!Auth::check()) { echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

$action = $_GET['action'] ?? '';
$db = db();

if ($action === 'get_stock') {
    $pid = intval($_GET['product_id'] ?? 0);
    $loc = trim($_GET['location'] ?? '');
    $bat = trim($_GET['batch'] ?? '');

    $sql    = "SELECT COALESCE(SUM(s.quantity),0) as qty, s.uom FROM stock s WHERE s.product_id=? AND s.stock_status='Available'";
    $params = [$pid];
    if ($loc) { $sql .= " AND s.location=?"; $params[] = $loc; }
    if ($bat) { $sql .= " AND s.batch_number=?"; $params[] = $bat; }
    $sql .= " GROUP BY s.uom LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    echo json_encode(['success'=>true, 'qty'=>$row ? floatval($row['qty']) : 0, 'uom'=>$row['uom']??'']);
    exit;
}

if ($action === 'get_locations') {
    $pid = intval($_GET['product_id'] ?? 0);
    $bat = trim($_GET['batch'] ?? '');

    if (!$pid) { echo json_encode(['success'=>false,'locations'=>[]]); exit; }

    
    $sql = "
        SELECT
            sl.location_code,
            sl.pallet_seq,
            COALESCE(sl.original_quantity, sl.quantity) AS qty_per_pallet,
            s.quantity AS qty_current,
            s.batch_number,
            s.uom,
            s.expiry_date,
            sl.is_full_pallet,
            sl.status AS loc_status
        FROM stock_locations sl
        JOIN stock s ON sl.stock_id = s.id
        WHERE s.product_id = ?
          AND s.stock_status = 'Available'
          AND s.quantity > 0
          AND sl.status IN ('Available','Reserved')
    ";
    $params = [$pid];
    if ($bat) {
        $sql .= " AND s.batch_number = ?";
        $params[] = $bat;
    }
    $sql .= " ORDER BY sl.location_code, sl.pallet_seq";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    
    $sql2 = "
        SELECT
            s.location AS location_code,
            NULL AS pallet_seq,
            s.quantity AS qty_per_pallet,
            s.quantity AS qty_current,
            s.batch_number,
            s.uom,
            s.expiry_date,
            1 AS is_full_pallet,
            'Available' AS loc_status
        FROM stock s
        WHERE s.product_id = ?
          AND s.stock_status = 'Available'
          AND s.quantity > 0
          AND s.location IS NOT NULL
          AND s.location NOT IN (
              SELECT DISTINCT sl2.location_code FROM stock_locations sl2
              JOIN stock s2 ON sl2.stock_id = s2.id WHERE s2.product_id = ?
          )
    ";
    $params2 = [$pid, $pid];
    if ($bat) {
        $sql2 .= " AND s.batch_number = ?";
        $params2[] = $bat;
    }
    $stmt2 = $db->prepare($sql2);
    $stmt2->execute($params2);
    $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $allRows = array_merge($rows, $rows2);

    
    $locations = [];
    foreach ($allRows as $r) {
        $locations[] = [
            'location_code' => $r['location_code'],
            'pallet_seq'    => $r['pallet_seq'],
            'qty'           => (float)$r['qty_current'],
            'batch'         => $r['batch_number'],
            'uom'           => $r['uom'] ?? '',
            'expiry'        => $r['expiry_date'] ? date('d M Y', strtotime($r['expiry_date'])) : '—',
            'is_full'       => (bool)$r['is_full_pallet'],
        ];
    }

    
    $totalQty = array_sum(array_column($locations, 'qty'));

    echo json_encode([
        'success'   => true,
        'locations' => $locations,
        'total_qty' => $totalQty,
        'count'     => count($locations),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'get_scope_locations') {
    $stmt = $db->prepare("
        SELECT lm.location_code, lm.aisle, lm.zone,
               COALESCE(SUM(s.quantity),0)     AS total_qty,
               COUNT(DISTINCT s.product_id)    AS product_count,
               COUNT(DISTINCT s.batch_number)  AS batch_count
        FROM location_master lm
        LEFT JOIN stock s ON s.location = lm.location_code
            AND s.stock_status = 'Available' AND s.quantity > 0
        WHERE lm.is_active = 1
          AND lm.location_code NOT IN ('QUA_SHELL','STAGING')
        GROUP BY lm.location_code, lm.aisle, lm.zone
        ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($rows as $r) {
        $aisle = $r['aisle'] ?: 'Lainnya';
        if (!isset($grouped[$aisle])) $grouped[$aisle] = [];
        $grouped[$aisle][] = [
            'code'    => $r['location_code'],
            'zone'    => $r['zone'],
            'qty'     => (float)$r['total_qty'],
            'prods'   => (int)$r['product_count'],
            'batches' => (int)$r['batch_count'],
        ];
    }
    echo json_encode(['success'=>true,'grouped'=>$grouped,'total'=>count($rows)], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action']);
