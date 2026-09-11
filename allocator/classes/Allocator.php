<?php
/**
 * Allocation Engine for Warehouse Picking
 * 
 * Logic:
 * 1. Order quantity is split into full pallets (from bulk) + remainder (from pickface)
 * 2. Full pallets are picked from B/C/D/E level bins (FEFO order)
 * 3. Remainder is picked from A level (pickface) bins
 * 4. If pickface insufficient, trigger replenishment from bulk to pickface
 * 5. Replenishment only triggers when there's an order
 */

require_once __DIR__ . '/../../config/database.php';

class Allocator
{
    private $db;
    private $products = [];      // material => ['upp', 'uom_type']
    private $stock = [];         // location => ['item_code', 'quantity', 'batch', 'expiry']
    private $bulkBins = [];      // item_code => [locations sorted by FEFO]
    private $pickfaceBins = [];  // item_code => [locations sorted by FEFO]
    
    // Replenishment minimums per UOM type
    private const REPLENISH_MIN = [
        'Drum' => 1,
        'Carton' => 10,
        'CAR' => 10,      // Alias for Carton
        'Pail' => 10,
        'Fluidbag' => 0,  // No replenishment
        'IBC' => 0,       // No replenishment
    ];

    // Expected UPP values per UOM type (sanity check)
    private const EXPECTED_UPP = [
        'Drum'     => [4],
        'Carton'   => [36, 44, 48],
        'CAR'      => [36, 44, 48],  // Alias for Carton
        'Pail'     => [24],
        'Fluidbag' => [1],
        'IBC'      => [1],
        'EA'       => [4],
        'Bags'     => [1],
    ];

    // Default UPP per UOM type when value is invalid
    private const DEFAULT_UPP = [
        'Drum'     => 4,
        'Carton'   => 44,
        'CAR'      => 44,  // Alias for Carton
        'Pail'     => 24,
        'Fluidbag' => 1,
        'IBC'      => 1,
        'EA'       => 4,
        'Bags'     => 1,
    ];

    public function __construct()
    {
        $this->db = db();
    }

    /**
     * Load product data (UPP and UOM type)
     * Validates UPP against UOM type to prevent mismatches from Excel source data.
     */
    public function loadProducts(array $masterSku): void
    {
        foreach ($masterSku as $material => $data) {
            $upp = (int)($data['upp'] ?? 0);
            $uomType = $data['uom_type'] ?? 'Drum';

            // Normalize UOM type alias
            if ($uomType === 'CAR') {
                $uomType = 'Carton';
            }

            // Validate UPP against UOM type
            $validUpps = self::EXPECTED_UPP[$uomType] ?? [];
            if ($upp <= 0 || !in_array($upp, $validUpps, true)) {
                $upp = self::DEFAULT_UPP[$uomType] ?? 4;
            }

            $this->products[$material] = [
                'upp' => $upp,
                'uom_type' => $uomType,
            ];
        }
    }

    /**
     * Load current stock from putaway data
     * When WMS already loaded, putaway only fills in batch/expiry details
     */
    public function loadStock(array $putawayStock): void
    {
        foreach ($putawayStock as $item) {
            $loc = $item['location'];
            if (isset($this->stock[$loc])) {
                // WMS already loaded — only add batch/expiry from putaway
                $this->stock[$loc]['batch_number'] = $item['batch_number'] ?? $this->stock[$loc]['batch_number'] ?? null;
                $this->stock[$loc]['expiry_date'] = $item['expiry_date'] ?? $this->stock[$loc]['expiry_date'] ?? null;
            } else {
                // Putaway-only location (not in WMS)
                $this->stock[$loc] = [
                    'item_code' => $item['item_code'],
                    'quantity' => $item['quantity'],
                    'batch_number' => $item['batch_number'],
                    'expiry_date' => $item['expiry_date'],
                ];

                // Categorize by level
                $level = $loc[4]; // 5th character
                if ($level === 'A') {
                    $this->pickfaceBins[$item['item_code']][] = $loc;
                } else {
                    $this->bulkBins[$item['item_code']][] = $loc;
                }
            }
        }
    }

