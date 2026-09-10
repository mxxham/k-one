<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Checker.php';

use PHPUnit\Framework\TestCase;

class CheckerTest extends TestCase
{
    private PDO $db;
    private int $testUserId;
    private int $adminUserId;
    private int $operatorUserId;
    private int $outboundId;

    protected function setUp(): void
    {
        $this->db = ApiTestHelpers::pdo();
        $this->db->exec("SET FOREIGN_KEY_CHECKS=0");

        $this->cleanUp();

        // Seed required FK parents
        $this->db->exec("INSERT IGNORE INTO customers (id, customer_code, customer_name, address)
            VALUES (99901, 'CKR-CUST', 'CKR Test Customer', 'N/A')");
        $this->db->exec("INSERT IGNORE INTO products (id, product_code, product_name)
            VALUES (99901, 'SKU-CKR-001', 'Checker Test Product')");

        // Create test users
        $this->db->exec("INSERT INTO users (username, password, full_name, email, role, department, is_active)
            VALUES ('ckr_admin', 'pass', 'CKR Admin', 'ckr_admin@test.local', 'admin', 'outbound', 1)");
        $this->adminUserId = (int) $this->db->lastInsertId();

        $this->db->exec("INSERT INTO users (username, password, full_name, email, role, department, is_active)
            VALUES ('ckr_picker', 'pass', 'CKR Picker', 'ckr_picker@test.local', 'operator', 'outbound', 1)");
        $this->operatorUserId = (int) $this->db->lastInsertId();

        $this->db->exec("INSERT INTO users (username, password, full_name, email, role, department, is_active)
            VALUES ('ckr_checker', 'pass', 'CKR Checker', 'ckr_checker@test.local', 'operator', 'outbound', 1)");
        $this->testUserId = (int) $this->db->lastInsertId();

        $this->db->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    protected function tearDown(): void
    {
        $this->db->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->cleanUp();
        $this->db->exec("SET FOREIGN_KEY_CHECKS=1");
    }

    private function cleanUp(): void
    {
        $this->db->exec("DELETE FROM check_scans WHERE user_id IN (
            SELECT id FROM users WHERE username LIKE 'ckr_%')");
        $this->db->exec("DELETE FROM picklist_items WHERE picked_by IN (
            SELECT id FROM users WHERE username LIKE 'ckr_%')");
        $this->db->exec("DELETE FROM picklist_items WHERE checked_by IN (
            SELECT id FROM users WHERE username LIKE 'ckr_%')");
        $this->db->exec("DELETE FROM picklists WHERE outbound_order_id IN (
            SELECT id FROM outbound_orders WHERE order_number LIKE 'CKR-%')");
        $this->db->exec("DELETE FROM outbound_items WHERE outbound_order_id IN (
            SELECT id FROM outbound_orders WHERE order_number LIKE 'CKR-%')");
        $this->db->exec("DELETE FROM outbound_orders WHERE order_number LIKE 'CKR-%'");
        $this->db->exec("DELETE FROM users WHERE username LIKE 'ckr_%'");
    }

    /**
     * Create outbound order + outbound item + picklist + picklist item.
     * Returns ['outbound_id', 'outbound_item_id', 'picklist_id', 'picklist_item_id'].
     */
    private function createFullFixture(
        string $status = 'Picked',
        ?int $pickerId = null,
        string $lpn = 'LPN-CKR-001',
        string $productCode = 'SKU-CKR-001',
        float $quantity = 10,
        ?string $batchNumber = 'BATCH-CKR'
    ): array {
        // outbound_orders
        $this->db->exec("INSERT INTO outbound_orders
            (order_number, order_date, status, customer_id, created_by, all_lines_checked)
            VALUES ('CKR-TEST-001', CURDATE(), '{$status}', 99901, {$this->operatorUserId}, 0)");
        $outboundId = (int) $this->db->lastInsertId();

        // outbound_items
        $this->db->exec("INSERT INTO outbound_items (outbound_order_id, product_id, quantity)
            VALUES ({$outboundId}, 99901, 10)");
        $outboundItemId = (int) $this->db->lastInsertId();

        // picklists
        $this->db->exec("INSERT INTO picklists
            (outbound_order_id, picklist_number, created_date, status, created_by)
            VALUES ({$outboundId}, 'CKR-PL-001', CURDATE(), 'Picked', {$this->operatorUserId})");
        $picklistId = (int) $this->db->lastInsertId();

        // picklist_items
        $pickerSql = $pickerId !== null ? $pickerId : $this->operatorUserId;
        $batchVal = $batchNumber !== null ? "'{$batchNumber}'" : 'NULL';
        $this->db->prepare("INSERT INTO picklist_items
            (outbound_item_id, picklist_id, product_id, batch_no, batch_number, quantity, uom, pallet,
             picked_by, status, lpn_code, location)
            VALUES (?, ?, 99901, {$batchVal}, {$batchVal}, ?, 'Pcs', 1, ?, 'Picked', ?, 'A01-01-01')")
            ->execute([$outboundItemId, $picklistId, $quantity, $pickerSql, $lpn]);
        $picklistItemId = (int) $this->db->lastInsertId();

        return [
            'outbound_id'       => $outboundId,
            'outbound_item_id'  => $outboundItemId,
            'picklist_id'       => $picklistId,
            'picklist_item_id'  => $picklistItemId,
        ];
    }

    // ────────────────────────────────────────
    // Tests
    // ────────────────────────────────────────

    public function testPendingLinesReturnsPendingItems(): void
    {
        $f = $this->createFullFixture();

        $pending = Checker::pendingLines();
        self::assertNotEmpty($pending);
        self::assertSame('Pending', $pending[0]['check_status']);
        self::assertSame($f['picklist_item_id'], $pending[0]['id']);
    }

    public function testConfirmLineMatchSetsChecked(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-MATCH', 'SKU-CKR-001');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';
        $result = Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-MATCH', 'SKU-CKR-001', 10.0,
            'BATCH-CKR'
        );
        self::assertTrue($result);

        $row = $this->db->query(
            "SELECT check_status, checked_by FROM picklist_items WHERE id = {$f['picklist_item_id']}"
        )->fetch();
        self::assertSame('Checked', $row['check_status']);
        self::assertSame($this->testUserId, (int) $row['checked_by']);
    }

    public function testConfirmLineMismatchWithoutOverrideThrows(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-REAL', 'SKU-CKR-001');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Alasan override wajib diisi');
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-WRONG', 'SKU-CKR-001', 10.0,
            null, null, null
        );
    }

    public function testConfirmLineMismatchWithOverrideByNonSupervisorThrows(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-REAL', 'SKU-CKR-001');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('izin supervisor');
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-WRONG', 'SKU-CKR-001', 10.0,
            null, null, 'Wrong LPN'
        );
    }

    public function testConfirmLineMismatchWithOverrideBySupervisorSucceeds(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-REAL', 'SKU-CKR-001');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'supervisor';
        $result = Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-WRONG', 'SKU-WRONG', 10.0,
            null, null, 'Swapped during staging'
        );
        self::assertTrue($result);

        $row = $this->db->query(
            "SELECT check_status, check_override_reason FROM picklist_items WHERE id = {$f['picklist_item_id']}"
        )->fetch();
        self::assertSame('Discrepancy', $row['check_status']);
        self::assertSame('Swapped during staging', $row['check_override_reason']);

        $scans = $this->db->query(
            "SELECT scan_type, result FROM check_scans WHERE context_id = {$f['picklist_item_id']} ORDER BY scan_type"
        )->fetchAll();
        self::assertCount(4, $scans);
        $scanMap = array_column($scans, 'result', 'scan_type');
        self::assertSame('MISMATCH', $scanMap['LPN']);
        self::assertSame('MISMATCH', $scanMap['SKU']);
        self::assertSame('MATCH', $scanMap['LOCATION']);
        self::assertSame('MATCH', $scanMap['QTY']);
    }

    public function testConfirmLineMismatchWithOverrideByAdminSucceeds(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-REAL', 'SKU-CKR-001');

        $_SESSION['user_id'] = $this->adminUserId;
        $_SESSION['role'] = 'admin';
        $result = Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-WRONG', 'SKU-CKR-001', 10.0,
            null, null, 'Admin override'
        );
        self::assertTrue($result);

        $row = $this->db->query(
            "SELECT check_status FROM picklist_items WHERE id = {$f['picklist_item_id']}"
        )->fetch();
        self::assertSame('Discrepancy', $row['check_status']);
    }

    public function testSegregationOfDutiesCheckerCannotBePicker(): void
    {
        $f = $this->createFullFixture('Picked', $this->testUserId, 'LPN-001', 'SKU-001');

        // Same user as picker tries to check
        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Checker tidak boleh sama dengan picker');
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-001', 'SKU-001', 10.0,
            null, null, null
        );
    }

    public function testAllLinesCheckedFlipsOrderFlag(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-ALL', 'SKU-CKR-001');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-ALL', 'SKU-CKR-001', 10.0,
            null, null, null
        );

        $flag = $this->db->query(
            "SELECT all_lines_checked FROM outbound_orders WHERE id = {$f['outbound_id']}"
        )->fetch();
        self::assertSame(1, (int) $flag['all_lines_checked']);
    }

    public function testShipBlockedWhenAllLinesNotChecked(): void
    {
        $f = $this->createFullFixture('Picked');

        require_once dirname(__DIR__) . '/classes/Outbound.php';
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Semua item harus dicek');
        Outbound::ship($f['outbound_id']);
    }

    public function testAlreadyCheckedItemThrows(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-DUP', 'SKU-CKR-001');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-DUP', 'SKU-CKR-001', 10.0,
            null, null, null
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('sudah dicek');
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-DUP', 'SKU-CKR-001', 10.0,
            null, null, null
        );
    }

    public function testOrderNotInPickingOrPickedStateThrows(): void
    {
        $f = $this->createFullFixture('Open');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('belum siap untuk dicek');
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-001', 'SKU-CKR-001', 10.0,
            null, null, null
        );
    }

    // ────────────────────────────────────────
    // NEW: Lot / Qty mismatch tests
    // ────────────────────────────────────────

    public function testLotMismatchTriggersDiscrepancyWithFailedCheckNamed(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-LOT', 'SKU-CKR-001', 10, 'BATCH-REAL');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('LOT');
        // Location/LPN/SKU/Qty match, but Lot is wrong
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-LOT', 'SKU-CKR-001', 10.0,
            'BATCH-WRONG', null, null
        );
    }

    public function testQtyMismatchOfOneUnitIsRealMismatch(): void
    {
        $f = $this->createFullFixture('Picked', $this->operatorUserId, 'LPN-QTY', 'SKU-CKR-001', 10, 'BATCH-QTY');

        $_SESSION['user_id'] = $this->testUserId;
        $_SESSION['role'] = 'operator';

        // Qty is 10 in fixture, scan 9 → must fail
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('QTY');
        Checker::confirmLine(
            $f['picklist_item_id'], 'A01-01-01', 'LPN-QTY', 'SKU-CKR-001', 9.0,
            'BATCH-QTY', null, null
        );
    }
}
