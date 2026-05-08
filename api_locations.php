<?php
ob_start();
error_reporting(0);
ini_set('display_errors', '0');

session_start();
ob_end_clean();
header('Content-Type: application/json');
header('Cache-Control: no-store');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/LocationManager.php';

if (!Auth::check()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    switch ($action) {

        
        case 'suggest':
            $qty          = floatval($_GET['qty'] ?? 1);
            $uomPerPallet = intval($_GET['uom_per_pallet'] ?? 4);
            $uom          = $_GET['uom'] ?? 'EA';
            $zone         = $_GET['zone'] ?? null;

            $result = LocationManager::suggestLocationsForInbound($qty, $uom, $uomPerPallet, $zone);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        
        case 'check':
            $code = trim($_GET['code'] ?? '');
            if (!$code) {
                echo json_encode(['success' => false, 'message' => 'Location code required']);
                break;
            }
            $info      = LocationManager::getLocationInfo($code);
            $available = LocationManager::isAvailable($code);
            echo json_encode([
                'success'   => true,
                'available' => $available,
                'info'      => $info
            ]);
            break;

        
        case 'list':
            $q             = trim($_GET['q'] ?? '');
            $zone          = $_GET['zone'] ?? null;
            $availableOnly = ($_GET['available_only'] ?? '0') === '1';
            $limit         = min(50, intval($_GET['limit'] ?? 20));

            $db = db();
            $sql = "SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name,
                        lm.position, lm.zone,
                        CASE WHEN EXISTS (
                            SELECT 1 FROM stock_locations sl
                            WHERE sl.location_code = lm.location_code
                            AND sl.status IN ('Available','Reserved')
                        ) THEN 'Occupied' ELSE 'Available' END AS availability,
                        sl2.batch_number as current_batch,
                        sl2.quantity as current_qty
                    FROM location_master lm
                    LEFT JOIN stock_locations sl2 ON sl2.id = (
                        SELECT id FROM stock_locations
                        WHERE location_code = lm.location_code
                        AND status IN ('Available','Reserved')
                        ORDER BY id DESC LIMIT 1
                    )
                    WHERE lm.is_active = 1";

            $params = [];
            if ($q) {
                $sql .= " AND lm.location_code LIKE ?";
                $params[] = $q . '%';
            }
            if ($zone) {
                $sql .= " AND lm.zone = ?";
                $params[] = $zone;
            }
            if ($availableOnly) {
                $sql .= " AND NOT EXISTS (
                    SELECT 1 FROM stock_locations sl
                    WHERE sl.location_code = lm.location_code
                    AND sl.status IN ('Available','Reserved')
                )";
            }

            $sql .= " ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position LIMIT ?";
            $params[] = $limit;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            echo json_encode(['success' => true, 'locations' => $rows, 'count' => count($rows)]);
            break;

        
        case 'summary':
            $summary = LocationManager::getZoneSummary();
            echo json_encode(['success' => true, 'summary' => $summary]);
            break;

        
        case 'aisle_detail':
            $aisle = trim($_GET['aisle'] ?? '');
            if (!$aisle) { echo json_encode(['success'=>false,'message'=>'aisle required']); break; }

            $db = db();

            
            $stmt = $db->prepare("
                SELECT
                    lm.location_code AS code,
                    lm.rack, lm.row_name, lm.zone,
                    COALESCE(s1.quantity, s2.quantity, 0)       AS qty,
                    COALESCE(s1.pallet,  s2.pallet,  0)        AS pallet,
                    COALESCE(s1.uom, s2.uom)                    AS uom,
                    COALESCE(s1.batch_number, s2.batch_number)  AS batch,
                    COALESCE(s1.expiry_date, s2.expiry_date)    AS expiry,
                    COALESCE(p1.product_name, p2.product_name)  AS product,
                    COALESCE(p1.product_code, p2.product_code)  AS product_code,
                    COALESCE(p1.uom_per_pallet, p2.uom_per_pallet) AS uom_per_pallet,
                    CASE WHEN lm.row_name = 'A' THEN 1 ELSE 0 END AS is_eceran
                FROM location_master lm
                -- Path 1: via stock_locations (inbound per-pallet)
                LEFT JOIN stock_locations sl
                    ON sl.location_code COLLATE utf8mb4_general_ci
                     = lm.location_code COLLATE utf8mb4_general_ci
                    AND sl.status IN ('Available','Reserved')
                LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
                LEFT JOIN products p1 ON s1.product_id = p1.id
                -- Path 2: via stock.location directly (manual/old entries)
                LEFT JOIN stock s2
                    ON s2.location COLLATE utf8mb4_general_ci
                     = lm.location_code COLLATE utf8mb4_general_ci
                    AND s2.quantity > 0 AND s2.stock_status = 'Available'
                    AND s1.id IS NULL
                LEFT JOIN products p2 ON s2.product_id = p2.id
                WHERE lm.aisle = ? AND lm.is_active = 1
                ORDER BY lm.rack, lm.row_name, lm.position
            ");
            $stmt->execute([$aisle]);
            $locations = $stmt->fetchAll();

            
            foreach ($locations as &$l) {
                if ($l['expiry']) $l['expiry'] = date('d M Y', strtotime($l['expiry']));
                $l['qty']    = (float)$l['qty'];
                $l['pallet'] = (float)$l['pallet'];
                $l['is_eceran'] = (bool)($l['row_name'] === 'A');
                
                if (!$l['is_eceran'] && $l['pallet'] > 0) {
                    $l['pallet'] = (int)ceil($l['pallet']);
                }
                
                $upp = (int)($l['uom_per_pallet'] ?? 4);
                $l['is_partial'] = $l['is_eceran'] ||
                    (!$l['is_eceran'] && $l['qty'] > 0 && $upp > 0 && $l['qty'] < $upp);
            }
            unset($l);

            
            $total    = count($locations);
            $occupied = count(array_filter($locations, function($l){ return $l['qty'] > 0; }));
            $totalQty = array_sum(array_column($locations, 'qty'));
            $totalPlt = (int)ceil(array_sum(array_column($locations, 'pallet')));

            echo json_encode([
                'success'   => true,
                'locations' => $locations,
                'stats'     => [
                    'aisle'        => $aisle,
                    'total'        => $total,
                    'occupied'     => $occupied,
                    'total_qty'    => number_format($totalQty, 0),
                    'total_pallet' => $totalPlt,
                ],
            ]);
            break;

        // Fresh location data for pallet assignment modal
        // Excludes the current item's own allocations from the "occupied" check
        case 'modal_data':
            $itemId = intval($_GET['item_id'] ?? 0);
            if (!$itemId) {
                echo json_encode(['success' => false, 'message' => 'item_id required']);
                break;
            }
            $db = db();

            // Get item info
            $itemStmt = $db->prepare("
                SELECT ii.actual_qty, ii.quantity, ii.uom, p.uom_per_pallet
                FROM inbound_items ii
                JOIN products p ON p.id = ii.product_id
                WHERE ii.id = ?
            ");
            $itemStmt->execute([$itemId]);
            $itemInfo = $itemStmt->fetch();
            if (!$itemInfo) {
                echo json_encode(['success' => false, 'message' => 'Item not found']);
                break;
            }
            $totalQty = floatval($itemInfo['actual_qty'] ?? $itemInfo['quantity'] ?? 0);
            $uomPlt   = max(1, intval($itemInfo['uom_per_pallet'] ?? 4));
            $uom      = $itemInfo['uom'];

            // All active locations — occupied check excludes this item's own pallets
            $locStmt = $db->prepare("
                SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name, lm.position,
                    CASE WHEN EXISTS (
                        SELECT 1 FROM stock_locations sl
                        WHERE sl.location_code = lm.location_code
                          AND sl.status IN ('Available','Reserved')
                          AND sl.inbound_item_id != ?
                    ) THEN 1 ELSE 0 END AS is_occupied,
                    (SELECT sl2.batch_number FROM stock_locations sl2
                     WHERE sl2.location_code = lm.location_code
                       AND sl2.status IN ('Available','Reserved')
                       AND sl2.inbound_item_id != ?
                     LIMIT 1) AS occ_batch,
                    (SELECT sl2.quantity FROM stock_locations sl2
                     WHERE sl2.location_code = lm.location_code
                       AND sl2.status IN ('Available','Reserved')
                       AND sl2.inbound_item_id != ?
                     LIMIT 1) AS occ_qty,
                    (SELECT sl2.uom FROM stock_locations sl2
                     WHERE sl2.location_code = lm.location_code
                       AND sl2.status IN ('Available','Reserved')
                       AND sl2.inbound_item_id != ?
                     LIMIT 1) AS occ_uom,
                    (SELECT p2.product_code FROM stock_locations sl2
                     LEFT JOIN stock s2 ON sl2.stock_id = s2.id
                     LEFT JOIN products p2 ON s2.product_id = p2.id
                     WHERE sl2.location_code = lm.location_code
                       AND sl2.status IN ('Available','Reserved')
                       AND sl2.inbound_item_id != ?
                     LIMIT 1) AS occ_prod
                FROM location_master lm
                WHERE lm.is_active = 1
                  AND lm.location_code NOT IN ('QUA_SHELL','STAGING')
                ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position
            ");
            $locStmt->execute([$itemId, $itemId, $itemId, $itemId, $itemId]);
            $allLocs = $locStmt->fetchAll();

            $locations = array_map(fn($l) => [
                'code'      => $l['location_code'],
                'row'       => $l['row_name'],
                'aisle'     => $l['aisle'],
                'rack'      => $l['rack'],
                'occupied'  => (bool)$l['is_occupied'],
                'occ_batch' => $l['occ_batch'] ?? '',
                'occ_qty'   => floatval($l['occ_qty'] ?? 0),
                'occ_uom'   => $l['occ_uom'] ?? '',
                'occ_prod'  => $l['occ_prod'] ?? '',
            ], $allLocs);

            // Saved pallet assignments for this item
            $savedStmt = $db->prepare("
                SELECT pallet_seq, location_code, quantity
                FROM stock_locations
                WHERE inbound_item_id = ?
                ORDER BY pallet_seq
            ");
            $savedStmt->execute([$itemId]);
            $saved = array_values(array_map(fn($s) => [
                'pallet_seq'    => intval($s['pallet_seq']),
                'location_code' => $s['location_code'],
                'quantity'      => floatval($s['quantity']),
            ], $savedStmt->fetchAll()));

            // Auto-suggest: full pallets to B-E rows, partial to A row
            $freeBE = []; $freeA = [];
            foreach ($allLocs as $l) {
                if ($l['is_occupied']) continue;
                if ($l['row_name'] === 'A') $freeA[]  = $l['location_code'];
                else                        $freeBE[] = $l['location_code'];
            }
            $nFull = (int)floor($totalQty / $uomPlt);
            $rem   = round(($totalQty - $nFull * $uomPlt) * 1000) / 1000;
            $auto  = [];
            for ($i = 0; $i < $nFull; $i++) {
                $auto[] = ['pallet_seq' => $i+1, 'location_code' => $freeBE[$i] ?? '', 'quantity' => $uomPlt, 'is_full' => true];
            }
            if ($rem > 0) {
                $auto[] = ['pallet_seq' => $nFull+1, 'location_code' => $freeA[0] ?? ($freeBE[$nFull] ?? ''), 'quantity' => $rem, 'is_full' => false];
            }
            if (empty($auto)) {
                $auto = [['pallet_seq' => 1, 'location_code' => '', 'quantity' => $totalQty, 'is_full' => ($totalQty >= $uomPlt)]];
            }

            echo json_encode([
                'success'   => true,
                'locations' => $locations,
                'saved'     => $saved,
                'auto'      => $auto,
                'item'      => ['qty' => $totalQty, 'uom' => $uom, 'uom_per_pallet' => $uomPlt],
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action']);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
