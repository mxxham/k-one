<?php

class Stock {
    public static function getAll($status = null, $expiring = false, $year = null, $db = null) {
        $db = $db ?? db();
        $sql = "SELECT s.*, p.product_code, p.product_name, p.category, p.uom_type, p.uom_per_pallet, p.velocity_class
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.quantity > 0";

        if ($status) {
            $sql .= " AND s.stock_status = ?";
        }

        if ($year) {
            $sql .= " AND YEAR(s.expiry_date) = " . intval($year);
        }

        if ($expiring) {
            $sql .= " AND s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY s.expiry_date ASC";
        } else {
            $sql .= " ORDER BY p.product_name, s.expiry_date ASC";
        }

        $stmt = $db->prepare($sql);
        if ($status) {
            $stmt->execute([$status]);
        } else {
            $stmt->execute();
        }
        return $stmt->fetchAll();
    }

    public static function getById($id, $db = null) {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT s.*, p.product_code, p.product_name, p.category, p.uom_type, p.uom_per_pallet
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function getByProduct($productId, $db = null) {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT * FROM stock WHERE product_id = ? AND quantity > 0 ORDER BY expiry_date ASC");
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    public static function getSummary($db = null) {
        $db = $db ?? db();
        $summary = $db->query("SELECT
                COUNT(DISTINCT product_id) as total_products,
                SUM(quantity) as total_drums,
                COUNT(CASE WHEN stock_status = 'Available' THEN 1 END) as available_items,
                COUNT(CASE WHEN stock_status = 'Reserved' THEN 1 END) as reserved_items,
                COUNT(CASE WHEN stock_status = 'Expired' THEN 1 END) as expired_items,
                COUNT(CASE WHEN stock_status = 'Dues In' THEN 1 END) as dues_in_items
                FROM stock WHERE quantity > 0")->fetch();

        $palletResult = $db->query("SELECT
                SUM(CEIL(s.quantity / GREATEST(COALESCE(p.uom_per_pallet, 4), 1))) as total_pallets
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.quantity > 0 AND s.stock_status = 'Available'")->fetch();
        $summary['total_pallets'] = (int)($palletResult['total_pallets'] ?? 0);

        $expiring = $db->query("SELECT COUNT(*) as count FROM stock
                WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                AND quantity > 0 AND stock_status = 'Available'")->fetch()['count'];

        $critical = $db->query("SELECT COUNT(*) as count FROM stock
                WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 120 DAY)
                AND quantity > 0 AND stock_status = 'Available'")->fetch()['count'];

        $expired = $db->query("SELECT COUNT(*) as count FROM stock
                WHERE expiry_date < CURDATE()
                AND quantity > 0")->fetch()['count'];

        $summary['expiring_soon'] = $expiring;
        $summary['critical'] = $critical;
        $summary['expired'] = $expired;
        $summary['total_qty'] = floatval($summary['total_drums'] ?? 0);
        return $summary;
    }

    public static function getExpiringSoon($days = 30, $db = null) {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT s.*, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                DATEDIFF(s.expiry_date, CURDATE()) as days_until_expiry
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
                AND s.quantity > 0 AND s.stock_status = 'Available'
                ORDER BY s.expiry_date ASC");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }

    

    public static function getExpiryInfo($expiryDate) {
        if (!$expiryDate) {
            return [
                'has_expiry' => false,
                'text' => 'No expiry',
                'months' => 0,
                'days' => 0,
                'total_days' => 0,
                'is_expired' => false,
                'is_critical' => false,
                'css_class' => 'text-gray-400',
                'bg_class' => 'bg-gray-50'
            ];
        }

        $expiry = new DateTime($expiryDate);
        $today = new DateTime();
        $interval = $today->diff($expiry);

        $months = ($interval->y * 12) + $interval->m;
        $days = $interval->d;
        $totalDays = $interval->days;

        $isExpired = $interval->invert;
        $isCritical = !$isExpired && $totalDays <= 120;
        $isWarning = !$isExpired && $totalDays <= 180;

        $cssClass = 'text-gray-700';
        $bgClass = 'bg-gray-50';
        $icon = '';

        if ($isExpired) {
            $cssClass = 'text-red-700 font-bold';
            $bgClass = 'bg-red-100';
            $icon = '✗ ';
        } elseif ($isCritical) {
            $cssClass = 'text-red-600 font-bold';
            $bgClass = 'bg-red-50 border border-red-300';
            $icon = '⚠ ';
        } elseif ($isWarning) {
            $cssClass = 'text-orange-600';
            $bgClass = 'bg-orange-50';
            $icon = '⚠ ';
        }

        if ($isExpired) {
            $text = "Expired {$totalDays} days ago";
        } else {
            $text = "{$months}m {$days}d left";
        }

        return [
            'has_expiry' => true,
            'text' => $text,
            'formatted_date' => date('d M Y', strtotime($expiryDate)),
            'months' => $months,
            'days' => $days,
            'total_days' => $totalDays,
            'is_expired' => $isExpired,
            'is_critical' => $isCritical,
            'is_warning' => $isWarning,
            'css_class' => $cssClass,
            'bg_class' => $bgClass,
            'icon' => $icon
        ];
    }

    public static function getMovement($productId = null, $startDate = null, $endDate = null, $limit = 100, $db = null) {
        $db = $db ?? db();
        $sql = "SELECT sl.*, p.product_code, p.product_name
                FROM stock_ledger sl
                JOIN products p ON sl.product_id = p.id
                WHERE 1=1";

        $params = [];

        if ($productId) {
            $sql .= " AND sl.product_id = ?";
            $params[] = $productId;
        }

        if ($startDate) {
            $sql .= " AND sl.transaction_date >= ?";
            $params[] = $startDate;
        }

        if ($endDate) {
            $sql .= " AND sl.transaction_date <= ?";
            $params[] = $endDate;
        }

        $sql .= " ORDER BY sl.transaction_date DESC, sl.created_at DESC";

        if ($limit) {
            $sql .= " LIMIT " . intval($limit);
        }

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getStockByLocation($db = null) {
        $db = $db ?? db();
        return $db->query("SELECT LEFT(location, 2) as area,
                COUNT(DISTINCT product_id) as products,
                SUM(quantity) as total_qty,
                SUM(pallet) as total_pallet
                FROM stock WHERE quantity > 0 AND location IS NOT NULL
                GROUP BY LEFT(location, 2)
                ORDER BY area")->fetchAll();
    }

    public static function transfer($stockId, $newLocation, $quantity = null, $db = null) {
        $db = $db ?? db();
        try {
            $db->beginTransaction();

            $stock = self::getById($stockId);
            $transferQty = $quantity ?? $stock['quantity'];

            if ($transferQty > $stock['quantity']) {
                throw new Exception("Transfer quantity exceeds available stock");
            }

            
            if ($transferQty < $stock['quantity']) {
                $stmt = $db->prepare("UPDATE stock SET quantity = quantity - ?, pallet = pallet - ? WHERE id = ?");
                $uomPerPallet = $stock['uom_per_pallet'] ?? 4;
                $palletReduction = ceil($transferQty / $uomPerPallet);
                $stmt->execute([$transferQty, $palletReduction, $stockId]);

                $stmt = $db->prepare("INSERT INTO stock (product_id, batch_number, quantity, uom, pallet, manufacture_date, expiry_date, location, stock_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $stock['product_id'],
                    $stock['batch_number'],
                    $transferQty,
                    $stock['uom'],
                    $palletReduction,
                    $stock['manufacture_date'],
                    $stock['expiry_date'],
                    $newLocation,
                    $stock['stock_status']
                ]);
            } else {
                
                $stmt = $db->prepare("UPDATE stock SET location = ? WHERE id = ?");
                $stmt->execute([$newLocation, $stockId]);
            }

            $db->commit();
            return true;
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function adjust($stockId, $newQuantity, $reason, $db = null) {
        $db = $db ?? db();
        try {
            $db->beginTransaction();

            $stock = self::getById($stockId);
            $oldQuantity = $stock['quantity'];
            $difference = $newQuantity - $oldQuantity;

            
            $stmt = $db->prepare("UPDATE stock SET quantity = ?, pallet = ? WHERE id = ?");
            $uomPerPallet = $stock['uom_per_pallet'] ?? 4;
            $newPallet = ceil($newQuantity / $uomPerPallet);
            $stmt->execute([$newQuantity, $newPallet, $stockId]);

            
            $stmt = $db->prepare("SELECT COALESCE(SUM(quantity), 0) as balance FROM stock WHERE product_id = ? AND stock_status = 'Available'");
            $stmt->execute([$stock['product_id']]);
            $balance = $stmt->fetch()['balance'];

            $type = $difference > 0 ? 'IN' : 'OUT';
            $refNo = 'ADJ-' . date('Ymd') . gmdate('His');
            $stmt = $db->prepare("INSERT INTO stock_ledger (transaction_date, product_id, batch_number, transaction_type, quantity_in, quantity_out, uom, pallet, reference_number, reference_type, balance, location, notes) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, 'Adjustment', ?, ?, ?)");
            $stmt->execute([
                $stock['product_id'],
                $stock['batch_number'],
                $type,
                $type === 'IN' ? $difference : 0,
                $type === 'OUT' ? abs($difference) : 0,
                $stock['uom_type'] ?? $stock['uom'],
                $difference / $uomPerPallet,
                $refNo,
                $balance + ($type === 'IN' ? $difference : 0),
                $stock['location'],
                $reason
            ]);

            $db->commit();
            return true;
        } catch (Exception $e) {
            if (isset($db) && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /* S19 — Stock Hold / Quarantine                                       */
    /* ------------------------------------------------------------------ */

    private const HOLD_STATUSES = ['on_hold', 'quarantine', 'damaged'];

    /** Query filter snippet to EXCLUDE held stock from picking/allocation. */
    public static function availableHoldClause(): string {
        return "(hold_status = 'available' OR hold_status IS NULL)";
    }

    public static function hold(int $stockId, string $status, ?string $reason = null, ?int $userId = null, $db = null): bool {
        $status = strtolower(trim($status));
        if (!in_array($status, self::HOLD_STATUSES)) {
            throw new Exception("Status hold tidak valid. Gunakan: " . implode(', ', self::HOLD_STATUSES));
        }
        if ($status !== 'damaged' && empty(trim((string)$reason))) {
            throw new Exception("Alasan (reason) wajib diisi untuk status '{$status}'.");
        }
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);

        $db = $db ?? db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $stock = self::getById($stockId);
            if (!$stock) throw new Exception("Stock tidak ditemukan");
            if (($stock['hold_status'] ?? 'available') === $status) {
                throw new Exception("Stock sudah berstatus {$status}.");
            }

            $db->prepare("UPDATE stock
                    SET hold_status = ?, hold_reason = ?, hold_by = ?, hold_at = NOW(), updated_at = NOW()
                    WHERE id = ?")
               ->execute([$status, $reason, $userId, $stockId]);

            self::_addHoldLedger($stock, 'HOLD', $status, $reason);

            if ($ownTx) $db->commit();
            return true;
        } catch (Exception $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function release(int $stockId, ?string $reason = null, ?int $userId = null, $db = null): bool {
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);

        $db = $db ?? db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $stock = self::getById($stockId);
            if (!$stock) throw new Exception("Stock tidak ditemukan");
            if (($stock['hold_status'] ?? 'available') === 'available') {
                throw new Exception("Stock sudah berstatus available.");
            }

            $db->prepare("UPDATE stock
                    SET hold_status = 'available', hold_reason = NULL, hold_by = NULL, hold_at = NULL, updated_at = NOW()
                    WHERE id = ?")
               ->execute([$stockId]);

            self::_addHoldLedger($stock, 'RELEASE', 'available', $reason);

            if ($ownTx) $db->commit();
            return true;
        } catch (Exception $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function _addHoldLedger(array $stock, string $txType, string $newStatus, ?string $reason, $db = null): void {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
                FROM stock_ledger
                WHERE product_id = ?
                  AND (location IS NULL OR location != 'QUA_SHELL')
                  AND transaction_type NOT IN ('TRANSFER_IN','TRANSFER_OUT')");
        $stmt->execute([$stock['product_id']]);
        $balance = floatval($stmt->fetch()['running_balance'] ?? 0);

        $db->prepare("INSERT INTO stock_ledger
                (transaction_date, product_id, transaction_type, reference_type,
                 reference_id, batch_number, quantity_in, quantity_out, uom, balance, location, notes)
                VALUES (CURDATE(), ?, ?, 'Stock', ?, ?, 0, 0, ?, ?, ?, ?)")
           ->execute([
               $stock['product_id'],
               $txType,
               $stock['id'],
               $stock['batch_number'] ?? null,
                $stock['uom_type'] ?? $stock['uom'] ?? UOM_DEFAULT_TYPE,
               $balance,
               $stock['location'],
               $txType === 'HOLD'
                   ? "Stock di-hold: {$newStatus}" . ($reason ? " — {$reason}" : '')
                   : "Stock di-release" . ($reason ? " — {$reason}" : '')
           ]);
    }

    /* ------------------------------------------------------------------ */
    /* S21 — Barcode Scanning (stock::scan / stock::scan_override)         */
    /* ------------------------------------------------------------------ */

    /**
     * Single-query product lookup + FEFO-first expected location.
     * Returns array with expected locations, or null when product unknown.
     */
    public static function scan(string $productCode, $db = null): ?array {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
                FROM products p
                WHERE p.product_code = ? AND p.is_active = 1
                LIMIT 1");
        $stmt->execute([trim($productCode)]);
        $product = $stmt->fetch();
        if (!$product) return null;

        $locStmt = $db->prepare("SELECT s.location, s.batch_number, s.expiry_date,
                        COALESCE(SUM(s.quantity),0) AS qty
                FROM stock s
                WHERE s.product_id = ?
                  AND s.quantity > 0
                  AND (s.stock_status = 'Available' OR s.stock_status IS NULL OR s.stock_status = '')
                  AND " . self::availableHoldClause() . "
                  AND s.location NOT IN ('QUA_SHELL','STAGING','UNALLOCATED')
                GROUP BY s.location, s.batch_number, s.expiry_date
                ORDER BY CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END ASC, s.expiry_date ASC, s.location ASC
                LIMIT 5");
        $locStmt->execute([$product['id']]);
        $locations = $locStmt->fetchAll();

        return [
            'product'   => $product,
            'locations' => $locations,
            'expected_location' => $locations[0]['location'] ?? null,
        ];
    }

    /**
     * Record a scan mismatch override with reason -> activity_log SCAN_OVERRIDE.
     */
    public static function scanOverride(int $productId, string $scannedLocation, string $expectedLocation, string $reason, ?int $userId = null, $db = null): void {
        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $db = $db ?? db();
        $db->prepare("INSERT INTO activity_log
                (user_id, username, full_name, action, module, reference_type, reference_id,
                 description, old_value, new_value, scan_override_reason, ip_address)
                VALUES (?, ?, ?, 'SCAN_OVERRIDE', 'stock', 'Stock', ?, ?, ?, ?, ?, ?)")
           ->execute([
               $userId,
               $_SESSION['username'] ?? null,
               $_SESSION['full_name'] ?? null,
               $productId,
               "Scan override: {$scannedLocation} ≠ {$expectedLocation}",
               $expectedLocation,
               $scannedLocation,
               $reason,
               $_SERVER['REMOTE_ADDR'] ?? null
           ]);
    }
}
?>
