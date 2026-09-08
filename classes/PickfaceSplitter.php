<?php
declare(strict_types=1);

/**
 * PickfaceSplitter — Auto-replenishment split and trigger logic for outbound order lines.
 *
 * Splits outbound order quantities into bulk picks and pickface replenishment needs,
 * then triggers replenishment tasks when pickface stock is projected to fall below minimum.
 *
 * Spec references:
 *   §3 — Split order line into bulk_qty and pickfaceQty
 *   §4 — Check and trigger replenishment when projected_on_hand <= pickface_min
 */
class PickfaceSplitter
{
    /**
     * Split an outbound order line quantity into bulk and pickface components.
     *
     * Spec §3:
     *   bulk_qty    = floor(orderQty / pickfaceMax) * pickfaceMax
     *   pickfaceQty = orderQty % pickfaceMax
     *
     * @param float $orderQty    The total order quantity
     * @param int   $pickfaceMax The maximum pickface bin capacity
     * @return array{bulk_qty: float, pickface_qty: float}
     * @throws \InvalidArgumentException if pickfaceMax <= 0 or orderQty < 0
     */
    public static function splitOrderLine(float $orderQty, int $pickfaceMax): array
    {
        if ($pickfaceMax <= 0) {
            throw new \InvalidArgumentException("pickfaceMax must be greater than zero, got {$pickfaceMax}");
        }
        if ($orderQty < 0) {
            throw new \InvalidArgumentException("orderQty must not be negative, got {$orderQty}");
        }

        $bulkQty    = floor($orderQty / $pickfaceMax) * $pickfaceMax;
        $pickfaceQty = fmod($orderQty, $pickfaceMax);

        // Handle floating-point precision: round to 6 decimal places
        $bulkQty    = round($bulkQty, 6);
        $pickfaceQty = round($pickfaceQty, 6);

        return [
            'bulk_qty'      => $bulkQty,
            'pickface_qty'  => $pickfaceQty,
        ];
    }

