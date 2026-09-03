<?php

/**
 * Automated Replenishment System
 * Detects shortages and auto-generates bin transfers on schedule or events.
 *
 * Uses Replenishment::detectShortages() for shortage detection and
 * Replenishment::generateTransfersFull() for transfer generation.
 */
class AutoReplenishment {

    /* ------------------------------------------------------------------ */
    /* Main entry points                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Run one replenishment cycle.
     *
     * @param string $triggerType 'scheduled'|'stock_drop'|'inbound'|'manual'
     * @return array Results: generated[], skipped[], failed[]
     */
    public static function runCycle(string $triggerType = 'manual'): array {
        $db = db();

        // 1. Check if enabled
        if (!self::getConfigValue('enabled', '1')) {
            return [
                'generated' => [],
                'skipped'   => [],
                'failed'    => [],
                'message'   => 'Auto-replenishment is disabled.',
            ];
        }

        $batchSize   = (int)self::getConfigValue('batch_size', '10');
        $cooldownMin = (int)self::getConfigValue('cooldown_minutes', '30');

        // 2. Detect all shortages
        $shortages = Replenishment::detectShortages();

        // 3. Apply cooldown filter
        $shortages = self::applyCooldown($shortages, $cooldownMin);

        // 4. Apply batch limit
        $shortages = array_slice($shortages, 0, $batchSize);

        $generated = [];
        $skipped   = [];
        $failed    = [];

        // 5. Process each shortage
        foreach ($shortages as $s) {
            $productId    = (int)$s['product_id'];
            $locationCode = $s['pick_face_location'];
            $locationId   = (int)$s['target_id'];
            $shortageQty  = floatval($s['shortage']);

            // 5a. Check rule — only auto-generate if enabled for this product/location
            $rule = self::getRule($productId, $locationId);
            if ($rule && !$rule['auto_generate']) {
                self::logAction($productId, $locationCode, $triggerType, $shortageQty, null, 'skipped', 'auto_generate disabled');
                $skipped[] = [
                    'product_id'      => $productId,
                    'location_code'   => $locationCode,
                    'shortage'        => $shortageQty,
                    'reason'          => 'auto_generate disabled',
                ];
                continue;
            }

            // 5b. Generate transfer via Replenishment class (system user_id=1)
            try {
                $result = Replenishment::generateTransfersFull(1);

                if (!empty($result['generated'])) {
                    $transfer = end($result['generated']);
                    $transferId = (int)$transfer['transfer_id'];
                    self::logAction($productId, $locationCode, $triggerType, $shortageQty, $transferId, 'generated');
                    $generated[] = $transfer;
                } elseif (!empty($result['skipped'])) {
                    $skip = end($result['skipped']);
                    self::logAction($productId, $locationCode, $triggerType, $shortageQty, null, 'skipped', $skip['reason'] ?? null);
                    $skipped[] = $skip;
                } elseif (!empty($result['insufficient'])) {
                    $ins = end($result['insufficient']);
                    self::logAction($productId, $locationCode, $triggerType, $shortageQty, null, 'failed', 'Insufficient source stock');
                    $failed[] = [
                        'product_id'    => $productId,
                        'location_code' => $locationCode,
                        'shortage'      => $shortageQty,
                        'reason'        => 'Insufficient source stock',
                    ];
                }
            } catch (\Throwable $e) {
                self::logAction($productId, $locationCode, $triggerType, $shortageQty, null, 'failed', $e->getMessage());
                $failed[] = [
                    'product_id'    => $productId,
                    'location_code' => $locationCode,
                    'shortage'      => $shortageQty,
                    'reason'        => $e->getMessage(),
                ];
            }
        }

        // 6. Send notification summary
        $total = count($generated);
        if ($total > 0) {
            self::notify(
                'Auto-Replenishment ' . ucfirst($triggerType),
                "{$total} transfer(s) generated from {$triggerType} trigger.",
                'info'
            );
        }

        if (count($failed) > 0) {
            self::notify(
                'Auto-Replenishment Failures',
                count($failed) . ' replenishment(s) failed during ' . $triggerType . ' cycle.',
                'error'
            );
        }

        return [
            'generated' => $generated,
            'skipped'   => $skipped,
            'failed'    => $failed,
        ];
    }

