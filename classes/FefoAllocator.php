<?php
declare(strict_types=1);

/**
 * FefoAllocator — pure FEFO allocation logic over stock_locations × stock rows.
 *
 * Given a SKU (product_code or product_name) and required quantity, returns an
 * array of allocation rows ordered by expiry_date ASC (First Expired First Out),
 * skipping blocked bins and insufficient/zero-qty locations.
 *
 * When pickfacePriority=true, the pickface bin is tried first. If stock is
 * insufficient, a replenishment task is created and the caller can block the
 * outbound item until replenishment completes.
 *
 * Returns: ['allocation' => [...], 'sufficient' => bool, 'shortage' => float, 'total_available' => float]
 */
class FefoAllocator
{
    /**
     * Allocate stock for a SKU using FEFO (expiry) ordering at LPN/bin granularity.
     *
     * @param string   $sku              Product code (SKU string) or numeric product ID
     * @param float    $qty              Required quantity
     * @param ?string  $zoneFilter       Optional zone prefix to restrict allocation
     * @param bool     $pickfacePriority When true, allocate from pickface bin first;
     *                                   create replenishment task on shortage
     * @return array   ['allocation' => [...], 'sufficient' => bool, 'shortage' => float,
     *                  'total_available' => float, 'replen_task_id' => ?int]
     * @throws InsufficientStockException if no stock available at all (non-pickface path)
     * @throws ApiException if product not found
     */
    public static function allocate(
        string $sku,
        float $qty,
        ?string $zoneFilter = null,
        bool $pickfacePriority = false
    ): array {
        $db = db();

        // Resolve product — accept both product_code/SKU string and numeric product_id
        if (is_numeric($sku)) {
            $prodStmt = $db->prepare(
                "SELECT id, product_code, product_name FROM products WHERE id = ? LIMIT 1"
            );
            $prodStmt->execute([(int)$sku]);
        } else {
            $prodStmt = $db->prepare(
                "SELECT id, product_code, product_name FROM products
                 WHERE product_code = ? OR product_name = ? LIMIT 1"
            );
            $prodStmt->execute([$sku, $sku]);
        }
        $product = $prodStmt->fetch();
        if (!$product) {
            throw new ApiException("Product not found: {$sku}", 404);
        }
        $productId = (int)$product['id'];

        if ($pickfacePriority) {
            return self::_allocatePickfaceFirst($db, $productId, $sku, $qty);
        }

        return self::_allocateFefo($db, $productId, $sku, $qty, $zoneFilter);
    }

