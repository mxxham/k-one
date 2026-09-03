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
     * Spec §4:
     *   projected_on_hand = current_on_hand + in_transit - reserved
     *   Trigger replenishment when projected_on_hand <= pickface_min
     *
     * In-flight dedup: open/incomplete replen_task records for the same SKU
     * and pickface bin are treated as "already received" when computing the trigger.
     *
     * @param int         $skuId       The product/SKU ID
     * @param float       $pickfaceQty The pickface qty from splitOrderLine
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
     * @return int The ID of the created replen_task
     * @throws \Exception if no suitable source bin is found
     * @throws StockException if stock data is invalid
     */
    public static function createReplenTask(int $skuId, float $pickfaceQty, ?int $orderId, $db = null): int
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

        // 2. Find best source bin (bulk/reserve locations, B-E levels, FEFO order)
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

        // 3. Acquire Redis lock on pickface_bin_id (stub — logs acquisition)
        self::_acquirePickfaceLock($pickfaceBinId, $skuId);

        try {
            // 4. Insert replen_task record (no nested transaction — caller may already own one)
            $stmt = $db->prepare(
                "INSERT INTO replen_task
                    (sku_id, source_bin_id, destination_bin_id, qty, triggering_order_id, status, created_at)
                 VALUES (?, ?, ?, ?, ?, 'pending', NOW())"
            );
            $stmt->execute([
                $skuId,
                $sourceBinId,
                $pickfaceBinId,
                (int)$pickfaceQty,
                $orderId,
            ]);

            $taskId = (int)$db->lastInsertId();

            // 5. Enqueue BullMQ job (stub for now)
            self::_enqueueBullMqJob($taskId, $skuId, $sourceBinId, $pickfaceBinId, (int)$pickfaceQty);

            return $taskId;
        } finally {
            // 6. Release Redis lock
            self::_releasePickfaceLock($pickfaceBinId, $skuId);
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
                    lm.location_code AS pickface_location_code,
                    lm.row_name, lm.aisle, lm.zone
             FROM sku_pickface_config c
             JOIN location_master lm ON lm.id = c.pickface_bin_id
             WHERE c.sku_id = ?
             LIMIT 1"
        );
        $stmt->execute([$skuId]);
        $row = $stmt->fetch();

        if ($row) {
            return [
                'id'                    => (int)$row['id'],
                'sku_id'                => (int)$row['sku_id'],
                'pickface_bin_id'       => (int)$row['pickface_bin_id'],
                'pickface_max'          => (int)$row['pickface_max'],
                'pickface_min'          => (int)$row['pickface_min'],
                'pickface_location_code' => $row['pickface_location_code'],
                'row_name'              => $row['row_name'],
                'aisle'                 => $row['aisle'],
                'zone'                  => $row['zone'],
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
        // Get product UOM details
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

        // Determine pickface_min based on UOM type
        $pickfaceMin = match ($uomType) {
            'Carton', 'CAR' => 10,
            'Drum'          => 1,
            'Pail'          => 5,
            default         => 1,
        };

        // Find A-level bins with available stock for this product
        $binStmt = $db->prepare(
            "SELECT lm.id AS location_id, lm.location_code, lm.row_name, lm.aisle, lm.zone,
                    SUM(s.quantity) AS total_qty
             FROM stock s
             JOIN location_master lm ON lm.location_code = s.location
             WHERE s.product_id = ?
               AND s.stock_status = 'Available'
               AND (s.hold_status = 'available' OR s.hold_status IS NULL)
               AND s.quantity > 0
               AND lm.location_code REGEXP '[A-Z]{2}[0-9]{2}A[0-9]{2}$'
               AND lm.is_active = 1
             GROUP BY lm.id, lm.location_code, lm.row_name, lm.aisle, lm.zone
             ORDER BY total_qty DESC
             LIMIT 1"
        );
        $binStmt->execute([$skuId]);
        $bin = $binStmt->fetch();

        if (!$bin) {
            return null;
        }

        return [
            'id'                    => 0,  // auto-detected, not from config table
            'sku_id'                => $skuId,
            'pickface_bin_id'       => (int)$bin['location_id'],
            'pickface_max'          => $uomPerPallet,
            'pickface_min'          => $pickfaceMin,
            'pickface_location_code' => $bin['location_code'],
            'row_name'              => $bin['row_name'],
            'aisle'                 => $bin['aisle'],
            'zone'                  => $bin['zone'],
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

        // Find bulk bins (levels B–E) with any available stock, FEFO order
        $stmt = $db->prepare(
            "SELECT s.location, lm.id AS location_id, lm.row_name,
                    COALESCE(SUM(s.quantity), 0) AS available_qty
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
                CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END,
                s.expiry_date ASC,
                s.location ASC,
                s.id ASC
             LIMIT 1"
        );
        $stmt->execute([$skuId, $pickfaceLocation]);
        $row = $stmt->fetch();

        return $row ? (int)$row['location_id'] : null;
    }

    /**
     * Acquire a Redis-style lock on the pickface bin for a SKU.
     * Stub implementation — logs lock acquisition. Replace with actual Redis lock
     * (e.g., Redisson, Predis) when infrastructure is available.
     *
     * @param int $pickfaceBinId The pickface bin location ID
     * @param int $skuId         The product/SKU ID
     */
    private static function _acquirePickfaceLock(int $pickfaceBinId, int $skuId): void
    {
        // TODO: Replace with actual Redis lock when infrastructure is available
        // Example: $redis = new Redis(); $redis->connect('127.0.0.1');
        //          $lockKey = "replen:pickface:lock:{$pickfaceBinId}:{$skuId}";
        //          $redis->set($lockKey, '1', ['NX', 'EX' => 30]);
        error_log("[PickfaceSplitter] Acquire lock: pickface_bin={$pickfaceBinId}, sku={$skuId}");
    }

    /**
     * Release the Redis-style lock on the pickface bin for a SKU.
     * Stub implementation — logs lock release. Replace with actual Redis lock.
     *
     * @param int $pickfaceBinId The pickface bin location ID
     * @param int $skuId         The product/SKU ID
     */
    private static function _releasePickfaceLock(int $pickfaceBinId, int $skuId): void
    {
        // TODO: Replace with actual Redis lock release when infrastructure is available
        // Example: $redis->del("replen:pickface:lock:{$pickfaceBinId}:{$skuId}");
        error_log("[PickfaceSplitter] Release lock: pickface_bin={$pickfaceBinId}, sku={$skuId}");
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
        // Example:
        //   $queue = new \BullMQ\Queue('replenishment');
        //   $queue->add([
        //       'task_id'          => $taskId,
        //       'sku_id'           => $skuId,
        //       'source_bin_id'    => $sourceBinId,
        //       'destination_bin_id' => $pickfaceBinId,
        //       'qty'              => $qty,
        //   ]);
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
}
