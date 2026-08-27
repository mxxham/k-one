<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Putaway task queue — task creation, listing, detail, assignment, completion, cancellation.
 * Tests the v2 putaway task lifecycle end-to-end via the API.
 */
final class PutawayTaskTest extends TestCase
{
    private static string $token = '';
    private static int $productId = 0;
    private static int $inboundId = 0;
    private static int $inboundItemId = 0;
    private static int $taskId = 0;

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$token = ApiTestHelpers::login();

        // Seed a product
        self::$productId = ApiTestHelpers::createProduct([
            'uom_type' => 'Drum',
            'uom_per_pallet' => 4,
        ]);

        // Create an inbound order
        $created = ApiTestHelpers::api('inbound', 'create', self::$token, [
            'po_number'  => 'PO-PUT-' . random_int(0, 99999),
            'order_date' => '2026-08-15',
        ]);
        self::$inboundId = (int) $created['body']['id'];
        self::assertGreaterThan(0, self::$inboundId);

        // Add an item
        $add = ApiTestHelpers::api('inbound', 'add_item', self::$token, [
            'inbound_id' => self::$inboundId,
            'item' => [
                'product_id'        => self::$productId,
                'quantity'          => 12,
                'uom'               => 'Drum',
                'batch_number'      => 'PB-TASK',
                'expiry_date'       => '2029-12-31',
                'in_process_status' => 'Dues In',
                'pallet_no'         => 'P1',
            ],
        ]);
        self::$inboundItemId = (int) $add['body']['item_id'];

