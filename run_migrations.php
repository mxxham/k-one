<?php
/**
 * Ordered SQL migration runner — CLI mirror of v2 apps/api/src/database/migrate.ts.
 * Usage: php run_migrations.php
 * Applies migrations/0NN-*.sql in order, tracking applied files in
 * schema_migrations (filename PK). Re-runs skip already-applied files.
 * Exit code 0 = success, 1 = failure.
 */
require_once __DIR__ . '/config/database.php';

$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$dir = __DIR__ . '/migrations';
$files = glob($dir . '/0[0-9][0-9]-*.sql');
sort($files);

$applied = 0;
$skipped = 0;

foreach ($files as $file) {
    $name = basename($file);

    $stmt = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE filename = ?');
    $stmt->execute([$name]);
    if ($stmt->fetch()) {
        echo "skip $name\n";
        $skipped++;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        fwrite(STDERR, "FAILED to read $name\n");
        exit(1);
    }

    echo "apply $name... ";
    try {
        $pdo->exec($sql);
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR on $name: " . $e->getMessage() . "\n");
        exit(1);
    }

    $ins = $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)');
    $ins->execute([$name]);
    echo "ok\n";
    $applied++;
}

echo "done: $applied applied, $skipped skipped\n";
exit(0);