<?php
/**
 * Automated Replenishment Cron Runner
 *
 * Usage:
 *   php run_replenishment_cycle.php
 *
 * Windows Task Scheduler:
 *   Create a .bat file or use RUN-REPLENISHMENT.bat
 *   Schedule: Every 15 minutes (or as configured)
 *
 * Linux Cron:
 *   */15 * * * * cd /path/to/k-one && php run_replenishment_cycle.php
 *
 * HTTP (with token):
 *   https://your-domain/run_replenishment_cycle.php?token=YOUR_TOKEN
 *
 * Token is read from env REPLENISHMENT_CRON_TOKEN, defaults to 'k-one-replenish-2026'.
 */

// ── Security ────────────────────────────────────────────────────────────────
// Block non-CLI access without a valid token.
if (php_sapi_name() !== 'cli') {
    // HTTP access: require a valid token via GET/POST/query
    $token     = $_GET['token'] ?? $_POST['token'] ?? '';
    $validToken = getenv('REPLENISHMENT_CRON_TOKEN') ?: 'k-one-replenish-2026';
    if (!hash_equals($validToken, $token)) {
        http_response_code(403);
        die('Access denied: CLI execution or valid token required');
    }
}

// ── Bootstrap ───────────────────────────────────────────────────────────────
// Load Composer autoloader, .env, DB config, and define db() helper.
require_once __DIR__ . '/config/database.php';

// Load domain classes (config/database.php already loaded the autoloader).
require_once __DIR__ . '/classes/AutoReplenishment.php';
require_once __DIR__ . '/classes/ActivityLogger.php';

// ── Run Cycle ───────────────────────────────────────────────────────────────
$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');
echo "[{$timestamp}] Starting automated replenishment cycle...\n";

try {
    $result = \AutoReplenishment::runCycle('scheduled');

    $duration  = round((microtime(true) - $startTime) * 1000);
    $generated = count($result['generated'] ?? []);
    $skipped   = count($result['skipped'] ?? []);
    $failed    = count($result['failed'] ?? []);

    echo "[{$timestamp}] Cycle completed in {$duration}ms\n";
    echo "  - Generated: {$generated} transfer(s)\n";
    echo "  - Skipped:   {$skipped} (cooldown / no stock / rule disabled)\n";
    echo "  - Failed:    {$failed}\n";

    // Log success (no session context in CLI, so ActivityLogger will write NULLs for user fields)
    \ActivityLogger::log(
        'REPLENISHMENT_CRON_CYCLE',
        'replenishment',
        null,
        null,
        null,
        "Scheduled cycle completed: {$generated} generated, {$skipped} skipped, {$failed} failed ({$duration}ms)",
        null,
        [
            'trigger'   => 'scheduled',
            'generated' => $generated,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'duration'  => $duration,
        ]
    );

    exit(0);

} catch (\Throwable $e) {
    $duration = round((microtime(true) - $startTime) * 1000);
    $endTs    = date('Y-m-d H:i:s');
    echo "[{$endTs}] ERROR after {$duration}ms: " . $e->getMessage() . "\n";

    // Log the error
    \ActivityLogger::log(
        'REPLENISHMENT_CRON_ERROR',
        'replenishment',
        null,
        null,
        null,
        $e->getMessage(),
        null,
        [
            'trigger'  => 'scheduled',
            'message'  => $e->getMessage(),
            'file'     => $e->getFile(),
            'line'     => $e->getLine(),
            'duration' => $duration,
        ]
    );

    exit(1);
}
