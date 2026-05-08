<?php

class Product {
    public static function getAll($limit = null, $offset = 0) {
        $db = db();
        $sql = "SELECT p.*,
                COALESCE(SUM(s.quantity), 0) as total_drums,
                COALESCE(SUM(s.quantity), 0) as total_qty,
                COALESCE(SUM(CEILING(s.quantity / GREATEST(p.uom_per_pallet, 1))), 0) as total_pallets
                FROM products p
                LEFT JOIN stock s ON p.id = s.product_id
                    AND (s.stock_status IN ('Available','Dues In') OR s.stock_status IS NULL OR s.stock_status = '')
                    AND s.quantity > 0
                    AND (s.location IS NULL OR s.location NOT IN ('QUA_SHELL','STAGING'))
                GROUP BY p.id
                ORDER BY p.product_name";

        if ($limit) {
            $sql .= " LIMIT " . intval($limit) . " OFFSET " . intval($offset);
        }

        return $db->query($sql)->fetchAll();
    }

    public static function getCount(string $search = ''): int {
        $db = db();
        if ($search) {
            $term = "%$search%";
            $stmt = $db->prepare("SELECT COUNT(*) FROM products WHERE product_code LIKE ? OR product_name LIKE ? OR category LIKE ?");
            $stmt->execute([$term, $term, $term]);
        } else {
            $stmt = $db->query("SELECT COUNT(*) FROM products");
        }
        return (int)$stmt->fetchColumn();
    }

    public static function getStatsByUom(): array {
        $db   = db();
        $rows = $db->query("SELECT uom_type, COUNT(*) as cnt FROM products GROUP BY uom_type")->fetchAll();
        $result = [];
        foreach ($rows as $r) $result[$r['uom_type']] = (int)$r['cnt'];
        return $result;
    }

    public static function getPaginated(string $search, int $perPage, int $offset): array {
        $db     = db();
        $where  = '';
        $params = [];
        if ($search) {
            $term   = "%$search%";
            $where  = " WHERE p.product_code LIKE ? OR p.product_name LIKE ? OR p.category LIKE ?";
            $params = [$term, $term, $term];
        }
        $sql = "SELECT p.*,
                COALESCE(SUM(s.quantity), 0) as total_drums,
                COALESCE(SUM(s.quantity), 0) as total_qty,
                COALESCE(SUM(CEILING(s.quantity / GREATEST(p.uom_per_pallet, 1))), 0) as total_pallets
                FROM products p
                LEFT JOIN stock s ON p.id = s.product_id
                    AND (s.stock_status IN ('Available','Dues In') OR s.stock_status IS NULL OR s.stock_status = '')
                    AND s.quantity > 0
                    AND (s.location IS NULL OR s.location NOT IN ('QUA_SHELL','STAGING'))
                {$where}
                GROUP BY p.id
                ORDER BY p.product_name
                LIMIT " . intval($perPage) . " OFFSET " . intval($offset);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getById($id) {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public static function getByCode($code) {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM products WHERE product_code = ?");
        $stmt->execute([$code]);
        return $stmt->fetch();
    }

    public static function create($data) {
        $db = db();
        $stmt = $db->prepare("INSERT INTO products (product_code, product_name, category, description, drums_per_pallet, uom_type, uom_per_pallet, liters_per_unit, max_sku_qty, max_trans_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        return $stmt->execute([
            $data['product_code'],
            $data['product_name'],
            $data['category'] ?? null,
            $data['description'] ?? null,
            $data['drums_per_pallet'] ?? 4,
            $data['uom_type'] ?? 'Drum',
            $data['uom_per_pallet'] ?? 4,
            $data['liters_per_unit'] ?? 209.00,
            $data['max_sku_qty'] ?? 44,
            $data['max_trans_qty'] ?? 80
        ]);
    }

    public static function update($id, $data) {
        $db = db();
        $stmt = $db->prepare("UPDATE products SET product_code = ?, product_name = ?, category = ?, description = ?, drums_per_pallet = ?, uom_type = ?, uom_per_pallet = ?, liters_per_unit = ?, max_sku_qty = ?, max_trans_qty = ? WHERE id = ?");
        return $stmt->execute([
            $data['product_code'],
            $data['product_name'],
            $data['category'] ?? null,
            $data['description'] ?? null,
            $data['drums_per_pallet'] ?? 4,
            $data['uom_type'] ?? 'Drum',
            $data['uom_per_pallet'] ?? 4,
            $data['liters_per_unit'] ?? 209.00,
            $data['max_sku_qty'] ?? 44,
            $data['max_trans_qty'] ?? 80,
            $id
        ]);
    }

    public static function delete($id) {
        $db = db();
        $stmt = $db->prepare("DELETE FROM products WHERE id = ?");
        return $stmt->execute([$id]);
    }

    public static function search($keyword) {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM products WHERE product_code LIKE ? OR product_name LIKE ? OR category LIKE ?");
        $term = "%$keyword%";
        $stmt->execute([$term, $term, $term]);
        return $stmt->fetchAll();
    }

    

    public static function calculatePallet($productId, $quantity) {
        $product = self::getById($productId);
        if (!$product) return 0;

        $uomPerPallet = $product['uom_per_pallet'] ?? 4;
        return $quantity / $uomPerPallet;
    }

    

    public static function calculatePalletDistribution($productId, $totalQuantity) {
        $product = self::getById($productId);
        if (!$product) return [];

        $uomPerPallet = $product['uom_per_pallet'] ?? 4;
        $fullPallets = intdiv($totalQuantity, $uomPerPallet);
        $remainder = $totalQuantity % $uomPerPallet;

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

    

    public static function getUomOptions($uomType) {
        $options = [
            'Drum'   => [4],
            'Carton' => [36, 44, 48],
            'Pail'   => [24],
            'EA'     => [4],
            'Bags'   => [1]
        ];
        return $options[$uomType] ?? [4];
    }

    

    public static function validateQuantity($productId, $quantity) {
        $product = self::getById($productId);
        if (!$product) {
            return ['valid' => false, 'message' => 'Product not found'];
        }

        $maxSku = $product['max_sku_qty'] ?? 44;
        $maxTrans = $product['max_trans_qty'] ?? 80;

        if ($quantity > $maxTrans) {
            return [
                'valid' => false,
                'message' => "Quantity cannot exceed $maxTrans per transaction"
            ];
        }

        if ($quantity > $maxSku) {
            return [
                'valid' => false,
                'message' => "Quantity cannot exceed $maxSku per SKU"
            ];
        }

        return ['valid' => true];
    }

    

    public static function calculateExpiryDate($productionDate, $years = 4) {
        if (empty($productionDate)) return null;

        $date = new DateTime($productionDate);
        $date->add(new DateInterval("P{$years}Y"));
        return $date->format('Y-m-d');
    }
}
?>