    /**
     * Check if replenishment is needed for a SKU's pickface bin.
     *
     * Trigger condition:
     *   projected_on_hand <= pickface_min
     *
     * projected_on_hand = current_on_hand + in_transit - reserved
     *
     * In-flight dedup: open/incomplete replen_task records for the same SKU
     * and pickface bin are treated as "already received" when computing the trigger.
     *
     * @param int         $skuId       The product/SKU ID
     * @param float       $pickfaceQty The pickface qty from splitOrderLine (remainder)
     * @param \PDO|null   $db          PDO connection (uses db() singleton if null)
     * @return array{needs_replenishment: bool, projected_on_hand: float, pickface_min: float, config: ?array}
     */
    public static function checkReplenishment(int $skuId, float $pickfaceQty, $db = null): array
    {
        $db = $db ?? db();

        // 1. Get pickface configuration
        $config = self::getPickfaceConfig($skuId, $db);

        if (!$config) {
            return [
                'needs_replenishment' => false,
                'projected_on_hand'   => 0.0,
                'pickface_min'        => 0.0,
                'config'              => null,
            ];
        }

        $pickfaceMin = (int)$config['pickface_min'];
        $pickfaceBinId = (int)$config['pickface_bin_id'];

        // 2. Get pickface bin location code
        $locStmt = $db->prepare(
            "SELECT location_code FROM location_master WHERE id = ? LIMIT 1"
        );
        $locStmt->execute([$pickfaceBinId]);
        $pickfaceLocation = $locStmt->fetchColumn();

        if (!$pickfaceLocation) {
            return [
                'needs_replenishment' => false,
                'projected_on_hand'   => 0.0,
                'pickface_min'        => (float)$pickfaceMin,
                'config'              => $config,
            ];
        }

        // 3. Compute current_on_hand at pickface bin
        $onHandStmt = $db->prepare(
            "SELECT COALESCE(SUM(s.quantity), 0) FROM stock s
             WHERE s.product_id = ?
               AND s.location = ?
               AND s.stock_status = 'Available'
               AND (s.hold_status = 'available' OR s.hold_status IS NULL)
               AND s.quantity > 0"
        );
        $onHandStmt->execute([$skuId, $pickfaceLocation]);
        $currentOnHand = floatval($onHandStmt->fetchColumn());

        // 4. Compute in_transit: pending replen_task moving TO this pickface bin
        $inTransitStmt = $db->prepare(
            "SELECT COALESCE(SUM(r.qty), 0) FROM replen_task r
             WHERE r.sku_id = ?
               AND r.destination_bin_id = ?
               AND r.status IN ('pending', 'printed', 'in_progress')"
        );
        $inTransitStmt->execute([$skuId, $pickfaceBinId]);
        $inTransit = floatval($inTransitStmt->fetchColumn());

        // 5. Compute reserved: stock reserved for outbound orders at pickface bin
        $reservedStmt = $db->prepare(
            "SELECT COALESCE(SUM(oil.quantity), 0) FROM outbound_item_locations oil
             JOIN outbound_items oi ON oi.id = oil.outbound_item_id
             JOIN stock_locations sl ON sl.id = oil.stock_location_id
             WHERE oi.product_id = ?
               AND sl.location_code = ?
               AND oi.outbound_order_id IS NOT NULL"
        );
        $reservedStmt->execute([$skuId, $pickfaceLocation]);
        $reserved = floatval($reservedStmt->fetchColumn());

        // 6. Projected on-hand
        $projectedOnHand = $currentOnHand + $inTransit - $reserved;

        // 7. In-flight dedup: if there's already a pending replen_task for this SKU+bin,
        //    treat it as if the stock will arrive (already counted in in_transit above)
        //    but also don't trigger a duplicate task
        $existingTaskStmt = $db->prepare(
            "SELECT COUNT(*) FROM replen_task
             WHERE sku_id = ?
               AND destination_bin_id = ?
               AND status IN ('pending', 'printed', 'in_progress')"
        );
        $existingTaskStmt->execute([$skuId, $pickfaceBinId]);
        $hasInFlightTask = (int)$existingTaskStmt->fetchColumn() > 0;

        // 8. Trigger decision: need replenishment if projected <= min AND no in-flight task
        $needsReplenishment = ($projectedOnHand <= $pickfaceMin) && !$hasInFlightTask;

        return [
            'needs_replenishment' => $needsReplenishment,
            'projected_on_hand'   => round($projectedOnHand, 6),
            'pickface_min'        => (float)$pickfaceMin,
            'config'              => $config,
        ];
    }