    /* ------------------------------------------------------------------ */
    /* Pickface-first allocation                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Allocate from the pickface bin first. On shortage, create a replenishment
     * task so the outbound item can be blocked until stock arrives.
     */
    private static function _allocatePickfaceFirst(
        \PDO $db,
        int $productId,
        string $sku,
        float $qty
    ): array {
        // Use PickfaceSplitter::getPickfaceConfig() which handles both explicit
        // config AND auto-detection of A-level bins for all products
        require_once __DIR__ . '/PickfaceSplitter.php';
        $pfConfig = PickfaceSplitter::getPickfaceConfig($productId, $db);

        if (!$pfConfig) {
            return self::_allocateFefo($db, $productId, $sku, $qty, null);
        }

        $pickfaceBin = $pfConfig['pickface_location_code'];

        $pfSql = "SELECT
                      sl.id AS stock_location_id,
                      sl.lpn_code,
                      sl.location_code,
                      sl.quantity,
                      s.expiry_date,
                      s.batch_number
                  FROM stock_locations sl
                  JOIN stock s ON s.id = sl.stock_id
                  WHERE s.product_id = ?
                    AND sl.location_code = ?
                    AND sl.quantity > 0
                    AND sl.status = 'Available'
                    AND s.stock_status = 'Available'
                    AND (s.hold_status IS NULL OR s.hold_status = 'available')
                  ORDER BY s.expiry_date ASC, sl.id ASC";

        $pfStmt2 = $db->prepare($pfSql);
        $pfStmt2->execute([$productId, $pickfaceBin]);
        $pfRows = $pfStmt2->fetchAll();

        $pickfaceAllocations = [];
        $remainingQty        = round($qty, 6);
        $totalPickfaceAvail  = 0.0;

        foreach ($pfRows as $row) {
            $avail = round((float)$row['quantity'], 6);
            $totalPickfaceAvail += $avail;

            if ($remainingQty <= 1e-9) {
                break;
            }

            $take      = min($remainingQty, $avail);
            $isPartial = $take < $avail;

            $pickfaceAllocations[] = [
                'lpn_code'          => $row['lpn_code'],
                'bin_location'      => $row['location_code'],
                'qty_to_take'       => round($take, 6),
                'stock_location_id' => (int)$row['stock_location_id'],
                'expiry_date'       => $row['expiry_date'],
                'batch_number'      => $row['batch_number'],
                'available_qty'     => $avail,
                'is_partial'        => $isPartial,
            ];

            $remainingQty = round($remainingQty - $take, 6);
        }

        $shortage   = max(0, $remainingQty);
        $sufficient = $shortage <= 1e-5;

        $taskId = null;
        if ($shortage > 0) {
            require_once __DIR__ . '/PickfaceSplitter.php';
            try {
                $taskId = PickfaceSplitter::createReplenTask(
                    $productId,
                    $shortage,
                    null,
                    $db
                );
            } catch (\Throwable $e) {
                error_log("[FefoAllocator] Replenishment task creation failed for SKU #{$productId}: " . $e->getMessage());
                // Mark insufficient so caller knows replenishment couldn't be scheduled
                $sufficient = false;
            }
        }

        return [
            'allocation'      => $pickfaceAllocations,
            'sufficient'      => $sufficient,
            'shortage'        => $shortage,
            'total_available' => round($totalPickfaceAvail, 6),
            'replen_task_id'  => $taskId,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Standard FEFO allocation (any bin)                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Standard FEFO allocation across all bins (existing behaviour).
     */
    private static function _allocateFefo(
        \PDO $db,
        int $productId,
        string $sku,
        float $qty,
        ?string $zoneFilter
    ): array {
        // Build query — join stock_locations × stock, filter by availability + not blocked
        $sql = "SELECT
                    sl.id AS stock_location_id,
                    sl.lpn_code,
                    sl.location_code,
                    sl.quantity,
                    sl.status,
                    s.expiry_date,
                    s.batch_number,
                    s.product_id
                FROM stock_locations sl
                JOIN stock s ON s.id = sl.stock_id
                WHERE s.product_id = ?
                  AND sl.quantity > 0
                  AND sl.status = 'Available'
                  AND s.stock_status = 'Available'
                  AND (s.hold_status IS NULL OR s.hold_status = 'available')
                  AND sl.lpn_code IS NOT NULL
                  AND sl.lpn_code != ''";

        // Exclude blocked bins (putaway_location_blocks with is_active=1)
        $sql .= " AND NOT EXISTS (
                    SELECT 1 FROM putaway_location_blocks b
                    WHERE b.is_active = 1
                      AND (
                          (b.scope_type = 'location' AND b.location_code = sl.location_code)
                          OR
                          (b.scope_type = 'aisle' AND b.aisle_prefix = LEFT(sl.location_code, 2))
                      )
                 )";

        $params = [$productId];

        // Optional zone filter (match aisle prefix)
        if ($zoneFilter !== null && $zoneFilter !== '') {
            $sql .= " AND LEFT(sl.location_code, 2) = ?";
            $params[] = $zoneFilter;
        }

        // FEFO: order by expiry_date ASC, oldest first
        $sql .= " ORDER BY s.expiry_date ASC, sl.id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $allocation   = [];
        $remainingQty = round($qty, 6);
        $totalAvail   = 0.0;

        foreach ($rows as $row) {
            $avail = round((float)$row['quantity'], 6);
            $totalAvail += $avail;

            if ($remainingQty <= 1e-9) break;

            $take    = min($remainingQty, $avail);
            $isPartial = $take < $avail;

            $allocation[] = [
                'lpn_code'          => $row['lpn_code'],
                'bin_location'      => $row['location_code'],
                'qty_to_take'       => round($take, 6),
                'stock_location_id' => (int)$row['stock_location_id'],
                'expiry_date'       => $row['expiry_date'],
                'batch_number'      => $row['batch_number'],
                'available_qty'     => $avail,
                'is_partial'        => $isPartial,
            ];

            $remainingQty = round($remainingQty - $take, 6);
        }

        $sufficient = $remainingQty <= 1e-5;
        $shortage   = max(0, $remainingQty);

        if (empty($allocation) && $qty > 0) {
            throw new InsufficientStockException($sku, $qty, 0.0);
        }

        return [
            'allocation'      => $allocation,
            'sufficient'      => $sufficient,
            'shortage'        => $shortage,
            'total_available' => round($totalAvail, 6),
            'replen_task_id'  => null,
        ];
    }
}
