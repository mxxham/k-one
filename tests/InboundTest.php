<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/inbound.e2e-spec.ts — Inbound lifecycle.
 * Tests: create inbound order, add item + advance to ATP + complete + stock created,
 * reject completing while items still Dues In.
 */
final class InboundTest extends TestCase
{
    private static string $token = '';
    private static int $productId = 0;

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();
        self::$productId = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
    }

    public function testCreatesAnInboundOrder(): void
    {
        $poNumber = 'PO' . random_int(0, 99999);
        $res = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number'  => $poNumber,
            'order_date' => '2026-08-15',
        ]);
        $this->assertTrue($res['body']['success']);
        $id = (int) $res['body']['id'];
        $this->assertGreaterThan(0, $id);

        $rows = ApiTestHelpers::q('SELECT status FROM inbound_orders WHERE id = ?', [$id]);
        $this->assertSame('Draft', $rows[0]['status']);
    }

    public function testAddsItemAdvancesToATPCompletesAndCreatesStock(): void
    {
        $poNumber = 'PO-ATP' . random_int(0, 99999);
        $created = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number'  => $poNumber,
            'order_date' => '2026-08-15',
        ]);
        $this->assertTrue($created['body']['success']);
        $orderId = (int) $created['body']['id'];

        $add = ApiTestHelpers::api('inbound', 'add_item', self::$token, [
            'inbound_id' => $orderId,
            'item'       => [
                'product_id'        => self::$productId,
                'quantity'          => 12,
                'uom'               => 'Drum',
                'batch_number'      => 'INB-BATCH',
                'expiry_date'       => '2028-06-30',
                'in_process_status' => 'Dues In',
                'pallet_no'         => 'P1',
            ],
        ]);
        $this->assertTrue($add['body']['success']);
        $itemId = (int) $add['body']['item_id'];

        $adv = ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id'             => $orderId,
            'status'         => 'Receiving',
            'received_by_id' => 1,
            'received_date'  => '2026-08-15',
        ]);
        $this->assertTrue($adv['body']['success']);

        $gr = ApiTestHelpers::api('inbound', 'update_item_status', self::$token, [
            'item_id' => $itemId,
            'status'  => 'Goods Received',
        ]);
        $this->assertTrue($gr['body']['success']);

        $atp = ApiTestHelpers::api('inbound', 'update_item_status', self::$token, [
            'item_id'  => $itemId,
            'status'   => 'ATP',
            'location' => 'CA01A01',
        ]);
        $this->assertTrue($atp['body']['success']);

        $locs = ApiTestHelpers::api('inbound', 'save_pallet_locations', self::$token, [
            'inbound_id'      => $orderId,
            'item_id'         => $itemId,
            'pallet_locations' => [
                ['location_code' => 'CA01A01', 'pallet_seq' => 1, 'quantity' => 12, 'is_full' => 1, 'batch_number' => 'INB-BATCH'],
            ],
        ]);
        $this->assertTrue($locs['body']['success']);

        $comp = ApiTestHelpers::api('inbound', 'complete', self::$token, ['id' => $orderId]);
        $this->assertTrue($comp['body']['success']);

        $stock = ApiTestHelpers::q(
            'SELECT quantity, location FROM stock WHERE product_id = ? AND batch_number = ?',
            [self::$productId, 'INB-BATCH']
        );
        $this->assertCount(1, $stock);
        $this->assertSame(12, (int) $stock[0]['quantity']);
        $this->assertSame('CA01A01', $stock[0]['location']);

        $orders = ApiTestHelpers::q('SELECT status FROM inbound_orders WHERE id = ?', [$orderId]);
        $this->assertSame('Completed', $orders[0]['status']);
    }

    public function testRejectsCompletingWhileItemsAreStillDuesIn(): void
    {
        $poNumber = 'PO-NOCOMPLETE' . random_int(0, 99999);
        $created = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number'  => $poNumber,
            'order_date' => '2026-08-15',
        ]);
        $this->assertTrue($created['body']['success']);
        $orderId = (int) $created['body']['id'];

        ApiTestHelpers::api('inbound', 'add_item', self::$token, [
            'inbound_id' => $orderId,
            'item'       => [
                'product_id'        => self::$productId,
                'quantity'          => 4,
                'uom'               => 'Drum',
                'in_process_status' => 'Dues In',
            ],
        ]);

        $comp = ApiTestHelpers::api('inbound', 'complete', self::$token, ['id' => $orderId]);
        $this->assertFalse($comp['body']['success']);
        $this->assertSame(409, $comp['status']);
    }
}