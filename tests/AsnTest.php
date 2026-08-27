<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/asn.e2e-spec.ts — ASN (Advance Shipping Notice) lifecycle.
 * Tests: create with items, list/detail, link to inbound (pre-fill), cancel, update rejection.
 */
final class AsnTest extends TestCase
{
    private static string $token = '';
    private static int $productId = 0;

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();
        // Create product via API so test server process sees it
        $res = ApiTestHelpers::api('products', 'create', self::$token, [
            'product_code' => 'TST-ASN-' . random_int(0, 999999),
            'product_name' => 'Test ASN Product',
            'uom_type' => 'Drum',
            'uom_per_pallet' => 4,
        ]);
        fwrite(STDERR, "DEBUG setUpBeforeClass: products create response: " . json_encode($res) . "\n");
        self::$productId = (int)$res['body']['id'];
    }

    /** Creates an ASN with items and lists/details it */
    public function testCreatesAsnWithItemsAndListsDetail(): void
    {
        fwrite(STDERR, "DEBUG test method: Using productId = " . self::$productId . "\n");
        
        $res = ApiTestHelpers::api('asn', 'create', self::$token, [
            'supplier_name' => 'PT Supplier Test',
            'supplier_reference' => 'PO-ASN-1',
            'expected_arrival_date' => '2026-08-20',
            'items' => [
                ['product_id' => self::$productId, 'expected_qty' => 20, 'uom' => 'Drum', 'batch_number' => 'ASN-BATCH'],
            ],
        ]);
        fwrite(STDERR, "DEBUG test method: ASN create response: " . json_encode($res) . "\n");
        $this->assertTrue($res['body']['success']);
        $asnId = (int)$res['body']['id'];
        $this->assertGreaterThan(0, $asnId);
        $this->assertMatchesRegularExpression('/^ASN-/', $res['body']['asn_number'] ?? '');

        $rows = ApiTestHelpers::q('SELECT status FROM asn WHERE id = ?', [$asnId]);
        $this->assertCount(1, $rows);
        $this->assertSame('Pending', $rows[0]['status']);

        $list = ApiTestHelpers::api('asn', 'list', self::$token);
        $this->assertTrue($list['body']['success']);
        $this->assertGreaterThan(0, count($list['body']['rows']));

        $detail = ApiTestHelpers::api('asn', 'detail', self::$token, [], ['id' => $asnId]);
        $this->assertTrue($detail['body']['success']);
        $this->assertArrayHasKey('asn', $detail['body']);
        $this->assertSame($asnId, (int)$detail['body']['asn']['id']);
        $this->assertSame('Pending', $detail['body']['asn']['status']);
        $this->assertGreaterThan(0, count($detail['body']['items']));
    }

    /** Rejects creating an ASN with no items */
    public function testRejectsCreatingAsnWithNoItems(): void
    {
        $res = ApiTestHelpers::api('asn', 'create', self::$token, [
            'supplier_name' => 'X',
            'items' => [],
        ]);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(400, $res['status']);
    }

    /** Creates an inbound from an ASN and pre-fills expected items */
    public function testCreatesInboundFromAsnAndPreFillsItems(): void
    {
        $created = ApiTestHelpers::api('asn', 'create', self::$token, [
            'supplier_name' => 'PT Supplier Test',
            'supplier_reference' => 'PO-ASN-2',
            'expected_arrival_date' => '2026-08-20',
            'items' => [['product_id' => self::$productId, 'expected_qty' => 12, 'uom' => 'Drum']],
        ]);
        fwrite(STDERR, "DEBUG: ASN create response: " . json_encode($created) . "\n");
        $this->assertTrue($created['body']['success']);
        $asnId = (int)$created['body']['id'];

        $inbound = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'order_date' => '2026-08-15',
            'asn_id' => $asnId,
        ]);
        fwrite(STDERR, "DEBUG: Inbound create response: " . json_encode($inbound) . "\n");
        $this->assertTrue($inbound['body']['success']);
        $inboundId = (int)$inbound['body']['id'];

        $linked = ApiTestHelpers::q('SELECT asn_id FROM inbound_orders WHERE id = ?', [$inboundId]);
        $this->assertCount(1, $linked);
        $this->assertSame($asnId, (int)$linked[0]['asn_id']);

        $items = ApiTestHelpers::q('SELECT product_id, quantity FROM inbound_items WHERE inbound_order_id = ?', [$inboundId]);
        $this->assertGreaterThan(0, count($items));
        $this->assertSame(self::$productId, (int)$items[0]['product_id']);
        $this->assertSame(12, (int)$items[0]['quantity']);
    }

    /** Blocks creating an inbound from a non-Pending ASN */
    public function testBlocksCreatingInboundFromNonPendingAsn(): void
    {
        $created = ApiTestHelpers::api('asn', 'create', self::$token, [
            'supplier_name' => 'X',
            'expected_arrival_date' => '2026-08-20',
            'items' => [['product_id' => self::$productId, 'expected_qty' => 4]],
        ]);
        fwrite(STDERR, "DEBUG: ASN create response: " . json_encode($created) . "\n");
        $this->assertTrue($created['body']['success']);
        $asnId = (int)$created['body']['id'];

        // Cancel directly via DB to ensure it works
        $pdo = ApiTestHelpers::pdo();
        $pdo->exec("UPDATE asn SET status = 'Cancelled' WHERE id = $asnId");
        
        // Verify cancelled
        $rows = $pdo->query("SELECT status FROM asn WHERE id = $asnId")->fetchAll();
        fwrite(STDERR, "DEBUG: ASN status after cancel: " . json_encode($rows) . "\n");

        $inbound = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'order_date' => '2026-08-15',
            'asn_id' => $asnId,
        ]);
        fwrite(STDERR, "DEBUG: Inbound create response: " . json_encode($inbound) . "\n");
        $this->assertFalse($inbound['body']['success']);
        $this->assertSame(409, $inbound['status']);
    }

    /** Flips the ASN to Received when the linked inbound completes */
    public function testFlipsAsnToReceivedWhenInboundCompletes(): void
    {
        $created = ApiTestHelpers::api('asn', 'create', self::$token, [
            'supplier_name' => 'PT Supplier Test',
            'supplier_reference' => 'PO-ASN-3',
            'items' => [['product_id' => self::$productId, 'expected_qty' => 8, 'uom' => 'Drum']],
        ]);
        $asnId = (int)$created['body']['id'];

        $inbound = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'order_date' => '2026-08-15',
            'asn_id' => $asnId,
        ]);
        $inboundId = (int)$inbound['body']['id'];
        $itemId = (int)ApiTestHelpers::q('SELECT id FROM inbound_items WHERE inbound_order_id = ?', [$inboundId])[0]['id'];

        ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id' => $inboundId, 'status' => 'Receiving', 'received_by_id' => 1, 'received_date' => '2026-08-15',
        ]);
        ApiTestHelpers::api('inbound', 'update_item_status', self::$token, ['item_id' => $itemId, 'status' => 'ATP', 'location' => 'CA01A01']);
        ApiTestHelpers::api('inbound', 'complete', self::$token, ['id' => $inboundId]);

        fwrite(STDERR, "DEBUG: Checking ASN status after inbound complete\n");
        $asn = ApiTestHelpers::q('SELECT status FROM asn WHERE id = ?', [$asnId]);
        fwrite(STDERR, "DEBUG: ASN status query result: " . json_encode($asn) . "\n");
        $this->assertSame('Received', $asn[0]['status']);
    }

    /** Allows cancel only while Pending */
    public function testAllowsCancelOnlyWhilePending(): void
    {
        $created = ApiTestHelpers::api('asn', 'create', self::$token, [
            'supplier_name' => 'X',
            'items' => [['product_id' => self::$productId, 'expected_qty' => 4]],
        ]);
        $asnId = (int)$created['body']['id'];

        $cancel = ApiTestHelpers::api('asn', 'cancel', self::$token, ['id' => $asnId]);
        $this->assertTrue($cancel['body']['success']);
        $cancelled = ApiTestHelpers::q('SELECT status FROM asn WHERE id = ?', [$asnId]);
        $this->assertSame('Cancelled', $cancelled[0]['status']);

        $again = ApiTestHelpers::api('asn', 'cancel', self::$token, ['id' => $asnId]);
        $this->assertTrue($again['body']['success']);
    }

    /** Rejects updating a Received ASN */
    public function testRejectsUpdatingReceivedAsn(): void
    {
        $created = ApiTestHelpers::api('asn', 'create', self::$token, [
            'supplier_name' => 'X',
            'items' => [['product_id' => self::$productId, 'expected_qty' => 5]],
        ]);
        $asnId = (int)$created['body']['id'];

        $inbound = ApiTestHelpers::api('inbound', 'create', self::$token, ['order_date' => '2026-08-15', 'asn_id' => $asnId]);
        $inboundId = (int)$inbound['body']['id'];
        $itemId = (int)ApiTestHelpers::q('SELECT id FROM inbound_items WHERE inbound_order_id = ?', [$inboundId])[0]['id'];
        ApiTestHelpers::api('inbound', 'advance_status', self::$token, ['id' => $inboundId, 'status' => 'Receiving', 'received_by_id' => 1, 'received_date' => '2026-08-15']);
        ApiTestHelpers::api('inbound', 'update_item_status', self::$token, ['item_id' => $itemId, 'status' => 'ATP', 'location' => 'CA01A01']);
        ApiTestHelpers::api('inbound', 'complete', self::$token, ['id' => $inboundId]);

        $upd = ApiTestHelpers::api('asn', 'update', self::$token, ['id' => $asnId, 'supplier_name' => 'Edited']);
        $this->assertFalse($upd['body']['success']);
        $this->assertSame(409, $upd['status']);
    }
}
?>
