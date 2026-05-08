<?php

class Stock {
    public static function getAll($status = null, $expiring = false) {
        $db = db();
        $sql = "SELECT s.*, p.product_code, p.product_name, p.category, p.uom_type, p.uom_per_pallet
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.quantity > 0";

        if ($status) {
            $sql .= " AND s.stock_status = ?";
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

    public static function getById($id) {
        $db = db();
        $stmt = $db->prepare("SELECT s.*, p.product_code, p.product_name, p.category, p.uom_type, p.uom_per_pallet
                FROM stock s
                JOIN products p ON s.product_id = p.id
                WHERE s.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function getByProduct($productId) {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM stock WHERE product_id = ? AND quantity > 0 ORDER BY expiry_date ASC");
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    public static function getSummary() {
        $db = db();
        $summary = $db->query("SELECT
                COUNT(DISTINCT product_id) as total_products,
                SUM(quantity) as total_drums,
                SUM(pallet) as total_pallets,
                COUNT(CASE WHEN stock_status = 'Available' THEN 1 END) as available_items,
                COUNT(CASE WHEN stock_status = 'Reserved' THEN 1 END) as reserved_items,
                COUNT(CASE WHEN stock_status = 'Expired' THEN 1 END) as expired_items,
                COUNT(CASE WHEN stock_status = 'Dues In' THEN 1 END) as dues_in_items
                FROM stock WHERE quantity > 0")->fetch();

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

    public static function getExpiringSoon($days = 30) {
        $db = db();
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

    public static function getMovement($productId = null, $startDate = null, $endDate = null, $limit = 100) {
        $db = db();
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

    public static function getStockByLocation() {
        $db = db();
        return $db->query("SELECT SUBSTRING_INDEX(location, '-', 1) as area,
                COUNT(DISTINCT product_id) as products,
                SUM(quantity) as total_qty,
                SUM(pallet) as total_pallet
                FROM stock WHERE quantity > 0 AND location IS NOT NULL
                GROUP BY SUBSTRING_INDEX(location, '-', 1)
                ORDER BY area")->fetchAll();
    }

    public static function transfer($stockId, $newLocation, $quantity = null) {
        $db = db();
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

    public static function adjust($stockId, $newQuantity, $reason) {
        $db = db();
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
            $stmt = $db->prepare("INSERT INTO stock_ledger (transaction_date, product_id, batch_number, transaction_type, quantity_in, quantity_out, uom, pallet, reference_number, reference_type, balance, location, notes) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, 'ADJ-' . date('YmdHis'), 'Adjustment', ?, ?, ?)");
            $stmt->execute([
                $stock['product_id'],
                $stock['batch_number'],
                $type === 'IN' ? $difference : 0,
                $type === 'OUT' ? abs($difference) : 0,
                $stock['uom_type'] ?? $stock['uom'],
                $difference / $uomPerPallet,
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
}
?>
