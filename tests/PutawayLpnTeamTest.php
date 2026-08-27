<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Putaway LPN label + team dual-scan + scan override.
 * Tests LPN data generation, label printing, my_tasks, and the scan override flow.
 */
final class PutawayLpnTeamTest extends TestCase
{
    private static string $token = '';
    private static int $productId = 0;
    private static int $inboundId = 0;
    private static int $inboundItemId = 0;
    private static int $taskId = 0;
    private static int $taskItemId = 0;
    private static int $forkliftId = 0;
    private static int $partnerId = 0;

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();

        // Seed product
        self::$productId = ApiTestHelpers::createProduct([
            'uom_type' => 'Drum',
            'uom_per_pallet' => 4,
        ]);

        // Seed two operator users for team assignment
        $pdo = ApiTestHelpers::pdo();
        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'ops', 1)"
        )->execute(['fo_lpn_' . rand(1000, 9999), password_hash('x', PASSWORD_BCRYPT), 'Forklift LPN', 'fo_lpn@test']);
        self::$forkliftId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'ops', 1)"
        )->execute(['cp_lpn_' . rand(1000, 9999), password_hash('x', PASSWORD_BCRYPT), 'Partner LPN', 'cp_lpn@test']);
        self::$partnerId = (int) $pdo->lastInsertId();

        // Create inbound + item + advance to ATP + pallet locations
        $created = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number'  => 'PO-LPN-' . random_int(0, 99999),
            'order_date' => '2026-08-15',
        ]);
        self::$inboundId = (int) $created['body']['id'];

        $add = ApiTestHelpers::api('inbound', 'add_item', self::$token, [
            'inbound_id' => self::$inboundId,
            'item' => [
                'product_id'        => self::$productId,
                'quantity'          => 8,
                'uom'               => 'Drum',
                'batch_number'      => 'LPN-BATCH',
                'expiry_date'       => '2029-06-30',
                'in_process_status' => 'Dues In',
                'pallet_no'         => 'P1',
            ],
        ]);
        self::$inboundItemId = (int) $add['body']['item_id'];

        ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id' => self::$inboundId, 'status' => 'Receiving',
            'received_by_id' => 1, 'received_date' => '2026-08-15',
        ]);
        ApiTestHelpers::api('inbound', 'update_item_status', self::$token, [
            'item_id' => self::$inboundItemId, 'status' => 'Goods Received',
        ]);
        ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id' => self::$inboundId, 'status' => 'ATP',
        ]);
        ApiTestHelpers::api('inbound', 'save_pallet_locations', self::$token, [
            'inbound_id' => self::$inboundId, 'item_id' => self::$inboundItemId,
            'pallet_locations' => [
                ['location_code' => 'CA01A01', 'pallet_seq' => 1, 'quantity' => 8, 'is_full' => 1, 'batch_number' => 'LPN-BATCH'],
            ],
        ]);

        // Create putaway task
        $task = ApiTestHelpers::api('putaway', 'create_task', self::$token, [
            'inbound_order_id' => self::$inboundId,
        ]);
        self::$taskId = (int) $task['body']['id'];

        // Get the first task item
        $items = ApiTestHelpers::q('SELECT id FROM putaway_task_items WHERE task_id = ?', [self::$taskId]);
        self::$taskItemId = (int) $items[0]['id'];

        // Assign team
        ApiTestHelpers::api('putaway', 'assign_task', self::$token, [
            'id' => self::$taskId,
            'forklift_operator_id' => self::$forkliftId,
            'checklist_partner_id' => self::$partnerId,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* LPN label data                                                      */
    /* ------------------------------------------------------------------ */

    public function testGetLpnLabelData(): void
    {
        $res = ApiTestHelpers::api('putaway', 'get_lpn_label_data', self::$token, [], ['id' => self::$taskItemId]);
        $this->assertSame(200, $res['status']);
        $this->assertArrayHasKey('lpn_code', $res['body']);
        $this->assertMatchesRegularExpression('/^LPN-/', $res['body']['lpn_code']);
        $this->assertNotEmpty($res['body']['product_code']);
        $this->assertSame('LPN-BATCH', $res['body']['batch_number']);
    }

    public function testGetLpnLabelDataReturns404ForBadId(): void
    {
        $res = ApiTestHelpers::api('putaway', 'get_lpn_label_data', self::$token, [], ['id' => 999999]);
        $this->assertSame(404, $res['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Print LPN label                                                     */
    /* ------------------------------------------------------------------ */

    public function testPrintLpnLabel(): void
    {
        $res = ApiTestHelpers::api('putaway', 'print_lpn_label', self::$token, ['id' => self::$taskItemId]);
        $this->assertSame(200, $res['status']);
        $this->assertArrayHasKey('html', $res['body']);
        $this->assertNotEmpty($res['body']['html']);
        $this->assertStringContainsString('LPN', $res['body']['html']);
    }

    /* ------------------------------------------------------------------ */
    /* Assignable users                                                    */
    /* ------------------------------------------------------------------ */

    public function testAssignableUsers(): void
    {
        $res = ApiTestHelpers::api('putaway', 'assignable_users', self::$token);
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['body']['rows']);
        $this->assertGreaterThanOrEqual(2, count($res['body']['rows']));
    }

    /* ------------------------------------------------------------------ */
    /* my_tasks (mobile)                                                   */
    /* ------------------------------------------------------------------ */

    public function testMyTasks(): void
    {
        // Forklift operator should see the task
        $res = ApiTestHelpers::api('putaway', 'my_tasks', self::$token);
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['body']['rows']);
    }

    /* ------------------------------------------------------------------ */
    /* Scan override                                                       */
    /* ------------------------------------------------------------------ */

    public function testScanOverride(): void
    {
        $res = ApiTestHelpers::api('putaway', 'scan_override', self::$token, [
            'id'            => self::$taskItemId,
            'location_code' => 'CA02B01',
            'reason'        => 'Rak CA01A01 penuh, geser ke CA02B01',
        ]);
        $this->assertTrue($res['body']['success']);

        // Verify actual_location updated
        $rows = ApiTestHelpers::q('SELECT actual_location, scan_override_reason FROM putaway_task_items WHERE id = ?', [self::$taskItemId]);
        $this->assertSame('CA02B01', $rows[0]['actual_location']);
        $this->assertNotEmpty($rows[0]['scan_override_reason']);
    }

    public function testScanOverrideRejectsEmptyReason(): void
    {
        $res = ApiTestHelpers::api('putaway', 'scan_override', self::$token, [
            'id'            => self::$taskItemId,
            'location_code' => 'CA03C01',
            'reason'        => '',
        ]);
        $this->assertSame(409, $res['status']);
    }

    /* ------------------------------------------------------------------ */
    /* task_complete_pallet (dual-scan confirm)                            */
    /* ------------------------------------------------------------------ */

    public function testCompleteTaskPalletWithMatchingLocation(): void
    {
        // First update the task pallet to CA01A01 (matching the suggestion)
        ApiTestHelpers::api('putaway', 'task_update_pallet', self::$token, [
            'id'            => self::$taskItemId,
            'location_code' => 'CA01A01',
        ]);

        $res = ApiTestHelpers::api('putaway', 'task_complete_pallet', self::$token, [
            'id' => self::$taskItemId,
        ]);
        $this->assertTrue($res['body']['success']);

        // Verify pallet is now Confirmed
        $rows = ApiTestHelpers::q('SELECT status FROM putaway_task_items WHERE id = ?', [self::$taskItemId]);
        $this->assertSame('Confirmed', $rows[0]['status']);
    }

    public function testCompleteTaskPalletRejectsAlreadyConfirmed(): void
    {
        $res = ApiTestHelpers::api('putaway', 'task_complete_pallet', self::$token, [
            'id' => self::$taskItemId,
        ]);
        $this->assertSame(409, $res['status']);
    }

    /* ------------------------------------------------------------------ */
    /* task_update_pallet                                                  */
    /* ------------------------------------------------------------------ */

    public function testUpdateTaskPalletRejectsConfirmedItem(): void
    {
        $res = ApiTestHelpers::api('putaway', 'task_update_pallet', self::$token, [
            'id'            => self::$taskItemId,
            'location_code' => 'CA03A01',
        ]);
        $this->assertSame(409, $res['status']);
    }
}