    /**
     * Load WMS locations for additional stock info
     */
    public function loadWmsLocations(array $wmsLocations): void
    {
        foreach ($wmsLocations as $loc) {
            if ($loc['on_hand'] <= 0) continue;
            
            $location = $loc['location'];
            if (!isset($this->stock[$location])) {
                $this->stock[$location] = [
                    'item_code' => $loc['item_code'],
                    'quantity' => $loc['on_hand'],
                    'batch_number' => $loc['batch_number'] ?? null,
                    'expiry_date' => $loc['expiry_date'] ?? null,
                ];

                if ($loc['is_pickface']) {
                    $this->pickfaceBins[$loc['item_code']][] = $location;
                } else {
                    $this->bulkBins[$loc['item_code']][] = $location;
                }
            } else {
                $this->stock[$location]['quantity'] = $loc['on_hand'];
            }
        }
    }

    /**
     * Allocate order lines
     * Returns: ['picks' => [...], 'replenishments' => [...], 'errors' => [...]]
     */
    public function allocate(array $orderLines): array
    {
        $result = [
            'picks' => [],
            'replenishments' => [],
            'errors' => [],
            'summary' => [
                'total_orders' => 0,
                'total_deliveries' => 0,
                'total_items' => 0,
                'full_pallet_picks' => 0,
                'pickface_picks' => 0,
                'replenishments' => 0,
            ],
        ];

        // Count unique shipments (the real "orders" from warehouse perspective)
        $shipments = [];
        $deliveries = [];
        foreach ($orderLines as $line) {
            $shipmentNo = $line['shipment_no'] ?? '';
            $orderNo = $line['order_no'] ?? '';
            if ($shipmentNo !== '') $shipments[$shipmentNo] = true;
            if ($orderNo !== '') $deliveries[$orderNo] = true;
        }
        $result['summary']['total_orders'] = count($shipments);
        $result['summary']['total_deliveries'] = count($deliveries);

        // Group by order (for allocation logic — each delivery doc is a picking unit)
        $orders = [];
        $orderMeta = []; // order_no → no, destination, ship_to_location
        foreach ($orderLines as $line) {
            $orders[$line['order_no']][] = $line;
            if (!isset($orderMeta[$line['order_no']])) {
                $orderMeta[$line['order_no']] = [
                    'no' => $line['no'] ?? '',
                    'destination' => $line['destination'] ?? '',
                    'ship_to_location' => $line['ship_to_location'] ?? '',
                ];
            }
        }

        foreach ($orders as $orderNo => $lines) {
            // Track replenishment destinations for this order's items
            $replenishDest = []; // item_code => to_location

            foreach ($lines as $line) {
                $material = $line['material'];
                $qty = $line['quantity'];
                $result['summary']['total_items']++;

                // Get product info
                $product = $this->products[$material] ?? null;
                if (!$product) {
                    $result['errors'][] = "Order $orderNo: Material $material tidak ditemukan di Master SKU";
                    continue;
                }

                $upp = $product['upp'];
                $uomType = $product['uom_type'];

                // Skip if UPP = 1 (Fluidbag/IBC) - no pallet splitting needed
                if ($upp <= 1) {
                    // Pick all from pickface (or bulk if no pickface)
                    $picks = $this->pickFromStock($material, $qty, $orderNo);
                    $result['picks'] = array_merge($result['picks'], $picks);
                    $result['summary']['pickface_picks'] += count($picks);
                    continue;
                }

                // Calculate full pallets and remainder
                $fullPallets = intdiv($qty, $upp);
                $remainder = $qty % $upp;

                // Step 1: Pick full pallets from bulk (FEFO)
                if ($fullPallets > 0) {
                    $bulkPicks = $this->pickFullPallets($material, $fullPallets, $upp, $orderNo);
                    $result['picks'] = array_merge($result['picks'], $bulkPicks);
                    $result['summary']['full_pallet_picks'] += count($bulkPicks);
                }

                // Step 2: Check if replenishment needed for remainder
                if ($remainder > 0) {
                    $pickfaceStock = $this->getPickfaceStock($material);

                    if ($pickfaceStock < $remainder) {
                        $replenishment = $this->triggerReplenishment($material, $upp, $uomType);
                        if ($replenishment) {
                            $result['replenishments'][] = $replenishment;
                            $result['summary']['replenishments']++;
                            $replenishDest[$material] = $replenishment['to_location'];
                        }
                    }

                    // Step 3: Pick remainder from pickface
                    $pickfacePicks = $this->pickFromPickface($material, $remainder, $orderNo);
                    $result['picks'] = array_merge($result['picks'], $pickfacePicks);
                    $result['summary']['pickface_picks'] += count($pickfacePicks);

                    $totalPicked = array_sum(array_column($pickfacePicks, 'quantity'));

                    // Step 4: If pickface short, try picking remaining from bulk bins directly
                    if ($totalPicked < $remainder) {
                        $stillNeeded = $remainder - $totalPicked;
                        $bulkPicks = $this->pickFromBulkRemainder($material, $stillNeeded, $orderNo);
                        $result['picks'] = array_merge($result['picks'], $bulkPicks);
                        $result['summary']['full_pallet_picks'] += count($bulkPicks);
                        $totalPicked += array_sum(array_column($bulkPicks, 'quantity'));
                    }

                    if ($totalPicked < $remainder) {
                        $result['errors'][] = "Order {$orderNo}: {$material} shortfall — needed {$remainder}, only {$totalPicked} picked (stock insufficient).";
                    }
                }
            }
        }

        // Enrich picks with order metadata
        foreach ($result['picks'] as &$pick) {
            $meta = $orderMeta[$pick['order_no']] ?? [];
            $pick['no'] = $meta['no'] ?? '';
            $pick['destination'] = $meta['destination'] ?? '';
            $pick['ship_to_location'] = $meta['ship_to_location'] ?? '';
        }
        unset($pick);

        // Enrich picks with bin-to-bin replenishment destination
        // Only applies to pickface picks when item had replenishment (remainder < full pallet)
        foreach ($result['picks'] as &$pick) {
            $itemCode = $pick['item_code'] ?? '';
            $pickLocation = $pick['location'] ?? '';
            $pick['bin_to_bin'] = '';
            // Only for pickface picks — full pallets come directly from bulk
            if (($pick['type'] ?? '') !== 'pickface') continue;
            // Find replenishment that fills THIS specific pickface location
            foreach ($result['replenishments'] as $rep) {
                if ($rep['item_code'] === $itemCode && $rep['to_location'] === $pickLocation) {
                    // Verify source is bulk (level B+), not another pickface (level A)
                    $sourceLevel = substr($rep['from_location'], -2, 1);
                    if ($sourceLevel !== 'A') {
                        $pick['bin_to_bin'] = $rep['from_location'] . ' → ' . $rep['to_location'];
                    }
                    break;
                }
            }
        }
        unset($pick);

        return $result;
    }

