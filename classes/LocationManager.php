<?php

class LocationManager {

    
    
    

    

    public static function getAll($zone = null, $availableOnly = false, $perPage = null, $offset = null) {
        $db = db();

        $sql = "SELECT lm.*,
                    COUNT(sl.id) as occupied_pallets,
                    SUM(CASE WHEN sl.status = 'Available' THEN sl.quantity ELSE 0 END) as current_qty,
                    MAX(sl2.batch_number) as current_batch,
                    MAX(sl2.stock_id) as stock_id,
                    CASE
                        WHEN COUNT(CASE WHEN sl.status IN ('Available','Reserved') THEN 1 END) > 0 THEN 'Occupied'
                        ELSE 'Available'
                    END AS availability
                FROM location_master lm
                LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                    AND sl.status IN ('Available','Reserved')
                LEFT JOIN stock_locations sl2 ON sl2.id = (
                    SELECT id FROM stock_locations
                    WHERE location_code = lm.location_code
                    AND status IN ('Available','Reserved')
                    ORDER BY id DESC LIMIT 1
                )
                WHERE lm.is_active = 1";

        $params = [];
        if ($zone) {
            $sql .= " AND lm.zone = ?";
            $params[] = $zone;
        }

        $sql .= " GROUP BY lm.id";

        if ($availableOnly) {
            $sql .= " HAVING availability = 'Available'";
        }

        $sql .= " ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position";

        if ($perPage !== null) {
            $sql .= " LIMIT " . intval($perPage);
            if ($offset !== null) $sql .= " OFFSET " . intval($offset);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Mirrors v2 countLocations(): is_active=1, optional zone, availableOnly via NOT EXISTS. */
    public static function countAll($zone = null, $availableOnly = false): int {
        $db = db();
        $where = ['lm.is_active = 1'];
        $params = [];
        if ($zone) {
            $params[] = $zone;
            $where[] = 'lm.zone = ?';
        }
        if ($availableOnly) {
            $where[] = "NOT EXISTS (SELECT 1 FROM stock_locations slx WHERE slx.location_code = lm.location_code AND slx.status = 'Available')";
        }
        $stmt = $db->prepare("SELECT COUNT(*) FROM location_master lm WHERE " . implode(' AND ', $where));
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    

    public static function getAvailableLocations($count = 20, $preferZone = null) {
        $db = db();

        $sql = "SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name,
                       lm.position, lm.zone
                FROM location_master lm
                WHERE lm.is_active = 1
                AND lm.location_code NOT IN (
                    SELECT DISTINCT location_code FROM stock_locations
                    WHERE status IN ('Available','Reserved')
                )";

        $params = [];
        if ($preferZone) {
            
            $sql .= " ORDER BY CASE WHEN lm.zone = ? THEN 0 ELSE 1 END, lm.aisle, lm.rack, lm.row_name, lm.position";
            $params[] = $preferZone;
        } else {
            $sql .= " ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position";
        }

        $sql .= " LIMIT ?";
        $params[] = (int) $count;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    

    public static function isAvailable($locationCode) {
        $db = db();
        $stmt = $db->prepare("SELECT COUNT(*) FROM stock_locations
                               WHERE location_code = ? AND status IN ('Available','Reserved')");
        $stmt->execute([$locationCode]);
        return $stmt->fetchColumn() == 0;
    }

    

    public static function getLocationInfo($locationCode) {
        $db = db();
        $stmt = $db->prepare("SELECT lm.*,
                sl.id as sl_id, sl.quantity, sl.batch_number, sl.uom, sl.status as stock_status,
                st.expiry_date, p.product_name, p.product_code
                FROM location_master lm
                LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                    AND sl.status IN ('Available','Reserved')
                LEFT JOIN stock st ON sl.stock_id = st.id
                LEFT JOIN products p ON st.product_id = p.id
                WHERE lm.location_code = ?
                LIMIT 1");
        $stmt->execute([$locationCode]);
        return $stmt->fetch();
    }

    
    
    

    

    public static function suggestLocationsForInbound($quantity, $uom, $uomPerPallet = 4, $preferZone = null) {
        if ($uomPerPallet <= 0) $uomPerPallet = 4;

        $fullPallets  = intdiv((int)$quantity, (int)$uomPerPallet);
        $remainder    = fmod($quantity, $uomPerPallet);
        $totalPallets = $fullPallets + ($remainder > 0 ? 1 : 0);

        
        $fullLocations = self::getAvailableLocationsByLevel($fullPallets + 20, $preferZone, ['B','C','D','E']);

        
        if (count($fullLocations) < $fullPallets) {
            $extraNeeded   = $fullPallets - count($fullLocations);
            $extraLocs     = self::getAvailableLocations($extraNeeded + 10, $preferZone);
            
            $existingCodes = array_column($fullLocations, 'location_code');
            foreach ($extraLocs as $el) {
                if (!in_array($el['location_code'], $existingCodes)) {
                    $fullLocations[]  = $el;
                    $existingCodes[]  = $el['location_code'];
                }
                if (count($fullLocations) >= $fullPallets) break;
            }
        }

        
        $canAssignFull = min($fullPallets, count($fullLocations));

        
        $partialLocations = [];
        if ($remainder > 0) {
            $partialLocations = self::getAvailableLocationsByLevel(5, $preferZone, ['A']);
            if (empty($partialLocations)) {
                
                $usedCodes   = array_slice(array_column($fullLocations, 'location_code'), 0, $canAssignFull);
                $anyLocs     = self::getAvailableLocations(10, $preferZone);
                foreach ($anyLocs as $al) {
                    if (!in_array($al['location_code'], $usedCodes)) {
                        $partialLocations[] = $al;
                        break;
                    }
                }
            }
        }

        $pallets   = [];
        $palletSeq = 1;

        
        for ($i = 0; $i < $canAssignFull; $i++) {
            $pallets[] = [
                'pallet_seq'    => $palletSeq,
                'location_code' => $fullLocations[$i]['location_code'],
                'quantity'      => $uomPerPallet,
                'is_full'       => true,
                'uom'           => $uom,
            ];
            $palletSeq++;
        }

        
        if ($remainder > 0) {
            $pallets[] = [
                'pallet_seq'    => $palletSeq,
                'location_code' => $partialLocations[0]['location_code'] ?? 'STAGING',
                'quantity'      => $remainder,
                'is_full'       => false,
                'uom'           => $uom,
            ];
        }

        $success = ($canAssignFull === $fullPallets);
        return [
            'success'       => $success,
            'message'       => $success ? '' : "Hanya {$canAssignFull}/{$fullPallets} lokasi full pallet tersedia — sisanya tidak ter-assign",
            'pallets'       => $pallets,
            'total_pallets' => $totalPallets,
        ];
    }

    

    public static function getAvailableLocationsByLevel($count = 20, $preferZone = null, $levels = ['B','C','D','E']) {
        $db = db();
        $placeholders = implode(',', array_fill(0, count($levels), '?'));

        $sql = "SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name, lm.position, lm.zone
                FROM location_master lm
                WHERE lm.is_active = 1
                AND lm.row_name IN ({$placeholders})
                AND lm.location_code NOT IN (
                    SELECT DISTINCT location_code FROM stock_locations
                    WHERE status IN ('Available','Reserved')
                )";

        $params = $levels;

        if ($preferZone) {
            $sql .= " ORDER BY CASE WHEN lm.zone = ? THEN 0 ELSE 1 END, lm.aisle, lm.rack, lm.row_name, lm.position";
            $params[] = $preferZone;
        } else {
            $sql .= " ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position";
        }

        $sql .= " LIMIT ?";
        $params[] = (int)$count;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    

    public static function commitInboundLocations($stockId, $itemId, $batchNumber, array $pallets) {
        $db = db();

        foreach ($pallets as $pallet) {
            $stmt = $db->prepare("INSERT INTO stock_locations
                    (stock_id, location_code, pallet_seq, quantity, uom,
                     is_full_pallet, batch_number, inbound_item_id, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Available')
                    ON DUPLICATE KEY UPDATE
                        quantity = VALUES(quantity),
                        status   = 'Available'");
            $stmt->execute([
                $stockId,
                $pallet['location_code'],
                $pallet['pallet_seq'],
                $pallet['quantity'],
                $pallet['uom'] ?? 'EA',
                $pallet['is_full'] ? 1 : 0,
                $batchNumber,
                $itemId,
            ]);
        }

        return count($pallets);
    }

    
    
    

    

    public static function getFEFOByLocation($productId, $requiredQty) {
        $db = db();

        
        $stmt = $db->prepare("
            SELECT sl.id as sl_id, sl.location_code, sl.quantity,
                   sl.batch_number, sl.pallet_seq, sl.uom, sl.is_full_pallet,
                   st.id as stock_id, st.expiry_date, st.manufacture_date,
                   st.product_id
            FROM stock_locations sl
            JOIN stock st ON sl.stock_id = st.id
            WHERE st.product_id = ?
              AND sl.status = 'Available'
              AND sl.quantity > 0
              AND (st.expiry_date IS NULL OR st.expiry_date > CURDATE())
            ORDER BY
                CASE WHEN st.expiry_date IS NULL THEN 1 ELSE 0 END,
                st.expiry_date ASC,
                sl.id ASC
        ");
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll();

        $allocations = [];
        $remaining = $requiredQty;

        foreach ($rows as $row) {
            if ($remaining <= 0) break;

            $take = min($row['quantity'], $remaining);
            $allocations[] = [
                'sl_id'         => $row['sl_id'],
                'stock_id'      => $row['stock_id'],
                'location_code' => $row['location_code'],
                'pallet_seq'    => $row['pallet_seq'],
                'batch_number'  => $row['batch_number'],
                'expiry_date'   => $row['expiry_date'],
                'quantity'      => $take,
                'uom'           => $row['uom'],
                'is_full'       => ($take == $row['quantity']),
            ];
            $remaining -= $take;
        }

        if ($remaining > 0) {
            return [
                'success' => false,
                'message' => 'Stok tidak cukup. Tersedia: ' . ($requiredQty - $remaining) . ', Dibutuhkan: ' . $requiredQty,
                'allocations' => []
            ];
        }

        return ['success' => true, 'allocations' => $allocations];
    }

    

    public static function reserveForOutbound(array $allocations) {
        $db = db();
        foreach ($allocations as $a) {
            $take = $a['quantity'];
            $slId = $a['sl_id'];

            
            $stmt = $db->prepare("SELECT quantity FROM stock_locations WHERE id = ?");
            $stmt->execute([$slId]);
            $current = $stmt->fetchColumn();

            if ($current <= $take) {
                
                $db->prepare("UPDATE stock_locations SET status='Reserved' WHERE id=?")->execute([$slId]);
            } else {
                
                $db->prepare("UPDATE stock_locations SET quantity = quantity - ? WHERE id=?")->execute([$take, $slId]);
                
                $stmt = $db->prepare("SELECT * FROM stock_locations WHERE id=?");
                $stmt->execute([$slId]);
                $orig = $stmt->fetch();
                $ins = $db->prepare("INSERT INTO stock_locations
                        (stock_id, location_code, pallet_seq, quantity, uom, is_full_pallet,
                         batch_number, inbound_item_id, status)
                        VALUES (?,?,?,?,?,0,?,?,'Reserved')");
                $ins->execute([
                    $orig['stock_id'], $orig['location_code'],
                    $orig['pallet_seq'], $take, $orig['uom'],
                    $orig['batch_number'], $orig['inbound_item_id']
                ]);
            }
        }
    }

    

    public static function deductAfterShip(array $allocations) {
        $db = db();
        foreach ($allocations as $a) {
            
            $db->prepare("UPDATE stock_locations SET status='Picked' WHERE id=?")
               ->execute([$a['sl_id']]);

            
            $db->prepare("UPDATE stock SET quantity = GREATEST(0, quantity - ?) WHERE id=?")
               ->execute([$a['quantity'], $a['stock_id']]);
        }
    }

    

    public static function releaseReservation(array $allocations) {
        $db = db();
        foreach ($allocations as $a) {
            $db->prepare("UPDATE stock_locations SET status='Available' WHERE id=?")
               ->execute([$a['sl_id']]);
        }
    }

    
    
    

    

    public static function getPicklistLocations($outboundItemId) {
        $db = db();
        $stmt = $db->prepare("SELECT sl.*, lm.zone
                FROM stock_locations sl
                LEFT JOIN location_master lm ON lm.location_code COLLATE utf8mb4_general_ci = sl.location_code COLLATE utf8mb4_general_ci
                WHERE sl.status IN ('Reserved','Available')
                AND sl.id IN (
                    SELECT stock_location_id FROM outbound_items WHERE id = ?
                )
                ORDER BY sl.pallet_seq");
        $stmt->execute([$outboundItemId]);
        return $stmt->fetchAll();
    }

    

    public static function getZoneSummary() {
        $db = db();
        $stmt = $db->query("
            SELECT lm.zone,
                COUNT(lm.id) as total_locations,
                COUNT(CASE WHEN sl.id IS NOT NULL THEN 1 END) as occupied,
                COUNT(CASE WHEN sl.id IS NULL THEN 1 END) as available
            FROM location_master lm
            LEFT JOIN stock_locations sl ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                AND sl.status IN ('Available','Reserved')
            WHERE lm.is_active = 1
            GROUP BY lm.zone
            ORDER BY lm.zone
        ");
        return $stmt->fetchAll();
    }
}
?>
