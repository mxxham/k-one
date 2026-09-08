<?php

class Outbound {

    

    public static function displayOrderNo(array $outbound): string {
        $ship = trim((string)($outbound['shipment_number'] ?? ''));
        if ($ship !== '') {
            return $ship;
        }
        $ord = trim((string)($outbound['order_number'] ?? ''));
        return $ord !== '' ? $ord : '-';
    }

    

    public static function saveDestinations(int $outboundId, array $names, array $locations, array $streets, array $kotas, array $notes, $db = null): void {
        $db = $db ?? db();
        
        $db->prepare("DELETE FROM outbound_destinations WHERE outbound_id = ?")->execute([$outboundId]);
        foreach ($names as $i => $name) {
            $name = trim($name ?? '');
            if ($name === '') continue;
            $db->prepare("INSERT INTO outbound_destinations
                    (outbound_id, seq, ship_to_name, ship_to_location, ship_to_street, kota, notes)
                    VALUES (?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                   $outboundId,
                   $i + 1,
                   $name,
                   trim($locations[$i] ?? ''),
                   trim($streets[$i]   ?? ''),
                   trim($kotas[$i]     ?? ''),
                   trim($notes[$i]     ?? ''),
               ]);
        }
    }

    

public static function generateNumber($db = null): string {
        $db = $db ?? db();
        $year  = date('Y');
        $month = date('m');
        $prefix = "OUT-{$year}{$month}-";

        // Track generated numbers within this request to avoid duplicates
        static $generated = [];
        if (!isset($generated[$prefix])) {
            $generated[$prefix] = [];
        }

        // Find the highest existing sequence in DB
        $stmt = $db->prepare("SELECT order_number FROM outbound_orders
                               WHERE order_number LIKE ?
                               ORDER BY order_number DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();

        $baseSeq = $last ? ((int) substr($last, strrpos($last, '-') + 1)) : 0;

        // Start from baseSeq + 1, then increment for each generated in this request
        $seq = max($baseSeq + 1, count($generated[$prefix]) + 1);
        while (in_array($seq, $generated[$prefix])) {
            $seq++;
        }

        $maxTries = 20;
        while ($maxTries-- > 0) {
            $number = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM outbound_orders WHERE order_number = ? LIMIT 1");
            $chk->execute([$number]);
            if (!$chk->fetch() && !in_array($seq, $generated[$prefix])) {
                $generated[$prefix][] = $seq;
                return $number;
            }
            $seq++;
        }
        return $prefix . date('His') . rand(10,99);
    }

    

    public static function getAll($status = null, $limit = null, $offset = 0, $odNo = null, $search = null, $db = null) {
        $db = $db ?? db();
        $db->exec("SET SESSION group_concat_max_len = 65536");
        $conditions = [];
        $params = [];
        if ($status) { $conditions[] = "o.status = ?"; $params[] = $status; }
        if ($odNo)   { $conditions[] = "oi.od_number LIKE ?"; $params[] = "%$odNo%"; }
        if ($search) {
            $conditions[] = "(o.order_number LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT o.*,
                c.customer_name, c.customer_code, c.city,
                u.full_name as created_by_name,
                s.full_name as shipped_by_name,
                COUNT(DISTINCT oi.id) as total_items,
                SUM(oi.actual_qty) as total_qty,
                SUM(oi.pallet) as total_pallet,
                GROUP_CONCAT(DISTINCT oi.od_number ORDER BY oi.id SEPARATOR ', ') as od_numbers
                FROM outbound_orders o
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN users u ON o.created_by = u.id
                LEFT JOIN users s ON o.shipped_by = s.id
                LEFT JOIN outbound_items oi ON o.id = oi.outbound_order_id
                $where
                GROUP BY o.id
                ORDER BY o.order_date DESC, o.created_at DESC";

        if ($limit) {
            $sql .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countAll($status = null, $odNo = null, $search = null, $db = null) {
        $db = $db ?? db();
        $conditions = [];
        $params = [];
        if ($status) { $conditions[] = "o.status = ?"; $params[] = $status; }
        if ($odNo)   { $conditions[] = "oi.od_number LIKE ?"; $params[] = "%$odNo%"; }
        if ($search) {
            $conditions[] = "(o.order_number LIKE ? OR c.customer_name LIKE ? OR c.customer_code LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";
        $sql = "SELECT COUNT(DISTINCT o.id) FROM outbound_orders o
                LEFT JOIN outbound_items oi ON o.id = oi.outbound_order_id
                LEFT JOIN customers c ON o.customer_id = c.id
                $where";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }

    

    public static function getById($id, $db = null) {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT o.*,
                c.customer_name, c.customer_code, c.address, c.city,
                u.full_name as created_by_name,
                s.full_name as shipped_by_name
                FROM outbound_orders o
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN users u ON o.created_by = u.id
                LEFT JOIN users s ON o.shipped_by = s.id
                WHERE o.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    

    public static function getItems($outboundId, $db = null) {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT oi.*,
                p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                COALESCE(oi.batch_number, oi.batch_no) AS resolved_batch,
                oi.location                            AS resolved_location,
                oi.exp_date                            AS resolved_expiry,
                COALESCE(NULLIF(od.ship_to_name,''), NULLIF(o.ship_to_name,'')) AS item_ship_to_name,
                COALESCE(NULLIF(od.ship_to_location,''), NULLIF(od.kota,''), NULLIF(o.ship_to_location,''), NULLIF(o.kota,'')) AS item_ship_to_location,
                NULLIF(od.ship_to_street,'') AS item_ship_to_street,
                COALESCE(NULLIF(od.kota,''), NULLIF(o.kota,'')) AS item_ship_to_kota,
                COALESCE(ci.customer_name, co.customer_name) AS order_customer_name,
                COALESCE(ci.customer_code, co.customer_code) AS order_customer_code,
                (SELECT ii.id FROM inbound_items ii
                 WHERE ii.cross_dock_outbound_order_id = oi.outbound_order_id
                   AND ii.product_id = oi.product_id
                 ORDER BY ii.id LIMIT 1) AS cross_dock_inbound_item_id,
                (SELECT ii.batch_number FROM inbound_items ii
                 WHERE ii.cross_dock_outbound_order_id = oi.outbound_order_id
                   AND ii.product_id = oi.product_id
                 ORDER BY ii.id LIMIT 1) AS cross_dock_batch,
                (SELECT io.order_number FROM inbound_items ii2
                 JOIN inbound_orders io ON io.id = ii2.inbound_order_id
                 WHERE ii2.cross_dock_outbound_order_id = oi.outbound_order_id
                   AND ii2.product_id = oi.product_id
                 ORDER BY ii2.id LIMIT 1) AS cross_dock_inbound_number
                FROM outbound_items oi
                JOIN products p ON oi.product_id = p.id
                LEFT JOIN outbound_destinations od ON oi.destination_id = od.id
                LEFT JOIN outbound_orders o ON oi.outbound_order_id = o.id
                LEFT JOIN customers ci ON oi.customer_id = ci.id
                LEFT JOIN customers co ON o.customer_id = co.id
                WHERE oi.outbound_order_id = ?
                ORDER BY oi.id");
        $stmt->execute([$outboundId]);
        $rows = $stmt->fetchAll();

        
        $stmtStock = $db->prepare(
            "SELECT COUNT(*) FROM stock
             WHERE product_id = ?
               AND batch_number <=> ?
               AND stock_status = 'Available'
               AND quantity > 0
               AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))"
        );
        $stmtStockCd = $db->prepare(
            "SELECT COUNT(*) FROM stock
             WHERE product_id = ?
               AND batch_number <=> ?
               AND stock_status = 'Available'
               AND quantity > 0
               AND (location IS NULL OR location NOT IN ('QUA_SHELL'))"
        );
        $stmtAutoAtp = $db->prepare(
            "UPDATE outbound_items SET in_process_status = 'ATP' WHERE id = ?"
        );
        $stmtResetGr = $db->prepare(
            "UPDATE outbound_items SET in_process_status = 'Goods Received' WHERE id = ?"
        );

        foreach ($rows as &$row) {
            if (empty($row['resolved_batch'])) {
                $fefo = $db->prepare("SELECT batch_number, location, expiry_date
                        FROM stock
                        WHERE product_id = ? AND quantity > 0
                        AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
                        AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))
                        ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC
                        LIMIT 1");
                $fefo->execute([$row['product_id']]);
                $f = $fefo->fetch();
                if ($f) {
                    $row['resolved_batch']   = $f['batch_number'];
                    $row['resolved_location'] = $f['location'];
                    $row['resolved_expiry']  = $f['expiry_date'];
                }
            }

            $row['batch_number'] = $row['resolved_batch'];
            $row['location']     = $row['resolved_location'];
            $row['expiry_date']  = $row['resolved_expiry'];
            $row['exp_date']     = $row['resolved_expiry'];

            // Auto-sync process_status dari stock (skip jika Unserviceable)
            $currentStatus = $row['in_process_status'] ?? 'Goods Received';
            if ($currentStatus !== 'Unserviceable') {
                $batch = $row['batch_number'];
                // Cross-docked lines arrive staged at STAGING (bypassing putaway),
                // so the stock-availability probe must consider STAGING for them.
                $probe = !empty($row['cross_dock_inbound_item_id']) ? $stmtStockCd : $stmtStock;
                $probe->execute([$row['product_id'], $batch]);
                $inStock = (int)$probe->fetchColumn() > 0;

                if ($inStock && $currentStatus !== 'ATP') {
                    $stmtAutoAtp->execute([$row['id']]);
                    $row['in_process_status'] = 'ATP';
                } elseif (!$inStock && $currentStatus === 'ATP') {
                    $stmtResetGr->execute([$row['id']]);
                    $row['in_process_status'] = 'Goods Received';
                }
            }
        }
        unset($row);
        return $rows;
    }

    

    public static function getItemPickedLocations($outboundItemId, $db = null) {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT oil.quantity AS picked_qty,
                COALESCE(sl.location_code, oi.location) AS location_code,
                sl.pallet_seq,
                COALESCE(sl.batch_number, oi.batch_number, oi.batch_no) AS batch_number,
                COALESCE(sl.original_quantity, sl.quantity, oil.quantity) AS original_qty,
                sl.is_full_pallet,
                COALESCE(sl.uom, oi.uom) AS uom
                FROM outbound_item_locations oil
                JOIN outbound_items oi ON oi.id = oil.outbound_item_id
                LEFT JOIN stock_locations sl ON oil.stock_location_id = sl.id
                WHERE oil.outbound_item_id = ?
                ORDER BY COALESCE(sl.location_code, oi.location), sl.pallet_seq");
        $stmt->execute([$outboundItemId]);
        return $stmt->fetchAll();
    }

    

    public static function getAvailableStock($productId, $quantity = 0, ?string $location = null, $db = null) {
        $db = $db ?? db();
        $location = $location !== null ? trim($location) : '';
        if ($location !== '') {
            $locClause = "AND LOWER(TRIM(st.location)) = LOWER(?)";
            $params    = [$productId, $location];
        } else {
            $locClause = "";
            $params    = [$productId];
        }
        $stmt = $db->prepare("SELECT
                st.id, st.product_id, st.batch_number, st.location,
                st.quantity, st.uom, st.pallet,
                st.manufacture_date,
                COALESCE(
                    (SELECT ii_exp.exp_date
                     FROM stock_locations sl_exp
                     JOIN inbound_items ii_exp ON ii_exp.id = sl_exp.inbound_item_id
                     WHERE sl_exp.stock_id = st.id
                     ORDER BY ii_exp.id DESC
                     LIMIT 1),
                    st.expiry_date
                ) AS expiry_date,
                st.stock_status,
                p.product_name, p.uom_type, p.uom_per_pallet
                FROM stock st
                JOIN products p ON st.product_id = p.id
                WHERE st.product_id = ?
                AND (st.stock_status IN ('Available','Dues In') OR st.stock_status IS NULL OR st.stock_status = '')
                AND (st.hold_status = 'available' OR st.hold_status IS NULL)
                AND st.quantity > 0
                AND (st.location IS NULL OR st.location NOT IN ('QUA_SHELL','STAGING'))
                $locClause
                ORDER BY
                    CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC,
                    expiry_date ASC,
                    st.id ASC");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    

    public static function getTotalAvailableQty(int $productId, $db = null): float {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT COALESCE(SUM(quantity),0) FROM stock
                WHERE product_id = ?
                AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
                AND (hold_status = 'available' OR hold_status IS NULL)
                AND quantity > 0
                AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))");
        $stmt->execute([$productId]);
        return floatval($stmt->fetchColumn());
    }

    public static function getFEFOAllocation($productId, $requiredQty, ?string $location = null) {
        $availableStock = self::getAvailableStock($productId, 0, $location);

        $allocation   = [];
        $remainingQty = round(floatval($requiredQty), 6);

        foreach ($availableStock as $stock) {
            if ($remainingQty <= 1e-9) {
                break;
            }
            $availableQty = floatval($stock['quantity']);
            $take = min($remainingQty, $availableQty);
            $allocation[] = [
                'stock_id'     => $stock['id'],
                'batch_number' => $stock['batch_number'],
                'location'     => $stock['location'],
                'expiry_date'  => $stock['expiry_date'],
                'required_qty' => $take,
                'available_qty'=> $availableQty,
                'is_partial'   => $take < $availableQty,
            ];
            $remainingQty = round($remainingQty - $take, 6);
        }

        $totalAvailable = 0.0;
        foreach ($availableStock as $row) {
            $totalAvailable += floatval($row['quantity'] ?? 0);
        }

        return [
            'allocation'      => $allocation,
            'sufficient'      => $remainingQty <= 1e-5,
            'shortage'        => max(0, $remainingQty),
            'total_available' => $totalAvailable,
        ];
    }

    

    public static function addItemWithFEFO($outboundId, $item, $db = null) {
        $db = $db ?? db();

        
        $product = $db->prepare("SELECT uom_type, uom_per_pallet, max_sku_qty, max_trans_qty
                                 FROM products WHERE id = ?");
        $product->execute([$item['product_id']]);
        $productInfo = $product->fetch();

        if (!$productInfo) {
            throw new Exception("Product not found");
        }

        $quantity = floatval($item['quantity']);
        $uom = $item['uom'] ?? $productInfo['uom_type'];

        
        $uomPerPallet = max(1, intval($productInfo['uom_per_pallet'] ?? DEFAULT_UOM_PER_PALLET));

        
        $manualLocs = $item['manual_locs'] ?? null; 
        $manualLoc  = !empty($item['manual_location']) ? trim($item['manual_location']) : null;

        
        $stmtAvail = $db->prepare(
            "SELECT COALESCE(SUM(quantity), 0) as total FROM stock WHERE product_id = ?
             AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
             AND (hold_status = 'available' OR hold_status IS NULL)
             AND quantity > 0
             AND (location IS NULL OR location NOT IN ('QUA_SHELL','STAGING'))"
        );
        $stmtAvail->execute([$item['product_id']]);
        $totalAvailable = floatval($stmtAvail->fetch()['total']);

        if ($quantity > $totalAvailable) {
            throw new Exception(
                "Stok tidak mencukupi. Stok tersedia: " . number_format($totalAvailable, 0) .
                ", Qty diminta: " . number_format($quantity, 0)
            );
        }

        
        if ($manualLocs && count($manualLocs) > 0) {
            
            $manualTotal = array_sum(array_column($manualLocs, 'qty'));
            if (abs($manualTotal - $quantity) > 0.01) {
                throw new Exception(
                    "Total qty bin manual (" . number_format($manualTotal, 0) .
                    ") tidak sama dengan qty order (" . number_format($quantity, 0) . ")"
                );
            }
            $allAllocations = [];
            foreach ($manualLocs as $binEntry) {
                $binLoc = trim($binEntry['location'] ?? '');
                $binQty = floatval($binEntry['qty'] ?? 0);
                if ($binQty <= 0) continue;
                $binFefo = self::getFEFOAllocation($item['product_id'], $binQty, $binLoc ?: null);
                if (!$binFefo['sufficient']) {
                    throw new Exception(
                        "Stok di bin '$binLoc' tidak mencukupi. " .
                        "Diminta: " . number_format($binQty, 0) .
                        ", Tersedia: " . number_format($binFefo['total_available'], 0)
                    );
                }
                foreach ($binFefo['allocation'] as &$alloc) {
                    $alloc['_bin'] = $binLoc;
                }
                unset($alloc);
                $allAllocations = array_merge($allAllocations, $binFefo['allocation']);
            }
            $fefo = [
                'sufficient'      => true,
                'shortage'        => 0,
                'total_available' => $totalAvailable,
                'allocation'      => $allAllocations,
            ];
        } else {
            // Check if SKU has pickface config — if so, prioritize pickface allocation
            require_once __DIR__ . '/FefoAllocator.php';
            require_once __DIR__ . '/PickfaceSplitter.php';
            $pfConfig = PickfaceSplitter::getPickfaceConfig((int)$item['product_id'], $db);
            
            if ($pfConfig) {
                // Use pickface-priority allocation
                // If product_code is not provided, look it up from product_id first
                $sku = $item['product_code'] ?? null;
                if (!$sku && isset($item['product_id'])) {
                    $skuLookup = $db->prepare("SELECT product_code FROM products WHERE id = ? LIMIT 1");
                    $skuLookup->execute([(int)$item['product_id']]);
                    $skuRow = $skuLookup->fetch();
                    $sku = $skuRow['product_code'] ?? null;
                }
                // Fall back to numeric ID if no product_code found
                $allocateSku = $sku ?? (string)$item['product_id'];
                $fefo = FefoAllocator::allocate(
                    $allocateSku,
                    $quantity,
                    null,
                    'bulk_first'  // bulk (B-E) first, pickface (A-level) for remainder
                );
                // Store replen_task_id for blocking later
                $item['_replen_task_id'] = $fefo['replen_task_id'] ?? null;
            } else {
                // No pickface config — standard FEFO across all bins
                $fefo = self::getFEFOAllocation($item['product_id'], $quantity, $manualLoc ?: null);
            }
            
            // Only throw if insufficient AND no replenishment task was created
            // When pickface priority is enabled, shortage triggers replenishment + blocking
            if (!$fefo['sufficient'] && empty($item['_replen_task_id'])) {
                $msg = "Stok tidak mencukupi untuk alokasi FEFO. Qty diminta: " . number_format($quantity, 0) . ".";
                $msg .= " Tersedia (FEFO): " . number_format($fefo['total_available'], 0) . ".";
                throw new Exception($msg);
            }
        }

        $pallet     = self::calculatePallet($quantity, $uomPerPallet);
        $firstBatch = $fefo['allocation'][0];

        
        $locSummary = null;
        if ($manualLocs && count($manualLocs) > 1) {
            $locSummary = implode(', ', array_column($manualLocs, 'location'));
        } else {
            // Use pickface bin location if available (from FefoAllocator), otherwise standard location
            $locSummary = $firstBatch['bin_location'] ?? $firstBatch['location'] ?? $manualLoc;
        }

        $stmt = $db->prepare("INSERT INTO outbound_items
                (outbound_order_id, product_id, quantity, uom,
                 actual_qty, pallet, batch_no, exp_date, location, notes, od_number, so_number, destination_id, customer_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $stmt->execute([
            $outboundId,
            $item['product_id'],
            $quantity,
            $uom,
            $item['actual_qty'] ?? $quantity,
            $pallet,
            $firstBatch['batch_number'],
            $firstBatch['expiry_date'],
            $locSummary,
            $item['notes'] ?? null,
            $item['od_number'] ?? null,
            $item['so_number'] ?? null,
            $item['destination_id'] ?? null,
            $item['customer_id'] ?? null,
        ]);

        $outboundItemId = $db->lastInsertId();

        // --- Block outbound item if replenishment task was created ---
        $replenTaskId = $item['_replen_task_id'] ?? null;
        if ($replenTaskId) {
            $stmtBlock = $db->prepare(
                "UPDATE outbound_items SET blocked_on_replen_task_id = ? WHERE id = ?"
            );
            $stmtBlock->execute([$replenTaskId, $outboundItemId]);
        }

        return $outboundItemId;
    }

    

    public static function create($data, $db = null) {
        $db = $db ?? db();
        $ownTransaction = !$db->inTransaction();
        try {
            if ($ownTransaction) $db->beginTransaction();

            
            // Always generate a unique order number; store shipment_number separately
            $outboundNumber = self::generateNumber();

            $stmt = $db->prepare("INSERT INTO outbound_orders
                    (order_number, order_date, customer_id, so_number, do_number,
                     shipment_number, ship_to_name, ship_to_location, ship_to_street,
                     destination, kota, armada_no, container_no, jenis_armada,
                     expected_date, status, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $outboundNumber,
                $data['order_date'],
                $data['customer_id'] ?? null,
                $data['so_number'] ?? null,
                $data['do_number'] ?? null,
                $data['shipment_number'] ?? null,
                $data['ship_to_name'] ?? null,
                $data['ship_to_location'] ?? null,
                $data['ship_to_street'] ?? null,
                $data['destination'] ?? null,
                $data['kota'] ?? null,
                $data['armada_no'] ?? null,
                $data['container_no'] ?? null,
                $data['jenis_armada'] ?? null,
                $data['expected_date'] ?? null,
                $data['status'] ?? OUTBOUND_STATUSES['DEFAULT'],
                $data['notes'] ?? null,
                $_SESSION['user_id']
            ]);

            $outboundId = $db->lastInsertId();

            
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    self::addItemWithFEFO($outboundId, $item);
                }
            }

            if ($ownTransaction) $db->commit();
            return $outboundId;

        } catch (Exception $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error creating outbound: " . $e->getMessage());
            throw $e;
        }
    }

    

    public static function calculatePallet($quantity, $uomPerPallet) {
        if ($uomPerPallet == 0) return 0;
        return ceil($quantity / $uomPerPallet);
    }

    

    public static function calculatePalletDistribution($quantity, $uomPerPallet) {
        $fullPallets = intval($quantity / $uomPerPallet);
        $remainder = $quantity % $uomPerPallet;

        $distribution = [];
        $palletNumber = 1;

        for ($i = 0; $i < $fullPallets; $i++) {
            $distribution[] = [
                'pallet_number' => $palletNumber++,
                'quantity' => $uomPerPallet,
                'is_full' => true
            ];
        }

        if ($remainder > 0) {
            $distribution[] = [
                'pallet_number' => $palletNumber,
                'quantity' => $remainder,
                'is_full' => false
            ];
        }

        return $distribution;
    }

    

    public static function update($id, $data, $db = null) {
        $db = $db ?? db();
        $ownTransaction = !$db->inTransaction();
        try {
            if ($ownTransaction) $db->beginTransaction();

            $stmt = $db->prepare("UPDATE outbound_orders SET
                    order_date = ?,
                    customer_id = ?,
                    so_number = ?,
                    do_number = ?,
                    shipment_number = ?,
                    ship_to_name = ?,
                    ship_to_location = ?,
                    ship_to_street = ?,
                    destination = ?,
                    kota = ?,
                    armada_no = ?,
                    container_no = ?,
                    jenis_armada = ?,
                    expected_date = ?,
                    status = ?,
                    notes = ?
                    WHERE id = ?");

            $stmt->execute([
                $data['order_date'],
                $data['customer_id'],
                $data['so_number'] ?? null,
                $data['do_number'] ?? null,
                $data['shipment_number'] ?? null,
                null,
                null,
                null,
                $data['destination'] ?? null,
                $data['kota'] ?? null,
                $data['armada_no'] ?? null,
                $data['container_no'] ?? null,
                $data['jenis_armada'] ?? null,
                $data['expected_date'] ?? null,
                $data['status'] ?? OUTBOUND_STATUSES['DEFAULT'],
                $data['notes'] ?? null,
                $id
            ]);

            
            if (isset($data['shipped_date']) || isset($data['status'])) {
                $stmt = $db->prepare("UPDATE outbound_orders SET
                        shipped_by = ?,
                        status = ?
                        WHERE id = ?");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $data['status'] ?? OUTBOUND_STATUSES['DEFAULT'],
                    $id
                ]);
            }

            if ($ownTransaction) $db->commit();
            return true;

        } catch (Exception $e) {
            if ($ownTransaction && $db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Error updating outbound: " . $e->getMessage());
            throw $e;
        }
    }

    

    public static function updateItem($itemId, $data, $db = null) {
        $db = $db ?? db();

        $stmt = $db->prepare("UPDATE outbound_items SET
                quantity = ?,
                uom = ?,
                actual_qty = ?,
                batch_no = ?,
                exp_date = ?,
                location = ?,
                notes = ?
                WHERE id = ?");

        return $stmt->execute([
            $data['quantity'] ?? 0,
            $data['uom'] ?? OUTBOUND_DEFAULT_UOM,
            $data['actual_qty'] ?? $data['quantity'] ?? 0,
            $data['batch_no'] ?? null,
            $data['exp_date'] ?? null,
            $data['location'] ?? null,
            $data['notes'] ?? null,
            $itemId
        ]);
    }

    

    public static function deleteItem($itemId, $db = null) {
        $db = $db ?? db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            
            $item = $db->prepare("SELECT oi.*, oo.status AS order_status
                FROM outbound_items oi
                JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
                WHERE oi.id = ?")->execute([$itemId]) ? null : null;
            $stItem = $db->prepare("SELECT oi.*, oo.status AS order_status
                FROM outbound_items oi
                JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
                WHERE oi.id = ?");
            $stItem->execute([$itemId]);
            $item = $stItem->fetch();

            if ($item) {
                $wasPicked  = in_array($item['order_status'] ?? '', ['Picking','Shipped','Completed']);
                $isUnserv   = ($item['in_process_status'] ?? '') === 'Unserviceable';
                $pid        = $item['product_id'];
                $batch      = $item['batch_no'] ?? $item['batch_number'] ?? null;
                $qty        = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);

                if ($wasPicked && $qty > 0 && !$isUnserv) {
                    
                    $obLocs = $db->prepare("
                        SELECT oil.quantity AS restore_qty,
                               sl.id AS sl_id, sl.stock_id, sl.location_code AS loc,
                               sl.batch_number AS sl_batch, sl.original_quantity AS orig_qty
                        FROM outbound_item_locations oil
                        JOIN stock_locations sl ON sl.id = oil.stock_location_id
                        WHERE oil.outbound_item_id = ?
                    ");
                    $obLocs->execute([$itemId]);
                    $pickRows = $obLocs->fetchAll();

                    foreach ($pickRows as $pr) {
                        $rQty  = floatval($pr['restore_qty'] ?? 0);
                        $rLoc  = $pr['loc'] ?? null;
                        $rBatch= $pr['sl_batch'] ?? $batch;
                        $slId  = $pr['sl_id'] ?? null;
                        $sid   = $pr['stock_id'] ?? null;
                        if ($rQty <= 0) continue;

                        $restored = false;
                        if ($sid) {
                            $srow = $db->prepare("SELECT id, quantity FROM stock WHERE id=?");
                            $srow->execute([$sid]);
                            if ($sr = $srow->fetch()) {
                                $db->prepare("UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?")
                                   ->execute([$rQty, $sid]);
                                $restored = true;
                            }
                        }
                        if (!$restored && $rLoc) {
                            $find = $db->prepare("SELECT id FROM stock
                                WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'");
                            $find->execute([$pid, $rBatch, $rLoc]);
                            $found = $find->fetch();
                            if ($found) {
                                $db->prepare("UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?")
                                   ->execute([$rQty, $found['id']]);
                                if ($slId) $db->prepare("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?")->execute([$found['id'], $slId]);
                            } else {
                                $uomPerPallet = max(1, intval($item['uom_per_pallet'] ?? DEFAULT_UOM_PER_PALLET));
                                $db->prepare("INSERT INTO stock (product_id,batch_number,location,quantity,uom,pallet,stock_status) VALUES (?,?,?,?,?,?,'Available')")
                                   ->execute([$pid,$rBatch,$rLoc,$rQty,$item['uom']??OUTBOUND_DEFAULT_UOM,max(1,(int)ceil($rQty / $uomPerPallet))]);
                                $nid = $db->lastInsertId();
                                if ($slId) $db->prepare("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?")->execute([$nid, $slId]);
                            }
                        }
                        if ($slId) {
                            $origQty = floatval($pr['orig_qty'] ?? $rQty);
                            $db->prepare("UPDATE stock_locations SET status='Available', quantity=? WHERE id=?")
                               ->execute([$origQty, $slId]);
                        }
                    }
                }
            }

            // Simpan destination_id sebelum item dihapus
            $destRow = $db->prepare("SELECT destination_id, outbound_order_id FROM outbound_items WHERE id=?");
            $destRow->execute([$itemId]);
            $destInfo = $destRow->fetch();

            // S20 — Shipped deleteItem: hapus baris OUT ledger ship-time untuk produk ini
            if (($item['order_status'] ?? '') === 'Shipped') {
                $db->prepare("DELETE FROM stock_ledger WHERE reference_type='Outbound' AND reference_id=? AND product_id=?")
                   ->execute([$destInfo['outbound_order_id'] ?? $item['outbound_order_id'], $pid]);
            }

            $db->prepare("DELETE FROM outbound_item_locations WHERE outbound_item_id=?")->execute([$itemId]);
            $db->prepare("DELETE FROM outbound_items WHERE id=?")->execute([$itemId]);

            // Hapus destination jika tidak ada item lain yang menggunakannya
            if (!empty($destInfo['destination_id'])) {
                $remaining = $db->prepare("SELECT COUNT(*) FROM outbound_items WHERE destination_id=?");
                $remaining->execute([$destInfo['destination_id']]);
                if ((int)$remaining->fetchColumn() === 0) {
                    $db->prepare("DELETE FROM outbound_destinations WHERE id=?")->execute([$destInfo['destination_id']]);
                }
            }

            if ($ownTx) $db->commit();
            return true;
        } catch (Exception $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    

    public static function pickItems($outboundId, $db = null) {
        $db = $db ?? db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $outbound = self::getById($outboundId);
            if (!$outbound) throw new \Exception("Outbound tidak ditemukan");
            if (($outbound['status'] ?? '') !== 'Open') {
                throw new \Exception("Hanya order berstatus Open yang bisa di-pick");
            }
            $items    = self::getItems($outboundId);

            // ── Lock: Block picking if any picking location is under active Stock Take ──
            $pickLocs = array_values(array_filter(array_unique(array_column($items, 'location'))));
            if (!empty($pickLocs)) {
                $ph   = implode(',', array_fill(0, count($pickLocs), '?'));
                $lock = $db->prepare("
                    SELECT st.take_number, sti.location
                    FROM stock_take_items sti
                    JOIN stock_take st ON st.id = sti.stock_take_id
                    WHERE st.status IN ('Counting','Review')
                      AND sti.location IN ($ph)
                    LIMIT 5");
                $lock->execute($pickLocs);
                $locked = $lock->fetchAll();
                if (!empty($locked)) {
                    $takeNo = $locked[0]['take_number'];
                    $locs   = implode(', ', array_unique(array_column($locked, 'location')));
                    throw new \Exception(
                        "Picking diblokir — lokasi [{$locs}] sedang dalam sesi Stock Take aktif ({$takeNo}). " .
                        "Selesaikan atau batalkan stock take terlebih dahulu."
                    );
                }
            }

            foreach ($items as $item) {
                
                $itemProcessStatus = $item['in_process_status'] ?? '';
                if ($itemProcessStatus !== 'ATP') {
                    continue;
                }

                $needed    = floatval($item['actual_qty'] ?: $item['quantity']);
                $productId = $item['product_id'];

                
                $preferBatch = $item['batch_number'] ?? $item['batch_no'] ?? null;

                // S25 — Cross-dock: lines fed from STAGING (inbound cross-dock) pick from STAGING only
                $isCrossDock = !empty($item['cross_dock_inbound_item_id']);

                $locFilter = $isCrossDock
                    ? "AND location = 'STAGING'"
                    : "AND location != 'QUA_SHELL' AND location != 'STAGING'";

                $q = $db->prepare("SELECT * FROM stock
                        WHERE product_id = ?
                        AND (stock_status IN ('Available','Dues In') OR stock_status IS NULL OR stock_status = '')
                        AND (hold_status = 'available' OR hold_status IS NULL)
                        AND quantity > 0
                        $locFilter
                        FOR UPDATE
                        ORDER BY
                            CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END ASC,
                            expiry_date ASC,
                            id ASC");
                $q->execute([$productId]);
                $stockRows = $q->fetchAll();

                
                if (!empty($preferBatch)) {
                    usort($stockRows, function($a, $b) use ($preferBatch) {
                        $aMatch = ($a['batch_number'] === $preferBatch) ? 0 : 1;
                        $bMatch = ($b['batch_number'] === $preferBatch) ? 0 : 1;
                        if ($aMatch !== $bMatch) return $aMatch - $bMatch;
                        $aExp = strtotime($a['expiry_date'] ?? '9999-12-31');
                        $bExp = strtotime($b['expiry_date'] ?? '9999-12-31');
                        return $aExp - $bExp;
                    });
                }

                if (empty($stockRows)) {
                    throw new Exception("Stok tidak tersedia untuk produk: " . ($item['product_name'] ?? $productId));
                }

                
                $totalAvail = array_sum(array_column($stockRows, 'quantity'));
                if ($totalAvail < $needed - 0.001) {
                    throw new Exception("Stok kurang " . round($needed - $totalAvail, 2) .
                        " unit untuk produk: " . ($item['product_name'] ?? $productId) .
                        " (tersedia: " . round($totalAvail, 2) . ", dibutuhkan: " . round($needed, 2) . ")");
                }

                
                $remaining    = $needed;
                $usedBatch    = null;
                $usedLocation = null;
                $pickedRows   = []; 

                foreach ($stockRows as $stock) {
                    if ($remaining <= 0.001) break;
                    $deduct = min($remaining, floatval($stock['quantity']));
                    $newQty = floatval($stock['quantity']) - $deduct;

                    
                    $palletRatio = $stock['quantity'] > 0 ? ($deduct / floatval($stock['quantity'])) : 0;
                    $newPlt = max(0, floatval($stock['pallet']) - (floatval($stock['pallet']) * $palletRatio));

                    if ($newQty <= 0.001) {
                        
                        $db->prepare("DELETE FROM stock WHERE id = ?")
                           ->execute([$stock['id']]);
                    } else {
                        $db->prepare("UPDATE stock SET
                                quantity     = ?,
                                pallet       = ?,
                                stock_status = 'Available',
                                updated_at   = NOW()
                                WHERE id = ?")
                           ->execute([$newQty, round($newPlt, 4), $stock['id']]);
                    }

                    
                    $slRow = $db->prepare("SELECT id, quantity FROM stock_locations
                            WHERE stock_id = ? AND status = 'Available'
                            ORDER BY pallet_seq ASC LIMIT 1");
                    $slRow->execute([$stock['id']]);
                    $sl = $slRow->fetch();
                    $slId = null;
                    if ($sl) {
                        $slId = $sl['id'];
                        $slNewQty = max(0, floatval($sl['quantity']) - $deduct);
                        $db->prepare("UPDATE stock_locations
                                SET quantity = ?,
                                    status   = ?
                                WHERE id = ?")
                           ->execute([
                               $slNewQty,
                               $slNewQty <= 0 ? 'Picked' : 'Available',
                               $slId
                           ]);
                    } else {
                        
                        $slFind = $db->prepare("SELECT sl.id, sl.quantity
                                FROM stock_locations sl
                                JOIN stock sx ON sx.id = sl.stock_id
                                WHERE sx.product_id = ?
                                  AND sx.batch_number <=> ?
                                  AND sx.location <=> ?
                                  AND sl.status IN ('Available','Picked')
                                ORDER BY (sl.status='Available') DESC, sl.pallet_seq ASC
                                LIMIT 1");
                        $slFind->execute([$productId, $stock['batch_number'] ?? null, $stock['location'] ?? null]);
                        $sl = $slFind->fetch();
                        if ($sl) {
                            $slId = $sl['id'];
                            $slNewQty = max(0, floatval($sl['quantity']) - $deduct);
                            $db->prepare("UPDATE stock_locations
                                    SET quantity = ?,
                                        status   = ?
                                    WHERE id = ?")
                               ->execute([
                                   $slNewQty,
                                   $slNewQty <= 0 ? 'Picked' : 'Available',
                                   $slId
                               ]);
                        } else {
                            
                            $insSl = $db->prepare("INSERT INTO stock_locations
                                    (stock_id, location_code, pallet_seq, quantity, original_quantity,
                                     uom, is_full_pallet, batch_number, inbound_item_id, status)
                                    VALUES (NULL, ?, 999, 0, ?, ?, 0, ?, NULL, 'Picked')");
                            $insSl->execute([
                                $stock['location'] ?? 'UNALLOCATED',
                                $deduct,
                                $stock['uom'] ?? ($item['uom'] ?? UOM_DEFAULT_SQL),
                                $stock['batch_number'] ?? null,
                            ]);
                            $slId = (int)$db->lastInsertId();
                        }
                    }

                    
                    $pickedRows[] = [
                        'stock_location_id' => $slId,
                        'location'          => $stock['location'],
                        'batch'             => $stock['batch_number'],
                        'quantity'          => $deduct,
                        'expiry_date'       => $stock['expiry_date'],
                    ];

                    if (!$usedBatch)    $usedBatch    = $stock['batch_number'];
                    if (!$usedLocation) $usedLocation = $stock['location'];
                    $remaining -= $deduct;
                }

                
                $db->prepare("DELETE FROM outbound_item_locations WHERE outbound_item_id = ?")
                   ->execute([$item['id']]);
                foreach ($pickedRows as $pr) {
                    if ($pr['stock_location_id']) {
                        $db->prepare("INSERT IGNORE INTO outbound_item_locations
                                (outbound_item_id, stock_location_id, quantity)
                                VALUES (?, ?, ?)")
                           ->execute([$item['id'], $pr['stock_location_id'], $pr['quantity']]);
                    }
                }

                
                $db->prepare("UPDATE outbound_items
                        SET batch_no = ?, batch_number = ?, location = COALESCE(?, location), exp_date = ?
                        WHERE id = ?")
                   ->execute([
                       $usedBatch, $usedBatch,
                       $usedLocation,
                       $stockRows[0]['expiry_date'] ?? null,
                       $item['id']
                   ]);


                $item['batch_no']   = $usedBatch;
                $item['location']   = $usedLocation;
                $item['actual_qty'] = $needed;

                // Ledger recorded at Shipped time, not pick time
            }

            $stmt = $db->prepare("UPDATE outbound_orders SET status = 'Picking' WHERE id = ?");
            $stmt->execute([$outboundId]);

            if ($ownTx) $db->commit();
            return true;

        } catch (Exception $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            error_log("Error picking items: " . $e->getMessage());
            throw $e;
        }
    }

    

    public static function ship($outboundId, $db = null) {
        $db = $db ?? db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $outbound = self::getById($outboundId);
            $items    = self::getItems($outboundId);

            // Gate: all picklist items must be checked before shipping
            if (!$outbound['all_lines_checked']) {
                throw new Exception("Semua item harus dicek sebelum order dapat dikirim.");
            }

            $stmt = $db->prepare("UPDATE outbound_orders SET
                    status = 'Shipped',
                    shipped_by = ?
                    WHERE id = ?");
            $stmt->execute([$_SESSION['user_id'], $outboundId]);

            // Record qty_out in ledger at Shipped time
            foreach ($items as $item) {
                $qty = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
                if ($qty <= 0) continue;
                self::addToLedger($item, $outbound);
            }

            if ($ownTx) $db->commit();
            return true;
        } catch (Exception $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    

    public static function complete($outboundId, $db = null) {
        $db = $db ?? db();

        $stmt = $db->prepare("UPDATE outbound_orders SET
                status = 'Completed'
                WHERE id = ?");
        $stmt->execute([$outboundId]);

        return true;
    }

    

    private static function addToLedger($item, $outbound, $db = null) {
        $db = $db ?? db();

        
        $stmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
                FROM stock_ledger WHERE product_id = ?");
        $stmt->execute([$item['product_id']]);
        $row = $stmt->fetch();
        $balance = floatval($row['running_balance'] ?? 0) - floatval($item['actual_qty']);

        $stmt = $db->prepare("INSERT INTO stock_ledger
                (transaction_date, product_id, transaction_type, reference_type,
                 reference_id, reference_number, batch_number, quantity_in,
                 quantity_out, uom, pallet, balance, location, notes)
                VALUES (?, ?, 'OUT', 'Outbound', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        
        
        $transactionDate = date('Y-m-d');

        $stmt->execute([
            $transactionDate,
            $item['product_id'],
            $outbound['id'],
            $outbound['order_number'],
            $item['batch_no'],
            0,
            $item['actual_qty'],
            $item['uom'],
            $item['pallet'],
            $balance,
            $item['location'],
            '[Outbound] Shipped | Status: Shipped | ' . $outbound['order_number']
        ]);
    }

    

    public static function delete($id, $db = null) {
        $db = $db ?? db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $items    = self::getItems($id);
            $outbound = self::getById($id);
            $wasPickedOrShipped = in_array($outbound['status'] ?? '', ['Picking','Shipped','Completed']);

            foreach ($items as $item) {
                $batch    = $item['batch_no'] ?? $item['batch_number'] ?? null;
                $qty      = floatval($item['actual_qty'] ?? $item['quantity'] ?? 0);
                $pid      = $item['product_id'];
                $isUnserv = ($item['in_process_status'] ?? '') === 'Unserviceable';

                if ($wasPickedOrShipped && $qty > 0 && !$isUnserv) {
                    
                    $obLocs = $db->prepare("
                        SELECT oil.quantity       AS restore_qty,
                               sl.id             AS sl_id,
                               sl.stock_id       AS stock_id,
                               sl.location_code  AS loc,
                               sl.batch_number   AS sl_batch,
                               sl.original_quantity AS orig_qty,
                               sl.uom            AS sl_uom
                        FROM outbound_item_locations oil
                        JOIN stock_locations sl ON sl.id = oil.stock_location_id
                        WHERE oil.outbound_item_id = ?
                    ");
                    $obLocs->execute([$item['id']]);
                    $pickRows = $obLocs->fetchAll();

                    if (!empty($pickRows)) {
                        $uomItem  = $item['uom'] ?? OUTBOUND_DEFAULT_UOM;
                        $expItem  = $item['exp_date'] ?? $item['expiry_date'] ?? null;

                        foreach ($pickRows as $pr) {
                            $rQty  = floatval($pr['restore_qty'] ?? 0);
                            $rLoc  = $pr['loc'] ?? null;
                            $rBatch= $pr['sl_batch'] ?? $batch;
                            $slId  = $pr['sl_id'] ?? null;
                            $sid   = $pr['stock_id'] ?? null;

                            if ($rQty <= 0) continue;

                            
                            $restored = false;
                            if ($sid) {
                                $srow = $db->prepare("SELECT id, quantity, pallet, uom_per_pallet FROM stock WHERE id=?");
                                $srow->execute([$sid]);
                                $stockRow = $srow->fetch();
                                if ($stockRow) {
                                    $upp = max(1, (int)($stockRow['uom_per_pallet'] ?? DEFAULT_UOM_PER_PALLET));
                                    $newQty = floatval($stockRow['quantity']) + $rQty;
                                    $newPlt = ceil($newQty / $upp);
                                    $db->prepare("UPDATE stock SET quantity=?, pallet=?, updated_at=NOW() WHERE id=?")
                                       ->execute([$newQty, $newPlt, $sid]);
                                    $restored = true;
                                }
                            }

                            
                            if (!$restored && $rLoc) {
                                $find = $db->prepare("SELECT id, quantity FROM stock
                                    WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'");
                                $find->execute([$pid, $rBatch, $rLoc]);
                                $found = $find->fetch();
                                if ($found) {
                                    $db->prepare("UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?")
                                       ->execute([$rQty, $found['id']]);
                                    if ($slId) {
                                        $db->prepare("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?")
                                           ->execute([$found['id'], $slId]);
                                    }
                                } else {
                                    
                                    $uomPerPallet = max(1, intval($item['uom_per_pallet'] ?? DEFAULT_UOM_PER_PALLET));
                                    $db->prepare("INSERT INTO stock
                                        (product_id, batch_number, location, quantity, uom, pallet, expiry_date, stock_status)
                                        VALUES (?,?,?,?,?,?,?,'Available')")
                                       ->execute([$pid, $rBatch, $rLoc, $rQty, $uomItem, max(1,(int)ceil($rQty / $uomPerPallet)), $expItem]);
                                    $newSid = $db->lastInsertId();
                                    if ($slId) {
                                        $db->prepare("UPDATE stock_locations SET stock_id=?, status='Available' WHERE id=?")
                                           ->execute([$newSid, $slId]);
                                    }
                                }
                                $restored = true;
                            }

                            
                            if ($slId) {
                                $origQty = floatval($pr['orig_qty'] ?? $rQty);
                                $db->prepare("UPDATE stock_locations
                                    SET status='Available', quantity=?
                                    WHERE id=?")
                                   ->execute([$origQty, $slId]);
                            }
                        }
                    } else {
                        
                        $loc = $item['location'] ?? null;
                        if ($loc && $loc !== 'QUA_SHELL' && $qty > 0) {
                            $find = $db->prepare("SELECT id FROM stock
                                WHERE product_id=? AND batch_number<=>? AND location=? AND stock_status='Available'");
                            $find->execute([$pid, $batch, $loc]);
                            $row = $find->fetch();
                            if ($row) {
                                $db->prepare("UPDATE stock SET quantity=quantity+?, updated_at=NOW() WHERE id=?")
                                   ->execute([$qty, $row['id']]);
                            } else {
                                $uomPerPallet = max(1, intval($item['uom_per_pallet'] ?? DEFAULT_UOM_PER_PALLET));
                                $db->prepare("INSERT INTO stock
                                    (product_id, batch_number, location, quantity, uom, pallet, stock_status)
                                    VALUES (?,?,?,?,?,?,'Available')")
                                   ->execute([$pid, $batch, $loc, $qty, $item['uom']??OUTBOUND_DEFAULT_UOM, max(1,(int)ceil($qty / $uomPerPallet))]);
                            }
                        }
                    }
                }

                
                if ($isUnserv && $qty > 0) {
                    $db->prepare("DELETE FROM stock
                        WHERE product_id=? AND batch_number<=>?
                        AND location='QUA_SHELL' AND stock_status='Rejected'")
                       ->execute([$pid, $batch]);
                }

                
                $db->prepare("DELETE FROM stock_ledger
                    WHERE reference_type='Outbound' AND reference_id=? AND product_id=?")
                   ->execute([$id, $pid]);

                
                $db->prepare("DELETE FROM outbound_item_locations WHERE outbound_item_id=?")
                   ->execute([$item['id']]);
            }

            
            $db->prepare("DELETE FROM stock_ledger
                WHERE reference_type='Outbound' AND reference_id=?")->execute([$id]);

            $db->prepare("DELETE FROM location_allocations WHERE reference_type='Outbound' AND reference_id=?")->execute([$id]);
            $db->prepare("DELETE pi FROM picklist_items pi JOIN picklists pl ON pl.id = pi.picklist_id WHERE pl.outbound_order_id=?")->execute([$id]);
            $db->prepare("DELETE FROM picklists WHERE outbound_order_id=?")->execute([$id]);
            $db->prepare("DELETE FROM outbound_items WHERE outbound_order_id=?")->execute([$id]);
            $db->prepare("DELETE FROM outbound_orders WHERE id=?")->execute([$id]);

            if ($ownTx) $db->commit();
            return true;

        } catch (Exception $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    

    public static function getStats($db = null) {
        $db = $db ?? db();

        $stats = [];

        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM outbound_orders
                WHERE YEAR(order_date) = YEAR(CURDATE())
                AND MONTH(order_date) = MONTH(CURDATE())");
        $stmt->execute();
        $stats['this_month'] = $stmt->fetch()['count'];

        
        $stmt = $db->query("SELECT status, COUNT(*) as count FROM outbound_orders
                GROUP BY status");
        $stats['by_status'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        
        $stmt = $db->query("SELECT COUNT(*) as count FROM outbound_orders
                WHERE status IN ('Open', 'Picking')");
        $stats['pending'] = $stmt->fetch()['count'];

        return $stats;
    }
}
?>
