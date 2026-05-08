<?php

class PalletHelper {
    
    const UOM_PALLET = [
        'Drum' => 4,        
        'Carton' => [36, 44, 48],  
        'Pail' => 24        
    ];

    
    const DEFAULT_PALLET = [
        'Drum' => 4,
        'Carton' => 44,
        'Pail' => 24
    ];

    

    public static function calculatePallet($quantity, $uom = 'Drum', $customPalletQty = null) {
        $palletCapacity = $customPalletQty ?? self::DEFAULT_PALLET[$uom] ?? 4;

        $pallets = floor($quantity / $palletCapacity);
        $remainder = $quantity % $palletCapacity;

        return [
            'units' => $quantity,
            'pallets' => $pallets,
            'pallet_decimal' => round($quantity / $palletCapacity, 2),
            'remainder' => $remainder,
            'pallet_capacity' => $palletCapacity
        ];
    }

    

    public static function palletToUnits($pallets, $uom = 'Drum', $customPalletQty = null) {
        $palletCapacity = $customPalletQty ?? self::DEFAULT_PALLET[$uom] ?? 4;
        return $pallets * $palletCapacity;
    }

    

    public static function getPalletCapacity($uom) {
        return self::DEFAULT_PALLET[$uom] ?? 4;
    }

    

    public static function validateQuantity($productId, $quantity, $pdo) {
        
        $stmt = $pdo->prepare("SELECT max_sku_qty, max_trans_qty FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            return ['valid' => false, 'message' => 'Product not found'];
        }

        $maxSku = $product['max_sku_qty'] ?? 44;
        $maxTrans = $product['max_trans_qty'] ?? 80;

        
        if ($quantity > $maxTrans) {
            return [
                'valid' => false,
                'message' => "Quantity exceeds maximum transaction limit ({$maxTrans}). Maximum allowed: {$maxTrans} units"
            ];
        }

        
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(quantity), 0) as current_stock FROM stock WHERE product_id = ? AND stock_status = 'Available'");
        $stmt->execute([$productId]);
        $currentStock = $stmt->fetch()['current_stock'];

        if (($currentStock + $quantity) > $maxSku) {
            return [
                'valid' => false,
                'message' => "Total stock would exceed maximum SKU limit ({$maxSku}). Current: {$currentStock}, Adding: {$quantity}, Max allowed: {$maxSku}"
            ];
        }

        return ['valid' => true];
    }

    

    public static function calculateExpiryDate($productionDate, $years = 4) {
        $date = new DateTime($productionDate);
        $date->add(new DateInterval("P{$years}Y"));
        return $date->format('Y-m-d');
    }

    

    public static function getExpiryInfo($expiryDate) {
        if (!$expiryDate) {
            return ['days' => null, 'months' => null, 'text' => 'No expiry', 'is_critical' => false];
        }

        $today = new DateTime();
        $expiry = new DateTime($expiryDate);
        $interval = $today->diff($expiry);

        $days = $interval->days;
        $months = ($interval->y * 12) + $interval->m;

        
        $isCritical = $interval->invert ? true : ($days <= 120);

        if ($interval->invert) {
            $text = "Expired {$days} days ago";
        } else {
            $text = "{$months}m {$interval->d}d left";
        }

        return [
            'days' => $days,
            'months' => $months,
            'remaining_days' => $interval->invert ? -$days : $days,
            'text' => $text,
            'is_critical' => $isCritical,
            'is_expired' => $interval->invert
        ];
    }

    

    public static function generateLocations($totalPallets, $baseLocation = 'SUB50') {
        $locations = [];
        for ($i = 1; $i <= $totalPallets; $i++) {
            $locations[] = [
                'pallet_number' => $i,
                'location' => $baseLocation . '-P' . str_pad($i, 2, '0', STR_PAD_LEFT),
                'is_full' => true
            ];
        }
        return $locations;
    }

    

    public static function getProductUOMInfo($productId, $pdo) {
        $stmt = $pdo->prepare("SELECT uom_type, uom_per_pallet, liters_per_unit FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();

        if (!$product) {
            return [
                'uom_type' => 'Drum',
                'uom_per_pallet' => 4,
                'liters_per_unit' => 209
            ];
        }

        return [
            'uom_type' => $product['uom_type'] ?? 'Drum',
            'uom_per_pallet' => $product['uom_per_pallet'] ?? 4,
            'liters_per_unit' => $product['liters_per_unit'] ?? 209
        ];
    }

    

    public static function calculateLiters($quantity, $litersPerUnit = 209) {
        return $quantity * $litersPerUnit;
    }
}
?>