    /**
     * Pick full pallets from bulk bins (FEFO order)
     */
    private function pickFullPallets(string $itemCode, int $numPallets, int $upp, string $orderNo): array
    {
        $picks = [];
        $bulkBins = $this->bulkBins[$itemCode] ?? [];

        $picked = 0;
        $binIdx = 0;
        while ($picked < $numPallets && $binIdx < count($bulkBins)) {
            $bin = $bulkBins[$binIdx];
            $binIdx++;

            $stock = $this->stock[$bin] ?? null;
            if (!$stock || $stock['quantity'] < $upp) {
                continue; // Skip depleted/insufficient bin, don't count as picked
            }

            $picks[] = [
                'order_no' => $orderNo,
                'item_code' => $itemCode,
                'location' => $bin,
                'quantity' => $upp,
                'type' => 'full_pallet',
                'batch_number' => $stock['batch_number'],
                'expiry_date' => $stock['expiry_date'],
            ];

            // Update stock (bin becomes empty)
            $this->stock[$bin]['quantity'] -= $upp;
            $picked++;
        }

        return $picks;
    }

    /**
     * Pick from pickface bins (one bin at a time)
     */
    private function pickFromPickface(string $itemCode, int $qty, string $orderNo): array
    {
        $picks = [];
        $remaining = $qty;
        $pickfaceBins = $this->pickfaceBins[$itemCode] ?? [];

        foreach ($pickfaceBins as $bin) {
            if ($remaining <= 0) break;

            $stock = $this->stock[$bin] ?? null;
            if (!$stock || $stock['quantity'] <= 0) continue;

            $pickQty = min($remaining, $stock['quantity']);

            $picks[] = [
                'order_no' => $orderNo,
                'item_code' => $itemCode,
                'location' => $bin,
                'quantity' => $pickQty,
                'type' => 'pickface',
                'batch_number' => $stock['batch_number'],
                'expiry_date' => $stock['expiry_date'],
            ];

            // Update stock
            $this->stock[$bin]['quantity'] -= $pickQty;
            $remaining -= $pickQty;
        }

        return $picks;
    }

