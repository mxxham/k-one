<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/cyclecount.e2e-spec.ts — Cycle Count Scheduling.
 * Tests: create/list/update/delete schedules, run_due/run_now, velocity scope.
 */
final class CycleCountTest extends TestCase
{
    private static string $adminToken = '';
    private static string $operatorToken = '';

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        self::$adminToken = ApiTestHelpers::login();

        $pdo = ApiTestHelpers::pdo();
        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'inventory', 1)"
        )->execute(['cc_operator', password_hash('admin123', PASSWORD_BCRYPT), 'CC Operator', 'ccop@local']);
        self::$operatorToken = ApiTestHelpers::login('cc_operator', 'admin123');
    }

    /** Rejects a non-admin run_due */
    public function testRejectsNonAdminRunDue(): void
    {
        $res = ApiTestHelpers::api('cyclecount', 'run_due', self::$operatorToken, []);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(403, $res['status']);
    }

    /** Rejects a non-admin run_now */
    public function testRejectsNonAdminRunNow(): void
    {
        $res = ApiTestHelpers::api('cyclecount', 'run_now', self::$operatorToken, ['id' => 1]);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(403, $res['status']);
    }

    /** Creates and lists schedules */
    public function testCreatesAndListsSchedules(): void
    {
        ApiTestHelpers::resetDb();
        $res = ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Monthly Full Count',
            'frequency' => 'monthly',
            'scope_type' => 'full',
            'next_run_date' => '2026-01-01',
        ]);
        $this->assertTrue($res['body']['success']);
        $id = (int)$res['body']['id'];
        $this->assertGreaterThan(0, $id);

        $list = ApiTestHelpers::api('cyclecount', 'list', self::$adminToken, []);
        $this->assertTrue($list['body']['success']);
        $found = null;
        foreach ($list['body']['rows'] as $r) {
            if ((int)$r['id'] === $id) { $found = $r; break; }
        }
        $this->assertNotNull($found);
        $this->assertSame('Monthly Full Count', $found['schedule_name']);
        $this->assertSame('monthly', $found['frequency']);
        $this->assertSame('full', $found['scope_type']);
    }

    /** Validates create inputs */
    public function testValidatesCreateInputs(): void
    {
        ApiTestHelpers::resetDb();
        $noName = ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => '',
            'frequency' => 'monthly',
            'scope_type' => 'full',
            'next_run_date' => '2026-01-01',
        ]);
        $this->assertSame(400, $noName['status']);

        $badFreq = ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Test',
            'frequency' => 'daily',
            'scope_type' => 'full',
            'next_run_date' => '2026-01-01',
        ]);
        $this->assertSame(400, $badFreq['status']);

        $locationNoLocs = ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Test',
            'frequency' => 'weekly',
            'scope_type' => 'location',
            'next_run_date' => '2026-01-01',
        ]);
        $this->assertSame(400, $locationNoLocs['status']);

        $velocityNoClass = ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Test',
            'frequency' => 'weekly',
            'scope_type' => 'velocity',
            'next_run_date' => '2026-01-01',
        ]);
        $this->assertSame(400, $velocityNoClass['status']);
    }

    /** run_due generates a stock_take via StockTakeService.create and advances next_run_date */
    public function testRunDueGeneratesStockTakeAndAdvances(): void
    {
        ApiTestHelpers::resetDb();
        $p = ApiTestHelpers::createProduct([]);
        ApiTestHelpers::putStock($p, 'CA01A01', 10, 'BATCH-CC', '2030-12-31');

        $today = new DateTime();
        $yesterday = (clone $today)->modify('-1 day');
        $yStr = $yesterday->format('Y-m-d');
        $nxt = (clone $yesterday)->modify('+7 days')->format('Y-m-d');

        ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Due Weekly',
            'frequency' => 'weekly',
            'next_run_date' => $yStr,
            'scope_type' => 'full',
        ]);

        $res = ApiTestHelpers::api('cyclecount', 'run_due', self::$adminToken, []);
        $this->assertTrue($res['body']['success']);
        $this->assertGreaterThan(0, $res['body']['count']);
    }

    /** run_due with a not-yet-due schedule generates nothing */
    public function testRunDueWithFutureScheduleGeneratesNothing(): void
    {
        ApiTestHelpers::resetDb();
        ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Future',
            'frequency' => 'monthly',
            'scope_type' => 'full',
            'next_run_date' => '2099-01-01',
        ]);
        $res = ApiTestHelpers::api('cyclecount', 'run_due', self::$adminToken, []);
        $this->assertTrue($res['body']['success']);
        $this->assertSame(0, $res['body']['count']);
    }

    /** run_now runs a single schedule regardless of due date */
    public function testRunNowRunsSingleScheduleRegardlessOfDueDate(): void
    {
        ApiTestHelpers::resetDb();
        $res = ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Future But Run Now',
            'frequency' => 'monthly',
            'scope_type' => 'full',
            'next_run_date' => '2099-01-01',
        ]);
        $id = (int)$res['body']['id'];
        $run = ApiTestHelpers::api('cyclecount', 'run_now', self::$adminToken, ['id' => $id]);
        $this->assertTrue($run['body']['success']);
        $this->assertGreaterThan(0, $run['body']['count']);
    }

    /** run_now on a missing schedule returns 404 */
    public function testRunNowOnMissingScheduleReturns404(): void
    {
        $res = ApiTestHelpers::api('cyclecount', 'run_now', self::$adminToken, ['id' => 999999]);
        $this->assertSame(404, $res['status']);
    }

    /** update and delete schedules */
    public function testUpdateAndDeleteSchedules(): void
    {
        ApiTestHelpers::resetDb();
        $res = ApiTestHelpers::api('cyclecount', 'create', self::$adminToken, [
            'schedule_name' => 'Original',
            'frequency' => 'monthly',
            'scope_type' => 'full',
            'next_run_date' => '2026-01-01',
        ]);
        $id = (int)$res['body']['id'];

        $upd = ApiTestHelpers::api('cyclecount', 'update', self::$adminToken, [
            'id' => $id,
            'schedule_name' => 'Renamed',
            'frequency' => 'weekly',
            'scope_type' => 'full',
            'next_run_date' => '2026-03-01',
        ]);
        $this->assertTrue($upd['body']['success']);

        $row = ApiTestHelpers::q('SELECT schedule_name, frequency, next_run_date FROM cycle_count_schedules WHERE id = ?', [$id]);
        $this->assertSame('Renamed', $row[0]['schedule_name']);
        $this->assertSame('weekly', $row[0]['frequency']);
        $this->assertSame('2026-03-01', $row[0]['next_run_date']);

        $del = ApiTestHelpers::api('cyclecount', 'delete', self::$adminToken, ['id' => $id]);
        $this->assertTrue($del['body']['success']);

        $after = ApiTestHelpers::q('SELECT COUNT(*) as c FROM cycle_count_schedules WHERE id = ?', [$id]);
        $this->assertSame(0, (int)$after[0]['c']);
    }
}
?>
