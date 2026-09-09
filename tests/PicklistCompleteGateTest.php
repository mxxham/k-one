<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/classes/Picklist.php';

use PHPUnit\Framework\TestCase;

/**
 * Tests for the Picklist::complete() gate — completion is blocked when
 * any picklist_item still has check_status = 'Pending'.
 */
class PicklistCompleteGateTest extends TestCase
{
    private PDO $db;
    private int $adminUserId;
    private int $operatorUserId;
    private int $outboundId;
    private int $picklistId;

    protected function setUp(): void
    {
        $this->db = ApiTestHelpers::pdo();
        $this->db->exec("SET FOREIGN_KEY_CHECKS=0");
        $this->cleanUp();

        // Seed required FK parents
        $this->db->exec("INSERT IGNORE INTO customers (id, customer_code, customer_name, address)
            VALUES (99902, 'PLG-CUST', 'Picklist Gate Customer', 'N/A')");
        $this->db->exec("INSERT IGNORE INTO products (id, product_code, product_name)
            VALUES (99902, 'SKU-PLG-001', 'Picklist Gate Product')");

        // Create test users
        $this->db->exec("INSERT INTO users (username, password, full_name, email, role, department, is_active)
            VALUES ('plg_admin', 'pass', 'PLG Admin', 'plg_admin@test.local', 'admin', 'outbound', 1)");
        $this->adminUserId = (int) $this->db->lastInsertId();

        $this->db->exec("INSERT INTO users (username, password, full_name, email, role, department, is_active)
            VALUES ('plg_picker', 'pass', 'PLG Picker', 'plg_picker@test.local', 'operator', 'outbound', 1)");
        $this->operatorUserId = (int) $this->db->lastInsertId();

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
        $this->db->exec("DELETE FROM picklist_items WHERE picklist_id IN (
            SELECT id FROM picklists WHERE outbound_order_id IN (
                SELECT id FROM outbound_orders WHERE order_number LIKE 'PLG-%'))");
        $this->db->exec("DELETE FROM picklists WHERE outbound_order_id IN (
            SELECT id FROM outbound_orders WHERE order_number LIKE 'PLG-%')");
        $this->db->exec("DELETE FROM outbound_items WHERE outbound_order_id IN (
            SELECT id FROM outbound_orders WHERE order_number LIKE 'PLG-%')");
        $this->db->exec("DELETE FROM outbound_orders WHERE order_number LIKE 'PLG-%'");
        $this->db->exec("DELETE FROM users WHERE username LIKE 'plg_%'");
    }

    private function createFixture(): void
    {
        $this->db->exec("INSERT INTO outbound_orders
            (order_number, order_date, status, customer_id, created_by, all_lines_checked)
            VALUES ('PLG-TEST-001', CURDATE(), 'Picked', 99902, {$this->operatorUserId}, 0)");
        $this->outboundId = (int) $this->db->lastInsertId();

        $this->db->exec("INSERT INTO outbound_items (outbound_order_id, product_id, quantity)
            VALUES ({$this->outboundId}, 99902, 10)");

        $this->db->exec("INSERT INTO picklists
            (outbound_order_id, picklist_number, created_date, status, created_by)
            VALUES ({$this->outboundId}, 'PLG-PL-001', CURDATE(), 'Picked', {$this->operatorUserId})");
        $this->picklistId = (int) $this->db->lastInsertId();
    }

    private function addPicklistItem(string $checkStatus = 'Pending'): int
    {
        $outboundItemId = (int) $this->db->query("SELECT id FROM outbound_items
            WHERE outbound_order_id = {$this->outboundId} LIMIT 1")->fetchColumn();

        $this->db->prepare("INSERT INTO picklist_items
            (outbound_item_id, picklist_id, product_id, batch_no, quantity, uom, pallet,
             picked_by, status, check_status, lpn_code, location)
            VALUES (?, ?, 99902, 'BATCH-PLG', 10, 'Pcs', 1, ?, 'Picked', ?, 'LPN-PLG-001', 'A01-01-01')")
            ->execute([$outboundItemId, $this->picklistId, $this->operatorUserId, $checkStatus]);
        return (int) $this->db->lastInsertId();
    }

    /** complete() should throw when items have check_status = 'Pending'. */
    public function testCompleteThrowsWhenItemsPending(): void
    {
        $this->createFixture();
        $this->addPicklistItem('Pending');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('belum dicek');
        Picklist::complete($this->picklistId);
    }

    /** complete() should succeed when all items are Checked (not Pending). */
    public function testCompleteSucceedsWhenAllChecked(): void
    {
        $this->createFixture();
        $this->addPicklistItem('Checked');

        $result = Picklist::complete($this->picklistId);
        $this->assertTrue($result);

        $row = $this->db->query("SELECT status FROM picklists WHERE id = {$this->picklistId}")->fetch();
        $this->assertSame('Completed', $row['status']);
    }

    /** complete() should succeed when all items are Discrepancy (not Pending). */
    public function testCompleteSucceedsWhenAllDiscrepancy(): void
    {
        $this->createFixture();
        $this->addPicklistItem('Discrepancy');

        $result = Picklist::complete($this->picklistId);
        $this->assertTrue($result);
    }

    /** complete() should throw when mix of Checked and Pending. */
    public function testCompleteThrowsOnMixedStatus(): void
    {
        $this->createFixture();
        $this->addPicklistItem('Checked');
        $this->addPicklistItem('Pending');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('belum dicek');
        Picklist::complete($this->picklistId);
    }

    /** complete() should throw when picklist has no items. */
    public function testCompleteThrowsOnEmptyPicklist(): void
    {
        $this->createFixture();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak memiliki item');
        Picklist::complete($this->picklistId);
    }
}
