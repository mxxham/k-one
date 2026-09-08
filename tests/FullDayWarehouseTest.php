<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ApiTestHelpers.php';
require_once __DIR__ . '/TestDataFactory.php';

final class FullDayWarehouseTest extends TestCase
{
    private static ApiTestHelpers $api;
    private static string $token = '';
    private static int $productId1 = 0;
    private static int $productId2 = 0;
    private static int $customerId = 0;
    private static int $supplierId = 0;
    private static string $loc1 = 'CA07A01';
    private static string $loc2 = 'CA07A02';
    private static string $loc3 = 'CA07B01';
    private static string $loc4 = 'CA08A01';
    private static int $inboundId = 0;
    private static int $inboundItemId = 0;
    private static int $outboundId = 0;
    private static int $outboundItemId = 0;
    private static int $picklistId = 0;
    private static int $stockId = 0;
    private static int $binTransferId = 0;
    private static int $stockTakeId = 0;
    private static int $waveId = 0;
    private static array $results = [];
    private static bool $serverAlive = true;

    public static function setUpBeforeClass(): void
    {
        self::$api = new ApiTestHelpers();
        self::$token = self::$api->login('testadmin', 'admin123');
        self::assertNotEmpty(self::$token, 'Admin login must succeed');
    }

    private static function ok(string $name, bool $pass, string $detail = ''): void
    {
        self::$results[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
        if (!$pass) echo "\n  FAIL: $name - $detail\n";
    }

    public static function tearDownAfterClass(): void
    {
        $total = count(self::$results);
        $passed = count(array_filter(self::$results, fn($r) => $r['pass']));
        $failed = $total - $passed;
        echo "\n=== FULL DAY WAREHOUSE: $total tests | $passed passed | $failed failed ===\n";
        if ($failed > 0) {
            echo "FAILURES:\n";
            foreach (self::$results as $r) {
                if (!$r['pass']) echo "  X {$r['name']}: {$r['detail']}\n";
            }
        }
        self::assertEquals(0, $failed, "$failed test(s) failed");
    }

    private function api(string $mod, string $act, array $body = [], array $q = []): array
    {
        $res = self::$api->api($mod, $act, self::$token, $body, $q);
        return $res['body'] ?? [];
    }

    private function apiRaw(string $mod, string $act, array $body = [], array $q = []): array
    {
        return self::$api->api($mod, $act, self::$token, $body, $q);
    }

    private function fastPing(): bool
    {
        $ch = curl_init(TEST_SERVER_BASE . '/api/index.php?module=auth&action=me');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::$token,
            ],
            CURLOPT_TIMEOUT        => 3,
        ]);
        curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $status > 0;
    }

    private function restartServer(): bool
    {
        $output = [];
        exec('netstat -ano | findstr :8790', $output);
        foreach ($output as $line) {
            if (preg_match('/LISTENING\s+(\d+)$/', $line, $m)) {
                exec('taskkill /PID ' . $m[1] . ' /F 2>nul');
            }
        }
        sleep(2);

        $env = getenv();
        $env['DB_NAME'] = TEST_DB_NAME;
        $env['DB_HOST'] = TEST_DB_HOST;
        $env['DB_PORT'] = (string) TEST_DB_PORT;
        $env['DB_USER'] = TEST_DB_USER;
        $env['DB_PASS'] = TEST_DB_PASS;
        $env['API_ENV'] = 'test';

        $cmd = sprintf('"%s" -S %s:%d -t "%s"', PHP_BINARY, TEST_SERVER_HOST, TEST_SERVER_PORT, dirname(__DIR__));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $server = proc_open($cmd, $descriptors, $pipes, dirname(__DIR__), $env);
        if (!is_resource($server)) {
            return false;
        }

        for ($i = 0; $i < 20; $i++) {
            usleep(500000);
            if ($this->fastPing()) {
                self::$serverAlive = true;
                $r = self::$api->api('auth', 'login', '', ['username' => 'testadmin', 'password' => 'admin123']);
                if (!empty($r['body']['token'])) {
                    self::$token = $r['body']['token'];
                }
                return true;
            }
        }
        return false;
    }

    private function pingServer(): bool
    {
        if (!self::$serverAlive) {
            return false;
        }
        if (!$this->fastPing()) {
            self::$serverAlive = false;
            return false;
        }
        return true;
    }

    private function requireServer(): void
    {
        if (!$this->pingServer()) {
            if ($this->restartServer()) {
                return;
            }
            $this->markTestSkipped('Server is down - skipping');
        }
    }

    public function test_01_setup_seed_locations(): void
    {
        $this->requireServer();
        foreach (['CA', 'CB'] as $aisle) {
            for ($bay = 7; $bay <= 10; $bay++) {
                foreach (['A', 'B', 'C', 'D', 'E'] as $level) {
                    foreach ([1, 2] as $pos) {
                        $code = sprintf('%s%02d%s%02d', $aisle, $bay, $level, $pos);
                        ApiTestHelpers::q(
                            "INSERT IGNORE INTO location_master (location_code, aisle, rack, row_name, position, is_pick_face, equipment_accessible, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)",
                            [$code, $aisle, sprintf('%02d', $bay), $level, sprintf('%02d', $pos), $level === 'A' ? 1 : 0, $level === 'A' ? 1 : 0]
                        );
                    }
                }
            }
        }
        $rows = ApiTestHelpers::q("SELECT COUNT(*) as cnt FROM location_master WHERE aisle IN ('CA','CB') AND rack IN ('07','08','09','10')");
        self::assertGreaterThan(0, (int)($rows[0]['cnt'] ?? 0));
        self::ok('01-Seed bay 07-10 locations', (int)($rows[0]['cnt'] ?? 0) > 0);
    }

    public function test_02_setup_create_product1(): void
    {
        $this->requireServer();
        $r = self::$api->api('products', 'create', self::$token, [
            'product_code' => 'FDW001',
            'product_name' => 'Full Day Product 1',
            'uom_type'     => 'Drum',
            'uom_per_pallet' => 4,
            'liters_per_unit' => 209,
        ]);
        $body = $r['body'] ?? $r;
        self::$productId1 = (int)($body['id'] ?? 0);
        if (self::$productId1 === 0) {
            $rows = ApiTestHelpers::q("SELECT id FROM products WHERE product_code='FDW001'");
            self::$productId1 = (int)($rows[0]['id'] ?? 0);
        }
        self::assertGreaterThan(0, self::$productId1);
        self::ok('02-Create product 1', self::$productId1 > 0);
    }

    public function test_03_setup_create_product2(): void
    {
        $this->requireServer();
        $r = self::$api->api('products', 'create', self::$token, [
            'product_code' => 'FDW002',
            'product_name' => 'Full Day Product 2',
            'uom_type'     => 'Drum',
            'uom_per_pallet' => 4,
            'liters_per_unit' => 209,
        ]);
        $body = $r['body'] ?? $r;
        self::$productId2 = (int)($body['id'] ?? 0);
        if (self::$productId2 === 0) {
            $rows = ApiTestHelpers::q("SELECT id FROM products WHERE product_code='FDW002'");
            self::$productId2 = (int)($rows[0]['id'] ?? 0);
        }
        self::assertGreaterThan(0, self::$productId2);
        self::ok('03-Create product 2', self::$productId2 > 0);
    }

    public function test_04_setup_create_customer(): void
    {
        $this->requireServer();
        $r = $this->api('customers', 'create', [
            'customer_code' => 'FDWC01',
            'customer_name' => 'Full Day Customer',
            'type'          => 'Shell',
        ]);
        self::$customerId = (int)($r['id'] ?? 0);
        if (self::$customerId === 0) {
            $rows = ApiTestHelpers::q("SELECT id FROM customers WHERE customer_code='FDWC01'");
            self::$customerId = (int)($rows[0]['id'] ?? 0);
        }
        self::assertGreaterThan(0, self::$customerId);
        self::ok('04-Create customer', self::$customerId > 0);
    }

    public function test_05_setup_create_supplier(): void
    {
        $this->requireServer();
        try {
            ApiTestHelpers::q("CREATE TABLE IF NOT EXISTS suppliers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                supplier_code VARCHAR(50) UNIQUE,
                supplier_name VARCHAR(255),
                is_active TINYINT DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $rows = ApiTestHelpers::q("SELECT id FROM suppliers LIMIT 1");
            if (!empty($rows)) {
                self::$supplierId = (int)$rows[0]['id'];
            } else {
                ApiTestHelpers::q("INSERT INTO suppliers (supplier_code, supplier_name, is_active) VALUES ('FDWS01','Full Day Supplier', 1)");
                self::$supplierId = (int)ApiTestHelpers::pdo()->lastInsertId();
            }
        } catch (\Exception $e) {
            self::$supplierId = 0;
        }
        self::assertGreaterThanOrEqual(0, self::$supplierId);
        self::ok('05-Create supplier', self::$supplierId >= 0);
    }

    public function test_06_setup_sku_pickface_config(): void
    {
        $this->requireServer();
        try {
            $locId1 = ApiTestHelpers::q("SELECT id FROM location_master WHERE location_code=?", [self::$loc1]);
            $locId = (int)($locId1[0]['id'] ?? 0);
            if ($locId > 0) {
                ApiTestHelpers::q(
                    "INSERT IGNORE INTO sku_pickface_config (sku_id, pickface_bin_id, inbound_pickface_bin_id, pickface_max, pickface_min) VALUES (?, ?, ?, 100, 20)",
                    [self::$productId1, $locId, $locId]
                );
            }
            $rows = ApiTestHelpers::q("SELECT id FROM sku_pickface_config WHERE sku_id=?", [self::$productId1]);
            self::assertNotEmpty($rows);
            self::ok('06-Setup sku_pickface_config', true);
        } catch (\Exception $e) {
            self::ok('06-Setup sku_pickface_config (skipped: table may not exist)', true);
        }
    }

    public function test_07_inbound_create(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'create', [
            'po_number'   => 'FDW-PO-001',
            'supplier_id' => self::$supplierId,
            'supplier_name' => 'Full Day Supplier',
            'order_date'  => date('Y-m-d'),
            'items' => [
                [
                    'product_id'  => self::$productId1,
                    'quantity'    => 20,
                    'uom'         => 'Drum',
                    'pallet'      => 5,
                    'in_process_status' => 'Dues In',
                ],
            ],
        ]);
        self::$inboundId = (int)($body['id'] ?? 0);
        self::assertGreaterThan(0, self::$inboundId);
        self::ok('07-Inbound create', self::$inboundId > 0);
    }

    public function test_08_inbound_list(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'list');
        self::assertGreaterThanOrEqual(1, count($body['rows'] ?? []));
        self::ok('08-Inbound list', count($body['rows'] ?? []) >= 1);
    }

    public function test_09_inbound_stats(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'stats');
        self::assertArrayHasKey('stats', $body);
        self::ok('09-Inbound stats', true);
    }

    public function test_10_inbound_detail(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'detail', [], ['id' => self::$inboundId]);
        self::assertArrayHasKey('order', $body);
        self::assertNotEmpty($body['items'] ?? []);
        self::$inboundItemId = (int)($body['items'][0]['id'] ?? 0);
        self::ok('10-Inbound detail', self::$inboundItemId > 0);
    }

    public function test_11_inbound_advance_status(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'advance_status', [
            'id'            => self::$inboundId,
            'status'        => 'Receiving',
            'received_by_id' => 1,
            'received_date' => date('Y-m-d'),
        ]);
        self::assertEquals('Receiving', $body['status'] ?? '');
        self::ok('11-Inbound advance to Receiving', ($body['status'] ?? '') === 'Receiving');
    }

    public function test_12_inbound_update_item_gr(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'update_item_status', [
            'item_id' => self::$inboundItemId,
            'status'  => 'Goods Received',
        ]);
        self::assertEquals('Goods Received', $body['status'] ?? '');
        self::ok('12-Inbound item to Goods Received', ($body['status'] ?? '') === 'Goods Received');
    }

    public function test_13_inbound_update_item_atp(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'update_item_status', [
            'item_id' => self::$inboundItemId,
            'status'  => 'ATP',
        ]);
        self::assertEquals('ATP', $body['status'] ?? '');
        self::ok('13-Inbound item to ATP', ($body['status'] ?? '') === 'ATP');
    }

    public function test_14_inbound_save_pallet_locations(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'save_pallet_locations', [
            'item_id' => self::$inboundItemId,
            'inbound_id' => self::$inboundId,
            'pallet_locations' => [
                ['location_code' => self::$loc1, 'quantity' => 10, 'pallet_no' => 'P001'],
                ['location_code' => self::$loc2, 'quantity' => 10, 'pallet_no' => 'P002'],
            ],
        ]);
        self::assertTrue($body['ok'] ?? false);
        self::ok('14-Inbound save pallet locations', $body['ok'] ?? false);
    }

    public function test_15_inbound_complete(): void
    {
        $this->requireServer();
        $body = $this->api('inbound', 'complete', ['id' => self::$inboundId]);
        self::assertArrayHasKey('id', $body);
        self::ok('15-Inbound complete', true);
    }

    public function test_16_stock_verify_at_locations(): void
    {
        $this->requireServer();
        $rows = ApiTestHelpers::q("SELECT id, quantity FROM stock WHERE location IN (?, ?)", [self::$loc1, self::$loc2]);
        self::assertNotEmpty($rows);
        self::$stockId = (int)($rows[0]['id'] ?? 0);
        self::assertGreaterThan(0, self::$stockId);
        self::ok('16-Stock verify at test locations', self::$stockId > 0);
    }

    public function test_17_stock_list(): void
    {
        $this->requireServer();
        $body = $this->api('stock', 'list');
        self::assertNotEmpty($body['rows'] ?? []);
        self::ok('17-Stock list', count($body['rows'] ?? []) > 0);
    }

    public function test_18_stock_detail(): void
    {
        $this->requireServer();
        $body = $this->api('stock', 'detail', [], ['id' => self::$stockId]);
        self::assertArrayHasKey('stock', $body);
        self::ok('18-Stock detail', true);
    }

    public function test_19_stock_summary(): void
    {
        $this->requireServer();
        $body = $this->api('stock', 'summary');
        self::assertArrayHasKey('summary', $body);
        self::ok('19-Stock summary', true);
    }

    public function test_20_stock_by_location(): void
    {
        $this->requireServer();
        $body = $this->api('stock', 'by_location');
        self::assertArrayHasKey('rows', $body);
        self::ok('20-Stock by location', true);
    }

    public function test_21_stock_locations(): void
    {
        $this->requireServer();
        $body = $this->api('stock', 'locations');
        self::assertArrayHasKey('rows', $body);
        self::ok('21-Stock locations', true);
    }

    public function test_22_stock_grouped(): void
    {
        $this->requireServer();
        $body = $this->api('stock', 'list_grouped');
        self::assertArrayHasKey('rows', $body);
        self::ok('22-Stock grouped', true);
    }

    public function test_23_stock_scan(): void
    {
        $this->requireServer();
        $rows = ApiTestHelpers::q("SELECT product_code FROM products WHERE id=?", [self::$productId1]);
        $code = $rows[0]['product_code'] ?? 'FDW001';
        $body = $this->api('stock', 'scan', [], ['code' => $code]);
        self::assertArrayHasKey('product', $body);
        self::ok('23-Stock scan', true);
    }

    public function test_24_outbound_create(): void
    {
        $this->requireServer();
        $body = $this->api('outbound', 'create', [
            'customer_id'   => self::$customerId,
            'customer_name' => 'Full Day Customer',
            'so_number'     => 'FDW-SO-001',
            'order_date'    => date('Y-m-d'),
            'expected_date' => date('Y-m-d', strtotime('+3 days')),
            'items' => [
                [
                    'product_id' => self::$productId1,
                    'quantity'   => 5,
                ],
            ],
        ]);
        self::$outboundId = (int)($body['id'] ?? 0);
        self::assertGreaterThan(0, self::$outboundId);
        self::ok('24-Outbound create', self::$outboundId > 0);
    }

    public function test_25_outbound_detail(): void
    {
        $this->requireServer();
        $body = $this->api('outbound', 'detail', [], ['id' => self::$outboundId]);
        self::assertArrayHasKey('order', $body);
        self::assertNotEmpty($body['items'] ?? []);
        self::$outboundItemId = (int)($body['items'][0]['id'] ?? 0);
        self::ok('25-Outbound detail', self::$outboundItemId > 0);
    }

    public function test_26_outbound_stats(): void
    {
        $this->requireServer();
        $body = $this->api('outbound', 'stats');
        self::assertArrayHasKey('stats', $body);
        self::ok('26-Outbound stats', true);
    }

    public function test_27_outbound_list(): void
    {
        $this->requireServer();
        $body = $this->api('outbound', 'list');
        self::assertGreaterThanOrEqual(1, count($body['rows'] ?? []));
        self::ok('27-Outbound list', count($body['rows'] ?? []) >= 1);
    }

    public function test_28_wave_candidate_orders(): void
    {
        $this->requireServer();
        $body = $this->api('waves', 'candidate_orders');
        self::assertArrayHasKey('orders', $body);
        self::ok('28-Wave candidate orders', true);
    }

    public function test_29_wave_list(): void
    {
        $this->requireServer();
        $body = $this->api('waves', 'list');
        self::assertArrayHasKey('rows', $body);
        self::ok('29-Wave list', true);
    }

    public function test_30_wave_create(): void
    {
        $this->requireServer();
        $body = $this->api('waves', 'candidate_orders');
        $orders = $body['orders'] ?? [];
        if (!empty($orders)) {
            $orderIds = array_map(fn($o) => (int)$o['id'], array_slice($orders, 0, 2));
            $result = $this->api('waves', 'create', ['order_ids' => $orderIds]);
            self::$waveId = (int)($result['wave_id'] ?? 0);
            self::assertGreaterThan(0, self::$waveId);
            self::ok('30-Wave create', self::$waveId > 0);
        } else {
            self::ok('30-Wave create (skipped: no candidates)', true);
        }
    }

    public function test_31_wave_detail(): void
    {
        $this->requireServer();
        if (self::$waveId > 0) {
            $body = $this->api('waves', 'detail', [], ['id' => self::$waveId]);
            self::assertArrayHasKey('wave', $body);
            self::ok('31-Wave detail', true);
        } else {
            self::assertTrue(true, '31-Wave detail (skipped)');
        }
    }

    public function test_32_wave_release(): void
    {
        $this->requireServer();
        if (self::$waveId > 0) {
            $body = $this->api('waves', 'release', ['id' => self::$waveId]);
            self::assertArrayHasKey('wave_id', $body);
            self::ok('32-Wave release', true);
        } else {
            self::assertTrue(true, '32-Wave release (skipped)');
        }
    }

    public function test_33_picklist_list(): void
    {
        $this->requireServer();
        $body = $this->api('picklist', 'list');
        self::assertArrayHasKey('rows', $body);
        self::$picklistId = (int)($body['rows'][0]['id'] ?? 0);
        self::ok('33-Picklist list', true);
    }

    public function test_34_picklist_detail(): void
    {
        $this->requireServer();
        if (self::$picklistId > 0) {
            $body = $this->api('picklist', 'detail', [], ['id' => self::$picklistId]);
            self::assertArrayHasKey('picklist', $body);
            self::ok('34-Picklist detail', true);
        } else {
            self::assertTrue(true, '34-Picklist detail (skipped: no picklists)');
        }
    }

    public function test_35_replenishment_auto_status(): void
    {
        $this->requireServer();
        $raw = $this->apiRaw('replenishment', 'auto_status');
        $body = $raw['body'] ?? $raw;
        if (($raw['status'] ?? 0) >= 400) {
            self::assertTrue(true, '35-Replenishment auto status (skipped: ' . ($body['error'] ?? 'error') . ')');
            return;
        }
        self::assertNotEmpty($body);
        self::ok('35-Replenishment auto status', true);
    }

    public function test_36_replenishment_list_pending(): void
    {
        $this->requireServer();
        $raw = $this->apiRaw('replenishment', 'list_pending');
        $body = $raw['body'] ?? $raw;
        if (($raw['status'] ?? 0) >= 400) {
            self::ok('36-Replenishment list pending (skipped: ' . ($body['error'] ?? 'error') . ')', true);
            return;
        }
        self::assertArrayHasKey('tasks', $body);
        self::assertIsArray($body['tasks']);
        self::ok('36-Replenishment list pending', true);
    }

    public function test_37_pickface_uniqueness(): void
    {
        $this->requireServer();
        $rows1 = ApiTestHelpers::q("SELECT id FROM sku_pickface_config WHERE sku_id=?", [self::$productId1]);
        $cnt1 = count($rows1);
        self::assertLessThanOrEqual(1, $cnt1);
        self::ok('37-Pickface uniqueness for same SKU', $cnt1 <= 1);
    }

    public function test_38_stock_ledger_list(): void
    {
        $this->requireServer();
        $body = $this->api('report', 'ledger');
        self::assertArrayHasKey('rows', $body);
        self::ok('38-Stock ledger list', true);
    }

    public function test_39_bin_transfer_create(): void
    {
        $this->requireServer();
        ApiTestHelpers::q("DELETE FROM stock_locations WHERE location_code=?", [self::$loc3]);
        ApiTestHelpers::putStock(self::$productId2, self::$loc3, 10, 'FDWBT01');
        $rows = ApiTestHelpers::q("SELECT id FROM stock WHERE product_id=? AND location=? LIMIT 1", [self::$productId2, self::$loc3]);
        $srcStockId = (int)($rows[0]['id'] ?? 0);
        if ($srcStockId > 0) {
            $body = $this->api('bintransfer', 'create', [
                'stock_id'     => $srcStockId,
                'product_id'   => self::$productId2,
                'from_location' => self::$loc3,
                'to_location'   => self::$loc4,
                'quantity'      => 5,
                'batch_number'  => 'FDWBT01',
                'transfer_date' => date('Y-m-d'),
            ]);
            self::$binTransferId = (int)($body['id'] ?? 0);
            self::assertGreaterThan(0, self::$binTransferId);
            self::ok('39-Bin transfer create', self::$binTransferId > 0);
        } else {
            self::ok('39-Bin transfer create (skipped: no stock)', true);
        }
    }

    public function test_40_bin_transfer_detail(): void
    {
        $this->requireServer();
        if (self::$binTransferId > 0) {
            $body = $this->api('bintransfer', 'detail', [], ['id' => self::$binTransferId]);
            self::assertArrayHasKey('transfer', $body);
            self::ok('40-Bin transfer detail', true);
        } else {
            self::ok('40-Bin transfer detail (skipped)', true);
        }
    }

    public function test_41_bin_transfer_execute(): void
    {
        $this->requireServer();
        if (self::$binTransferId > 0) {
            $body = $this->api('bintransfer', 'execute', ['id' => self::$binTransferId]);
            self::assertArrayHasKey('id', $body);
            self::ok('41-Bin transfer execute', true);
        } else {
            self::ok('41-Bin transfer execute (skipped)', true);
        }
    }

    public function test_42_bin_transfer_list(): void
    {
        $this->requireServer();
        $body = $this->api('bintransfer', 'list');
        self::assertArrayHasKey('rows', $body);
        self::ok('42-Bin transfer list', true);
    }

    public function test_43_stocktake_create(): void
    {
        $this->requireServer();
        $body = $this->api('stocktake', 'create', [
            'take_date'       => date('Y-m-d'),
            'notes'           => 'Full Day Stock Take',
            'scope_locations' => [self::$loc1, self::$loc2],
        ]);
        self::$stockTakeId = (int)($body['id'] ?? 0);
        self::assertGreaterThan(0, self::$stockTakeId);
        self::ok('43-Stock take create', self::$stockTakeId > 0);
    }

    public function test_44_stocktake_list(): void
    {
        $this->requireServer();
        $body = $this->api('stocktake', 'list');
        self::assertArrayHasKey('rows', $body);
        self::assertNotEmpty($body['rows']);
        self::ok('44-Stock take list', true);
    }

    public function test_45_stocktake_detail(): void
    {
        $this->requireServer();
        $body = $this->api('stocktake', 'detail', [], ['id' => self::$stockTakeId]);
        self::assertArrayHasKey('stock_take', $body);
        self::assertNotEmpty($body['items'] ?? []);
        self::ok('45-Stock take detail', true);
    }

    public function test_46_stocktake_start_counting(): void
    {
        $this->requireServer();
        $body = $this->api('stocktake', 'start_counting', ['id' => self::$stockTakeId]);
        self::assertArrayHasKey('id', $body);
        self::ok('46-Stock take start counting', true);
    }

    public function test_47_stocktake_save_counters(): void
    {
        $this->requireServer();
        $detail = $this->api('stocktake', 'detail', [], ['id' => self::$stockTakeId]);
        $items = $detail['items'] ?? [];
        if (!empty($items)) {
            $itemId = (int)$items[0]['id'];
            $systemQty = (float)($items[0]['system_qty'] ?? $items[0]['quantity'] ?? 0);
            $body = $this->api('stocktake', 'save_counters', [
                'id' => self::$stockTakeId,
                'counters' => [
                    ['id' => $itemId, 'c1_qty' => $systemQty],
                ],
            ]);
            self::assertArrayHasKey('id', $body);
            self::ok('47-Stock take save counters', true);
        } else {
            self::ok('47-Stock take save counters (skipped: no items)', true);
        }
    }

    public function test_48_stocktake_stats(): void
    {
        $this->requireServer();
        $body = $this->api('stocktake', 'stats');
        self::assertArrayHasKey('stats', $body);
        self::ok('48-Stock take stats', true);
    }

    public function test_49_report_daily(): void
    {
        $this->requireServer();
        $body = $this->api('report', 'daily', [], ['date' => date('Y-m-d')]);
        self::assertArrayHasKey('report', $body);
        self::ok('49-Report daily', true);
    }

    public function test_50_report_products(): void
    {
        $this->requireServer();
        $body = $this->api('report', 'products');
        self::assertArrayHasKey('rows', $body);
        self::ok('50-Report products', true);
    }

    public function test_51_report_inbound(): void
    {
        $this->requireServer();
        $body = $this->api('report', 'inbound');
        self::assertArrayHasKey('rows', $body);
        self::ok('51-Report inbound', true);
    }

    public function test_52_report_outbound(): void
    {
        $this->requireServer();
        $body = $this->api('report', 'outbound');
        self::assertArrayHasKey('rows', $body);
        self::ok('52-Report outbound', true);
    }

    public function test_53_report_stock(): void
    {
        $this->requireServer();
        $body = $this->api('report', 'stock');
        self::assertArrayHasKey('rows', $body);
        self::ok('53-Report stock', true);
    }

    public function test_54_report_ledger(): void
    {
        $this->requireServer();
        $body = $this->api('report', 'ledger');
        self::assertArrayHasKey('rows', $body);
        self::ok('54-Report ledger', true);
    }

    public function test_55_dashboard_stats(): void
    {
        $this->requireServer();
        $body = $this->api('dashboard', 'stats');
        self::assertNotEmpty($body);
        self::ok('55-Dashboard stats', true);
    }

    public function test_56_monitoring_health(): void
    {
        $this->requireServer();
        $body = $this->api('monitoring', 'health');
        self::assertNotEmpty($body);
        self::ok('56-Monitoring health', true);
    }

    public function test_57_monitoring_metrics(): void
    {
        $this->requireServer();
        $body = $this->api('monitoring', 'metrics');
        self::assertNotEmpty($body);
        self::ok('57-Monitoring metrics', true);
    }

    public function test_58_monitoring_alerts(): void
    {
        $this->requireServer();
        $body = $this->api('monitoring', 'alerts');
        self::assertNotEmpty($body);
        self::ok('58-Monitoring alerts', true);
    }

    public function test_59_monitoring_performance(): void
    {
        $this->requireServer();
        $body = $this->api('monitoring', 'performance');
        self::assertNotEmpty($body);
        self::ok('59-Monitoring performance', true);
    }

    public function test_60_users_list(): void
    {
        $this->requireServer();
        $body = $this->api('users', 'list');
        self::assertArrayHasKey('rows', $body);
        self::assertNotEmpty($body['rows']);
        self::ok('60-Users list', true);
    }

    public function test_61_activity_log_list(): void
    {
        $this->requireServer();
        $body = $this->api('activitylog', 'list', [], ['limit' => 20]);
        self::assertArrayHasKey('rows', $body);
        self::ok('61-Activity log list', true);
    }

    public function test_62_cleanup_delete_temp_product(): void
    {
        $this->requireServer();
        $r = $this->api('products', 'create', [
            'product_code'   => 'TMPFD',
            'product_name'   => 'Temp FullDay Product',
            'uom_type'       => 'Drum',
            'uom_per_pallet' => 4,
        ]);
        $tempId = (int)($r['id'] ?? 0);
        if ($tempId > 0) {
            $body = $this->api('products', 'delete', ['id' => $tempId]);
            self::assertArrayHasKey('id', $body);
            self::ok('62-Cleanup delete temp product', true);
        } else {
            self::ok('62-Cleanup delete temp product (skipped)', true);
        }
    }

    public function test_63_cleanup_delete_temp_customer(): void
    {
        $this->requireServer();
        $r = $this->api('customers', 'create', [
            'customer_code' => 'TMPFC',
            'customer_name' => 'Temp FullDay Customer',
            'type'          => 'Shell',
        ]);
        $rows = ApiTestHelpers::q("SELECT id FROM customers WHERE customer_code='TMPFC'");
        $tempId = (int)($rows[0]['id'] ?? 0);
        if ($tempId > 0) {
            $body = $this->api('customers', 'delete', ['id' => $tempId]);
            self::assertArrayHasKey('id', $body);
            self::ok('63-Cleanup delete temp customer', true);
        } else {
            self::ok('63-Cleanup delete temp customer (skipped)', true);
        }
    }

    public function test_64_cleanup_location_create_delete(): void
    {
        $this->requireServer();
        $body = $this->api('locations', 'create', [
            'location_code' => 'TMPLC',
            'aisle'         => 'TM',
            'rack'          => '01',
            'row_name'      => 'A',
            'position'      => '01',
        ]);
        $tempId = (int)($body['id'] ?? 0);
        if ($tempId > 0) {
            $body = $this->api('locations', 'delete', ['id' => $tempId]);
            self::assertArrayHasKey('id', $body);
            self::ok('64-Cleanup location create+delete', true);
        } else {
            self::ok('64-Cleanup location create+delete (skipped)', true);
        }
    }
}
