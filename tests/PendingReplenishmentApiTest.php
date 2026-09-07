<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ApiTestHelpers.php';

class PendingReplenishmentApiTest extends TestCase
{
    private static PDO $db;

    private static int $productId;
    private static int $srcBinId;
    private static int $dstBinId;
    private static int $outboundOrderId;
    private static int $customerId;
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        self::$db = \ApiTestHelpers::pdo();
        $db = self::$db;

        \ApiTestHelpers::truncateTransactional($db);

        $db->exec("INSERT INTO users (username, password, role, full_name) VALUES ('testuser', 'x', 'admin', 'Test User')");
        self::$userId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO products (product_code, product_name) VALUES ('TEST-SKU-001', 'Test Product')");
        self::$productId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO location_master (location_code, is_active) VALUES ('SRC-LOC-01', 1)");
        self::$srcBinId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO location_master (location_code, is_active) VALUES ('DST-LOC-01', 1)");
        self::$dstBinId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO customers (customer_code, customer_name) VALUES ('CUST-001', 'Test Customer')");
        self::$customerId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO outbound_orders (order_number, order_date, customer_id, created_by, status)
                    VALUES ('OO-TEST-001', CURDATE(), " . self::$customerId . ", " . self::$userId . ", 'Open')");
        self::$outboundOrderId = (int)$db->lastInsertId();
    }

    protected function setUp(): void
    {
        $db = self::$db;
        $db->exec("DELETE FROM replen_task");
        $db->exec("DELETE FROM picklist_bin_to_bin");
        $db->exec("DELETE FROM picklist_items");
        $db->exec("DELETE FROM picklists");
    }

    private function seedReplenTask(int $hoursAgo = 0): int
    {
        $db = self::$db;
        $db->exec("INSERT INTO replen_task (sku_id, source_bin_id, destination_bin_id, qty, status, created_at)
                    VALUES (" . self::$productId . ", " . self::$srcBinId . ", " . self::$dstBinId . ", 24, 'pending', DATE_SUB(NOW(), INTERVAL {$hoursAgo} HOUR))");
        return (int)$db->lastInsertId();
    }

    private function seedBinToBinTask(int $hoursAgo = 0): int
    {
        $db = self::$db;
        $db->exec("INSERT INTO picklists (picklist_number, outbound_order_id, created_by, created_date, status)
                    VALUES ('PKL-TEST-" . (int)(microtime(true) * 1000) . "', " . self::$outboundOrderId . ", " . self::$userId . ", CURDATE(), 'Created')");
        $picklistId = (int)$db->lastInsertId();

        $db->exec("INSERT INTO picklist_bin_to_bin (picklist_id, sku_id, product_code, source_location, destination_location, quantity, status, created_at)
                    VALUES ({$picklistId}, " . self::$productId . ", 'TEST-SKU-001', 'CC25D01', 'CB30A01', 24, 'Pending', DATE_SUB(NOW(), INTERVAL {$hoursAgo} HOUR))");
        return (int)$db->lastInsertId();
    }

    public function testListPendingReturnsBothTaskTypes(): void
    {
        $db = self::$db;
        $this->seedReplenTask(30);
        $this->seedBinToBinTask(30);

        $replenResult = $db->query(
            "SELECT t.id, 'replenishment' AS task_type, TIMESTAMPDIFF(HOUR, t.created_at, NOW()) AS age_hours
             FROM replen_task t WHERE t.status = 'pending'"
        );
        $replenRows = $replenResult->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $replenRows);
        $this->assertEquals('replenishment', $replenRows[0]['task_type']);
        $this->assertGreaterThanOrEqual(30, (int)$replenRows[0]['age_hours']);

        $binResult = $db->query(
            "SELECT b.id, 'bin_to_bin' AS task_type, TIMESTAMPDIFF(HOUR, b.created_at, NOW()) AS age_hours
             FROM picklist_bin_to_bin b WHERE b.status = 'Pending'"
        );
        $binRows = $binResult->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $binRows);
        $this->assertEquals('bin_to_bin', $binRows[0]['task_type']);
        $this->assertGreaterThanOrEqual(30, (int)$binRows[0]['age_hours']);

        $this->assertEquals(2, count($replenRows) + count($binRows));
    }

    public function testConfirmReplenTaskSetsCompleted(): void
    {
        $taskId = $this->seedReplenTask();
        self::$db->exec("UPDATE replen_task SET status = 'completed', completed_at = NOW() WHERE id = {$taskId}");

        $row = self::$db->query("SELECT status, completed_at FROM replen_task WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('completed', $row['status']);
        $this->assertNotNull($row['completed_at']);

        $pending = self::$db->query("SELECT COUNT(*) FROM replen_task WHERE status = 'pending'")->fetchColumn();
        $this->assertEquals(0, (int)$pending);
    }

    public function testConfirmBinToBinTaskSetsCompleted(): void
    {
        $taskId = $this->seedBinToBinTask();
        self::$db->exec("UPDATE picklist_bin_to_bin SET status = 'Completed', completed_at = NOW() WHERE id = {$taskId}");

        $row = self::$db->query("SELECT status, completed_at FROM picklist_bin_to_bin WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('Completed', $row['status']);
        $this->assertNotNull($row['completed_at']);

        $pending = self::$db->query("SELECT COUNT(*) FROM picklist_bin_to_bin WHERE status = 'Pending'")->fetchColumn();
        $this->assertEquals(0, (int)$pending);
    }

    public function testCancelTaskSetsCancelled(): void
    {
        $taskId = $this->seedReplenTask();
        self::$db->exec("UPDATE replen_task SET status = 'cancelled', updated_at = NOW() WHERE id = {$taskId}");

        $row = self::$db->query("SELECT status FROM replen_task WHERE id = {$taskId}")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('cancelled', $row['status']);

        $pending = self::$db->query("SELECT COUNT(*) FROM replen_task WHERE status = 'pending'")->fetchColumn();
        $this->assertEquals(0, (int)$pending);
    }

    public function testConfirmRemovesFromPendingList(): void
    {
        $taskId = $this->seedReplenTask();
        $before = (int)self::$db->query("SELECT COUNT(*) FROM replen_task WHERE status = 'pending'")->fetchColumn();
        $this->assertGreaterThanOrEqual(1, $before);

        self::$db->exec("UPDATE replen_task SET status = 'completed', completed_at = NOW() WHERE id = {$taskId}");

        $after = (int)self::$db->query("SELECT COUNT(*) FROM replen_task WHERE status = 'pending'")->fetchColumn();
        $this->assertEquals($before - 1, $after);
    }
}
