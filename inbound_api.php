<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Product.php';
Auth::requireAuth();
ob_end_clean();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';
try {
    switch ($action) {
        case 'search_products':
            $q = trim($_GET['q'] ?? '');
            $db = db();
            $sql = "SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                           p.max_sku_qty, p.max_trans_qty,
                           COALESCE(SUM(s.quantity),0) as stock_qty
                    FROM products p
                    LEFT JOIN stock s ON s.product_id = p.id AND s.stock_status = 'Available'
                    WHERE p.is_active = 1
                    AND (p.product_code LIKE ? OR p.product_name LIKE ?)
                    GROUP BY p.id
                    ORDER BY p.product_name
                    LIMIT 30";
            $like = '%' . $q . '%';
            $stmt = $db->prepare($sql);
            $stmt->execute([$like, $like]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['results' => array_map(fn($r) => [
                'id'            => $r['id'],
                'text'          => $r['product_code'] . ' — ' . $r['product_name'],
                'product_code'  => $r['product_code'],
                'product_name'  => $r['product_name'],
                'uom'           => $r['uom_type'],
                'uom_per_pallet'=> $r['uom_per_pallet'],
                'stock_qty'     => $r['stock_qty'],
            ], $rows)]);
            break;
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
