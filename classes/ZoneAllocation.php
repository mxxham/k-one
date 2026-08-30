<?php
declare(strict_types=1);

/**
 * ZoneAllocation — zone-aware stock allocation for optimized picking.
 * Allocates stock from the closest/most efficient zone first.
 */
class ZoneAllocation
{
    /**
     * Zone priority order (closest to shipping first).
     */
    const ZONE_PRIORITY = [
        'pick'    => 1,  // Pick face (fastest)
        'carton'  => 2,  // Carton flow
        'bulk'    => 3,  // Bulk storage
        'reserve' => 4,  // Reserve storage
    ];

    /**
     * Allocate stock with zone preference.
     * Tries preferred zone first, falls back to others.
     *
     * @param int         $productId      Product ID
     * @param float       $quantity       Required quantity
     * @param ?string     $preferredZone  Preferred zone (pick, carton, bulk, reserve)
     * @param ?string     $expiryDate     Minimum expiry date filter (YYYY-MM-DD)
     * @return array     Allocation records with stock_id, location, zone, qty, lpn_code
     * @throws InsufficientStockException if stock insufficient across all zones
     */
    public static function allocateByZone(
        int $productId,
        float $quantity,
        ?string $preferredZone = null,
        ?string $expiryDate = null
    ): array {
        $db = db();

        // Get available stock grouped by zone
        $zones = self::getAvailableByZone($productId, $expiryDate);

        if (empty($zones)) {
            throw new InsufficientStockException($productId, $quantity, 0);
        }

        // Sort zones by preference
        if ($preferredZone && isset(self::ZONE_PRIORITY[$preferredZone])) {
            // Move preferred zone to front
            uasort($zones, function ($a, $b) use ($preferredZone) {
                $aPriority = $a['zone'] === $preferredZone
                    ? 0
                    : (self::ZONE_PRIORITY[$a['zone']] ?? 99);
                $bPriority = $b['zone'] === $preferredZone
                    ? 0
                    : (self::ZONE_PRIORITY[$b['zone']] ?? 99);
                return $aPriority <=> $bPriority;
            });
        } else {
            // Sort by zone priority
            uasort($zones, function ($a, $b) {
                return (self::ZONE_PRIORITY[$a['zone']] ?? 99)
                    <=> (self::ZONE_PRIORITY[$b['zone']] ?? 99);
            });
        }

        // Allocate from zones in order
        $allocated = [];
        $remaining = round($quantity, 6);

        foreach ($zones as $zone) {
            if ($remaining <= 0) break;

            $toAllocate = round(min($remaining, $zone['available_qty']), 6);
            $allocated[] = [
                'stock_id'  => $zone['stock_id'],
                'location'  => $zone['location'],
                'zone'      => $zone['zone'],
                'qty'       => $toAllocate,
                'lpn_code'  => $zone['lpn_code'],
            ];
            $remaining = round($remaining - $toAllocate, 6);
        }

        if ($remaining > 1e-6) {
            throw new InsufficientStockException($productId, $quantity, $quantity - $remaining);
        }

        return $allocated;
    }

    /**
     * Get zone preference order for a product based on current stock distribution.
     * Returns zones sorted by available quantity (descending).
     *
     * @param int      $productId  Product ID
     * @return array   Sorted zone list with zone name, available_qty, and priority
     */
    public static function getZonePreference(int $productId): array
    {
        $zones = self::getAvailableByZone($productId);

        if (empty($zones)) {
            return [];
        }

        // Add priority info and sort by available quantity descending
        $result = [];
        foreach ($zones as $zone => $data) {
            $result[] = [
                'zone'          => $zone,
                'available_qty' => $data['available_qty'],
                'priority'      => self::ZONE_PRIORITY[$zone] ?? 99,
                'location'      => $data['location'],
            ];
        }

        usort($result, function ($a, $b) {
            return $b['available_qty'] <=> $a['available_qty'];
        });

        return $result;
    }

    /**
     * Optimize pick path for a list of items.
     * Orders items by zone proximity to reduce travel time.
     *
     * @param array  $pickItems  Array of items with 'zone' key
     * @return array Items sorted by zone priority (pick → carton → bulk → reserve)
     */
    public static function optimizePickPath(array $pickItems): array
    {
        // Group by zone
        $byZone = [];
        foreach ($pickItems as $item) {
            $zone = $item['zone'] ?? 'reserve';
            $byZone[$zone][] = $item;
        }

        // Sort zones by priority
        $sorted = [];
        foreach (self::ZONE_PRIORITY as $zone => $priority) {
            if (isset($byZone[$zone])) {
                $sorted = array_merge($sorted, $byZone[$zone]);
            }
        }

        // Add any remaining zones not in priority list
        foreach ($byZone as $zone => $items) {
            if (!isset(self::ZONE_PRIORITY[$zone])) {
                $sorted = array_merge($sorted, $items);
            }
        }

        return $sorted;
    }

    /**
     * Get available stock grouped by zone.
     *
     * @param int       $productId  Product ID
     * @param ?string   $expiryDate Minimum expiry date filter
     * @return array    Zone-keyed array with zone info and available_qty
     */
    private static function getAvailableByZone(int $productId, ?string $expiryDate = null): array
    {
        $db = db();

        $sql = "
            SELECT
                s.id as stock_id,
                s.location,
                s.quantity,
                lm.row_name as zone,
                sl.lpn_code,
                s.expiry_date
            FROM stock s
            JOIN location_master lm ON lm.location_code = s.location
            LEFT JOIN stock_locations sl ON sl.stock_id = s.id
            WHERE s.product_id = ?
              AND s.quantity > 0
              AND s.stock_status = 'Available'
              AND lm.is_active = 1
        ";
        $params = [$productId];

        if ($expiryDate) {
            $sql .= " AND s.expiry_date >= ?";
            $params[] = $expiryDate;
        }

        $sql .= " ORDER BY s.expiry_date ASC, s.quantity DESC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // Group by zone
        $zones = [];
        foreach ($rows as $row) {
            $zone = strtolower($row['zone'] ?? 'reserve');
            if (!isset($zones[$zone])) {
                $zones[$zone] = [
                    'zone'          => $zone,
                    'stock_id'      => $row['stock_id'],
                    'location'      => $row['location'],
                    'available_qty' => 0,
                    'lpn_code'      => $row['lpn_code'],
                ];
            }
            $zones[$zone]['available_qty'] += (float)$row['quantity'];
        }

        return $zones;
    }

    /**
     * Get zone statistics for a product.
     *
     * @param  int    $productId  Product ID
     * @return array  Zone stats with zone, location_count, total_qty, occupied_count
     */
    public static function getZoneStats(int $productId): array
    {
        $db = db();

        $stmt = $db->prepare("
            SELECT
                lm.row_name as zone,
                COUNT(*) as location_count,
                SUM(s.quantity) as total_qty,
                COUNT(CASE WHEN s.quantity > 0 THEN 1 END) as occupied_count
            FROM stock s
            JOIN location_master lm ON lm.location_code = s.location
            WHERE s.product_id = ?
              AND lm.is_active = 1
            GROUP BY lm.row_name
            ORDER BY FIELD(lm.row_name, 'A', 'B', 'C', 'D')
        ");
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }
}
