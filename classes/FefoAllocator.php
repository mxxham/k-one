<?php
declare(strict_types=1);

/**
 * FefoAllocator — pure FEFO allocation logic over stock_locations × stock rows.
 *
 * Given a SKU (product_code or product_name) and required quantity, returns an
 * array of allocation rows ordered by expiry_date ASC (First Expired First Out),
 * skipping blocked bins and insufficient/zero-qty locations.
 *
 * pickfacePriority modes:
 *   false/'none'         — standard FEFO across all bins (default)
 *   true/'pickface_first' — pickface bin first, bulk for remainder
 *   'bulk_first'         — bulk (B-E) first, pickface (A-level) for remainder
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
     * @param bool|string $pickfacePriority false/'none' = standard FEFO (default),
     *                                      true/'pickface_first' = pickface first, bulk for remainder,
     *                                      'bulk_first' = bulk (B-E) first, pickface (A-level) for remainder
     * @return array   ['allocation' => [...], 'sufficient' => bool, 'shortage' => float,
     *                  'total_available' => float, 'replen_task_id' => ?int]
     * @throws InsufficientStockException if no stock available at all (non-pickface path)
     * @throws ApiException if product not found
     */
    public static function allocate(
        int|string $sku,
        float $qty,
        ?string $zoneFilter = null,
        bool|string $pickfacePriority = false
    ): array {
        $db = db();

        // Resolve product — accept both product_code/SKU string and numeric product_id
        // Use strict type check: only treat as numeric ID if it's actually an int type
        // String SKUs like '550058593' always go through the string path (product_code lookup)
        if (is_int($sku)) {
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

        $mode = is_string($pickfacePriority) ? $pickfacePriority : ($pickfacePriority ? 'pickface_first' : 'none');

        if ($mode === 'bulk_first') {
            return self::_allocateBulkFirstPickfaceLast($db, $productId, $sku, $qty);
        }
        if ($mode === 'pickface_first') {
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
        int|string $sku,
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

        // If pickface didn't have enough, allocate remaining from bulk bins (standard FEFO)
        $bulkAllocations = [];
        if ($remainingQty > 1e-5) {
            // Query bulk bins (excluding pickface bin) in FEFO order
            $bulkSql = "SELECT
                          sl.id AS stock_location_id,
                          sl.lpn_code,
                          sl.location_code,
                          sl.quantity,
                          s.expiry_date,
                          s.batch_number
                        FROM stock_locations sl
                        JOIN stock s ON s.id = sl.stock_id
                        WHERE s.product_id = ?
                          AND sl.location_code != ?
                          AND sl.quantity > 0
                          AND sl.status = 'Available'
                          AND s.stock_status = 'Available'
                          AND (s.hold_status IS NULL OR s.hold_status = 'available')
                          AND sl.lpn_code IS NOT NULL
                          AND sl.lpn_code != ''
                        ORDER BY s.expiry_date ASC, sl.id ASC";

            $bulkStmt = $db->prepare($bulkSql);
            $bulkStmt->execute([$productId, $pickfaceBin]);
            $bulkRows = $bulkStmt->fetchAll();

            foreach ($bulkRows as $row) {
                $avail = round((float)$row['quantity'], 6);
                if ($remainingQty <= 1e-9) break;

                $take = min($remainingQty, $avail);
                $isPartial = $take < $avail;

                $bulkAllocations[] = [
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
        }

        $shortage   = max(0, $remainingQty);
        $sufficient = $shortage <= 1e-5;

        // Replenishment tasks are created by Picklist::insertPicklistItems() trigger
        // with the correct source bin (the picklist item's actual bulk bin location).

        // Combine pickface + bulk allocations
        $allAllocations = array_merge($pickfaceAllocations, $bulkAllocations);
        $totalAvailable = $totalPickfaceAvail + array_reduce($bulkAllocations, fn($sum, $a) => $sum + $a['available_qty'], 0.0);

        return [
            'allocation'      => $allAllocations,
            'sufficient'      => $sufficient,
            'shortage'        => $shortage,
            'total_available' => round($totalAvailable, 6),
            'replen_task_id'  => $taskId,
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Bulk-first, pickface-last allocation                                */
    /* ------------------------------------------------------------------ */

    /**
     * Allocate from bulk bins (B-E levels) first in FEFO order, then from
     * the pickface bin (A-level) for the remainder.  Triggers replenishment
     * when the pickface bin is empty or below pickface_min.
     */
    private static function _allocateBulkFirstPickfaceLast(
        \PDO $db,
        int $productId,
        int|string $sku,
        float $qty
    ): array {
        require_once __DIR__ . '/PickfaceSplitter.php';
        $pfConfig = PickfaceSplitter::getPickfaceConfig($productId, $db);

        if (!$pfConfig) {
            return self::_allocateFefo($db, $productId, $sku, $qty, null);
        }

        $pickfaceBinId   = (int)$pfConfig['pickface_bin_id'];
        $pickfaceBinCode = $pfConfig['pickface_location_code'];

        // Step 1: Allocate from bulk bins (levels B–E) in FEFO order
        // Location format: xxNNxNN — e.g. CB36B02 (aisle=CB, rack=36, level=B, bin=02)
        // Level letter is at position 5: A=pickface, B–E=bulk
        $bulkSql = "SELECT
                        sl.id AS stock_location_id,
                        sl.lpn_code,
                        sl.location_code,
                        sl.quantity,
                        s.expiry_date,
                        s.batch_number
                    FROM stock_locations sl
                    JOIN stock s ON s.id = sl.stock_id
                    WHERE s.product_id = ?
                      AND sl.quantity > 0
                      AND sl.status = 'Available'
                      AND s.stock_status = 'Available'
                      AND (s.hold_status IS NULL OR s.hold_status = 'available')
                      AND sl.lpn_code IS NOT NULL
                      AND sl.lpn_code != ''
                      AND SUBSTRING(sl.location_code, 5, 1) IN ('B','C','D','E')
                      AND NOT EXISTS (
                          SELECT 1 FROM putaway_location_blocks b
                          WHERE b.is_active = 1
                            AND (
                                (b.scope_type = 'location' AND b.location_code = sl.location_code)
                                OR
                                (b.scope_type = 'aisle' AND b.aisle_prefix = LEFT(sl.location_code, 2))
                            )
                      )
                    ORDER BY s.expiry_date ASC, sl.id ASC";

        $bulkStmt = $db->prepare($bulkSql);
        $bulkStmt->execute([$productId]);
        $bulkRows = $bulkStmt->fetchAll();

        $bulkAllocations = [];
        $remainingQty    = round($qty, 6);
        $totalBulkAvail  = 0.0;

        foreach ($bulkRows as $row) {
            $avail = round((float)$row['quantity'], 6);
            $totalBulkAvail += $avail;
            if ($remainingQty <= 1e-9) break;

            $take      = min($remainingQty, $avail);
            $isPartial = $take < $avail;

            $bulkAllocations[] = [
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

        // Step 2: Allocate remainder from pickface bin (A-level)
        $pickfaceAllocations = [];
        $totalPickfaceAvail  = 0.0;

        if ($remainingQty > 1e-5) {
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

            $pfStmt = $db->prepare($pfSql);
            $pfStmt->execute([$productId, $pickfaceBinCode]);
            $pfRows = $pfStmt->fetchAll();

            foreach ($pfRows as $row) {
                $avail = round((float)$row['quantity'], 6);
                $totalPickfaceAvail += $avail;
                if ($remainingQty <= 1e-9) break;

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
        }

        $shortage   = max(0, $remainingQty);
        $sufficient = $shortage <= 1e-5;

        // Replenishment tasks are created by Picklist::insertPicklistItems() trigger
        // with the correct source bin (the picklist item's actual bulk bin location).

        $allAllocations = array_merge($bulkAllocations, $pickfaceAllocations);
        $totalAvailable = $totalBulkAvail + $totalPickfaceAvail;

        return [
            'allocation'      => $allAllocations,
            'sufficient'      => $sufficient,
            'shortage'        => $shortage,
            'total_available' => round($totalAvailable, 6),
            'replen_task_id'  => null,
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
        int|string $sku,
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
        $sql .= " FOR UPDATE ORDER BY s.expiry_date ASC, sl.id ASC";

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