        // Advance to Receiving
        ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id'     => self::$inboundId,
            'status' => 'Receiving',
            'received_by_id' => 1,
            'received_date'  => '2026-08-15',
        ]);

        // Mark item as Goods Received
        ApiTestHelpers::api('inbound', 'update_item_status', self::$token, [
            'item_id' => self::$inboundItemId,
            'status'  => 'Goods Received',
        ]);

        // Advance to ATP
        ApiTestHelpers::api('inbound', 'advance_status', self::$token, [
            'id'     => self::$inboundId,
            'status' => 'ATP',
        ]);

        // Save pallet locations so item has an assigned location
        ApiTestHelpers::api('inbound', 'save_pallet_locations', self::$token, [
            'inbound_id'      => self::$inboundId,
            'item_id'         => self::$inboundItemId,
            'pallet_locations' => [
                ['location_code' => 'CA01A01', 'pallet_seq' => 1, 'quantity' => 12, 'is_full' => 1, 'batch_number' => 'PB-TASK'],
            ],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Task creation                                                       */
    /* ------------------------------------------------------------------ */

    public function testCreateTask(): void
    {
        $res = ApiTestHelpers::api('putaway', 'create_task', self::$token, [
            'inbound_order_id' => self::$inboundId,
        ]);
        $this->assertTrue($res['body']['success']);
        self::$taskId = (int) $res['body']['id'];
        $this->assertGreaterThan(0, self::$taskId);

        // Verify task row
        $rows = ApiTestHelpers::q('SELECT status, task_number FROM putaway_tasks WHERE id = ?', [self::$taskId]);
        $this->assertCount(1, $rows);
        $this->assertSame('Open', $rows[0]['status']);
        $this->assertMatchesRegularExpression('/^PKA-/', $rows[0]['task_number']);

        // Verify task items were created
        $items = ApiTestHelpers::q('SELECT * FROM putaway_task_items WHERE task_id = ?', [self::$taskId]);
        $this->assertGreaterThanOrEqual(1, count($items));
        $this->assertSame('Pending', $items[0]['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Task listing & detail                                               */
    /* ------------------------------------------------------------------ */

    public function testListTasks(): void
    {
        $res = ApiTestHelpers::api('putaway', 'task_list', self::$token);
        $this->assertSame(200, $res['status']);
        $this->assertIsArray($res['body']['rows']);

        $found = array_filter($res['body']['rows'], fn($r) => (int)$r['id'] === self::$taskId);
        $this->assertNotEmpty($found);
    }

    public function testListTasksFilterByStatus(): void
    {
        $res = ApiTestHelpers::api('putaway', 'task_list', self::$token, [], ['status' => 'Open']);
        $this->assertSame(200, $res['status']);
        foreach ($res['body']['rows'] as $row) {
            $this->assertSame('Open', $row['status']);
        }
    }

    public function testTaskDetail(): void
    {
        $res = ApiTestHelpers::api('putaway', 'task_detail', self::$token, [], ['id' => self::$taskId]);
        $this->assertSame(200, $res['status']);
        $this->assertArrayHasKey('task', $res['body']);
        $this->assertArrayHasKey('items', $res['body']);
        $this->assertSame(self::$taskId, (int)$res['body']['task']['id']);
        $this->assertGreaterThanOrEqual(1, count($res['body']['items']));
    }

    public function testTaskDetailReturns404ForBadId(): void
    {
        $res = ApiTestHelpers::api('putaway', 'task_detail', self::$token, [], ['id' => 999999]);
        $this->assertSame(404, $res['status']);
    }

    /* ------------------------------------------------------------------ */
    /* Team assignment                                                     */
    /* ------------------------------------------------------------------ */

    public function testAssignTeam(): void
    {
        // Seed two more users for team assignment
        $pdo = ApiTestHelpers::pdo();
        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'ops', 1)"
        )->execute(['forkop_' . rand(1000, 9999), password_hash('x', PASSWORD_BCRYPT), 'Forklift Op', 'fo@test']);
        $forkliftId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'ops', 1)"
        )->execute(['partner_' . rand(1000, 9999), password_hash('x', PASSWORD_BCRYPT), 'Check Partner', 'cp@test']);
        $partnerId = (int) $pdo->lastInsertId();

        $res = ApiTestHelpers::api('putaway', 'assign_task', self::$token, [
            'id' => self::$taskId,
            'forklift_operator_id' => $forkliftId,
            'checklist_partner_id' => $partnerId,
        ]);
        $this->assertTrue($res['body']['success']);

        // Verify task is now In Progress
        $rows = ApiTestHelpers::q('SELECT status, forklift_operator_id, checklist_partner_id FROM putaway_tasks WHERE id = ?', [self::$taskId]);
        $this->assertSame('In Progress', $rows[0]['status']);
        $this->assertSame($forkliftId, (int)$rows[0]['forklift_operator_id']);
        $this->assertSame($partnerId, (int)$rows[0]['checklist_partner_id']);
    }

    public function testAssignTeamRejectsSameUser(): void
    {
        $pdo = ApiTestHelpers::pdo();
        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'ops', 1)"
        )->execute(['solo_' . rand(1000, 9999), password_hash('x', PASSWORD_BCRYPT), 'Solo User', 'su@test']);
        $userId = (int) $pdo->lastInsertId();

        $res = ApiTestHelpers::api('putaway', 'assign_task', self::$token, [
            'id' => self::$taskId,
            'forklift_operator_id' => $userId,
            'checklist_partner_id' => $userId,
        ]);
        $this->assertSame(409, $res['status']);
    }

    public function testUnassignTeam(): void
    {
        $res = ApiTestHelpers::api('putaway', 'unassign_task', self::$token, ['id' => self::$taskId]);
        $this->assertTrue($res['body']['success']);

        $rows = ApiTestHelpers::q('SELECT status, forklift_operator_id FROM putaway_tasks WHERE id = ?', [self::$taskId]);
        $this->assertSame('Open', $rows[0]['status']);
        $this->assertNull($rows[0]['forklift_operator_id']);
    }

    /* ------------------------------------------------------------------ */
    /* Task cancellation                                                   */
    /* ------------------------------------------------------------------ */

    public function testCancelTask(): void
    {
        // Create a second task to cancel
        $res = ApiTestHelpers::api('putaway', 'create_task', self::$token, [
            'inbound_order_id' => self::$inboundId,
        ]);
        $cancelId = (int) $res['body']['id'];
        $this->assertGreaterThan(0, $cancelId);

        $cancel = ApiTestHelpers::api('putaway', 'task_cancel', self::$token, ['id' => $cancelId]);
        $this->assertTrue($cancel['body']['success']);

        $rows = ApiTestHelpers::q('SELECT status FROM putaway_tasks WHERE id = ?', [$cancelId]);
        $this->assertSame('Cancelled', $rows[0]['status']);
    }

    public function testCannotCancelNonOpenTask(): void
    {
        // self::$taskId is Open; assign it first
        $pdo = ApiTestHelpers::pdo();
        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'ops', 1)"
        )->execute(['fo2_' . rand(1000, 9999), password_hash('x', PASSWORD_BCRYPT), 'FO2', 'fo2@test']);
        $foId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'ops', 1)"
        )->execute(['cp2_' . rand(1000, 9999), password_hash('x', PASSWORD_BCRYPT), 'CP2', 'cp2@test']);
        $cpId = (int) $pdo->lastInsertId();

        ApiTestHelpers::api('putaway', 'assign_task', self::$token, [
            'id' => self::$taskId,
            'forklift_operator_id' => $foId,
            'checklist_partner_id' => $cpId,
        ]);

        $res = ApiTestHelpers::api('putaway', 'task_cancel', self::$token, ['id' => self::$taskId]);
        $this->assertSame(409, $res['status']);
    }

    /* ------------------------------------------------------------------ */
    /* has_open_pallets                                                    */
    /* ------------------------------------------------------------------ */

    public function testHasOpenPallets(): void
    {
        $res = ApiTestHelpers::api('putaway', 'has_open_pallets', self::$token, [], [
            'inbound_order_id' => self::$inboundId,
        ]);
        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['body']['has_open']);
    }
}
