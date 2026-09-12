<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../allocator/classes/Allocator.php';

/**
 * Tests bin-to-bin attribution correctness in the allocation engine.
 * 
 * Core rule: bin-to-bin should only appear on picks where a replenishment
 * was triggered for that specific line. If the pickface already had enough
 * surplus, no replenishment was triggered, so bin_to_bin stays empty.
 */
final class BinToBinAttributionTest extends TestCase
{
    private Allocator $allocator;

    protected function setUp(): void
    {
        $this->allocator = new Allocator();
    }

    /**
     * Test case from user: two lines, same item, UPP=4, each needs 2 units,
     * pickface starts at zero.
     * 
     * Expected: exactly 1 replenishment (4 units from bulk to pickface),
     * first pick gets bin-to-bin, second pick has no bin-to-bin,
     * total 4 units picked from pickface.
     */
    public function testTwoLinesSameItemPickfaceSurplusNoDuplicateReplenishment(): void
    {
        $itemCode = '550025043';

        // loadProducts expects: material => ['upp' => ..., 'uom_type' => ...]
        $this->allocator->loadProducts([
            $itemCode => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // Use loadStock for zero-qty pickface (loadWmsLocations skips on_hand <= 0)
        // loadStock categorizes by location[4]: 'A' = pickface, else = bulk
        $this->allocator->loadStock([
            [
                'location' => 'CA03A01',
                'item_code' => $itemCode,
                'quantity' => 0,
                'batch_number' => '12690046',
                'expiry_date' => '2030-08-08',
            ],
        ]);

        // Use loadWmsLocations for bulk stock (has on_hand > 0)
        // loadWmsLocations needs 'is_pickface' key and uses 'on_hand' not 'quantity'
        $this->allocator->loadWmsLocations([
            [
                'location' => 'CB08C01',
                'item_code' => $itemCode,
                'on_hand' => 100,
                'batch_number' => '12690046',
                'expiry_date' => '2030-08-08',
                'is_pickface' => false,
            ],
        ]);

        // allocate() expects 'material' and 'quantity' keys
        $lines = [
            ['order_no' => '1', 'material' => $itemCode, 'quantity' => 2],
            ['order_no' => '1', 'material' => $itemCode, 'quantity' => 2],
        ];

        $result = $this->allocator->allocate($lines);

        // Should trigger exactly 1 replenishment
        $this->assertCount(1, $result['replenishments'],
            'Should trigger exactly 1 replenishment, not ' . count($result['replenishments']));

        // Verify replenishment details
        $rep = $result['replenishments'][0];
        $this->assertEquals($itemCode, $rep['item_code']);
        $this->assertEquals('CB08C01', $rep['from_location']);
        $this->assertEquals('CA03A01', $rep['to_location']);
        $this->assertEquals(4, $rep['quantity']);

        // 2 pickface picks (one per order line), each with qty 2
        $pickfacePicks = array_filter($result['picks'], fn($p) => $p['type'] === 'pickface');
        $this->assertCount(2, $pickfacePicks,
            'Should have 2 pickface picks (one per order line), got ' . count($pickfacePicks));

        // All picks should be from CA03A01
        foreach ($pickfacePicks as $pick) {
            $this->assertEquals('CA03A01', $pick['location']);
        }

        // Total picked from pickface should be 4
        $totalPicked = array_sum(array_column($pickfacePicks, 'quantity'));
        $this->assertEquals(4, $totalPicked, 'Total picked from pickface should be 4');

        // Bin-to-bin attribution: exactly 1 pick should have bin-to-bin
        // (the first pick that triggered the replenishment)
        $picksWithBinToBin = array_filter($pickfacePicks, fn($p) => !empty($p['bin_to_bin']));
        $this->assertCount(1, $picksWithBinToBin,
            'Exactly 1 pick should have bin-to-bin, not ' . count($picksWithBinToBin));

        // Verify the bin-to-bin content
        $binToBinPick = reset($picksWithBinToBin);
        $this->assertEquals('CB08C01 → CA03A01', $binToBinPick['bin_to_bin']);

        // The other pick has empty bin-to-bin (satisfied from surplus)
        $picksWithoutBinToBin = array_filter($pickfacePicks, fn($p) => empty($p['bin_to_bin']));
        $this->assertCount(1, $picksWithoutBinToBin,
            '1 pick should have empty bin-to-bin');

        // Summary checks
        $this->assertEquals(1, $result['summary']['replenishments']);
        $this->assertEquals(2, $result['summary']['pickface_picks']);
    }

    /**
     * Test: pickface has some stock but not enough for remainder.
     * Replenishment should fill the gap, bin-to-bin only on picks
     * that needed the replenishment.
     */
    public function testPartialPickfaceStockReplenishmentCorrectAttribution(): void
    {
        $itemCode = '550030001';

        $this->allocator->loadProducts([
            $itemCode => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // Pickface has 1 unit (needs 2, so replenishment triggers)
        $this->allocator->loadStock([
            [
                'location' => 'CA03A01',
                'item_code' => $itemCode,
                'quantity' => 1,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
            ],
        ]);

        $this->allocator->loadWmsLocations([
            [
                'location' => 'CB08C01',
                'item_code' => $itemCode,
                'on_hand' => 50,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
                'is_pickface' => false,
            ],
        ]);

        $lines = [
            ['order_no' => '1', 'material' => $itemCode, 'quantity' => 2],
        ];

        $result = $this->allocator->allocate($lines);

        // Should trigger 1 replenishment (pickface had 1 < 2)
        $this->assertCount(1, $result['replenishments']);

        // 1 pickface pick with qty 2
        $pickfacePicks = array_filter($result['picks'], fn($p) => $p['type'] === 'pickface');
        $this->assertCount(1, $pickfacePicks);

        $totalPicked = array_sum(array_column($pickfacePicks, 'quantity'));
        $this->assertEquals(2, $totalPicked);

        // Both picks are from the same pickface location
        foreach ($pickfacePicks as $pick) {
            $this->assertEquals('CA03A01', $pick['location']);
        }
    }

    /**
     * Test: pickface already has enough stock. No replenishment should trigger.
     * All picks should have empty bin-to-bin.
     */
    public function testPickfaceHasEnoughStockNoReplenishment(): void
    {
        $itemCode = '550030002';

        $this->allocator->loadProducts([
            $itemCode => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // Pickface has 4 units, line needs 2 -> no replenishment
        $this->allocator->loadStock([
            [
                'location' => 'CA03A01',
                'item_code' => $itemCode,
                'quantity' => 4,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
            ],
        ]);

        $this->allocator->loadWmsLocations([
            [
                'location' => 'CB08C01',
                'item_code' => $itemCode,
                'on_hand' => 50,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
                'is_pickface' => false,
            ],
        ]);

        $lines = [
            ['order_no' => '1', 'material' => $itemCode, 'quantity' => 2],
        ];

        $result = $this->allocator->allocate($lines);

        // No replenishment should trigger
        $this->assertCount(0, $result['replenishments'],
            'No replenishment should trigger when pickface has enough stock');

        // Picks from pickface
        $pickfacePicks = array_filter($result['picks'], fn($p) => $p['type'] === 'pickface');
        $this->assertCount(1, $pickfacePicks);

        // All picks should have empty bin-to-bin
        foreach ($pickfacePicks as $pick) {
            $this->assertEmpty($pick['bin_to_bin'],
                'Pick should have empty bin-to-bin when no replenishment was needed');
        }
    }

    /**
     * Test: two lines, first line fully consumes pickface, triggers replenishment.
     * Second line sees replenished stock and picks from it without triggering
     * another replenishment. Only first line's picks get bin-to-bin.
     */
    public function testFirstLineConsumesPickfaceSecondLineUsesSurplus(): void
    {
        $itemCode = '550030003';

        $this->allocator->loadProducts([
            $itemCode => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // Pickface starts empty
        $this->allocator->loadStock([
            [
                'location' => 'CA03A01',
                'item_code' => $itemCode,
                'quantity' => 0,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
            ],
        ]);

        $this->allocator->loadWmsLocations([
            [
                'location' => 'CB08C01',
                'item_code' => $itemCode,
                'on_hand' => 100,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
                'is_pickface' => false,
            ],
        ]);

        // Line 1: qty=4, fullPallets=1, remainder=0 → picks full pallet from bulk
        // Line 2: qty=2, fullPallets=0, remainder=2 → pickface has 0 < 2 → replenish
        $lines = [
            ['order_no' => '1', 'material' => $itemCode, 'quantity' => 4],
            ['order_no' => '1', 'material' => $itemCode, 'quantity' => 2],
        ];

        $result = $this->allocator->allocate($lines);

        // Only 1 replenishment (for line 2)
        $this->assertCount(1, $result['replenishments']);

        // Line 2's pick should have bin-to-bin
        $pickfacePicks = array_filter($result['picks'], fn($p) => $p['type'] === 'pickface');
        $picksWithBinToBin = array_filter($pickfacePicks, fn($p) => !empty($p['bin_to_bin']));
        $this->assertCount(1, $picksWithBinToBin,
            'Line 2 pick should have bin-to-bin, got ' . count($picksWithBinToBin));
    }

    /**
     * Test: multiple items, ensure bin-to-bin attribution is per-item, not global.
     */
    public function testMultipleItemsBinToBinIsolation(): void
    {
        $item1 = 'ITEM001';
        $item2 = 'ITEM002';

        $this->allocator->loadProducts([
            $item1 => ['upp' => 4, 'uom_type' => 'Drum'],
            $item2 => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // Item 1 pickface (empty)
        $this->allocator->loadStock([
            [
                'location' => 'CA03A01',
                'item_code' => $item1,
                'quantity' => 0,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
            ],
        ]);

        // Item 1 bulk + Item 2 pickface (has stock) + Item 2 bulk
        $this->allocator->loadWmsLocations([
            [
                'location' => 'CB08C01',
                'item_code' => $item1,
                'on_hand' => 50,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
                'is_pickface' => false,
            ],
            [
                'location' => 'CA04A01',
                'item_code' => $item2,
                'on_hand' => 10,
                'batch_number' => 'BATCH02',
                'expiry_date' => '2031-01-01',
                'is_pickface' => true,
            ],
            [
                'location' => 'CB09C01',
                'item_code' => $item2,
                'on_hand' => 50,
                'batch_number' => 'BATCH02',
                'expiry_date' => '2031-01-01',
                'is_pickface' => false,
            ],
        ]);

        $lines = [
            ['order_no' => '1', 'material' => $item1, 'quantity' => 2],
            ['order_no' => '1', 'material' => $item2, 'quantity' => 2],
        ];

        $result = $this->allocator->allocate($lines);

        // Only item1 should have a replenishment (pickface empty)
        // Item2 has enough stock (10 >= 2)
        $this->assertCount(1, $result['replenishments']);
        $this->assertEquals($item1, $result['replenishments'][0]['item_code']);

        // Item1 picks should have bin-to-bin
        $item1Picks = array_filter($result['picks'], fn($p) => $p['item_code'] === $item1 && $p['type'] === 'pickface');
        $item1PicksWithBinToBin = array_filter($item1Picks, fn($p) => !empty($p['bin_to_bin']));
        $this->assertNotEmpty($item1PicksWithBinToBin,
            'Item1 picks should have bin-to-bin');

        // Item2 picks should NOT have bin-to-bin
        $item2Picks = array_filter($result['picks'], fn($p) => $p['item_code'] === $item2 && $p['type'] === 'pickface');
        foreach ($item2Picks as $pick) {
            $this->assertEmpty($pick['bin_to_bin'],
                'Item2 picks should have empty bin-to-bin (no replenishment needed)');
        }
    }

    /**
     * Five lines for same item, pickface starts empty.
     * Every 2 lines consume one replenishment (4 units), so lines 1+2 share
     * replenishment #1, lines 3+4 share #2, line 5 triggers #3.
     * Lines 2 and 4 use surplus — their picks get empty bin-to-bin.
     */
    public function testFiveLinesSurplusAttributionCorrect(): void
    {
        $itemCode = 'ITEM5LINE';

        $this->allocator->loadProducts([
            $itemCode => ['upp' => 4, 'uom_type' => 'Drum'],
        ]);

        // Empty pickface, plenty of bulk
        $this->allocator->loadStock([
            [
                'location' => 'CA03A01',
                'item_code' => $itemCode,
                'quantity' => 0,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
            ],
        ]);

        $this->allocator->loadWmsLocations([
            [
                'location' => 'CB08C01',
                'item_code' => $itemCode,
                'on_hand' => 200,
                'batch_number' => 'BATCH01',
                'expiry_date' => '2031-01-01',
                'is_pickface' => false,
            ],
        ]);

        // 5 lines, each needs 2 (remainder) -> UPP=4
        $lines = [];
        for ($i = 1; $i <= 5; $i++) {
            $lines[] = ['order_no' => '1', 'material' => $itemCode, 'quantity' => 2];
        }

        $result = $this->allocator->allocate($lines);

        // 5 pickface picks total (one per line)
        $pickfacePicks = array_filter($result['picks'], fn($p) => $p['type'] === 'pickface');
        $this->assertCount(5, $pickfacePicks,
            'Should have 5 pickface picks (one per line)');

        // 3 replenishments: lines 1+2 share #1, lines 3+4 share #2, line 5 triggers #3
        $this->assertCount(3, $result['replenishments'],
            'Should have 3 replenishments (pairs of 2 lines each consume 4 units)');

        // 3 picks have bin-to-bin (lines 1, 3, 5 triggered replenishment)
        $withBinToBin = array_filter($pickfacePicks, fn($p) => !empty($p['bin_to_bin']));
        $this->assertCount(3, $withBinToBin,
            'Lines 1, 3, 5 should have bin-to-bin (they triggered replenishment)');

        // 2 picks have empty bin-to-bin (lines 2, 4 used surplus)
        $withoutBinToBin = array_filter($pickfacePicks, fn($p) => empty($p['bin_to_bin']));
        $this->assertCount(2, $withoutBinToBin,
            'Lines 2, 4 should have empty bin-to-bin (used surplus)');

        // Total picked = 10 (5 lines x 2 units)
        $totalPicked = array_sum(array_column($pickfacePicks, 'quantity'));
        $this->assertEquals(10, $totalPicked);
    }
}
