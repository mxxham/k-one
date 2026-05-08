<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Outbound.php';

if (!Auth::check()) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

ob_end_clean();
header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'search_products':
            
            $q = trim($_GET['q'] ?? '');
            $db = db();
            $stmt = $db->prepare("SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                                         COALESCE(SUM(s.quantity),0) as stock_qty,
                                         COALESCE(SUM(s.pallet),0) as stock_plt
                                  FROM products p
                                  INNER JOIN stock s ON s.product_id = p.id
                                    AND (s.stock_status IN ('Available','Dues In') OR s.stock_status IS NULL OR s.stock_status = '')
                                    AND (s.location IS NULL OR s.location NOT IN ('QUA_SHELL','STAGING'))
                                  WHERE p.is_active = 1
                                  AND (p.product_code LIKE ? OR p.product_name LIKE ?)
                                  GROUP BY p.id
                                  HAVING stock_qty > 0
                                  ORDER BY p.product_name
                                  LIMIT 30");
            $like = '%' . $q . '%';
            $stmt->execute([$like, $like]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['results' => array_map(fn($r) => [
                'id'            => $r['id'],
                'text'          => $r['product_code'] . ' — ' . $r['product_name'],
                'product_code'  => $r['product_code'],
                'product_name'  => $r['product_name'],
                'uom'           => $r['uom_type'],
                'uom_per_pallet'=> $r['uom_per_pallet'],
                'stock_qty'     => (float)$r['stock_qty'],
                'stock_plt'     => (float)$r['stock_plt'],
            ], $rows)]);
            break;
        case 'check_stock':
            $productId = $_GET['product_id'] ?? null;
            $quantity = floatval($_GET['quantity'] ?? 0);

            if (!$productId || $quantity <= 0) {
                echo json_encode(['error' => 'Invalid parameters']);
                exit;
            }

            
            $allocation = Outbound::getFEFOAllocation($productId, $quantity);

            echo json_encode($allocation);
            break;

        case 'get_available_stock':
            $productId = $_GET['product_id'] ?? null;

            if (!$productId) {
                echo json_encode(['error' => 'Product ID required']);
                exit;
            }

            $stock = Outbound::getAvailableStock($productId);
            echo json_encode(['stock' => $stock]);
            break;

        default:
            echo json_encode(['error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
