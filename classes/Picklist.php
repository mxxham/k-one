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

    /**
     * createFromOutbound — mirrors TS picklist.service.ts lines 37-63.
     * Throws ApiException(400) on not-found (parity with TS badRequest).
     * Delegates item insertion to shared insertPicklistItems.
     */
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

            if (!$outbound) throw new ApiException("Outbound order not found", 400);

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

            self::insertPicklistItems($db, $picklistId, [$outboundId]);

            $db->commit();
            return $picklistId;

        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * createFromOrders — Wave Planning: builds ONE consolidated picklist across
     * multiple outbound orders (TS lines 76-90). Header sets outbound_order_id = NULL
     * and links wave_id. If a transaction $db is supplied it runs inside that
     * transaction (the waves module wraps everything in one tx); otherwise own tx.
     */
    public static function createFromOrders(array $outboundIds, int $createdBy, int $waveId, $client = null): int {
        $run = function ($db) use ($outboundIds, $createdBy, $waveId) {
            $picklistNumber = self::generateNumber();
            $ins = $db->prepare("INSERT INTO picklists
                    (outbound_order_id, wave_id, picklist_number, created_date, status, created_by)
                    VALUES (NULL, ?, ?, CURDATE(), 'Draft', ?)");
            $ins->execute([$waveId, $picklistNumber, $createdBy]);
            $picklistId = (int)$db->lastInsertId();

            self::insertPicklistItems($db, $picklistId, $outboundIds);

            return $picklistId;
        };

        if ($client) return $run($client);

        $db = db();
        try {
            $db->beginTransaction();
            $result = $run($db);
            $db->commit();
            return $result;
        } catch (Exception $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * insertPicklistItems — shared by createFromOutbound and createFromOrders.
     * Mirrors TS lines 98-173: FEFO exp_date order, allocations-based rows with
     * S19 held-stock exclusion, fallback to decomposed pallets.
     * Touches NO stock / NO ledger.
     */
    public static function insertPicklistItems($db, int $picklistId, array $outboundIds): void {
        $ph = implode(',', array_fill(0, count($outboundIds), '?'));
        $itemsStmt = $db->prepare("SELECT oi.*, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
                FROM outbound_items oi
                JOIN products p ON oi.product_id = p.id
                WHERE oi.outbound_order_id IN ($ph)
                ORDER BY oi.exp_date ASC, oi.id ASC");
        $itemsStmt->execute($outboundIds);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $item) {
            $batchNumber = $item['batch_number'] ?? $item['batch_no'] ?? null;
            $uomPerPallet = max(1, intval($item['uom_per_pallet'] ?? 4));

            // Aggregate outbound_item_locations by location to avoid duplicates
            $locStmt = $db->prepare("SELECT MIN(oil.stock_location_id) as stock_location_id,
                    SUM(oil.quantity) as alloc_qty,
                    sl.location_code,
                    MIN(sl.batch_number) as sl_batch
                FROM outbound_item_locations oil
                JOIN stock_locations sl ON oil.stock_location_id = sl.id
                LEFT JOIN stock st ON st.id = sl.stock_id
                WHERE oil.outbound_item_id = ?
                  AND (st.hold_status = 'available' OR st.hold_status IS NULL)
                GROUP BY sl.location_code
                ORDER BY sl.location_code");
            $locStmt->execute([$item['id']]);
            $locationRows = $locStmt->fetchAll();

            if (!empty($locationRows)) {
                $palletSeq = 1;
                foreach ($locationRows as $lr) {
                    $locBatch = $lr['sl_batch'] ?? $batchNumber;
                    $locCode  = $lr['location_code'] ?? '';
                    $locLevel = isset($locCode[4]) ? strtoupper($locCode[4]) : 'B';
                    $qty      = floatval($lr['alloc_qty']);
                    $plt = $uomPerPallet > 0
                         ? ($locLevel === 'A'
                            ? round($qty / $uomPerPallet, 2)
                            : (int)ceil($qty / $uomPerPallet))
                         : 0;
                    $insItem = $db->prepare("INSERT INTO picklist_items
                            (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                             location, quantity, uom, pallet, pallet_seq,
                             stock_location_id, status, replen_task_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
                    $insItem->execute([
                        $picklistId,
                        $item['id'],
                        $item['product_id'],
                        $locBatch, $locBatch,
                        $locCode,
                        $qty,
                        $item['uom_type'],
                        $plt,
                        $palletSeq++,
                        $lr['stock_location_id'],
                        $item['blocked_on_replen_task_id'] ?? null
                    ]);
                }
            } else {
                // Fallback: no outbound_item_locations — pick from available stock per pallet
                $distribution = self::calculatePalletDistribution(
                    $item['actual_qty'] ?: $item['quantity'],
                    $uomPerPallet
                );

                $availStmt = $db->prepare("SELECT sl.id, sl.location_code, sl.pallet_seq
                        FROM stock_locations sl
                        LEFT JOIN stock st ON st.id = sl.stock_id
                        WHERE sl.batch_number = ?
                          AND sl.status IN ('Available','Reserved')
                          AND (st.hold_status = 'available' OR st.hold_status IS NULL)
                          AND sl.id NOT IN (
                              SELECT DISTINCT stock_location_id
                              FROM picklist_items
                              WHERE batch_number = ? AND stock_location_id IS NOT NULL
                          )
                        ORDER BY sl.location_code, sl.pallet_seq");
                $availStmt->execute([$batchNumber, $batchNumber]);
                $available = $availStmt->fetchAll();

                $availIdx = 0;
                $palletSeq = 1;
                foreach ($distribution as $pallet) {
                    $slId = null;
                    $locCode2 = $item['location'] ?? 'TBD';
                    if ($availIdx < count($available)) {
                        $slId    = $available[$availIdx]['id'];
                        $locCode2 = $available[$availIdx]['location_code'];
                        $availIdx++;
                    }

                    $locLevel2 = isset($locCode2[4]) ? strtoupper($locCode2[4]) : 'B';
                    $qty2      = floatval($pallet['quantity']);
                    $plt2      = $uomPerPallet > 0
                               ? ($locLevel2 === 'A'
                                  ? round($qty2 / $uomPerPallet, 2)
                                  : (int)ceil($qty2 / $uomPerPallet))
                               : 0;

                    $insItem = $db->prepare("INSERT INTO picklist_items
                            (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                             location, quantity, uom, pallet, pallet_seq,
                             stock_location_id, status, replen_task_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");
                    $insItem->execute([
                        $picklistId,
                        $item['id'],
                        $item['product_id'],
                        $batchNumber, $batchNumber,
                        $locCode2,
                        $qty2,
                        $item['uom_type'],
                        $plt2,
                        $palletSeq++,
                        $slId,
                        $item['blocked_on_replen_task_id'] ?? null
                    ]);
                }
            }
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
                w.wave_number, w.carrier as wave_carrier, w.cutoff_time as wave_cutoff,
                u.full_name as created_by_name
                FROM picklists pkl
                LEFT JOIN outbound_orders o ON pkl.outbound_order_id = o.id
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN waves w ON pkl.wave_id = w.id
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
                oi.blocked_on_replen_task_id,
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
                w.wave_number,
                COUNT(pki.id) as total_items,
                SUM(pki.quantity) as total_qty,
                CEIL(SUM(pki.pallet)) as total_pallet
                FROM picklists pkl
                LEFT JOIN outbound_orders o ON pkl.outbound_order_id = o.id
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN waves w ON pkl.wave_id = w.id
                LEFT JOIN picklist_items pki ON pkl.id = pki.picklist_id
                $where
                GROUP BY pkl.id, o.order_number, o.so_number, o.do_number, o.shipment_number,
                         c.customer_name, w.wave_number
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

    /**
     * S25 — Cross-dock: add a staged item to the outbound order's Draft picklist.
     * Creates the picklist first if the order has none yet.
     */
    /** v2 — cross-dock: attach a STAGING picklist line to the linked outbound order. */
    public static function addCrossDockItem(int $outboundOrderId, int $productId, float $quantity, ?string $batchNumber = null, string $uom = 'Drum', ?int $createdBy = null, ?int $stockLocationId = null): int {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $plStmt = $db->prepare("SELECT id FROM picklists WHERE outbound_order_id = ? ORDER BY id LIMIT 1");
            $plStmt->execute([$outboundOrderId]);
            $pl = $plStmt->fetch();

            if ($pl) {
                $picklistId = (int)$pl['id'];
            } else {
                $picklistNumber = self::generateNumber();
                $ins = $db->prepare("INSERT INTO picklists
                        (outbound_order_id, picklist_number, created_date, status, created_by)
                        VALUES (?, ?, CURDATE(), 'Draft', ?)");
                $ins->execute([$outboundOrderId, $picklistNumber, $createdBy ?? ($_SESSION['user_id'] ?? null)]);
                $picklistId = (int)$db->lastInsertId();
            }

            $obItemR = $db->prepare("SELECT oi.id, oi.blocked_on_replen_task_id, p.uom_per_pallet
                    FROM outbound_items oi JOIN products p ON p.id = oi.product_id
                    WHERE oi.outbound_order_id = ? AND oi.product_id = ?
                    ORDER BY oi.id LIMIT 1");
            $obItemR->execute([$outboundOrderId, $productId]);
            $obItem = $obItemR->fetch();
            $obItemId = $obItem ? (int)$obItem['id'] : null;
            $uomPerPallet = max(1, intval($obItem['uom_per_pallet'] ?? 4));

            $batch = $batchNumber ?? null;
            $plt = max(1, (int)ceil($quantity / $uomPerPallet));
            $stmt = $db->prepare("INSERT INTO picklist_items
                    (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                     location, quantity, uom, pallet, pallet_seq, stock_location_id, status, replen_task_id)
                    VALUES (?,?,?,?,?,'STAGING',?,?,?,1,?,'Pending',?)");
            $stmt->execute([
                $picklistId, $obItemId, $productId, $batch, $batch,
                $quantity, $uom, $plt, $stockLocationId,
                $obItem['blocked_on_replen_task_id'] ?? null
            ]);
            $itemId = (int)$db->lastInsertId();

            if ($ownTx) $db->commit();
            return $itemId;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
?>
