<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap — mirrors v2 apps/api/test/global-setup.ts:
 *  1. Create a throwaway kone_test database (drop + recreate for a clean slate).
 *  2. Apply migrations/0NN-*.sql in order (same file set run_migrations.php uses).
 *  3. Seed a known login (testadmin / admin123, bcrypt) + the 60-bin controlled
 *     rack map (CA/CB, bays 1-3, levels A-E, 2 pos; Level A = pick-face).
 *  4. Truncate transactional tables.
 *  5. Start `php -S` on 127.0.0.1:8790 with DB_NAME=kone_test so the API under
 *     test talks ONLY to the throwaway DB — the dev DB (sanchaya) stays untouched.
 */

const TEST_DB_NAME   = 'kone_test';
const TEST_DB_HOST   = '127.0.0.1';
const TEST_DB_PORT   = 3306;
const TEST_DB_USER   = 'root';
const TEST_DB_PASS   = '';
const TEST_SERVER_HOST = '127.0.0.1';
const TEST_SERVER_PORT = 8790;
const TEST_SERVER_BASE = 'http://127.0.0.1:8790';

require_once __DIR__ . '/ApiTestHelpers.php';

$dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', TEST_DB_HOST, TEST_DB_PORT);

// 1. Throwaway DB
$admin = new PDO($dsn, TEST_DB_USER, TEST_DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$admin->exec('DROP DATABASE IF EXISTS `' . TEST_DB_NAME . '`');
$admin->exec('CREATE DATABASE `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin = null;

// 2. Migrations in order
$pdo = new PDO(sprintf('%s;dbname=%s', $dsn, TEST_DB_NAME), TEST_DB_USER, TEST_DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$files = glob(dirname(__DIR__) . '/migrations/0[0-9][0-9]-*.sql');
sort($files);

$mysqlCli = 'C:\\xampp\\mysql\\bin\\mysql.exe';

foreach ($files as $file) {
    $sql = file_get_contents($file);

    // Replace production DB name with test DB name
    $sql = str_replace('USE sanchaya;', 'USE ' . TEST_DB_NAME . ';', $sql);

    if (strpos($sql, 'DELIMITER') !== false && file_exists($mysqlCli)) {
        // Files with stored procedures must use mysql CLI (PDO splits on ;)
        $tmpFile = tempnam(sys_get_temp_dir(), 'mig_') . '.sql';
        file_put_contents($tmpFile, $sql);
        $cmd = sprintf('"%s" -h %s -P %d -u %s %s < "%s" 2>&1',
            $mysqlCli, TEST_DB_HOST, TEST_DB_PORT, TEST_DB_USER, TEST_DB_NAME, $tmpFile);
        exec($cmd, $output, $exitCode);
        unlink($tmpFile);
        if ($exitCode !== 0) {
            fwrite(STDERR, "WARNING: mysql CLI failed for " . basename($file) . ": " . implode("\n", $output) . "\n");
        }
    } else {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // Log but continue — some migrations reference columns added by
            // stored procedures or have non-critical index issues.
            fwrite(STDERR, "WARNING: migration " . basename($file) . " error (continuing): "
                . $e->getMessage() . "\n");
        }
    }
}

// 3a. Seeded login (bcrypt hash of "admin123")
$pdo->prepare(
    "INSERT INTO users (username, password, full_name, email, role, department, is_active)
     VALUES ('testadmin', ?, 'Test Admin', 'test@local', 'admin', 'all', 1)"
)->execute([password_hash('admin123', PASSWORD_BCRYPT)]);

// 3b. Controlled rack map (60 bins)
foreach (ApiTestHelpers::controlledBinsSql() as $binSql) {
    $pdo->exec($binSql);
}

// 4. Clean transactional state
ApiTestHelpers::truncateTransactional($pdo);
$pdo = null;

// 5. Start the test server pointed at kone_test
// Set env vars in parent process so child inherits them (Windows proc_open fix)
putenv('DB_NAME=' . TEST_DB_NAME);
putenv('DB_HOST=' . TEST_DB_HOST);
putenv('DB_PORT=' . TEST_DB_PORT);
putenv('DB_USER=' . TEST_DB_USER);
putenv('DB_PASS=' . TEST_DB_PASS);
putenv('API_ENV=test');

$env = getenv();
$env['DB_NAME'] = TEST_DB_NAME;
$env['DB_HOST'] = TEST_DB_HOST;
$env['DB_PORT'] = (string) TEST_DB_PORT;
$env['DB_USER'] = TEST_DB_USER;
$env['DB_PASS'] = TEST_DB_PASS;
$env['API_ENV'] = 'test';

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$server = proc_open(
    sprintf('"%s" -S %s:%d -t "%s"', PHP_BINARY, TEST_SERVER_HOST, TEST_SERVER_PORT, dirname(__DIR__)),
    $descriptors,
    $pipes,
    dirname(__DIR__),
    $env
);
if (!is_resource($server)) {
    fwrite(STDERR, "FATAL: could not start test server\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

register_shutdown_function(function () use ($server, $pipes) {
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    if (is_resource($server)) {
        proc_terminate($server);
    }
});

// 6. Wait until the server answers (banner endpoint)
$ready = false;
for ($i = 0; $i < 60; $i++) {
    $ch = curl_init(TEST_SERVER_BASE . '/api/index.php?module=x&action=y');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_NOBODY         => true,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404) { // handler file missing = gateway alive
        $ready = true;
        break;
    }
    usleep(250000);
}
if (!$ready) {
    fwrite(STDERR, "FATAL: test server did not become ready in 15s\n");
    $err = stream_get_contents($pipes[2]);
    if ($err) {
        fwrite(STDERR, "server stderr: " . $err . "\n");
    }
    exit(1);
}
