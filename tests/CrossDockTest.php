<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/crossdock.e2e-spec.ts — Cross-Docking (S25).
 * Tests: pre-sold outbound, cross-dock inbound staging at STAGING, picklist creation.
 */
final class CrossDockTest extends TestCase
{
    private static string $token = '';
    private static int $customerId = 0;

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();
        
        $pdo = ApiTestHelpers::pdo();
        $pdo->prepare(
            "INSERT INTO customers (customer_name, customer_code, address, city)
             VALUES (?, ?, ?, ?)"
        )->execute(['Cross Dock Customer', 'CD' . random_int(100000, 999999), 'Jln Test', 'Jakarta']);
        self::$customerId = (int)$pdo->lastInsertId();
    }

    /** Cross-docked inbound item at ATP stages at STAGING and attaches to outbound picklist */
    public function testCrossDockInboundStagesAtStagingAndAttachesToPicklist(): void
    {
        $pid = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        
        $obStmt = ApiTestHelpers::pdo()->prepare(
            "INSERT INTO outbound_orders (order_number, order_date, customer_id, expected_date, status, created_by)
             VALUES (?, ?, ?, ?, 'Open', 1)"
        );
        $obStmt->execute(['CD-OB' . random_int(100000, 999999), '2026-08-15', self::$customerId, '2026-08-20']);
        $obId = (int)ApiTestHelpers::pdo()->lastInsertId();

        ApiTestHelpers::pdo()->prepare(
            "INSERT INTO outbound_items (outbound_order_id, product_id, quantity, uom, actual_qty, pallet)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$obId, $pid, 10, 'Drum', 10, 3]);

        $created = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number' => 'PO-CD' . random_int(100000, 999999),
            'order_date' => '2026-08-15',
        ]);
        $orderId = (int)$created['body']['id'];

        $add = ApiTestHelpers::api('inbound', 'add_item', self::$token, [
            'inbound_id' => $orderId,
            'item' => [
                'product_id' => $pid,
                'quantity' => 10,
                'uom' => 'Drum',
                'batch_number' => 'CD-BATCH',
                'in_process_status' => 'Dues In',
                'cross_dock_outbound_order_id' => $obId,
            ],
        ]);
        $this->assertTrue($add['body']['success']);
        $itemId = (int)$add['body']['item_id'];

        ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id' => $orderId,
            'status' => 'Receiving',
            'received_by_id' => 1,
            'received_date' => '2026-08-15',
        ]);

        ApiTestHelpers::api('inbound', 'update_item_status', self::$token, ['item_id' => $itemId, 'status' => 'Goods Received']);
        $atp = ApiTestHelpers::api('inbound', 'update_item_status', self::$token, ['item_id' => $itemId, 'status' => 'ATP']);
        $this->assertTrue($atp['body']['success']);

        $item = ApiTestHelpers::q('SELECT in_process_status, stock_status, location, cross_dock_outbound_order_id FROM inbound_items WHERE id = ?', [$itemId]);
        $this->assertSame('ATP', $item[0]['in_process_status']);
        $this->assertSame('Accepted', $item[0]['stock_status']);
        $this->assertSame('STAGING', $item[0]['location']);
        $this->assertSame($obId, (int)$item[0]['cross_dock_outbound_order_id']);

        $stagingSl = ApiTestHelpers::q(
            'SELECT location_code, quantity FROM stock_locations WHERE inbound_item_id = ?',
            [$itemId]
        );
        $this->assertGreaterThan(0, count($stagingSl));
        $this->assertSame('STAGING', $stagingSl[0]['location_code']);
        $this->assertSame(10, (int)$stagingSl[0]['quantity']);

        $picklist = ApiTestHelpers::q(
            'SELECT id, status FROM picklists WHERE outbound_order_id = ?',
            [$obId]
        );
        $this->assertGreaterThan(0, count($picklist));
        $this->assertSame('Draft', $picklist[0]['status']);
    }

    /** complete() keeps the cross-dock line staged and pickable from STAGING */
    public function testCompleteKeepsCrossDockLineStaged(): void
    {
        $pid = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        
        $obStmt = ApiTestHelpers::pdo()->prepare(
            "INSERT INTO outbound_orders (order_number, order_date, customer_id, expected_date, status, created_by)
             VALUES (?, ?, ?, ?, 'Open', 1)"
        );
        $obStmt->execute(['CD-OB2-' . random_int(100000, 999999), '2026-08-15', self::$customerId, '2026-08-20']);
        $obId = (int)ApiTestHelpers::pdo()->lastInsertId();

        ApiTestHelpers::pdo()->prepare(
            "INSERT INTO outbound_items (outbound_order_id, product_id, quantity, uom, actual_qty, pallet)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$obId, $pid, 10, 'Drum', 10, 3]);

        $created = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number' => 'PO-CD2-' . random_int(100000, 999999),
            'order_date' => '2026-08-15',
        ]);
        $orderId = (int)$created['body']['id'];

        $add = ApiTestHelpers::api('inbound', 'add_item', self::$token, [
            'inbound_id' => $orderId,
            'item' => [
                'product_id' => $pid,
                'quantity' => 10,
                'uom' => 'Drum',
                'batch_number' => 'CD-BATCH2',
                'in_process_status' => 'Dues In',
                'cross_dock_outbound_order_id' => $obId,
            ],
        ]);
        $itemId = (int)$add['body']['item_id'];

        ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id' => $orderId, 'status' => 'Receiving', 'received_by_id' => 1, 'received_date' => '2026-08-15',
        ]);
        ApiTestHelpers::api('inbound', 'update_item_status', self::$token, ['item_id' => $itemId, 'status' => 'Goods Received']);
        ApiTestHelpers::api('inbound', 'update_item_status', self::$token, ['item_id' => $itemId, 'status' => 'ATP']);
        ApiTestHelpers::api('inbound', 'complete', self::$token, ['id' => $orderId]);

        $stock = ApiTestHelpers::q(
            'SELECT location, quantity, stock_status FROM stock WHERE product_id = ? AND batch_number = ?',
            [$pid, 'CD-BATCH2']
        );
        $this->assertGreaterThan(0, count($stock));
        foreach ($stock as $s) {
            $this->assertSame('STAGING', $s['location']);
        }

        $obDetail = ApiTestHelpers::api('outbound', 'detail', self::$token, [], ['id' => $obId]);
        $obItem = null;
        foreach ($obDetail['body']['items'] as $i) {
            if ((int)$i['product_id'] === $pid) { $obItem = $i; break; }
        }
        $this->assertNotNull($obItem);
        $this->assertSame($itemId, (int)$obItem['cross_dock_inbound_item_id']);
        $this->assertSame('ATP', $obItem['in_process_status']);
    }

    /** Sets and clears cross_dock_outbound_order_id via update_item */
    public function testSetsAndClearsCrossDockOrderId(): void
    {
        $pid = ApiTestHelpers::createProduct(['uom_type' => 'Drum']);
        
        $obStmt = ApiTestHelpers::pdo()->prepare(
            "INSERT INTO outbound_orders (order_number, order_date, customer_id, expected_date, status, created_by)
             VALUES (?, ?, ?, ?, 'Open', 1)"
        );
        $obStmt->execute(['CD-OB3-' . random_int(100000, 999999), '2026-08-15', self::$customerId, '2026-08-20']);
        $obId = (int)ApiTestHelpers::pdo()->lastInsertId();

        ApiTestHelpers::pdo()->prepare(
            "INSERT INTO outbound_items (outbound_order_id, product_id, quantity, uom, actual_qty, pallet)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([$obId, $pid, 5, 'Drum', 5, 2]);

        $created = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number' => 'PO-CD3-' . random_int(100000, 999999),
            'order_date' => '2026-08-15',
        ]);
        $orderId = (int)$created['body']['id'];

        $add = ApiTestHelpers::api('inbound', 'add_item', self::$token, [
            'inbound_id' => $orderId,
            'item' => ['product_id' => $pid, 'quantity' => 5, 'uom' => 'Drum', 'batch_number' => 'CD-BATCH3', 'in_process_status' => 'Dues In'],
        ]);
        $itemId = (int)$add['body']['item_id'];

        $set = ApiTestHelpers::api('inbound', 'update_item', self::$token, [
            'item_id' => $itemId,
            'product_id' => $pid,
            'quantity' => 5,
            'uom' => 'Drum',
            'in_process_status' => 'Dues In',
            'cross_dock_outbound_order_id' => $obId,
        ]);
        $this->assertTrue($set['body']['success']);

        $after = ApiTestHelpers::q('SELECT cross_dock_outbound_order_id FROM inbound_items WHERE id = ?', [$itemId]);
        $this->assertSame($obId, (int)$after[0]['cross_dock_outbound_order_id']);

        $clear = ApiTestHelpers::api('inbound', 'update_item', self::$token, [
            'item_id' => $itemId,
            'product_id' => $pid,
            'quantity' => 5,
            'uom' => 'Drum',
            'in_process_status' => 'Dues In',
            'cross_dock_outbound_order_id' => null,
        ]);
        $this->assertTrue($clear['body']['success']);

        $cleared = ApiTestHelpers::q('SELECT cross_dock_outbound_order_id FROM inbound_items WHERE id = ?', [$itemId]);
        $this->assertNull($cleared[0]['cross_dock_outbound_order_id']);
    }
}
?>