    /**
     * Event hook — called when stock quantity drops.
     * Triggers immediate replenishment if new qty falls below min_qty threshold.
     */
    public static function onStockDrop(int $productId, string $locationCode, float $oldQty, float $newQty): void {
        $db = db();

        // 1. Check if location is pick-face (row_name = 'A', is_pick_face = 1)
        $locStmt = $db->prepare("
            SELECT lm.id, lm.is_pick_face, lm.row_name
            FROM location_master lm
            WHERE lm.location_code = ? AND lm.is_active = 1
            LIMIT 1
        ");
        $locStmt->execute([$locationCode]);
        $loc = $locStmt->fetch();

        if (!$loc || !$loc['is_pick_face'] || strtoupper($loc['row_name'] ?? '') !== 'A') {
            return; // Not a pick-face location
        }

        // 2. Get rule for this product/location
        $rule = self::getRule($productId, (int)$loc['id']);
        $minQty = $rule ? floatval($rule['min_qty']) : 1;

        // 3. If newQty <= min_qty AND oldQty > min_qty: triggered
        if ($newQty <= $minQty && $oldQty > $minQty) {
            self::runCycle('stock_drop');
        }
    }

    /**
     * Event hook — called when inbound is received.
     * Checks if pending shortages can now be fulfilled.
     */
    public static function onInboundReceive(int $productId, float $receivedQty): void {
        $db = db();

        // 1. Check if there are recent pending shortages for this product
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM replenishment_log
            WHERE product_id = ?
              AND status IN ('pending', 'failed')
              AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $stmt->execute([$productId]);
        $count = (int)$stmt->fetchColumn();

        // 2. If yes: run cycle
        if ($count > 0) {
            self::runCycle('inbound');
        }
    }

    /* ------------------------------------------------------------------ */
    /* Status & Config                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Get current status and statistics.
     */
    public static function getStatus(): array {
        $db = db();

        $enabled = (bool)self::getConfigValue('enabled', '1');

        // Total active shortages
        $shortages = Replenishment::detectShortages();
        $totalShortages = count($shortages);

        // Pending transfers (Pending status)
        $pendingStmt = $db->prepare("
            SELECT COUNT(*) FROM bin_transfers
            WHERE transfer_type = 'REPLENISHMENT' AND status = 'Pending'
        ");
        $pendingStmt->execute();
        $pendingTransfers = (int)$pendingStmt->fetchColumn();

        // Recent activity (last 24h) — individual rows
        $recentStmt = $db->prepare("
            SELECT
                rl.id,
                rl.product_id,
                rl.location_code,
                rl.trigger_type,
                rl.shortage_qty,
                rl.transfer_id,
                rl.status,
                rl.skip_reason,
                rl.created_at
            FROM replenishment_log rl
            WHERE rl.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY rl.created_at DESC
            LIMIT 50
        ");
        $recentStmt->execute();
        $recentActivity = $recentStmt->fetchAll();

        return [
            'enabled'            => $enabled,
            'total_shortages'    => $totalShortages,
            'pending_transfers'  => $pendingTransfers,
            'recent_activity'    => $recentActivity,
        ];
    }

    /**
     * Get all configuration values.
     */
    public static function getConfig(): array {
        $db   = db();
        $stmt = $db->prepare("SELECT config_key, config_value, description FROM replenishment_config ORDER BY id");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $config = [];
        foreach ($rows as $row) {
            $config[$row['config_key']] = [
                'value'       => $row['config_value'],
                'description' => $row['description'],
            ];
        }
        return $config;
    }

    /**
     * Update configuration values.
     */
    public static function updateConfig(array $updates): bool {
        $db = db();
        $stmt = $db->prepare("
            UPDATE replenishment_config SET config_value = ? WHERE config_key = ?
        ");

        foreach ($updates as $key => $value) {
            $stmt->execute([(string)$value, $key]);
        }

        self::notify('Config Updated', 'Auto-replenishment configuration updated.', 'info');
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Private helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Apply cooldown filter — skip recently replenished locations.
     */
    private static function applyCooldown(array $shortages, int $cooldownMinutes): array {
        $db = db();

        $filtered = [];
        foreach ($shortages as $s) {
            $productId    = (int)$s['product_id'];
            $locationCode = $s['pick_face_location'];

            $stmt = $db->prepare("
                SELECT created_at FROM replenishment_log
                WHERE product_id = ?
                  AND location_code = ?
                  AND status IN ('generated', 'executed')
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$productId, $locationCode]);
            $last = $stmt->fetchColumn();

            if ($last) {
                $lastTime  = strtotime($last);
                $elapsed   = time() - $lastTime;
                $cooldown  = $cooldownMinutes * 60;

                if ($elapsed < $cooldown) {
                    continue; // Skip — within cooldown
                }
            }

            $filtered[] = $s;
        }

        return $filtered;
    }

    /**
     * Get replenishment rule for a product/location.
     */
    private static function getRule(int $productId, int $locationId): ?array {
        $db   = db();
        $stmt = $db->prepare("
            SELECT * FROM replenishment_rules
            WHERE product_id = ? AND location_id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$productId, $locationId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Log a replenishment action.
     */
    private static function logAction(
        int     $productId,
        string  $locationCode,
        string  $triggerType,
        float   $shortageQty,
        ?int    $transferId,
        string  $status,
        ?string $skipReason = null
    ): void {
        try {
            $db = db();
            $stmt = $db->prepare("
                INSERT INTO replenishment_log
                    (product_id, location_code, trigger_type, shortage_qty,
                     transfer_id, status, skip_reason)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $productId,
                $locationCode,
                $triggerType,
                $shortageQty,
                $transferId,
                $status,
                $skipReason,
            ]);
        } catch (\Throwable $e) {
            error_log('[AutoReplenishment::logAction] ' . $e->getMessage());
        }
    }

    /**
     * Send notification via ActivityLogger.
     */
    private static function notify(string $title, string $message, string $type = 'info'): void {
        ActivityLogger::log(
            'AUTO_REPLENISHMENT_' . strtoupper($type),
            'replenishment',
            null,
            null,
            null,
            "{$title}: {$message}"
        );
    }

    /**
     * Get config value by key.
     */
    private static function getConfigValue(string $key, $default = null) {
        try {
            $db   = db();
            $stmt = $db->prepare("SELECT config_value FROM replenishment_config WHERE config_key = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : $default;
        } catch (\Throwable $e) {
            return $default;
        }
    }
}