    /**
     * Create a replenishment task to move stock from a bulk bin to the pickface bin.
     *
     * - Inserts a replen_task record with status 'pending'
     * - Enqueues a BullMQ job (stub for now — placeholder for future wiring)
     * - Acquires and releases a Redis lock on the pickface_bin_id
     *
     * @param int         $skuId       The product/SKU ID
     * @param float       $pickfaceQty The quantity to replenish
     * @param int|null    $orderId     The triggering outbound order ID (nullable)
     * @param \PDO|null   $db          PDO connection (uses db() singleton if null)
     * @param int|null    $sourceBinId Override source bin (from picklist item location)
     * @return int The ID of the created replen_task
     * @throws \Exception if no suitable source bin is found
     * @throws StockException if stock data is invalid
     */
    public static function createReplenTask(int $skuId, float $pickfaceQty, ?int $orderId, $db = null, ?int $sourceBinId = null): int
    {
        $db = $db ?? db();

        if ($pickfaceQty <= 0) {
            throw new \InvalidArgumentException("pickfaceQty must be greater than zero, got {$pickfaceQty}");
        }

        // 1. Get pickface config and destination bin
        $config = self::getPickfaceConfig($skuId, $db);
        if (!$config) {
            throw new StockException("No pickface config found for SKU #{$skuId}", [
                'sku_id' => $skuId,
            ]);
        }

        $pickfaceBinId = (int)$config['pickface_bin_id'];

        // 2. Find best source bin — use override if provided, otherwise search bulk bins
        if ($sourceBinId) {
            $sourceBinId = (int)$sourceBinId;
        } else {
            $sourceBinId = self::_findSourceBin($skuId, $pickfaceBinId, $db);
            if (!$sourceBinId) {
                throw new StockException(
                    "No suitable source bin found for replenishment of SKU #{$skuId}, qty {$pickfaceQty}",
                    [
                        'sku_id'          => $skuId,
                        'pickface_qty'    => $pickfaceQty,
                        'pickface_bin_id' => $pickfaceBinId,
                    ]
                );
            }
        }

        // 3. In-flight dedup: if there's already a pending replen_task for this SKU+source+destination,
        //    skip creation to prevent duplicates (race-condition guard)
        $existingStmt = $db->prepare(
            "SELECT id FROM replen_task
             WHERE sku_id = ?
               AND source_bin_id = ?
               AND destination_bin_id = ?
               AND status IN ('pending', 'printed', 'in_progress')
             LIMIT 1
             FOR UPDATE"
        );
        $existingStmt->execute([$skuId, $sourceBinId, $pickfaceBinId]);
        $existingTaskId = $existingStmt->fetchColumn();
        if ($existingTaskId) {
            error_log("[PickfaceSplitter] Dedup: SKU #{$skuId} already has pending task #{$existingTaskId} for source #{$sourceBinId} -> dest #{$pickfaceBinId}, skipping creation");
            return (int)$existingTaskId;
        }

        // 4. Acquire Redis lock on pickface_bin_id (stub — logs acquisition)
        self::_acquirePickfaceLock($pickfaceBinId, $skuId, $db);

        try {
            // 5. Insert replen_task record (no nested transaction — caller may already own one)
            $stmt = $db->prepare(
                "INSERT INTO replen_task
                    (sku_id, source_bin_id, destination_bin_id, qty, triggering_order_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
            );
            $stmt->execute([
                $skuId,
                $sourceBinId,
                $pickfaceBinId,
                round($pickfaceQty),
                $orderId,
            ]);

            $taskId = (int)$db->lastInsertId();

            // 6. Enqueue BullMQ job (stub for now)
            self::_enqueueBullMqJob($taskId, $skuId, $sourceBinId, $pickfaceBinId, (int)round($pickfaceQty));

            return $taskId;
        } finally {
            // 7. Release Redis lock
            self::_releasePickfaceLock($pickfaceBinId, $skuId, $db);
        }
    }

    /**
     * Get pickface configuration for a SKU from sku_pickface_config table.
     *
     * @param int       $skuId The product/SKU ID
     * @param \PDO|null $db    PDO connection (uses db() singleton if null)
     * @return array|null The config row or null if not configured
     */
    public static function getPickfaceConfig(int $skuId, $db = null): ?array
    {
        $db = $db ?? db();

        // 1. Check for explicit config in sku_pickface_config table (takes priority)
        $stmt = $db->prepare(
            "SELECT c.id, c.sku_id, c.pickface_bin_id, c.pickface_max, c.pickface_min,
                    c.inbound_pickface_bin_id,
                    lm.location_code AS pickface_location_code,
                    lm.row_name, lm.aisle, lm.zone,
                    lm_in.location_code AS inbound_pickface_location_code
             FROM sku_pickface_config c
             JOIN location_master lm ON lm.id = c.pickface_bin_id
             LEFT JOIN location_master lm_in ON lm_in.id = c.inbound_pickface_bin_id
             WHERE c.sku_id = ?
             LIMIT 1"
        );
        $stmt->execute([$skuId]);
        $row = $stmt->fetch();

        if ($row) {
            return [
                'id'                              => (int)$row['id'],
                'sku_id'                          => (int)$row['sku_id'],
                'pickface_bin_id'                 => (int)$row['pickface_bin_id'],
                'pickface_max'                    => (int)$row['pickface_max'],
                'pickface_min'                    => (int)$row['pickface_min'],
                'pickface_location_code'          => $row['pickface_location_code'],
                'row_name'                        => $row['row_name'],
                'aisle'                           => $row['aisle'],
                'zone'                            => $row['zone'],
                'inbound_pickface_bin_id'         => $row['inbound_pickface_bin_id'] ? (int)$row['inbound_pickface_bin_id'] : null,
                'inbound_pickface_location_code'  => $row['inbound_pickface_location_code'] ?? null,
            ];
        }

        // 2. No explicit config — auto-detect pickface bin from A-level locations
        return self::_autoDetectPickfaceConfig($skuId, $db);
    }

    /**
     * Auto-detect pickface configuration for a product by finding A-level bins with stock.
     *
     * A-level bins are pickface-level locations (pattern: xxANN e.g. CA01A01, CB30A01).
     * pickface_min is determined by UOM type, pickface_max from products.uom_per_pallet.
     *
     * @param int    $skuId The product/SKU ID
     * @param \PDO   $db    PDO connection
     * @return array|null The auto-detected config or null if no A-level bin found
     */
    private static function _autoDetectPickfaceConfig(int $skuId, \PDO $db): ?array
    {
        $productStmt = $db->prepare(
            "SELECT id, uom_type, uom_per_pallet FROM products WHERE id = ? LIMIT 1"
        );
        $productStmt->execute([$skuId]);
        $product = $productStmt->fetch();

        if (!$product) {
            return null;
        }

        $uomType = $product['uom_type'];
        $uomPerPallet = max((int)$product['uom_per_pallet'], 1);

        $pickfaceMin = match ($uomType) {
            'Carton', 'CAR' => 10,
            'Drum'          => 1,
            'Pail'          => 5,
            default         => 1,
        };

        // Step 1: compute A-level occupancy
        $occupancyStmt = $db->query(
            "SELECT
                (SELECT COUNT(*) FROM location_master
                 WHERE location_code REGEXP '[A-Z]{2}[0-9]{2}A[0-9]{2}$' AND is_active = 1) AS total_a_bins,
                (SELECT COUNT(DISTINCT lm.id)
                 FROM location_master lm
                 JOIN stock s ON s.location = lm.location_code
                 WHERE lm.location_code REGEXP '[A-Z]{2}[0-9]{2}A[0-9]{2}$'
                   AND lm.is_active = 1
                   AND s.quantity > 0
                   AND s.stock_status = 'Available') AS occupied_a_bins"
        );
        $occ = $occupancyStmt->fetch();
        $totalABins    = (int)($occ['total_a_bins'] ?? 0);
        $occupiedABins = (int)($occ['occupied_a_bins'] ?? 0);
        $occupancyPct  = $totalABins > 0 ? ($occupiedABins / $totalABins) * 100 : 100.0;

        // Step 2: below 90% occupancy — claim an empty A01 bin for OUTBOUND pickface
        if ($occupancyPct < 90.0) {
            $emptyBinStmt = $db->prepare(
                "SELECT lm.id AS location_id, lm.location_code, lm.row_name, lm.aisle, lm.zone
                 FROM location_master lm
                 WHERE lm.location_code REGEXP '[A-Z]{2}[0-9]{2}A01$'
                   AND lm.is_active = 1
                   AND lm.id NOT IN (SELECT pickface_bin_id FROM sku_pickface_config)
                   AND lm.id NOT IN (SELECT inbound_pickface_bin_id FROM sku_pickface_config WHERE inbound_pickface_bin_id IS NOT NULL)
                   AND NOT EXISTS (
                       SELECT 1 FROM stock s
                       WHERE s.location = lm.location_code AND s.quantity > 0
                   )
                 ORDER BY lm.location_code ASC
                 LIMIT 5"
            );
            $emptyBinStmt->execute();
            $candidates = $emptyBinStmt->fetchAll();

            foreach ($candidates as $candidate) {
                try {
                    $insertStmt = $db->prepare(
                        "INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_max, pickface_min)
                         VALUES (?, ?, ?, ?)"
                    );
                    $insertStmt->execute([$skuId, $candidate['location_id'], $uomPerPallet, $pickfaceMin]);

                    // Also claim the A02 in the same row for INBOUND pickface
                    $rowPrefix = substr($candidate['location_code'], 0, 5); // e.g. 'CA01A'
                    $inboundBinStmt = $db->prepare(
                        "SELECT lm.id AS location_id, lm.location_code
                         FROM location_master lm
                         WHERE lm.location_code = ?
                           AND lm.is_active = 1
                           AND lm.id NOT IN (SELECT pickface_bin_id FROM sku_pickface_config)
                           AND lm.id NOT IN (SELECT inbound_pickface_bin_id FROM sku_pickface_config WHERE inbound_pickface_bin_id IS NOT NULL)
                         LIMIT 1"
                    );
                    $inboundBinStmt->execute([$rowPrefix . '02']);
                    $inboundBin = $inboundBinStmt->fetch();

                    $inboundBinId = null;
                    $inboundLocationCode = null;
                    if ($inboundBin) {
                        $updateStmt = $db->prepare(
                            "UPDATE sku_pickface_config SET inbound_pickface_bin_id = ? WHERE id = ?"
                        );
                        $updateStmt->execute([$inboundBin['location_id'], $db->lastInsertId()]);
                        $inboundBinId = (int)$inboundBin['location_id'];
                        $inboundLocationCode = $inboundBin['location_code'];
                    }

                    return [
                        'id'                              => (int)$db->lastInsertId(),
                        'sku_id'                          => $skuId,
                        'pickface_bin_id'                 => (int)$candidate['location_id'],
                        'pickface_max'                    => $uomPerPallet,
                        'pickface_min'                    => $pickfaceMin,
                        'pickface_location_code'          => $candidate['location_code'],
                        'row_name'                        => $candidate['row_name'],
                        'aisle'                           => $candidate['aisle'],
                        'zone'                            => $candidate['zone'],
                        'inbound_pickface_bin_id'         => $inboundBinId,
                        'inbound_pickface_location_code'  => $inboundLocationCode,
                    ];
                } catch (\PDOException $e) {
                    continue;
                }
            }
        }

        // Step 3: occupancy >= 90%, or no empty bin found — reuse a bin
        // that already holds this SKU's stock (not persisted, same as before)
        $binStmt = $db->prepare(
            "SELECT lm.id AS location_id, lm.location_code, lm.row_name, lm.aisle, lm.zone,
                    SUM(s.quantity) AS total_qty
             FROM stock s
             JOIN location_master lm ON lm.location_code = s.location
             WHERE s.product_id = ?
               AND s.stock_status = 'Available'
               AND (s.hold_status = 'available' OR s.hold_status IS NULL)
               AND s.quantity > 0
               AND lm.location_code REGEXP '[A-Z]{2}[0-9]{2}A01$'
               AND lm.is_active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM sku_pickface_config c
                   WHERE c.pickface_bin_id = lm.id AND c.sku_id != ?
               )
             GROUP BY lm.id, lm.location_code, lm.row_name, lm.aisle, lm.zone
             ORDER BY total_qty DESC
             LIMIT 1"
        );
        $binStmt->execute([$skuId, $skuId]);
        $bin = $binStmt->fetch();

        if (!$bin) {
            return null;
        }

        return [
            'id'                              => 0,
            'sku_id'                          => $skuId,
            'pickface_bin_id'                 => (int)$bin['location_id'],
            'pickface_max'                    => $uomPerPallet,
            'pickface_min'                    => $pickfaceMin,
            'pickface_location_code'          => $bin['location_code'],
            'row_name'                        => $bin['row_name'],
            'aisle'                           => $bin['aisle'],
            'zone'                            => $bin['zone'],
            'inbound_pickface_bin_id'         => null,
            'inbound_pickface_location_code'  => null,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Private helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Find a suitable source bin for replenishment.
     * Selects from bulk/reserve locations (levels B–E) in FEFO order.
     * Returns the best FEFO source bin with any available stock.
     * The replenishment worker handles picking from multiple bins if needed.
     *
     * @param int     $skuId          Product ID
     * @param int     $pickfaceBinId  Destination pickface bin location ID
     * @param \PDO    $db             PDO connection
     * @return int|null Source location_master.id or null if none found
     */
    private static function _findSourceBin(int $skuId, int $pickfaceBinId, \PDO $db): ?int
    {
        // Get pickface location code to exclude it
        $pfLocStmt = $db->prepare(
            "SELECT location_code FROM location_master WHERE id = ? LIMIT 1"
        );
        $pfLocStmt->execute([$pickfaceBinId]);
        $pickfaceLocation = $pfLocStmt->fetchColumn();

        if (!$pickfaceLocation) {
            return null;
        }

        $palletStmt = $db->prepare(
            "SELECT uom_per_pallet FROM products WHERE id = ? LIMIT 1"
        );
        $palletStmt->execute([$skuId]);
        $uomPerPallet = max((int)$palletStmt->fetchColumn(), 1);

        // Aggregate expiry + row id so ORDER BY is deterministic after GROUP BY.
        // Priority: (1) partial pallets first, (2) FEFO, (3) lowest qty, (4) location.
        $stmt = $db->prepare(
            "SELECT s.location, lm.id AS location_id, lm.row_name,
                    COALESCE(SUM(s.quantity), 0) AS available_qty,
                    MIN(s.expiry_date) AS min_expiry
             FROM stock s
             JOIN location_master lm ON lm.location_code = s.location
             WHERE s.product_id = ?
               AND s.stock_status = 'Available'
               AND (s.hold_status = 'available' OR s.hold_status IS NULL)
               AND s.quantity > 0
               AND s.location != ?
               AND s.location NOT IN ('QUA_SHELL', 'STAGING', 'UNALLOCATED')
               AND lm.row_name IN ('B', 'C', 'D', 'E')
               AND lm.is_active = 1
             GROUP BY s.location, lm.id, lm.row_name
             HAVING available_qty > 0
             ORDER BY
                CASE WHEN SUM(s.quantity) < ? THEN 0 ELSE 1 END,
                CASE WHEN MIN(s.expiry_date) IS NULL THEN 1 ELSE 0 END,
                MIN(s.expiry_date) ASC,
                SUM(s.quantity) ASC,
                s.location ASC
             LIMIT 1"
        );
        $stmt->execute([$skuId, $pickfaceLocation, $uomPerPallet]);
        $row = $stmt->fetch();

        return $row ? (int)$row['location_id'] : null;
    }

    /**
     * Acquire a database-level lock on the pickface config row for a SKU
     * using SELECT ... FOR UPDATE. This prevents concurrent replenishment
     * tasks from being created for the same SKU.
     *
     * @param int   $pickfaceBinId The pickface bin location ID
     * @param int   $skuId         The product/SKU ID
     * @param \PDO  $db            PDO connection
     */
    private static function _acquirePickfaceLock(int $pickfaceBinId, int $skuId, \PDO $db): void
    {
        // Lock the sku_pickface_config row for this SKU using SELECT ... FOR UPDATE
        $stmt = $db->prepare(
            "SELECT id FROM sku_pickface_config
             WHERE sku_id = ?
             FOR UPDATE"
        );
        $stmt->execute([$skuId]);
        // Row is locked until the transaction commits or rolls back
    }

    /**
     * Release the lock on the pickface config row for a SKU.
     * With SELECT ... FOR UPDATE, the lock is released when the
     * transaction commits or rolls back — no explicit release needed.
     *
     * @param int   $pickfaceBinId The pickface bin location ID
     * @param int   $skuId         The product/SKU ID
     * @param \PDO  $db            PDO connection
     */
    private static function _releasePickfaceLock(int $pickfaceBinId, int $skuId, \PDO $db): void
    {
        // Lock is released on transaction commit/rollback — no explicit action needed
    }

    /**
     * Enqueue a BullMQ job for the replenishment task.
     * Stub implementation — logs the job. Replace with actual BullMQ producer
     * when the queue infrastructure is available.
     *
     * @param int      $taskId         The replen_task ID
     * @param int      $skuId          The product/SKU ID
     * @param int      $sourceBinId    Source bin location ID
     * @param int      $pickfaceBinId  Destination pickface bin location ID
     * @param int      $qty            Quantity to replenish
     */
    private static function _enqueueBullMqJob(
        int $taskId,
        int $skuId,
        int $sourceBinId,
        int $pickfaceBinId,
        int $qty
    ): void {
        // TODO: Replace with actual BullMQ producer when queue infrastructure is available
        error_log("[PickfaceSplitter] Enqueue BullMQ job: task={$taskId}, sku={$skuId}, "
            . "source={$sourceBinId}, dest={$pickfaceBinId}, qty={$qty}");
    }

    /**
     * Allocate stock with pickface priority via FefoAllocator.
     *
     * Returns the extended allocation result including 'replen_task_id' when
     * a replenishment task was created due to pickface shortage.
     */
    public static function allocatePickface(string $sku, float $qty): array
    {
        require_once __DIR__ . '/FefoAllocator.php';
        return FefoAllocator::allocate($sku, $qty, null, true);
    }

    public static function consolidatePartialPallets(int $skuId, $db = null): array
    {
        $db = $db ?? db();
        $taskIds = [];
        $partialBinsFound = 0;

        $palletStmt = $db->prepare(
            "SELECT uom_per_pallet FROM products WHERE id = ? LIMIT 1"
        );
        $palletStmt->execute([$skuId]);
        $uomPerPallet = (int)$palletStmt->fetchColumn();

        if ($uomPerPallet <= 0) {
            error_log("[PickfaceSplitter] consolidatePartialPallets: SKU #{$skuId} has invalid uom_per_pallet={$uomPerPallet}, skipping");
            return ['task_ids' => [], 'partial_bins_found' => 0];
        }

        $config = self::getPickfaceConfig($skuId, $db);
        if (!$config) {
            error_log("[PickfaceSplitter] consolidatePartialPallets: SKU #{$skuId} has no pickface config, skipping");
            return ['task_ids' => [], 'partial_bins_found' => 0];
        }

        $pickfaceBinId = (int)$config['pickface_bin_id'];

        $partialStmt = $db->prepare(
            "SELECT lm.id AS location_id, lm.location_code,
                    COALESCE(SUM(s.quantity), 0) AS total_qty
             FROM stock s
             JOIN location_master lm ON lm.location_code = s.location
             WHERE s.product_id = ?
               AND s.stock_status = 'Available'
               AND (s.hold_status = 'available' OR s.hold_status IS NULL)
               AND s.quantity > 0
               AND lm.row_name IN ('B', 'C', 'D', 'E')
               AND lm.is_active = 1
             GROUP BY lm.id, lm.location_code
             HAVING total_qty > 0 AND total_qty < ?
             ORDER BY lm.location_code ASC"
        );
        $partialStmt->execute([$skuId, $uomPerPallet]);
        $partialBins = $partialStmt->fetchAll();

        $partialBinsFound = count($partialBins);

        foreach ($partialBins as $bin) {
            $sourceBinId = (int)$bin['location_id'];
            $binQty = (float)$bin['total_qty'];

            $dedupStmt = $db->prepare(
                "SELECT id FROM replen_task
                 WHERE sku_id = ?
                   AND source_bin_id = ?
                   AND destination_bin_id = ?
                   AND status IN ('pending', 'printed', 'in_progress')
                 LIMIT 1"
            );
            $dedupStmt->execute([$skuId, $sourceBinId, $pickfaceBinId]);
            if ($dedupStmt->fetchColumn()) {
                error_log("[PickfaceSplitter] consolidatePartialPallets: dedup — SKU #{$skuId} source bin #{$sourceBinId} already has pending task, skipping");
                continue;
            }

            try {
                $taskId = self::createReplenTask($skuId, $binQty, null, $db);
                $taskIds[] = $taskId;
                error_log("[PickfaceSplitter] consolidatePartialPallets: created task #{$taskId} for SKU #{$skuId} from bin #{$sourceBinId} ({$bin['location_code']}) qty={$binQty}");
            } catch (\Exception $e) {
                error_log("[PickfaceSplitter] consolidatePartialPallets: failed to create task for SKU #{$skuId} from bin #{$sourceBinId}: " . $e->getMessage());
            }
        }

        return [
            'task_ids' => $taskIds,
            'partial_bins_found' => $partialBinsFound,
        ];
    }
}