    /**
     * Pick remainder directly from bulk bins (fallback when pickface empty)
     */
    private function pickFromBulkRemainder(string $itemCode, int $qty, string $orderNo): array
    {
        $picks = [];
        $remaining = $qty;
        $bulkBins = $this->bulkBins[$itemCode] ?? [];

        foreach ($bulkBins as $bin) {
            if ($remaining <= 0) break;

            $stock = $this->stock[$bin] ?? null;
            if (!$stock || $stock['quantity'] <= 0) continue;

            $pickQty = min($remaining, $stock['quantity']);

            $picks[] = [
                'order_no' => $orderNo,
                'item_code' => $itemCode,
                'location' => $bin,
                'quantity' => $pickQty,
                'type' => 'bulk_remainder',
                'batch_number' => $stock['batch_number'],
                'expiry_date' => $stock['expiry_date'],
            ];

            $this->stock[$bin]['quantity'] -= $pickQty;
            $remaining -= $pickQty;
        }

        return $picks;
    }

    /**
     * Pick from any available stock (for Fluidbag/IBC with UPP=1)
     */
    private function pickFromStock(string $itemCode, int $qty, string $orderNo): array
    {
        $picks = [];
        $remaining = $qty;

        // Try pickface first, then bulk
        $allBins = array_merge(
            $this->pickfaceBins[$itemCode] ?? [],
            $this->bulkBins[$itemCode] ?? []
        );

        foreach ($allBins as $bin) {
            if ($remaining <= 0) break;

            $stock = $this->stock[$bin] ?? null;
            if (!$stock || $stock['quantity'] <= 0) continue;

            $pickQty = min($remaining, $stock['quantity']);

            $picks[] = [
                'order_no' => $orderNo,
                'item_code' => $itemCode,
                'location' => $bin,
                'quantity' => $pickQty,
                'type' => 'direct',
                'batch_number' => $stock['batch_number'],
                'expiry_date' => $stock['expiry_date'],
            ];

            // Update stock
            $this->stock[$bin]['quantity'] -= $pickQty;
            $remaining -= $pickQty;
        }

        return $picks;
    }

