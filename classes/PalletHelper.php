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

    /**
     * Derive units-per-pallet (UPP) from a product's pack size embedded in the
     * name/description, mirroring the live WMS putaway data (S35).
     * 16 pack-size rules:
     *   "12*0.8L" → 48, "12*1L" → 44, "4*4L" / "3*5L" → 36,
     *   "24*0.12L" → 80, "1*209L" → 4, "1*20L" → 24, "1*770kg" → 1, ...
     * Returns null when the pack cannot be resolved.
     */
    public static function deriveUppFromPackSize(?string $text): ?int {
        if ($text === null || $text === '') {
            return null;
        }
        if (!preg_match('/(\d+)\s*\*\s*(\d+(?:\.\d+)?)\s*(l|kg)/i', $text, $m)) {
            return null;
        }
        $qty  = (int)$m[1];
        $size = (float)$m[2];
        $unit = strtolower($m[3]);

        if ($unit === 'kg') {
            if ($size == 770)  return 1;   // fluid bag
            if ($size >= 100)  return 4;   // 180kg drum
            if ($size == 18 || $size == 15) return 24; // pail
            return null;
        }
        if ($qty === 1) {
            if ($size >= 1000) return 1;   // IBC tank
            if ($size >= 200)  return 4;   // 200/209L drum
            if ($size == 20)   return 24;  // 20L pail
            return null;
        }
        if ($qty === 12) {
            if ($size == 1) return 44;                    // 12*1L carton
            if (in_array($size, [0.8, 0.7, 0.65], true)) return 48; // 12*0.8L carton
            return null;
        }
        if ($qty === 4 && $size == 4)      return 36;   // 4*4L carton
        if ($qty === 3 && $size == 5)      return 36;   // 3*5L carton
        if ($qty === 24 && $size == 0.12)  return 80;   // 24*0.12L carton
        if ($qty === 24 && $size == 1)     return 16;   // 24*1L carton
        if ($qty === 10 && $size == 0.25)  return 100;  // 10*0.25L carton
        return null;
    }

    /* ------------------------------------------------------------------ */
    /* v2 parity helpers — mirrors pallet.ts                               */
    /* ------------------------------------------------------------------ */

    /** UOM pallet options (maps to v2 getUomOptions). */
    public static function getUomOptions(string $uomType): array {
        $options = [
            'Drum'   => [4],
            'Carton' => [36, 44, 48],
            'Pail'   => [24],
            'EA'     => [4],
            'Bags'   => [1],
        ];
        return $options[$uomType] ?? [4];
    }

    /** Full pallets + remainder distribution (maps to v2 calculatePalletDistribution). */
    public static function calculatePalletDistribution(int $totalQty, int $uomPerPallet): array {
        $upp = max(1, (int)$uomPerPallet);
        $fullPallets = (int)floor($totalQty / $upp);
        $remainder = $totalQty % $upp;
        $distribution = [];
        $palletNumber = 1;
        for ($i = 0; $i < $fullPallets; $i++) {
            $distribution[] = [
                'pallet_number' => $palletNumber++,
                'quantity'      => $upp,
                'is_full'       => true,
            ];
        }
        if ($remainder > 0) {
            $distribution[] = [
                'pallet_number' => $palletNumber,
                'quantity'      => $remainder,
                'is_full'       => false,
            ];
        }
        return $distribution;
    }

    /** Level = 5th char of location code (maps to v2 levelOf). */
    public static function levelOf(?string $locationCode): string {
        if (!$locationCode) return 'B';
        return strtoupper($locationCode[4] ?? 'B');
    }

    /** PICK_FACE at pick-face level A, otherwise RESERVE (maps to v2 palletFunctionFor). */
    public static function palletFunctionFor(?string $locationCode): string {
        return self::levelOf($locationCode) === 'A' ? 'PICK_FACE' : 'RESERVE';
    }

    /** is_full_pallet flag: 1 when qty reaches UPP (maps to v2 isFullPallet). */
    public static function isFullPallet($qty, ?int $uomPerPallet): int {
        $upp = (int)($uomPerPallet ?? 4);
        if (!($upp > 0)) return 1;
        return (int)$qty >= ($upp - 0.001) ? 1 : 0;
    }

    /** Level-aware pallet count (maps to v2 calcPalletByLocation). */
    public static function calcPalletByLocation($qty, int $upp, ?string $loc): float {
        if ($upp <= 0) return 0;
        $level = self::levelOf($loc);
        if ($level === 'A') {
            return round((float)$qty / $upp, 2);
        }
        return ceil((float)$qty / $upp);
    }

    /** v2 validateQuantity: pure-function signature (no DB). */
    public static function validateQuantityV2(
        int $qty,
        array $product,
        int $currentStock = 0
    ): array {
        $maxSku   = $product['max_sku_qty']   ?? 44;
        $maxTrans = $product['max_trans_qty'] ?? 80;
        if ($qty > $maxTrans) {
            return ['valid' => false, 'message' => "Quantity cannot exceed {$maxTrans} per transaction"];
        }
        if ($qty > $maxSku) {
            return ['valid' => false, 'message' => "Quantity cannot exceed {$maxSku} per SKU"];
        }
        if ($currentStock + $qty > $maxSku) {
            return [
                'valid'   => false,
                'message' => "Total stock would exceed maximum SKU limit ({$maxSku}). Current: {$currentStock}, Adding: {$qty}, Max allowed: {$maxSku}",
            ];
        }
        return ['valid' => true];
    }
}
?>
