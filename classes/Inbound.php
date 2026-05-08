<?php

class Inbound {

    public static function generateNumber() {
        $db = db();
        $year  = date('Y');
        $month = date('m');
        $prefix = "IN-{$year}{$month}-";

        $stmt = $db->prepare("SELECT order_number FROM inbound_orders
                               WHERE order_number LIKE ?
                               ORDER BY order_number DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();

        $seq = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        $maxTries = 20;
        while ($maxTries-- > 0) {
            $number = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM inbound_orders WHERE order_number = ? LIMIT 1");
            $chk->execute([$number]);
            if (!$chk->fetch()) return $number;
            $seq++;
        }
        return $prefix . date('His') . rand(10,99);
    }

    public static function getAll($status = null, $limit = null, $offset = 0, $odNo = null) {
        $db = db();
        $db->exec("SET SESSION group_concat_max_len = 65536");
        $conditions = [];
        $params = [];
        if ($status) { $conditions[] = "io.status = ?"; $params[] = $status; }
        if ($odNo)   { $conditions[] = "ii.od_number LIKE ?"; $params[] = "%$odNo%"; }
        $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT io.*,
                u.full_name as created_by_name,
                r.full_name as received_by_name,
                COUNT(DISTINCT ii.id) as total_items,
                SUM(ii.actual_qty) as total_qty,
                SUM(ii.pallet) as total_pallet,
                GROUP_CONCAT(DISTINCT ii.od_number ORDER BY ii.id SEPARATOR ', ') as od_numbers
                FROM inbound_orders io
                LEFT JOIN users u ON io.created_by = u.id
                LEFT JOIN users r ON io.received_by = r.id
                LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id
                $where
                GROUP BY io.id
                ORDER BY COALESCE(NULLIF(TRIM(io.shipment_no), ''), io.order_number) DESC,
                         io.order_date DESC, io.created_at DESC";

        if ($limit) $sql .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countAll($status = null, $odNo = null) {
        $db = db();
        $conditions = [];
        $params = [];
        if ($status) { $conditions[] = "io.status = ?"; $params[] = $status; }
        if ($odNo)   { $conditions[] = "ii.od_number LIKE ?"; $params[] = "%$odNo%"; }
        $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT COUNT(DISTINCT io.id) FROM inbound_orders io
                LEFT JOIN inbound_items ii ON io.id = ii.inbound_order_id
                $where";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    public static function getById($id) {
        $db = db();
        $stmt = $db->prepare("SELECT io.*,
                u.full_name as created_by_name,
                r.full_name as received_by_name
                FROM inbound_orders io
                LEFT JOIN users u ON io.created_by = u.id
                LEFT JOIN users r ON io.received_by = r.id
                WHERE io.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function getItems($inboundId) {
        $db = db();
        $stmt = $db->prepare("SELECT ii.*,
                p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
                FROM inbound_items ii
                JOIN products p ON ii.product_id = p.id
                WHERE ii.inbound_order_id = ?
                ORDER BY ii.id");
        $stmt->execute([$inboundId]);
        return $stmt->fetchAll();
    }

    

    public static function getItemLocations($itemId) {
        $db = db();
        $stmt = $db->prepare("SELECT sl.*,
                COALESCE(sl.original_quantity, sl.quantity) AS display_quantity
                FROM stock_locations sl
                WHERE sl.inbound_item_id = ?
                ORDER BY sl.pallet_seq");
        $stmt->execute([$itemId]);
        return $stmt->fetchAll();
    }

    

    public static function getOrderLocations($inboundId) {
        $db = db();
        $stmt = $db->prepare("SELECT sl.*,
                p.product_code, p.product_name,
                ii.batch_number, ii.uom, ii.exp_date
                FROM stock_locations sl
                JOIN inbound_items ii ON sl.inbound_item_id = ii.id
                JOIN products p ON ii.product_id = p.id
                WHERE ii.inbound_order_id = ?
                ORDER BY sl.pallet_seq");
        $stmt->execute([$inboundId]);
        return $stmt->fetchAll();
    }

    public static function create($data) {
        $db = db();
        try {
            $db->beginTransaction();

            
            $inboundNumber = !empty($data['shipment_no'])
                ? $data['shipment_no']
                : self::generateNumber();

            $stmt = $db->prepare("INSERT INTO inbound_orders
                    (order_number, order_date, carrier_name, po_number, shipment_no, do_number,
                     container_no, armada_no, production_date, expected_date,
                     received_by, received_date, status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            
            $receivedBy = null;
            if (!empty($data['received_by'])) {
                
                if (is_numeric($data['received_by'])) {
                    $receivedBy = (int)$data['received_by'];
                } else {
                    
                    $u = $db->prepare("SELECT id FROM users WHERE full_name = ? LIMIT 1");
                    $u->execute([$data['received_by']]);
                    $uRow = $u->fetch();
                    $receivedBy = $uRow['id'] ?? null;
                }
            }

            $stmt->execute([
                $inboundNumber,
                $data['order_date'],
                $data['carrier_name']    ?? null,
                $data['po_number']       ?? null,
                $data['shipment_no']     ?? null,
                $data['do_number']       ?? null,
                $data['container_no']    ?? null,
                $data['armada_no']       ?? null,
                ($data['production_date'] ?: null),
                ($data['expected_date']   ?: null),
                $receivedBy,
                ($data['received_date']   ?: null),
                $data['status'] ?? 'Draft',
                $data['notes']           ?? null,
                $_SESSION['user_id']
            ]);

            $inboundId = $db->lastInsertId();

            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    self::addItem($inboundId, $item);
                }
            }

            $db->commit();
            return $inboundId;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    

    public static function addItem($inboundId, $item) {
        $db = db();

        
        $stmt = $db->prepare("SELECT uom_type, uom_per_pallet, liters_per_unit,
                                      max_sku_qty, max_trans_qty
                               FROM products WHERE id = ?");
        $stmt->execute([$item['product_id']]);
        $productInfo = $stmt->fetch();

        if (!$productInfo) throw new Exception("Product not found");

        $quantity = floatval($item['quantity']);
        $uom      = $item['uom'] ?? $productInfo['uom_type'];

        
        $uomPerPallet = max(1, intval($productInfo['uom_per_pallet'] ?? 4));

        $pallet = self::calculatePallet($quantity, $uomPerPallet);

        
        $inbound = self::getById($inboundId);
        $mfgDate = $item['manufacture_date'] ?? null;
        $expDate = $item['exp_date'] ?? null;

        if (!empty($mfgDate)) {
            
            try {
                $d = new DateTime($mfgDate);
                $d->modify('+4 years');
                $expDate = $d->format('Y-m-d');
            } catch (Exception $e) {}
        } elseif (empty($expDate) && !empty($inbound['production_date'])) {
            
            $expDate = self::calculateExpiryDate($inbound['production_date']);
        }

        $batchNumber = $item['batch_number'] ?? $item['batch_no'] ?? null;

        $batchCol = 'batch_number';

        $stmt = $db->prepare("INSERT INTO inbound_items
                (inbound_order_id, od_number, so_number, product_id, {$batchCol}, location,
                 quantity, uom, actual_qty, pallet, pallet_no,
                 manufacture_date, exp_date, stock_status, in_process_status, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        
        $firstLocation = $item['location'] ?? null;
        if (!empty($item['pallet_locations']) && is_array($item['pallet_locations'])) {
            $firstLocation = $item['pallet_locations'][0]['location_code'] ?? $firstLocation;
        }

        $stmt->execute([
            $inboundId,
            $item['od_number'] ?? null,
            $item['so_number'] ?? null,
            $item['product_id'],
            $batchNumber,
            $firstLocation,
            $quantity,
            $uom,
            $item['actual_qty'] ?? $quantity,
            $pallet,
            $item['pallet_no'] ?? null,
            $item['manufacture_date'] ?? null,
            $expDate,
            $item['stock_status'] ?? 'Pending',
            $item['in_process_status'] ?? 'Dues In',
            $item['notes'] ?? null
        ]);

        $itemId = $db->lastInsertId();

        
        
        $skipPallets = ($item['in_process_status'] ?? '') === 'Unserviceable'
                    || ($item['stock_status'] ?? '') === 'Rejected';

        if (!$skipPallets) {
            if (!empty($item['pallet_locations']) && is_array($item['pallet_locations'])) {
                
                self::saveItemLocations($itemId, null, $item['pallet_locations'], $batchNumber, $uom);
            } elseif (!empty($item['location'])) {
                
                $dist = self::calculatePalletDistribution($quantity, $uomPerPallet);
                $palletLocs = [];
                foreach ($dist as $p) {
                    $palletLocs[] = array_merge($p, ['location_code' => $item['location']]);
                }
                self::saveItemLocations($itemId, null, $palletLocs, $batchNumber, $uom);
            }
        }

        return $itemId;
    }

    

    public static function saveItemLocations($itemId, $stockId, array $palletLocs, $batchNumber, $uom = 'EA') {
        $db = db();

        $db->prepare("DELETE FROM stock_locations WHERE inbound_item_id = ?")->execute([$itemId]);

        if (empty($palletLocs)) return;

        $placeholders = [];
        $values = [];
        foreach ($palletLocs as $p) {
            $qty = floatval($p['quantity']);
            $placeholders[] = "(?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available')";
            array_push($values,
                $stockId,
                $p['location_code'],
                $p['pallet_seq'] ?? $p['pallet_number'] ?? 1,
                $qty,
                $qty,
                $uom,
                ($p['is_full'] ?? true) ? 1 : 0,
                $batchNumber,
                $itemId
            );
        }

        $stmt = $db->prepare("INSERT INTO stock_locations
                (stock_id, location_code, pallet_seq, quantity, original_quantity, uom,
                 is_full_pallet, batch_number, inbound_item_id, status)
                VALUES " . implode(',', $placeholders));
        $stmt->execute($values);
    }

    public static function calculatePallet($quantity, $uomPerPallet) {
        if ($uomPerPallet == 0) return 0;
        return ceil($quantity / $uomPerPallet);
    }

    

    public static function calcPalletByLocation($quantity, $uomPerPallet, $locationCode) {
        if ($uomPerPallet <= 0) return 0;
        
        $level = isset($locationCode[4]) ? strtoupper($locationCode[4]) : 'B';
        if ($level === 'A') {
            
            return round($quantity / $uomPerPallet, 2);
        }
        
        return (int)ceil($quantity / $uomPerPallet);
    }

    public static function calculateExpiryDate($productionDate, $years = 4) {
        if (empty($productionDate)) return null;
        $date = date_create($productionDate);
        if (!$date) return null;
        date_add($date, date_interval_create_from_date_string("{$years} years"));
        return date_format($date, 'Y-m-d');
    }

    public static function calculatePalletDistribution($quantity, $uomPerPallet) {
        $fullPallets = intdiv((int)$quantity, (int)$uomPerPallet);
        $remainder   = $quantity % $uomPerPallet;
        $dist  = [];
        $palletNum = 1;

        for ($i = 0; $i < $fullPallets; $i++) {
            $dist[] = ['pallet_seq' => $palletNum++, 'quantity' => $uomPerPallet, 'is_full' => true];
        }
        if ($remainder > 0) {
            $dist[] = ['pallet_seq' => $palletNum, 'quantity' => $remainder, 'is_full' => false];
        }
        return $dist;
    }

    public static function update($id, $data) {
        $db = db();
        try {
            $db->beginTransaction();

            $stmt = $db->prepare("UPDATE inbound_orders SET
                    order_date = ?, carrier_name = ?, po_number = ?,
                    shipment_no = ?, do_number = ?,
                    container_no = ?, armada_no = ?,
                    production_date = ?, expected_date = ?, status = ?, notes = ?
                    WHERE id = ?");
            $stmt->execute([
                $data['order_date'], $data['carrier_name'] ?? null,
                $data['po_number']    ?? null,
                $data['shipment_no']  ?? null,
                $data['do_number']    ?? null,
                $data['container_no'] ?? null, $data['armada_no'] ?? null,
                ($data['production_date'] ?: null), ($data['expected_date'] ?: null),
                $data['status'] ?? 'Draft', $data['notes'] ?? null,
                $id
            ]);

            
            if (isset($data['received_date']) || isset($data['received_by'])) {
                $receivedBy = null;
                if (!empty($data['received_by'])) {
                    if (is_numeric($data['received_by'])) {
                        $receivedBy = (int)$data['received_by'];
                    } else {
                        $u = $db->prepare("SELECT id FROM users WHERE full_name = ? LIMIT 1");
                        $u->execute([$data['received_by']]);
                        $uRow = $u->fetch();
                        $receivedBy = $uRow['id'] ?? $_SESSION['user_id'];
                    }
                }
                $db->prepare("UPDATE inbound_orders SET received_by = ?, received_date = ? WHERE id = ?")
                   ->execute([$receivedBy, ($data['received_date'] ?: null), $id]);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function updateItem($itemId, $data) {
        $db = db();

        $batchCol = 'batch_number';

        $stmt = $db->prepare("UPDATE inbound_items SET
                {$batchCol} = ?, location = ?, quantity = ?, uom = ?, actual_qty = ?,
                manufacture_date = ?, exp_date = ?, stock_status = ?, notes = ?
                WHERE id = ?");

        $ok = $stmt->execute([
            $data['batch_number'] ?? $data['batch_no'] ?? null,
            $data['location'] ?? null,
            $data['quantity'] ?? 0,
            $data['uom'] ?? 'EA',
            $data['actual_qty'] ?? $data['quantity'] ?? 0,
            $data['manufacture_date'] ?? null,
            $data['exp_date'] ?? null,
            $data['stock_status'] ?? 'Accepted',
            $data['notes'] ?? null,
            $itemId
        ]);

        
        if ($ok && !empty($data['pallet_locations'])) {
            self::saveItemLocations(
                $itemId, null,
                $data['pallet_locations'],
                $data['batch_number'] ?? null,
                $data['uom'] ?? 'EA'
            );
        }

        return $ok;
    }

    

    public static function updateItemDates(int $itemId, ?string $manufactureDate, ?string $expDate): bool {
        $db = db();
        $stmt = $db->prepare("SELECT ii.*, io.status AS ord_status
                FROM inbound_items ii
                JOIN inbound_orders io ON ii.inbound_order_id = io.id
                WHERE ii.id = ?");
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }
        $mfg = ($manufactureDate !== null && $manufactureDate !== '') ? $manufactureDate : null;
        $exp = ($expDate !== null && $expDate !== '') ? $expDate : null;
        $db->prepare("UPDATE inbound_items SET manufacture_date = ?, exp_date = ? WHERE id = ?")
           ->execute([$mfg, $exp, $itemId]);

        if (($row['ord_status'] ?? '') === 'Completed') {
            $batch = $row['batch_number'] ?? $row['batch_no'] ?? null;
            $pid   = (int)$row['product_id'];
            
            
            $db->prepare("UPDATE stock s
                    JOIN stock_locations sl ON sl.stock_id = s.id
                    SET s.manufacture_date = ?, s.expiry_date = ?
                    WHERE sl.inbound_item_id = ?")
               ->execute([$mfg, $exp, $itemId]);
            
            $db->prepare("UPDATE stock SET manufacture_date = ?, expiry_date = ?
                    WHERE product_id = ? AND batch_number <=> ?")
               ->execute([$mfg, $exp, $pid, $batch]);
        }
        return true;
    }

    public static function updateItemPalletNo($itemId, $palletNo) {
        $db = db();
        $val = ($palletNo !== null && trim($palletNo) !== '') ? strtoupper(trim($palletNo)) : null;
        $db->prepare("UPDATE inbound_items SET pallet_no = ? WHERE id = ?")
           ->execute([$val, $itemId]);
        return true;
    }

    

    public static function updateItemQty(int $itemId, float $newQty): bool {
        $db = db();
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("SELECT ii.*, io.status AS ord_status, p.uom_per_pallet
                    FROM inbound_items ii
                    JOIN inbound_orders io ON io.id = ii.inbound_order_id
                    JOIN products p ON p.id = ii.product_id
                    WHERE ii.id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) throw new \Exception("Item tidak ditemukan");
            if ($item['in_process_status'] !== 'Dues In') {
                throw new \Exception("Hanya item berstatus Dues In yang bisa diedit qty-nya");
            }
            if ($newQty <= 0) throw new \Exception("Qty harus lebih dari 0");

            $uomPlt = max(1, (int)($item['uom_per_pallet'] ?? 4));
            $newPallet = (int)ceil($newQty / $uomPlt);

            
            $db->prepare("UPDATE inbound_items SET quantity=?, actual_qty=?, pallet=? WHERE id=?")
               ->execute([$newQty, $newQty, $newPallet, $itemId]);

            
            $batch = $item['batch_number'] ?? $item['batch_no'] ?? null;
            $db->prepare("UPDATE stock SET quantity=?, pallet=?, updated_at=NOW()
                    WHERE product_id=? AND batch_number<=>? AND stock_status IN ('Dues In','Pending')")
               ->execute([$newQty, $newPallet, $item['product_id'], $batch]);


            $locStmt = $db->prepare("SELECT pallet_seq, location_code
                                     FROM stock_locations
                                     WHERE inbound_item_id=? AND stock_id IS NULL
                                     ORDER BY pallet_seq ASC");
            $locStmt->execute([$itemId]);
            $existingLocs = $locStmt->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($existingLocs)) {
                $dist = self::calculatePalletDistribution($newQty, $uomPlt);
                $lastLoc = $existingLocs[count($existingLocs) - 1]['location_code'];
                $palletLocs = [];
                foreach ($dist as $i => $p) {
                    $loc = $existingLocs[$i]['location_code'] ?? $lastLoc;
                    $palletLocs[] = array_merge($p, ['location_code' => $loc]);
                }
                self::saveItemLocations($itemId, null, $palletLocs, $batch, $item['uom']);
            }

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function deleteItem($itemId) {
        $db = db();
        try {
            $db->beginTransaction();

            
            $item = $db->prepare("SELECT ii.*, io.status as inbound_status
                    FROM inbound_items ii
                    JOIN inbound_orders io ON ii.inbound_order_id = io.id
                    WHERE ii.id = ?");
            $item->execute([$itemId]);
            $itemData = $item->fetch();

            
            if ($itemData && $itemData['inbound_status'] === 'Completed') {
                $batchVal = $itemData['batch_number'] ?? $itemData['batch_no'] ?? null;
                $location = $itemData['location'] ?? null;
                $qty      = floatval($itemData['actual_qty'] ?: $itemData['quantity']);

                if ($batchVal && $qty > 0) {
                    
                    $st = $db->prepare("SELECT id, quantity, pallet FROM stock
                            WHERE product_id = ? AND batch_number = ?
                            " . ($location ? "AND location = ?" : "") . "
                            LIMIT 1");
                    $params = [$itemData['product_id'], $batchVal];
                    if ($location) $params[] = $location;
                    $st->execute($params);
                    $stockRow = $st->fetch();

                    if ($stockRow) {
                        $newQty = max(0, floatval($stockRow['quantity']) - $qty);
                        $palletRatio = $stockRow['quantity'] > 0 ? ($qty / floatval($stockRow['quantity'])) : 1;
                        $newPlt = max(0, floatval($stockRow['pallet']) - (floatval($stockRow['pallet']) * $palletRatio));

                        if ($newQty <= 0) {
                            $db->prepare("DELETE FROM stock WHERE id = ?")
                               ->execute([$stockRow['id']]);
                        } else {
                            $db->prepare("UPDATE stock SET quantity = ?, pallet = ?, updated_at = NOW() WHERE id = ?")
                               ->execute([$newQty, round($newPlt, 4), $stockRow['id']]);
                        }

                        
                        $db->prepare("INSERT INTO stock_ledger
                                (transaction_date, product_id, transaction_type, reference_type,
                                 reference_id, batch_number, quantity_in, quantity_out, uom, balance, notes)
                                VALUES (NOW(), ?, 'OUT', 'Inbound-Reversal', ?, ?, 0, ?, ?, 0, 'Item deleted from completed inbound')")
                           ->execute([
                               $itemData['product_id'],
                               $itemData['inbound_order_id'],
                               $batchVal,
                               $qty,
                               $itemData['uom'] ?? 'Drum',
                           ]);
                    }
                }
            }

            
            $db->prepare("DELETE FROM stock_locations WHERE inbound_item_id = ?")
               ->execute([$itemId]);
            $db->prepare("DELETE FROM inbound_items WHERE id = ?")
               ->execute([$itemId]);

            $db->commit();
            return true;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    

    public static function complete($id) {
        $db = db();
        try {
            $db->beginTransaction();

            $inbound = self::getById($id);
            $items   = self::getItems($id);

            $stockStatusMap = [
                'ATP'            => 'Available',
                'Picked'         => 'Available',
                'Dues In'        => 'Dues In',
                'Unserviceable'  => 'Rejected',
            ];

            
            
            // Ledger entries are written in real-time when items are marked GR/ATP/Unserviceable
            // complete() only handles stock creation and order status update

            foreach ($items as $item) {
                $batchVal  = $item['batch_number'] ?? $item['batch_no'] ?? null;
                $inProcess = $item['in_process_status'] ?? 'Dues In';

                if ($inProcess === 'Dues In') continue;

                // Goods Received items: ledger already written, no stock entry needed
                if ($inProcess === 'Goods Received') continue;

                $stockTarget = $stockStatusMap[$inProcess] ?? 'Available';
                $pid       = $item['product_id'];
                $totalQty  = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);


                $uomPerPlt = max(1, intval($item['uom_per_pallet'] ?? 4));

                
                
                
                
                
                

                
                $db->prepare("DELETE s FROM stock s
                    JOIN stock_locations sl ON sl.stock_id = s.id
                    WHERE sl.inbound_item_id = ?")->execute([$item['id']]);

                
                $db->prepare("UPDATE stock_locations SET stock_id=NULL
                    WHERE inbound_item_id=?")->execute([$item['id']]);

                
                
                
                $db->prepare("DELETE s FROM stock s
                    WHERE s.product_id = ? AND s.batch_number <=> ?
                    AND (s.location IS NULL OR s.location = 'UNALLOCATED')
                    AND NOT EXISTS (
                        SELECT 1 FROM stock_locations sl2 WHERE sl2.stock_id = s.id
                    )")->execute([$pid, $batchVal]);

                
                
                
                
                $db->prepare("DELETE FROM stock
                    WHERE product_id = ? AND batch_number <=> ?
                    AND stock_status IN ('Dues In', 'Pending')
                    AND NOT EXISTS (
                        SELECT 1 FROM stock_locations sl3 WHERE sl3.stock_id = stock.id
                    )")->execute([$pid, $batchVal]);

                
                
                

                if ($inProcess === 'Unserviceable') {
                    
                    $db->prepare("UPDATE inbound_items
                        SET stock_status='Rejected', location='QUA_SHELL'
                        WHERE id=?")->execute([$item['id']]);

                    $db->prepare("DELETE FROM stock_locations WHERE inbound_item_id=?")
                       ->execute([$item['id']]);

                    
                    $db->prepare("DELETE FROM stock
                        WHERE product_id=? AND batch_number<=>? AND location='QUA_SHELL' AND stock_status='Rejected'")
                       ->execute([$pid, $batchVal]);

                    
                    $plt = ($uomPerPlt > 0) ? (int)ceil($totalQty / $uomPerPlt) : 1;
                    $db->prepare("INSERT INTO stock
                        (product_id, batch_number, location, quantity, uom,
                         pallet, manufacture_date, expiry_date, stock_status)
                        VALUES (?,?,'QUA_SHELL',?,?,?,?,?,'Rejected')")
                       ->execute([$pid, $batchVal, $totalQty, $item['uom'],
                                  $plt, $item['manufacture_date'], $item['exp_date']]);

                    // Ledger already written when Unserviceable status was set
                    continue;
                }


                $palletRows = self::getItemLocations($item['id']);

                if (!empty($palletRows)) {
                    
                    
                    
                    $palletTotal = array_sum(array_column($palletRows, 'quantity'));
                    if ($palletTotal > 0.001 && abs($palletTotal - $totalQty) > 0.001) {
                        $scale = $totalQty / $palletTotal;
                        foreach ($palletRows as &$pr) {
                            $pr['quantity'] = round(floatval($pr['quantity']) * $scale, 4);
                        }
                        unset($pr);
                    }

                    
                    $locGroups = [];
                    foreach ($palletRows as $pl) {
                        $loc   = $pl['location_code'] ?? 'UNALLOCATED';
                        $qty   = floatval($pl['quantity']);
                        $batch = $pl['batch_number'] ?? $batchVal;
                        $key   = $loc . '|' . $batch;
                        if (!isset($locGroups[$key])) {
                            $locGroups[$key] = [
                                'loc'      => $loc,
                                'batch'    => $batch,
                                'qty'      => 0,
                                'rows'     => [],
                            ];
                        }
                        $locGroups[$key]['qty']  += $qty;
                        $locGroups[$key]['rows'][] = $pl['id'];
                    }

                    $assignedQty = 0;

                    foreach ($locGroups as $group) {
                        $loc   = $group['loc'];
                        $qty   = $group['qty'];
                        $batch = $group['batch'];
                        $assignedQty += $qty;

                        
                        
                        $level = isset($loc[4]) ? strtoupper($loc[4]) : 'B';
                        if ($level === 'A') {
                            $plt = ($uomPerPlt > 0) ? round($qty / $uomPerPlt, 4) : 1;
                        } else {
                            $plt = count($group['rows']);
                        }
                        $plt = max(1, $plt);

                        $db->prepare("INSERT INTO stock
                            (product_id, batch_number, location, quantity, uom,
                             pallet, manufacture_date, expiry_date, stock_status)
                            VALUES (?,?,?,?,?,?,?,?,?)")
                           ->execute([$pid, $batch, $loc, $qty, $item['uom'],
                                      $plt, $item['manufacture_date'], $item['exp_date'],
                                      $stockTarget]);
                        $newId = $db->lastInsertId();
                        foreach ($group['rows'] as $slId) {
                            $db->prepare("UPDATE stock_locations SET stock_id=? WHERE id=?")
                               ->execute([$newId, $slId]);
                        }
                    }

                    
                    
                    
                    $remainderQty = $totalQty - $assignedQty;
                    if ($remainderQty > 0.001) {
                        $fallbackLoc = 'UNALLOCATED';
                        $plt = max(1, ($uomPerPlt > 0) ? (int)ceil($remainderQty / $uomPerPlt) : 1);
                        $db->prepare("INSERT INTO stock
                            (product_id, batch_number, location, quantity, uom,
                             pallet, manufacture_date, expiry_date, stock_status)
                            VALUES (?,?,?,?,?,?,?,?,?)")
                           ->execute([$pid, $batchVal, $fallbackLoc, $remainderQty, $item['uom'],
                                      $plt, $item['manufacture_date'], $item['exp_date'],
                                      $stockTarget]);
                        $remStockId = (int)$db->lastInsertId();
                        
                        $db->prepare("INSERT INTO stock_locations
                            (stock_id, location_code, pallet_seq, quantity, original_quantity,
                             uom, is_full_pallet, batch_number, inbound_item_id, status)
                            VALUES (?, ?, 999, ?, ?, ?, 0, ?, ?, 'Available')")
                           ->execute([
                               $remStockId, $fallbackLoc,
                               $remainderQty, $remainderQty,
                               $item['uom'], $batchVal, $item['id'],
                           ]);
                    }
                } else {
                    
                    $loc = $item['location'] ?? 'UNALLOCATED';
                    $plt = ($uomPerPlt > 0) ? (int)ceil($totalQty / $uomPerPlt) : 1;
                    $plt = max(1, $plt);

                    $db->prepare("INSERT INTO stock
                        (product_id, batch_number, location, quantity, uom,
                         pallet, manufacture_date, expiry_date, stock_status)
                        VALUES (?,?,?,?,?,?,?,?,?)")
                       ->execute([$pid, $batchVal, $loc, $totalQty, $item['uom'],
                                  $plt, $item['manufacture_date'], $item['exp_date'],
                                  $stockTarget]);
                    $newId = $db->lastInsertId();
                    $db->prepare("UPDATE stock_locations SET stock_id=? WHERE inbound_item_id=?")
                       ->execute([$newId, $item['id']]);
                }

                $db->prepare("UPDATE inbound_items SET stock_status=? WHERE id=?")
                   ->execute([$stockTarget === 'Available' ? 'Accepted' : 'Pending', $item['id']]);

                // Ledger already written when GR/ATP status was set

                if ($stockTarget === 'Available') {
                    self::syncBatchToOutbound($pid, $batchVal, $item['exp_date']);
                }
            }

            $db->prepare("UPDATE inbound_orders SET status='Completed' WHERE id=?")
               ->execute([$id]);

            $db->commit();
            return true;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

        private static function syncBatchToOutbound($productId, $batchNumber, $expDate) {
        if (empty($batchNumber)) return;
        $db = db();

        
        $db->prepare("UPDATE outbound_items oi
                JOIN outbound_orders oo ON oi.outbound_order_id = oo.id
                SET oi.batch_number = ?, oi.batch_no = ?, oi.exp_date = ?
                WHERE oi.product_id = ?
                AND (oi.batch_number IS NULL OR oi.batch_number = '')
                AND oo.status IN ('Open','Picking','Draft')")
           ->execute([$batchNumber, $batchNumber, $expDate, $productId]);

        
        $db->prepare("UPDATE picklist_items pki
                JOIN picklists pkl ON pki.picklist_id = pkl.id
                JOIN outbound_orders oo ON pkl.outbound_order_id = oo.id
                SET pki.batch_number = ?, pki.batch_no = ?
                WHERE pki.product_id = ?
                AND (pki.batch_number IS NULL OR pki.batch_number = '')
                AND pkl.status IN ('Draft','Confirmed')
                AND oo.status IN ('Open','Picking','Draft')")
           ->execute([$batchNumber, $batchNumber, $productId]);
    }

    private static function addToLedger($item, $inbound, $batchVal = null) {
        $db = db();

        $isRejected = ($item['in_process_status'] ?? '') === 'Unserviceable'
                   || ($item['stock_status'] ?? '') === 'Rejected';

        // Use actual_qty if positive, fall back to quantity — same logic as complete()
        $ledgerQty = floatval($item['actual_qty'] ?? 0);
        if ($ledgerQty <= 0) $ledgerQty = floatval($item['quantity'] ?? 0);

        $stmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
                FROM stock_ledger
                WHERE product_id = ?
                  AND (location IS NULL OR location != 'QUA_SHELL')
                  AND transaction_type NOT IN ('TRANSFER_IN','TRANSFER_OUT')");
        $stmt->execute([$item['product_id']]);
        $row = $stmt->fetch();
        $currentBalance = floatval($row['running_balance'] ?? 0);

        $balance = $isRejected
            ? $currentBalance
            : $currentBalance + $ledgerQty;

        $locForLedger = $isRejected ? 'QUA_SHELL' : ($item['location'] ?? null);
        $inProcessLabel = $item['in_process_status'] ?? ($isRejected ? 'Unserviceable' : 'ATP');
        $notes = $isRejected
            ? '[Inbound] Unserviceable (QUA_SHELL) | In-Process: ' . $inProcessLabel . ' | ' . $inbound['order_number']
            : '[Inbound] ' . $inProcessLabel . ' | In-Process: ' . $inProcessLabel . ' | ' . $inbound['order_number'];

        $uomPP = max(1, intval($item['uom_per_pallet'] ?? 4));
        $palletForLedger = ($uomPP > 0) ? (int)ceil($ledgerQty / $uomPP) : intval($item['pallet'] ?? 0);

        $db->prepare("INSERT INTO stock_ledger
                (transaction_date, product_id, transaction_type, reference_type,
                 reference_id, reference_number, batch_number, quantity_in,
                 quantity_out, uom, pallet, balance, location, notes)
                VALUES (?, ?, 'IN', 'Inbound', ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)")
           ->execute([
               date('Y-m-d'), $item['product_id'],
               $inbound['id'], $inbound['order_number'],
               $batchVal ?? ($item['batch_number'] ?? $item['batch_no'] ?? null),
               $ledgerQty, $item['uom'], $palletForLedger,
               $balance, $locForLedger, $notes
           ]);
    }

    public static function regenerateLedger($id) {
        $db = db();
        try {
            $db->beginTransaction();

            $inbound = self::getById($id);
            $items   = self::getItems($id);

            $db->prepare("DELETE FROM stock_ledger WHERE reference_type='Inbound' AND reference_id=?")
               ->execute([$id]);

            foreach ($items as $item) {
                $batchVal  = $item['batch_number'] ?? $item['batch_no'] ?? null;
                $inProcess = $item['in_process_status'] ?? 'Dues In';
                if ($inProcess === 'Dues In') continue;
                self::addToLedger($item, $inbound, $batchVal);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function delete($id) {
        $db = db();
        try {
            $db->beginTransaction();

            $items = self::getItems($id);

            foreach ($items as $item) {
                $batch      = $item['batch_number'] ?? $item['batch_no'] ?? null;
                $qty        = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
                $pid        = $item['product_id'];
                $isUnserv   = ($item['in_process_status']??'') === 'Unserviceable'
                           || ($item['stock_status']??'') === 'Rejected';

                
                $linked = $db->prepare("SELECT DISTINCT stock_id FROM stock_locations
                    WHERE inbound_item_id=? AND stock_id IS NOT NULL");
                $linked->execute([$item['id']]);
                foreach ($linked->fetchAll() as $lnk) {
                    $db->prepare("DELETE FROM stock WHERE id=?")
                       ->execute([$lnk['stock_id']]);
                }

                
                if (!$isUnserv) {
                    $db->prepare("DELETE FROM stock
                        WHERE product_id=? AND batch_number<=>?
                        AND stock_status IN ('Available','Dues In','Reserved')")
                       ->execute([$pid, $batch]);
                }

                
                if ($isUnserv) {
                    $db->prepare("DELETE FROM stock
                        WHERE product_id=? AND batch_number<=>?
                        AND location='QUA_SHELL' AND stock_status='Rejected'")
                       ->execute([$pid, $batch]);
                }

                
                $db->prepare("DELETE FROM stock_ledger
                    WHERE reference_type='Inbound' AND reference_id=?
                    AND product_id=?")
                   ->execute([$id, $pid]);
            }

            
            $db->prepare("DELETE FROM stock_ledger
                WHERE reference_type='Inbound' AND reference_id=?")
               ->execute([$id]);

            
            $db->prepare("DELETE sl FROM stock_locations sl
                JOIN inbound_items ii ON sl.inbound_item_id = ii.id
                WHERE ii.inbound_order_id=?")->execute([$id]);
            $db->prepare("DELETE FROM location_allocations
                WHERE reference_type='Inbound' AND reference_id=?")->execute([$id]);
            $db->prepare("DELETE FROM inbound_items WHERE inbound_order_id=?")->execute([$id]);
            $db->prepare("DELETE FROM inbound_orders WHERE id=?")->execute([$id]);

            $db->commit();
            return true;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function getStats() {
        $db = db();
        $stats = [];

        $stmt = $db->prepare("SELECT COUNT(*) as count FROM inbound_orders
                WHERE YEAR(order_date) = YEAR(CURDATE()) AND MONTH(order_date) = MONTH(CURDATE())");
        $stmt->execute();
        $stats['this_month'] = $stmt->fetch()['count'];

        $stmt = $db->query("SELECT status, COUNT(*) as count FROM inbound_orders GROUP BY status");
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $db->query("SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Dues In'");
        $stats['dues_in'] = $stmt->fetch()['count'];
        $stats['pending'] = $stats['dues_in'];

        $stmt = $db->query("SELECT COUNT(*) as count FROM inbound_orders WHERE status = 'Receiving'");
        $stats['receiving'] = $stmt->fetch()['count'];

        return $stats;
    }
}
?>
