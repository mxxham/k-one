<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
Auth::requireAuth();

ob_end_clean();
header('Content-Type: application/json');
$pid = (int)($_GET['product_id'] ?? 0);
if (!$pid) { echo json_encode(['error'=>'No product']); exit; }

$db = db();

$stock = $db->prepare("
    SELECT
        SUM(CASE WHEN stock_status='Available' THEN quantity ELSE 0 END) AS qty_available,
        SUM(CASE WHEN stock_status='Rejected'  THEN quantity ELSE 0 END) AS qty_rejected,
        SUM(CASE WHEN stock_status='Dues In'   THEN quantity ELSE 0 END) AS qty_dues_in,
        SUM(CASE WHEN stock_status='Available' THEN pallet   ELSE 0 END) AS pallets,
        uom
    FROM stock WHERE product_id=? GROUP BY uom LIMIT 1
");
$stock->execute([$pid]);
$stockRow = $stock->fetch() ?: [];

$ib = $db->prepare("
    SELECT
        COUNT(DISTINCT io.id)                                         AS total_orders,
        SUM(CASE WHEN ii.in_process_status='Dues In'        THEN ii.actual_qty ELSE 0 END) AS qty_dues_in,
        SUM(CASE WHEN ii.in_process_status='Goods Received' THEN ii.actual_qty ELSE 0 END) AS qty_gr,
        SUM(CASE WHEN ii.in_process_status='ATP'            THEN ii.actual_qty ELSE 0 END) AS qty_atp,
        SUM(CASE WHEN ii.in_process_status='Picked'         THEN ii.actual_qty ELSE 0 END) AS qty_picked,
        SUM(CASE WHEN ii.in_process_status='Unserviceable'  THEN ii.actual_qty ELSE 0 END) AS qty_unserv,
        SUM(ii.actual_qty)                                            AS qty_total_in
    FROM inbound_items ii
    JOIN inbound_orders io ON io.id = ii.inbound_order_id
    WHERE ii.product_id=? AND io.status='Completed'
");
$ib->execute([$pid]);
$ibRow = $ib->fetch() ?: [];

$ob = $db->prepare("
    SELECT
        COUNT(DISTINCT oo.id)  AS total_orders,
        SUM(oi.actual_qty)     AS qty_total_out,
        SUM(CASE WHEN oo.status IN ('Picked','Shipped','Completed') THEN oi.actual_qty ELSE 0 END) AS qty_shipped
    FROM outbound_items oi
    JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
    WHERE oi.product_id=?
      AND (oi.in_process_status IS NULL OR oi.in_process_status != 'Unserviceable')
");
$ob->execute([$pid]);
$obRow = $ob->fetch() ?: [];

$led = $db->prepare("
    SELECT sl.transaction_date, sl.transaction_type, sl.reference_number,
           sl.batch_number, sl.quantity_in, sl.quantity_out, sl.balance, sl.location
    FROM stock_ledger sl
    WHERE sl.product_id=?
    ORDER BY sl.id DESC LIMIT 10
");
$led->execute([$pid]);
$ledger = $led->fetchAll();

$byLoc = $db->prepare("
    SELECT location, batch_number, quantity, pallet, stock_status, expiry_date
    FROM stock
    WHERE product_id=? AND quantity>0
    ORDER BY stock_status, expiry_date ASC
");
$byLoc->execute([$pid]);
$locations = $byLoc->fetchAll();

$pending = $db->prepare("
    SELECT io.order_number, io.status, io.order_date, ii.actual_qty, ii.in_process_status
    FROM inbound_items ii
    JOIN inbound_orders io ON io.id = ii.inbound_order_id
    WHERE ii.product_id=? AND io.status NOT IN ('Completed','Cancelled')
    ORDER BY io.order_date DESC LIMIT 5
");
$pending->execute([$pid]);
$pendingIb = $pending->fetchAll();

echo json_encode([
    'stock'      => $stockRow,
    'inbound'    => $ibRow,
    'outbound'   => $obRow,
    'ledger'     => $ledger,
    'locations'  => $locations,
    'pending_ib' => $pendingIb,
]);
