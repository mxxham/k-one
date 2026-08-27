<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Port of v2 apps/api/test/abc.e2e-spec.ts — ABC Analysis / Velocity ranking.
 * Tests: analyze/recompute with custom splits, velocity_class persistence, role restrictions.
 */
final class AbcTest extends TestCase
{
    private static string $adminToken = '';
    private static string $operatorToken = '';

    public static function setUpBeforeClass(): void
    {
        ApiTestHelpers::resetDb();
        $adminToken = ApiTestHelpers::login();
        self::$adminToken = $adminToken;

        $pdo = ApiTestHelpers::pdo();
        $pdo->prepare(
            "INSERT INTO users (username, password, full_name, email, role, department, is_active)
             VALUES (?, ?, ?, ?, 'operator', 'all', 1)"
        )->execute(['abc_operator', password_hash('admin123', PASSWORD_BCRYPT), 'ABC Operator', 'op@local']);
        self::$operatorToken = ApiTestHelpers::login('abc_operator', 'admin123');
    }

    /** Rejects a non-admin recompute */
    public function testRejectsNonAdminRecompute(): void
    {
        $res = ApiTestHelpers::api('abc', 'recompute', self::$operatorToken, []);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(403, $res['status']);
    }

    /** Analyze ranks products by OUT volume into A/B/C tiers */
    public function testAnalyzeRanksProductsByVolume(): void
    {
        $pa = ApiTestHelpers::createProduct([]);
        $pb = ApiTestHelpers::createProduct([]);
        $pc = ApiTestHelpers::createProduct([]);

        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test abc')",
            [date('Y-m-d'), $pa, 70]
        );
        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test abc')",
            [date('Y-m-d'), $pb, 20]
        );
        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test abc')",
            [date('Y-m-d'), $pc, 10]
        );

        $res = ApiTestHelpers::api('abc', 'analyze', self::$adminToken, [], ['date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')]);
        $this->assertTrue($res['body']['success']);
        $this->assertSame(100, $res['body']['total_qty']);

        $byId = [];
        foreach ($res['body']['rows'] as $r) {
            $byId[(int)$r['product_id']] = $r['velocity_class'];
        }

        $this->assertSame('A', $byId[$pa]);
        $this->assertSame('B', $byId[$pb]);
        $this->assertSame('C', $byId[$pc]);
        $this->assertSame(1, $res['body']['counts']['A']);
        $this->assertSame(1, $res['body']['counts']['B']);
        $this->assertSame(1, $res['body']['counts']['C']);
    }

    /** Supports a custom split */
    public function testSupportsCustomSplit(): void
    {
        $pa = ApiTestHelpers::createProduct([]);
        $pb = ApiTestHelpers::createProduct([]);
        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test')",
            [date('Y-m-d'), $pa, 90]
        );
        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test')",
            [date('Y-m-d'), $pb, 10]
        );

        $res = ApiTestHelpers::api('abc', 'analyze', self::$adminToken, [], [
            'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d'),
            'split_a' => 50, 'split_b' => 50,
        ]);
        $this->assertTrue($res['body']['success']);
        $found = null;
        foreach ($res['body']['rows'] as $r) {
            if ((int)$r['product_id'] === $pa) { $found = $r; break; }
        }
        $this->assertNotNull($found);
        $this->assertSame('A', $found['velocity_class']);
    }

    /** Rejects an invalid split */
    public function testRejectsInvalidSplit(): void
    {
        $res = ApiTestHelpers::api('abc', 'analyze', self::$adminToken, [], [
            'date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d'),
            'split_a' => 60, 'split_b' => 60,
        ]);
        $this->assertFalse($res['body']['success']);
        $this->assertSame(400, $res['status']);
    }

    /** Recompute writes velocity_class onto products */
    public function testRecomputeWritesVelocityClass(): void
    {
        ApiTestHelpers::resetDb();
        $pa = ApiTestHelpers::createProduct([]);
        $pb = ApiTestHelpers::createProduct([]);
        $pc = ApiTestHelpers::createProduct([]);
        $idle = ApiTestHelpers::createProduct([]);

        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test')",
            [date('Y-m-d'), $pa, 80]
        );
        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test')",
            [date('Y-m-d'), $pb, 15]
        );
        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test')",
            [date('Y-m-d'), $pc, 5]
        );

        $res = ApiTestHelpers::api('abc', 'recompute', self::$adminToken, [], ['date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')]);
        $this->assertTrue($res['body']['success']);
        $this->assertSame(1, $res['body']['counts']['A']);

        $rows = ApiTestHelpers::q('SELECT id, velocity_class FROM products WHERE id IN (?, ?, ?, ?)', [$pa, $pb, $pc, $idle]);
        $byId = [];
        foreach ($rows as $r) {
            $byId[(int)$r['id']] = $r['velocity_class'];
        }

        $this->assertSame('A', $byId[$pa]);
        $this->assertSame('B', $byId[$pb]);
        $this->assertSame('C', $byId[$pc]);
        $this->assertNull($byId[$idle]);
    }

    /** Exposes velocity_class on products and stock lists */
    public function testExposesVelocityClassOnProductsList(): void
    {
        ApiTestHelpers::resetDb();
        $p = ApiTestHelpers::createProduct([]);
        ApiTestHelpers::q(
            "INSERT INTO stock_ledger (transaction_date, product_id, transaction_type, quantity_out, uom, balance, notes)
             VALUES (?, ?, 'OUT', ?, 'Drum', 0, 'test')",
            [date('Y-m-d'), $p, 100]
        );
        ApiTestHelpers::api('abc', 'recompute', self::$adminToken, [], ['date_from' => date('Y-m-01'), 'date_to' => date('Y-m-d')]);

        $prod = ApiTestHelpers::api('products', 'list', self::$adminToken, [], ['page' => 1, 'per_page' => 100]);
        $found = null;
        foreach ($prod['body']['rows'] as $r) {
            if ((int)$r['id'] === $p) { $found = $r; break; }
        }
        $this->assertNotNull($found);
        $this->assertSame('A', $found['velocity_class']);

        $stock = ApiTestHelpers::api('stock', 'list', self::$adminToken, []);
        $this->assertTrue($stock['body']['success']);
    }
}
?>
