<?php
declare(strict_types=1);

/**
 * Test helpers — ports v2 apps/api/test/helpers.ts to PHP.
 * All HTTP traffic goes through the spawned php -S test server (TEST_SERVER_BASE),
 * which is pointed at the isolated kone_test DB.
 */
final class ApiTestHelpers
{
    /** @var PDO|null */
    private static $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            self::$pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', TEST_DB_HOST, TEST_DB_PORT, TEST_DB_NAME),
                TEST_DB_USER,
                TEST_DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]
            );
        }
        return self::$pdo;
    }

    /**
     * Port of v2 helpers.api(): POST /index.php?module=..&action=.. with an
     * optional Bearer token and JSON body. Returns ['status' => int, 'body' => ?array].
     */
    public static function api(string $module, string $action, string $token = '', array $body = [], array $query = []): array
    {
        $url = TEST_SERVER_BASE . '/api/index.php?' . http_build_query(array_merge(['module' => $module, 'action' => $action], $query));
        $ch = curl_init($url);
        $headers = ['Content-Type: application/json'];
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = json_decode((string) $raw, true);
        return [
            'status' => $status,
            'body'   => is_array($decoded) ? $decoded : null,
        ];
    }

    /**
     * Port of v2 helpers.login(): logs in the seeded testadmin and returns the token.
     */
    public static function login(string $username = 'testadmin', string $password = 'admin123'): string
    {
        $res = self::api('auth', 'login', '', ['username' => $username, 'password' => $password]);
        if (empty($res['body']['token'])) {
            throw new RuntimeException('login failed: ' . json_encode($res['body']));
        }
        return $res['body']['token'];
    }

    /** Port of v2 helpers.q(): run SQL against the isolated test DB, return rows. */
    public static function q(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Port of v2 helpers.createProduct(): minimal product row, returns its id. */
    public static function createProduct(array $overrides = []): int
    {
        $code = 'TST' . random_int(0, 999999);
        $stmt = self::pdo()->prepare(
            "INSERT INTO products (product_code, product_name, uom_type, uom_per_pallet, liters_per_unit, is_active)
             VALUES (?, ?, ?, ?, 209.00, 1)"
        );
        $stmt->execute([
            $overrides['product_code'] ?? $code,
            $overrides['product_name'] ?? 'Test Product ' . $code,
            $overrides['uom_type'] ?? 'Drum',
            $overrides['uom_per_pallet'] ?? 4,
        ]);
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Port of v2 helpers.putStock(): insert stock directly into a rack bin and
     * mark the stock_locations row Available.
     */
    public static function putStock(int $productId, string $location, float $quantity, string $batch = 'BATCH1', string $expiry = '2030-12-31'): void
    {
        $pdo = self::pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO stock (product_id, batch_number, location, quantity, uom, pallet, expiry_date, stock_status)
             VALUES (?, ?, ?, ?, 'Drum', ?, ?, 'Available')"
        );
        $stmt->execute([$productId, $batch, $location, $quantity, (int) ceil($quantity / 4), $expiry]);
        $stockId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare(
            "INSERT INTO stock_locations (stock_id, location_code, pallet_seq, quantity, original_quantity, uom, is_full_pallet, batch_number, status)
             VALUES (?, ?, 1, ?, ?, 'Drum', 1, ?, 'Available')"
        );
        $stmt->execute([$stockId, $location, $quantity, $quantity, $batch]);
    }

    /**
     * Port of v2 helpers.controlledBins(): the default test rack map
     * (CA/CB, bays 1-3, levels A-E, 2 pos => 60 bins; Level A pick-face).
     */
    public static function controlledBinsSql(): array
    {
        $sql = [];
        foreach (['CA', 'CB'] as $aisle) {
            for ($bay = 1; $bay <= 3; $bay++) {
                foreach (['A', 'B', 'C', 'D', 'E'] as $level) {
                    foreach ([1, 2] as $pos) {
                        $code = sprintf('%s%02d%s%02d', $aisle, $bay, $level, $pos);
                        $sql[] = sprintf(
                            "INSERT IGNORE INTO location_master (location_code, aisle, rack, row_name, position, is_pick_face, equipment_accessible, is_active)
                             VALUES ('%s','%s','%02d','%s','%02d', %d, %d, 1)",
                            $code, $aisle, $bay, $level, $pos, $level === 'A' ? 1 : 0, $level === 'A' ? 1 : 0
                        );
                    }
                }
            }
        }
        return $sql;
    }

    /** Port of v2 helpers.resetDb(): wipe transactional state + reseed controlled bins. */
    public static function resetDb(): void
    {
        $pdo = self::pdo();
        self::truncateTransactional($pdo);
        $pdo->exec("DELETE FROM location_master WHERE aisle IN ('CA','CB','CC','CD','CE','CF','CG')");
        foreach (self::controlledBinsSql() as $binSql) {
            $pdo->exec($binSql);
        }
    }

    /**
     * Port of v2 helpers.seedFullMap(): seed the full production warehouse map
     * (2560 bins across aisles CA-CG). Returns the total seeded.
     */
    public static function seedFullMap(): int
    {
        $counts = ['CA' => 190, 'CB' => 400, 'CC' => 380, 'CD' => 400, 'CE' => 390, 'CF' => 400, 'CG' => 400];
        $sql = [];
        $total = 0;
        foreach ($counts as $aisle => $limit) {
            $n = 0;
            for ($bay = 1; $bay <= 20 && $n < $limit; $bay++) {
                foreach (['A', 'B', 'C', 'D', 'E'] as $level) {
                    for ($pos = 1; $pos <= 4 && $n < $limit; $pos++) {
                        $code = sprintf('%s%02d%s%02d', $aisle, $bay, $level, $pos);
                        $sql[] = sprintf(
                            "INSERT IGNORE INTO location_master (location_code, aisle, rack, row_name, position, is_pick_face, equipment_accessible, is_active)
                             VALUES ('%s','%s','%02d','%s','%02d', %d, %d, 1)",
                            $code, $aisle, $bay, $level, $pos, $level === 'A' ? 1 : 0, $level === 'A' ? 1 : 0
                        );
                        $n++;
                        $total++;
                    }
                }
            }
        }
        $pdo = self::pdo();
        foreach ($sql as $stmt) {
            $pdo->exec($stmt);
        }
        return $total;
    }

    /** Port of v2 global-setup TRUNCATE list (MariaDB-safe with FK checks off). */
    public static function truncateTransactional(PDO $pdo): void
    {
        $tables = [
            'stock', 'stock_locations', 'stock_ledger', 'inbound_orders', 'inbound_items',
            'outbound_orders', 'outbound_items', 'outbound_item_locations', 'picklists',
            'picklist_items', 'bin_transfers', 'stock_take', 'stock_take_items', 'asn',
            'asn_items', 'cycle_count_schedules', 'putaway_location_blocks', 'putaway_tasks',
            'putaway_task_items',
        ];
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $pdo->exec('TRUNCATE TABLE `' . $t . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