    /**
     * Trigger replenishment from bulk to pickface
     * Tries all bulk bins — doesn't give up on first failure
     */
    private function triggerReplenishment(string $itemCode, int $upp, string $uomType): ?array
    {
        $bulkBins = $this->bulkBins[$itemCode] ?? [];
        
        foreach ($bulkBins as $bin) {
            $stock = $this->stock[$bin] ?? null;
            if (!$stock || $stock['quantity'] < $upp) continue;

            // Find empty or partially-filled pickface bin
            $pickfaceBin = $this->findEmptyPickface($itemCode);
            if (!$pickfaceBin) {
                $pickfaceBin = $this->createPickfaceLocation($itemCode);
            }

            // If still no pickface space, try next bulk bin (different pickface might exist)
            if (!$pickfaceBin) continue;

            // Update stock records
            $this->stock[$bin]['quantity'] -= $upp;
            $this->stock[$pickfaceBin]['quantity'] = ($this->stock[$pickfaceBin]['quantity'] ?? 0) + $upp;

            return [
                'item_code' => $itemCode,
                'from_location' => $bin,
                'to_location' => $pickfaceBin,
                'quantity' => $upp,
                'uom_type' => $uomType,
                'type' => 'replenishment',
            ];
        }

        return null;
    }

    /**
     * Get total pickface stock for an item
     */
    private function getPickfaceStock(string $itemCode): int
    {
        $total = 0;
        $pickfaceBins = $this->pickfaceBins[$itemCode] ?? [];
        
        foreach ($pickfaceBins as $bin) {
            $stock = $this->stock[$bin] ?? null;
            if ($stock) {
                $total += $stock['quantity'];
            }
        }
        
        return $total;
    }

    /**
     * Find empty pickface bin for an item
     * First tries a truly empty bin, then one with room (qty < UPP)
     */
    private function findEmptyPickface(string $itemCode): ?string
    {
        $pickfaceBins = $this->pickfaceBins[$itemCode] ?? [];
        $upp = $this->products[$itemCode]['upp'] ?? 44;

        // First pass: truly empty bin (best case)
        foreach ($pickfaceBins as $bin) {
            $stock = $this->stock[$bin] ?? null;
            if (!$stock || $stock['quantity'] <= 0) {
                return $bin;
            }
        }

        // Second pass: bin with room for more stock
        foreach ($pickfaceBins as $bin) {
            $stock = $this->stock[$bin] ?? null;
            if ($stock && $stock['quantity'] > 0 && $stock['quantity'] < $upp) {
                return $bin;
            }
        }

        return null;
    }

    /**
     * Create new pickface location (A level)
     */
    private function createPickfaceLocation(string $itemCode): ?string
    {
        // Find an aisle with empty A-level bins
        $usedAisles = [];
        foreach (array_keys($this->stock) as $loc) {
            $aisle = substr($loc, 0, 2);
            $usedAisles[$aisle] = true;
        }

        // Try each aisle
        $aisles = ['CA', 'CB', 'CC', 'CD', 'CE', 'CF'];
        foreach ($aisles as $aisle) {
            // Find empty rack in this aisle
            for ($rack = 1; $rack <= 40; $rack++) {
                $rackStr = $aisle . str_pad($rack, 2, '0', STR_PAD_LEFT);
                $loc = $rackStr . 'A01';
                
                if (!isset($this->stock[$loc])) {
                    // Initialize empty bin
                    $this->stock[$loc] = [
                        'item_code' => $itemCode,
                        'quantity' => 0,
                        'batch_number' => null,
                        'expiry_date' => null,
                    ];
                    $this->pickfaceBins[$itemCode][] = $loc;
                    return $loc;
                }
            }
        }

        return null;
    }

    /**
     * Compare two bins by expiry date (FEFO)
     */
    private function compareFefo(string $a, string $b): int
    {
        $stockA = $this->stock[$a] ?? null;
        $stockB = $this->stock[$b] ?? null;

        $expiryA = $stockA['expiry_date'] ?? '9999-12-31';
        $expiryB = $stockB['expiry_date'] ?? '9999-12-31';

        return strcmp($expiryA, $expiryB);
    }

    /**
     * Get current stock levels
     */
    public function getStock(): array
    {
        return $this->stock;
    }

    /**
     * Get pickface bins
     */
    public function getPickfaceBins(): array
    {
        return $this->pickfaceBins;
    }

    /**
     * Get bulk bins
     */
    public function getBulkBins(): array
    {
        return $this->bulkBins;
    }
}
