<?php

class Replenishment {

    /* ------------------------------------------------------------------ */
    /* S22 — Pick-face targets & replenishment suggestions                 */
    /* ------------------------------------------------------------------ */

    /**
     * List all pick-face targets with current qty, shortage, and sources.
     * Shortage = uom_per_pallet - available_qty.
     * Auto-discovers from location_master across all aisles.
     */
    public static function list(array $params = []): array {
        $db = db();
        $blocked = self::_blockedClause('lm');
        $sql = "                SELECT lm.id AS target_id, c.id AS config_id,
                       lm.location_code, lm.row_name, lm.zone, lm.aisle,
                       s.product_id,
                       p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                       COALESCE(c.pickface_min, 1) AS min_qty,
                       COALESCE(SUM(s.quantity),0) AS available_qty
                FROM location_master lm
                JOIN stock s ON s.location = lm.location_code
                    AND s.stock_status = 'Available'
                    AND (s.hold_status = 'available' OR s.hold_status IS NULL)
                    AND s.quantity > 0
                JOIN products p ON p.id = s.product_id
                LEFT JOIN sku_pickface_config c ON c.sku_id = s.product_id AND c.pickface_bin_id = lm.id
                WHERE lm.is_pick_face = 1
                  AND lm.row_name = 'A'
                  AND lm.is_active = 1
                  $blocked";
        $args = [];
        if (!empty($params['product_id'])) {
            $sql .= " AND s.product_id = ?";
            $args[] = (int)$params['product_id'];
        }
        if (!empty($params['location_code'])) {
            $sql .= " AND lm.location_code = ?";
            $args[] = $params['location_code'];
        }
        $sql .= " GROUP BY lm.id, lm.location_code, lm.row_name, lm.zone, lm.aisle,
                          s.product_id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet, c.pickface_min
                  HAVING available_qty <= COALESCE(c.pickface_min, 1)
                  ORDER BY lm.location_code, p.product_code";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['available_qty']  = floatval($r['available_qty']);
            $r['min_qty']        = floatval($r['min_qty']);
            $r['uom_per_pallet'] = floatval($r['uom_per_pallet']);
            $r['shortage']       = max(0, floatval($r['uom_per_pallet']) - floatval($r['available_qty']));
            $r['below_min']      = floatval($r['available_qty']) <= floatval($r['min_qty']);
            $r['sources']        = self::_findSourceRows($r['product_id'], $r['location_code'], $r['shortage']);
        }
        unset($r);
        return $rows;
    }

    /**
     * All pick-face targets with current pick-face qty (no filter).
     * Shortage = uom_per_pallet - available_qty.
     * Auto-discovers from location_master across all aisles.
     * Shows blocked status for each target.
     */
    public static function targets(array $params = []): array {
        $db = db();
        $sql = "SELECT lm.id AS target_id, c.id AS config_id,
                       lm.location_code, lm.row_name, lm.aisle, lm.zone,
                       s.product_id,
                       p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                       COALESCE(c.pickface_min, 1) AS min_qty,
                       COALESCE(SUM(s.quantity),0) AS available_qty,
                       EXISTS(SELECT 1 FROM putaway_location_blocks b
                               WHERE b.is_active = 1
                                 AND ((b.scope_type='location' AND b.location_code=lm.location_code)
                                   OR (b.scope_type='aisle' AND lm.location_code LIKE CONCAT(b.aisle_prefix,'%')))
                       ) AS is_blocked
                FROM location_master lm
                JOIN stock s ON s.location = lm.location_code
                    AND s.stock_status = 'Available'
                    AND (s.hold_status = 'available' OR s.hold_status IS NULL)
                    AND s.quantity > 0
                JOIN products p ON p.id = s.product_id
                LEFT JOIN sku_pickface_config c ON c.sku_id = s.product_id AND c.pickface_bin_id = lm.id
                WHERE lm.is_pick_face = 1
                  AND lm.row_name = 'A'
                  AND lm.is_active = 1";
        $args = [];
        if (!empty($params['location_code'])) {
            $sql .= " AND lm.location_code = ?";
            $args[] = $params['location_code'];
        }
        $sql .= " GROUP BY lm.id, lm.location_code, lm.row_name, lm.aisle, lm.zone,
                          s.product_id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet, c.pickface_min
                  ORDER BY lm.aisle, lm.location_code, p.product_code";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['available_qty']  = floatval($r['available_qty']);
            $r['min_qty']        = floatval($r['min_qty']);
            $r['uom_per_pallet'] = floatval($r['uom_per_pallet']);
            $r['shortage']       = max(0, floatval($r['uom_per_pallet']) - floatval($r['available_qty']));
            $r['is_blocked']     = (int)$r['is_blocked'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Find FEFO-ordered reserve/bulk source rows for a shortage.
     * Only B–E levels, excludes pick-face location and virtual locations.
     * v2 parity: findSourceRows().
     */
    private static function _findSourceRows(int $productId, string $pickFaceLocation, float $needed): array {
        $db = db();
        $uomStmt = $db->prepare("SELECT uom_per_pallet FROM products WHERE id = ? LIMIT 1");
        $uomStmt->execute([$productId]);
        $uomPerPallet = max((int)$uomStmt->fetchColumn(), 1);

        // Priority: partial pallets first (qty < uom_per_pallet), then FEFO,
        // then lowest qty, then location.  This avoids draining full pallets
        // while partials sit in reserve.
        $stmt = $db->prepare("SELECT s.*, lm.row_name AS level, lm.rack
                FROM stock s
                JOIN location_master lm ON lm.location_code = s.location
                WHERE s.product_id = ?
                  AND s.stock_status = 'Available'
                  AND (s.hold_status = 'available' OR s.hold_status IS NULL)
                  AND s.quantity > 0
                  AND s.location != ?
                  AND s.location NOT IN ('QUA_SHELL','STAGING','UNALLOCATED')
                  AND lm.row_name IN ('B','C','D','E')
                  " . self::_blockedClause('lm') . "
                ORDER BY
                    CASE WHEN s.quantity < ? THEN 0 ELSE 1 END,
                    CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END,
                    s.expiry_date ASC,
                    s.quantity ASC,
                    s.location ASC,
                    s.id ASC
                LIMIT 50");
        $stmt->execute([$productId, $pickFaceLocation, $uomPerPallet]);
        $rows = $stmt->fetchAll();

        $remaining = $needed;
        $result = [];
        foreach ($rows as $row) {
            if ($remaining <= 0.001) break;
            $take = min($remaining, floatval($row['quantity']));
            $result[] = [
                'location'      => $row['location'],
                'level'         => $row['level'],
                'batch_number'  => $row['batch_number'],
                'expiry_date'   => $row['expiry_date'],
                'quantity'      => floatval($row['quantity']),
                'take_qty'      => $take,
            ];
            $remaining -= $take;
        }
        return $result;
    }

    /**
     * Detect all shortages with auto-discovery from location_master.
     * Shortage = uom_per_pallet - available_qty.
     * Replenishment target is always the product's uom_per_pallet (Level A pick-face only).
     * Auto-discovers all Level A pick-face bins across all aisles (CA-CG).
     * Uses sku_pickface_config for per-product pickface_min override (default: 1).
     * Excludes blocked locations from putaway_location_blocks.
     */
    public static function detectShortages(): array {
        $db = db();
        $blocked = self::_blockedClause('lm');
        $sql = "SELECT lm.id AS target_id,
                       lm.id AS location_id,
                       lm.location_code AS pick_face_location,
                       lm.aisle,
                       s.product_id,
                       p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                       COALESCE(c.pickface_min, 1) AS min_qty,
                       COALESCE(SUM(s.quantity),0) AS available_qty,
                       GREATEST(p.uom_per_pallet - COALESCE(SUM(s.quantity),0), 0) AS shortage
                FROM location_master lm
                JOIN stock s ON s.location = lm.location_code
                    AND s.stock_status = 'Available'
                    AND (s.hold_status = 'available' OR s.hold_status IS NULL)
                    AND s.quantity > 0
                JOIN products p ON p.id = s.product_id
                LEFT JOIN sku_pickface_config c ON c.sku_id = s.product_id AND c.pickface_bin_id = lm.id
                WHERE lm.is_pick_face = 1
                  AND lm.row_name = 'A'
                  AND lm.is_active = 1
                  $blocked
                GROUP BY lm.id, lm.location_code, lm.aisle, s.product_id, p.product_code,
                         p.product_name, p.uom_type, p.uom_per_pallet, c.pickface_min
                HAVING available_qty <= COALESCE(c.pickface_min, 1)
                   AND p.uom_per_pallet > available_qty
                ORDER BY lm.location_code, p.product_code";
        $stmt = $db->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['available_qty']  = floatval($r['available_qty']);
            $r['min_qty']        = floatval($r['min_qty']);
            $r['uom_per_pallet'] = floatval($r['uom_per_pallet']);
            $r['shortage']       = floatval($r['shortage']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Dry-run suggestions with full source detail.
     * Shortage target is always uom_per_pallet.
     */
    public static function suggestTransfers(): array {
        $shortages = self::detectShortages();
        $suggestions = [];
        foreach ($shortages as $s) {
            $sourceRows = self::_findSourceRows($s['product_id'], $s['pick_face_location'], $s['shortage']);
            $suggestions[] = [
                'target_id'           => (int)$s['target_id'],
                'pick_face_location'  => $s['pick_face_location'],
                'product_id'          => (int)$s['product_id'],
                'product_code'        => $s['product_code'],
                'current_qty'         => $s['available_qty'],
                'min_qty'             => $s['min_qty'],
                'uom_per_pallet'      => $s['uom_per_pallet'],
                'shortage'            => $s['shortage'],
                'source'              => array_map(function($r) {
                    return [
                        'location'    => $r['location'],
                        'level'       => $r['level'],
                        'batch'       => $r['batch_number'],
                        'expiry_date' => $r['expiry_date'],
                        'take_qty'    => $r['take_qty'],
                    ];
                }, $sourceRows),
            ];
        }
        return ['suggestions' => $suggestions];
    }

    /**
     * Generate replenishment bin transfers for all shortages.
     * Returns structured result: generated, insufficient, skipped.
     * v2 parity: generateTransfers().
     */
    public static function generateTransfersFull(int $userId): array {
        $db = db();
        $shortages = self::detectShortages();
        $generated = [];
        $insufficient = [];
        $skipped = [];

        foreach ($shortages as $s) {
            // Check if destination is blocked
            $blockCheck = $db->prepare("SELECT EXISTS(
                SELECT 1 FROM putaway_location_blocks b
                WHERE b.is_active = 1
                  AND ((b.scope_type='location' AND b.location_code=?)
                    OR (b.scope_type='aisle' AND ? LIKE CONCAT(b.aisle_prefix,'%')))
            )");
            $blockCheck->execute([$s['pick_face_location'], $s['pick_face_location']]);
            if ($blockCheck->fetchColumn()) {
                $skipped[] = [
                    'target_id'          => (int)$s['target_id'],
                    'pick_face_location' => $s['pick_face_location'],
                    'reason'             => 'Lokasi tujuan terblokir: ' . $s['pick_face_location'],
                ];
                continue;
            }

            $needed = $s['shortage'];
            $sourceRows = self::_findSourceRows($s['product_id'], $s['pick_face_location'], $needed);
            $available = array_sum(array_column($sourceRows, 'take_qty'));

            if ($available < $needed - 0.001) {
                $insufficient[] = [
                    'target_id'          => (int)$s['target_id'],
                    'pick_face_location' => $s['pick_face_location'],
                    'product_id'         => (int)$s['product_id'],
                    'shortage'           => $needed,
                    'available'          => $available,
                ];
                continue;
            }

            try {
                $transferId = BinTransfer::create([
                    'transfer_date'    => date('Y-m-d'),
                    'product_id'       => $s['product_id'],
                    'from_location'    => $sourceRows[0]['location'],
                    'to_location'      => $s['pick_face_location'],
                    'quantity'         => $needed,
                    'uom'              => $s['uom_type'],
                    'reason'           => "Auto-replenishment pick-face {$s['pick_face_location']} (min {$s['min_qty']})",
                    'transfer_type'    => 'REPLENISHMENT',
                    'pick_face_target_id' => $s['target_id'],
                    'is_breakdown'     => 1,
                    'source_rows'      => $sourceRows,
                ]);

                $generated[] = [
                    'target_id'          => (int)$s['target_id'],
                    'pick_face_location' => $s['pick_face_location'],
                    'transfer_id'        => $transferId,
                    'transfer_number'    => self::_getTransferNumber($transferId),
                    'quantity'           => $needed,
                ];
            } catch (Throwable $e) {
                $skipped[] = [
                    'target_id'          => (int)$s['target_id'],
                    'pick_face_location' => $s['pick_face_location'],
                    'reason'             => 'Gagal membuat transfer: ' . $e->getMessage(),
                ];
            }
        }

        return ['generated' => $generated, 'insufficient' => $insufficient, 'skipped' => $skipped];
    }

    /**
     * Demand-driven replenishment for a specific product/demand.
     * v2 parity: demandReplenishment().
     */
    public static function demandReplenishment(int $userId, int $productId, float $demandQty, bool $createTransfer = false): array {
        $db = db();

        $prod = $db->prepare("SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products WHERE id = ?");
        $prod->execute([$productId]);
        $product = $prod->fetch();
        if (!$product) throw new Exception("Produk tidak ditemukan.");

        // Available pick-face (Level A, is_pick_face=1) stock — excludes blocked locations
        $blocked = self::_blockedClause('lm');
        $pick = $db->prepare("SELECT COALESCE(SUM(s.quantity), 0) AS qty
                FROM stock s
                JOIN location_master lm ON lm.location_code = s.location
                WHERE s.product_id = ?
                  AND s.stock_status = 'Available'
                  AND (s.hold_status = 'available' OR s.hold_status IS NULL)
                  AND s.quantity > 0
                  AND lm.row_name = 'A'
                  AND lm.is_pick_face = 1
                  $blocked");
        $pick->execute([$productId]);
        $pickAvailable = floatval($pick->fetchColumn() ?? 0);

        $shortage = max(0, $demandQty - $pickAvailable);

        if ($shortage <= 0.001) {
            return [
                'triggered'      => false,
                'product'        => $product,
                'pick_available' => $pickAvailable,
                'demand_qty'     => $demandQty,
                'shortage'       => 0,
                'message'        => 'Stok pick-face (Level A) mencukupi kebutuhan.',
            ];
        }

        // Target: existing pick-face bin of the SKU, else first free Level A bin — excludes blocked
        $targetRow = $db->prepare("SELECT lm.location_code
                FROM stock_locations sl
                JOIN location_master lm ON lm.location_code = sl.location_code
                JOIN stock s ON s.id = sl.stock_id
                WHERE s.product_id = ?
                  AND lm.row_name = 'A'
                  AND lm.is_pick_face = 1
                  AND sl.status IN ('Available','Reserved')
                  AND s.quantity > 0
                  $blocked
                ORDER BY lm.location_code LIMIT 1");
        $targetRow->execute([$productId]);
        $target = $targetRow->fetchColumn();

        if (!$target) {
            $free = $db->prepare("SELECT lm.location_code
                    FROM location_master lm
                    WHERE lm.is_active = 1
                      AND lm.row_name = 'A'
                      AND lm.is_pick_face = 1
                      AND lm.location_code NOT IN (
                          SELECT DISTINCT location_code FROM stock_locations
                          WHERE status IN ('Available','Reserved'))
                      $blocked
                    ORDER BY lm.aisle, lm.rack, lm.position LIMIT 1");
            $free->execute();
            $target = $free->fetchColumn();
        }

        $sourceRows = self::_findSourceRows($productId, $target ?? '', $shortage);
        $available = array_sum(array_column($sourceRows, 'take_qty'));
        $canFulfill = $available >= $shortage - 0.001;

        $transferId = null;
        $transferNumber = '';
        if ($createTransfer && $canFulfill && $target) {
            try {
                $transferId = BinTransfer::create([
                    'transfer_date'    => date('Y-m-d'),
                    'product_id'       => $productId,
                    'from_location'    => $sourceRows[0]['location'],
                    'to_location'      => $target,
                    'quantity'         => $shortage,
                    'uom'              => $product['uom_type'],
                    'reason'           => "Auto-replenishment untuk demand {$demandQty} (pick {$pickAvailable})",
                    'transfer_type'    => 'REPLENISHMENT',
                    'is_breakdown'     => 1,
                    'source_rows'      => $sourceRows,
                ]);
                $transferNumber = self::_getTransferNumber($transferId);
            } catch (Throwable $e) {
                $transferId = null;
            }
        }

        return [
            'triggered'       => true,
            'product'         => $product,
            'pick_available'  => $pickAvailable,
            'demand_qty'      => $demandQty,
            'shortage'        => $shortage,
            'target'          => $target,
            'available'       => $available,
            'can_fulfill'     => $canFulfill,
            'transfer_id'     => $transferId,
            'transfer_number' => $transferNumber,
            'source'          => array_map(function($r) {
                return [
                    'location'    => $r['location'],
                    'level'       => $r['level'],
                    'batch'       => $r['batch_number'],
                    'expiry_date' => $r['expiry_date'],
                    'take_qty'    => $r['take_qty'],
                ];
            }, $sourceRows),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Targets CRUD                                                        */
    /* ------------------------------------------------------------------ */

    public static function saveTarget(array $data): int {
        $db = db();
        $locationId = (int)($data['location_id'] ?? 0);
        $productId  = (int)($data['product_id'] ?? 0);
        $minQty     = floatval($data['min_qty'] ?? 0);
        $maxQty     = floatval($data['max_qty'] ?? 0);

        if (!$locationId) throw new Exception("location_id wajib diisi.");
        if (!$productId)  throw new Exception("product_id wajib diisi.");
        if ($minQty < 0)  throw new Exception("min_qty tidak boleh negatif.");
        if ($maxQty < 0)  throw new Exception("max_qty tidak boleh negatif.");

        $chk = $db->prepare("SELECT id FROM location_master WHERE id = ?");
        $chk->execute([$locationId]);
        if (!$chk->fetch()) throw new Exception("Lokasi target tidak ditemukan di master lokasi.");

        $chkP = $db->prepare("SELECT id FROM products WHERE id = ?");
        $chkP->execute([$productId]);
        if (!$chkP->fetch()) throw new Exception("Produk tidak ditemukan.");

        $stmt = $db->prepare("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_min, pickface_max)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE pickface_min = VALUES(pickface_min), pickface_max = VALUES(pickface_max),
                    updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$productId, $locationId, $minQty, $maxQty]);
        return (int)$db->lastInsertId();
    }

    public static function deleteTarget(int $id): bool {
        $db = db();
        $stmt = $db->prepare("DELETE FROM sku_pickface_config WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * SQL fragment excluding blocked bins from putaway_location_blocks.
     * @param string $alias Table alias for location_code (e.g. 'lm', 's')
     */
    private static function _blockedClause(string $alias = 'lm'): string {
        return "AND NOT EXISTS (
            SELECT 1 FROM putaway_location_blocks b
            WHERE b.is_active = 1
              AND ( (b.scope_type = 'location' AND b.location_code = {$alias}.location_code)
                 OR (b.scope_type = 'aisle'   AND {$alias}.location_code LIKE CONCAT(b.aisle_prefix, '%')) )
        )";
    }

    private static function _getTransferNumber(int $transferId): string {
        $db = db();
        $stmt = $db->prepare("SELECT transfer_number FROM bin_transfers WHERE id = ?");
        $stmt->execute([$transferId]);
        return $stmt->fetchColumn() ?? '';
    }
}