<?php

class Picklist {

    public static function generateNumber() {
        $db = db();
        $year  = date('Y');
        $month = date('m');
        $prefix = "PKL-{$year}{$month}-";

        $stmt = $db->prepare("SELECT picklist_number FROM picklists
                               WHERE picklist_number LIKE ? ORDER BY picklist_number DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();

        $seq = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        $maxTries = 20;
        while ($maxTries-- > 0) {
            $number = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM picklists WHERE picklist_number = ? LIMIT 1");
            $chk->execute([$number]);
            if (!$chk->fetch()) return $number;
            $seq++;
        }
        return $prefix . date('His') . rand(10,99);
    }

    

    public static function createFromOutbound($outboundId) {
        $db = db();
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("SELECT o.*, c.customer_name, c.address, c.city
                                  FROM outbound_orders o
                                  LEFT JOIN customers c ON o.customer_id = c.id
                                  WHERE o.id = ?");
            $stmt->execute([$outboundId]);
            $outbound = $stmt->fetch();

            if (!$outbound) throw new Exception("Outbound order not found");

            
            $stmt = $db->prepare("SELECT id FROM picklists WHERE outbound_order_id = ?");
            $stmt->execute([$outboundId]);
            $existing = $stmt->fetch();
            if ($existing) return $existing['id'];

            $picklistNumber = self::generateNumber();
            $stmt = $db->prepare("INSERT INTO picklists
                    (outbound_order_id, picklist_number, created_date, status, created_by)
                    VALUES (?, ?, CURDATE(), 'Draft', ?)");
            $stmt->execute([$outboundId, $picklistNumber, $_SESSION['user_id']]);
            $picklistId = $db->lastInsertId();

            
            $stmt = $db->prepare("SELECT oi.*,
                    p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
                    FROM outbound_items oi
                    JOIN products p ON oi.product_id = p.id
                    WHERE oi.outbound_order_id = ?
                    ORDER BY oi.exp_date ASC, oi.id ASC");
            $stmt->execute([$outboundId]);
            $items = $stmt->fetchAll();

            foreach ($items as $item) {
                $batchNumber = $item['batch_number'] ?? $item['batch_no'] ?? null;
                
                $uomPerPallet = max(1, intval($item['uom_per_pallet'] ?? 4));

                
                $stmt2 = $db->prepare("SELECT sl.*, oil.quantity as alloc_qty,
                        lm.zone, lm.aisle
                        FROM outbound_item_locations oil
                        JOIN stock_locations sl ON oil.stock_location_id = sl.id
                        LEFT JOIN location_master lm
                               ON lm.location_code COLLATE utf8mb4_general_ci
                                = sl.location_code  COLLATE utf8mb4_general_ci
                        WHERE oil.outbound_item_id = ?
                        ORDER BY sl.location_code, sl.pallet_seq");
                $stmt2->execute([$item['id']]);
                $locationRows = $stmt2->fetchAll();

                if (!empty($locationRows)) {
                    
                    $palletSeq = 1;
                    foreach ($locationRows as $lr) {
                        $locBatch = $lr['batch_number'] ?? $batchNumber;
                        $locCode  = $lr['location_code'] ?? '';
                        $locLevel = isset($locCode[4]) ? strtoupper($locCode[4]) : 'B';
                        $qty      = floatval($lr['alloc_qty']);
                        
                        $plt = $uomPerPallet > 0
                             ? ($locLevel === 'A'
                                ? round($qty / $uomPerPallet, 2)
                                : (int)ceil($qty / $uomPerPallet))
                             : 0;
                        $stmt3 = $db->prepare("INSERT INTO picklist_items
                                (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                                 location, quantity, uom, pallet, pallet_seq,
                                 stock_location_id, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                        $stmt3->execute([
                            $picklistId,
                            $item['id'],
                            $item['product_id'],
                            $locBatch, $locBatch,
                            $locCode,
                            $qty,
                            $item['uom_type'],
                            $plt,
                            $palletSeq++,
                            $lr['id']
                        ]);
                    }
                } else {
                    
                    $distribution = self::calculatePalletDistribution(
                        $item['actual_qty'] ?: $item['quantity'],
                        $uomPerPallet
                    );

                    $palletSeq = 1;
                    foreach ($distribution as $pallet) {
                        $slId = null;
                        if (!empty($item['location']) && !empty($batchNumber)) {
                            $slStmt = $db->prepare("SELECT id FROM stock_locations
                                    WHERE batch_number=? AND location_code=?
                                    AND status IN ('Available','Reserved')
                                    AND pallet_seq=? LIMIT 1");
                            $slStmt->execute([$batchNumber, $item['location'], $palletSeq]);
                            $slRow = $slStmt->fetch();
                            $slId  = $slRow['id'] ?? null;
                        }

                        $locCode2  = $item['location'] ?? 'TBD';
                        $locLevel2 = isset($locCode2[4]) ? strtoupper($locCode2[4]) : 'B';
                        $qty2      = floatval($pallet['quantity']);
                        $plt2      = $uomPerPallet > 0
                                   ? ($locLevel2 === 'A'
                                      ? round($qty2 / $uomPerPallet, 2)
                                      : (int)ceil($qty2 / $uomPerPallet))
                                   : 0;

                        $stmt3 = $db->prepare("INSERT INTO picklist_items
                                (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                                 location, quantity, uom, pallet, pallet_seq,
                                 stock_location_id, status)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
                        $stmt3->execute([
                            $picklistId,
                            $item['id'],
                            $item['product_id'],
                            $batchNumber, $batchNumber,
                            $locCode2,
                            $qty2,
                            $item['uom_type'],
                            $plt2,
                            $palletSeq++,
                            $slId
                        ]);
                    }
                }
            }

            $db->commit();
            return $picklistId;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function calculatePalletDistribution($quantity, $uomPerPallet) {
        $fullPallets = intdiv((int)$quantity, (int)$uomPerPallet);
        $remainder   = $quantity % $uomPerPallet;
        $dist        = [];
        for ($i = 0; $i < $fullPallets; $i++) {
            $dist[] = ['quantity' => $uomPerPallet, 'is_full' => true];
        }
        if ($remainder > 0) {
            $dist[] = ['quantity' => $remainder, 'is_full' => false];
        }
        return $dist;
    }

    public static function getById($id) {
        $db = db();
        $stmt = $db->prepare("SELECT pkl.*,
                o.order_number as outbound_number,
                o.so_number, o.do_number, o.shipment_number,
                o.destination, o.kota, o.armada_no, o.container_no,
                c.customer_name, c.address, c.city,
                u.full_name as created_by_name
                FROM picklists pkl
                JOIN outbound_orders o ON pkl.outbound_order_id = o.id
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN users u ON pkl.created_by = u.id
                WHERE pkl.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    

    public static function getItems($picklistId) {
        $db = db();
        $stmt = $db->prepare("SELECT pki.*,
                p.product_code, p.product_name,
                COALESCE(pki.batch_number, pki.batch_no) as resolved_batch,
                sl.pallet_seq as sl_pallet_seq,
                lm.zone, lm.aisle,
                oi.so_number  AS item_so_number,
                oi.od_number  AS item_od_number,
                COALESCE(ci.customer_name, co.customer_name) AS item_customer_name,
                COALESCE(NULLIF(od.ship_to_name,''), NULLIF(o.ship_to_name,'')) AS item_ship_to,
                COALESCE(NULLIF(od.kota,''), NULLIF(o.kota,''))                 AS item_kota
                FROM picklist_items pki
                JOIN products p ON pki.product_id = p.id
                LEFT JOIN stock_locations sl ON sl.id = pki.stock_location_id
                LEFT JOIN location_master lm
                       ON lm.location_code COLLATE utf8mb4_general_ci
                        = pki.location    COLLATE utf8mb4_general_ci
                LEFT JOIN outbound_items oi ON oi.id = pki.outbound_item_id
                LEFT JOIN outbound_destinations od ON od.id = oi.destination_id
                LEFT JOIN outbound_orders o ON o.id = oi.outbound_order_id
                LEFT JOIN customers ci ON ci.id = oi.customer_id
                LEFT JOIN customers co ON co.id = o.customer_id
                WHERE pki.picklist_id = ?
                ORDER BY pki.location, pki.pallet_seq, pki.id");
        $stmt->execute([$picklistId]);
        return $stmt->fetchAll();
    }

    public static function getAll($status = null, $limit = null, $offset = 0) {
        $db = db();
        $where  = $status ? "WHERE pkl.status = ?" : "";
        $params = $status ? [$status] : [];

        $sql = "SELECT pkl.*,
                o.order_number as outbound_number,
                o.so_number, o.do_number, o.shipment_number,
                c.customer_name,
                COUNT(pki.id) as total_items,
                SUM(pki.quantity) as total_qty,
                CEIL(SUM(pki.pallet)) as total_pallet
                FROM picklists pkl
                JOIN outbound_orders o ON pkl.outbound_order_id = o.id
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN picklist_items pki ON pkl.id = pki.picklist_id
                $where
                GROUP BY pkl.id
                ORDER BY pkl.created_date DESC, pkl.created_at DESC";

        if ($limit) {
            $sql .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countAll($status = null) {
        $db     = db();
        $where  = $status ? "WHERE pkl.status = ?" : "";
        $params = $status ? [$status] : [];
        $stmt   = $db->prepare("SELECT COUNT(*) FROM picklists pkl $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function getStats() {
        $db   = db();
        $rows = $db->query("SELECT status, COUNT(*) AS cnt FROM picklists GROUP BY status")->fetchAll();
        $s = ['total' => 0, 'pending' => 0, 'picking' => 0, 'completed' => 0];
        foreach ($rows as $r) {
            $s['total'] += $r['cnt'];
            if (in_array($r['status'], ['Draft', 'Confirmed'])) $s['pending'] += $r['cnt'];
            elseif ($r['status'] === 'Picked')    $s['picking']   += $r['cnt'];
            elseif ($r['status'] === 'Completed') $s['completed'] += $r['cnt'];
        }
        return $s;
    }

    

    public static function updateItem($itemId, $data) {
        $db = db();

        $stmt = $db->prepare("UPDATE picklist_items SET
                picked_quantity = ?,
                status = ?,
                location = COALESCE(?, location),
                batch_number = COALESCE(NULLIF(?, ''), batch_number),
                batch_no = COALESCE(NULLIF(?, ''), batch_no),
                notes = ?,
                picked_at = NOW()
                WHERE id = ?");

        return $stmt->execute([
            $data['picked_quantity'] ?? 0,
            $data['status'] ?? 'Pending',
            $data['location'] ?? null,
            $data['batch_number'] ?? null,
            $data['batch_number'] ?? null,
            $data['notes'] ?? null,
            $itemId
        ]);
    }

    public static function confirm($picklistId) {
        $db = db();
        return $db->prepare("UPDATE picklists SET status='Confirmed', confirmed_at=NOW() WHERE id=?")
                  ->execute([$picklistId]);
    }

    public static function complete($picklistId) {
        $db = db();
        return $db->prepare("UPDATE picklists SET status='Completed', completed_at=NOW() WHERE id=?")
                  ->execute([$picklistId]);
    }

    public static function delete($picklistId) {
        $db = db();
        try {
            $db->beginTransaction();
            $db->prepare("DELETE FROM picklist_items WHERE picklist_id=?")->execute([$picklistId]);
            $db->prepare("DELETE FROM picklists WHERE id=?")->execute([$picklistId]);
            $db->commit();
            return true;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function exportForPrint($picklistId) {
        return ['picklist' => self::getById($picklistId), 'items' => self::getItems($picklistId)];
    }
}
?>
