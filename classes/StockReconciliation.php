<?php

/**
 * Stock Reconciliation — detects and manages inventory discrepancies.
 * Compares stock table vs stock_ledger (audit trail) for variance analysis.
 */
class StockReconciliation {

    /* ------------------------------------------------------------------ */
    /* Table bootstrap (idempotent)                                        */
    /* ------------------------------------------------------------------ */

    private static function ensureTable(\PDO $db): void {
        $db->exec("CREATE TABLE IF NOT EXISTS `stock_adjustments` (
            `id`              INT AUTO_INCREMENT PRIMARY KEY,
            `stock_id`        INT          DEFAULT NULL,
            `product_id`      INT          NOT NULL,
            `batch_number`    VARCHAR(100) DEFAULT NULL,
            `location`        VARCHAR(50)  DEFAULT NULL,
            `old_qty`         DECIMAL(15,4) NOT NULL,
            `new_qty`         DECIMAL(15,4) NOT NULL,
            `variance`        DECIMAL(15,4) NOT NULL,
            `variance_percent` DECIMAL(8,4) DEFAULT NULL,
            `reason`          VARCHAR(255) DEFAULT NULL,
            `adjustment_type` VARCHAR(50)  NOT NULL DEFAULT 'manual',
            `adjusted_by`     INT          DEFAULT NULL,
            `adjusted_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX `idx_product` (`product_id`),
            INDEX `idx_stock`   (`stock_id`),
            INDEX `idx_date`    (`adjusted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /* ------------------------------------------------------------------ */
    /* detectDiscrepancies                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Detect discrepancies between stock table qty and ledger-calculated balance.
     * Returns products where current qty != last ledger balance.
     */
    public static function detectDiscrepancies(): array {
        try {
            $db = db();
            self::ensureTable($db);

            // Compare current stock qty vs last ledger balance per stock record
            $sql = "SELECT
                        s.id AS stock_id,
                        s.product_id,
                        p.product_code,
                        p.product_name,
                        s.batch_number,
                        s.location,
                        s.quantity AS current_qty,
                        s.uom,
                        s.stock_status,
                        COALESCE(ledger.last_balance, 0) AS ledger_balance,
                        (s.quantity - COALESCE(ledger.last_balance, 0)) AS variance,
                        CASE
                            WHEN COALESCE(ledger.last_balance, 0) = 0 THEN 0
                            ELSE ROUND(((s.quantity - COALESCE(ledger.last_balance, 0)) / ABS(ledger.last_balance)) * 100, 2)
                        END AS variance_percent
                    FROM stock s
                    JOIN products p ON s.product_id = p.id
                    LEFT JOIN (
                        SELECT sl.product_id, sl.batch_number, sl.location,
                               sl.balance AS last_balance
                        FROM stock_ledger sl
                        INNER JOIN (
                            SELECT product_id, batch_number, location,
                                   MAX(id) AS max_id
                            FROM stock_ledger
                            GROUP BY product_id, batch_number, location
                        ) latest ON sl.id = latest.max_id
                    ) ledger ON ledger.product_id = s.product_id
                            AND ledger.batch_number <=> s.batch_number
                            AND ledger.location <=> s.location
                    WHERE s.quantity > 0
                      AND ABS(s.quantity - COALESCE(ledger.last_balance, 0)) > 0.001
                    ORDER BY ABS(s.quantity - COALESCE(ledger.last_balance, 0)) DESC";

            $stmt = $db->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[StockReconciliation::detectDiscrepancies] ' . $e->getMessage());
            return [];
        }
    }

    /* ------------------------------------------------------------------ */
    /* getVarianceReport                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Get variance report for a specific product or all products.
     * Joins stock, ledger to calculate expected vs actual.
     */
    public static function getVarianceReport(
        ?int    $productId = null,
        ?string $dateFrom  = null,
        ?string $dateTo    = null
    ): array {
        try {
            $db = db();
            self::ensureTable($db);

            $sql = "SELECT
                        s.id AS stock_id,
                        s.product_id,
                        p.product_code,
                        p.product_name,
                        s.batch_number,
                        s.location,
                        s.quantity AS current_qty,
                        s.uom,
                        s.expiry_date,
                        s.stock_status,
                        COALESCE(ledger.last_balance, 0) AS ledger_balance,
                        (s.quantity - COALESCE(ledger.last_balance, 0)) AS variance,
                        COALESCE(ledger.total_in, 0) AS total_ledger_in,
                        COALESCE(ledger.total_out, 0) AS total_ledger_out,
                        COALESCE(ledger.tx_count, 0) AS transaction_count,
                        ledger.last_transaction_date
                    FROM stock s
                    JOIN products p ON s.product_id = p.id
                    LEFT JOIN (
                        SELECT sl.product_id, sl.batch_number, sl.location,
                               SUM(sl.quantity_in)  AS total_in,
                               SUM(sl.quantity_out) AS total_out,
                               COUNT(*)             AS tx_count,
                               MAX(sl.id)           AS max_id,
                               MAX(sl.transaction_date) AS last_transaction_date
                        FROM stock_ledger sl
                        WHERE 1=1";

            $params = [];

            if ($dateFrom) {
                $sql .= " AND sl.transaction_date >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $sql .= " AND sl.transaction_date <= ?";
                $params[] = $dateTo;
            }

            $sql .= " GROUP BY sl.product_id, sl.batch_number, sl.location
                    ) ledger ON ledger.product_id = s.product_id
                            AND ledger.batch_number <=> s.batch_number
                            AND ledger.location <=> s.location";

            // Subquery to get the balance from the max_id ledger row
            $sql = str_replace(
                'COALESCE(ledger.last_balance, 0) AS ledger_balance',
                '(SELECT sl2.balance FROM stock_ledger sl2 WHERE sl2.id = ledger.max_id) AS ledger_balance',
                $sql
            );

            $sql .= " WHERE s.quantity > 0";

            if ($productId) {
                $sql .= " AND s.product_id = ?";
                $params[] = $productId;
            }

            $sql .= " ORDER BY ABS(s.quantity - COALESCE(
                        (SELECT sl2.balance FROM stock_ledger sl2 WHERE sl2.id = ledger.max_id), 0
                    )) DESC";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[StockReconciliation::getVarianceReport] ' . $e->getMessage());
            return [];
        }
    }

    /* ------------------------------------------------------------------ */
    /* reconcile (manual)                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * Manual reconciliation — operator confirms variance is correct.
     * Adjusts stock qty, creates adjustment record, logs to ledger.
     */
    public static function reconcile(
        int     $stockId,
        float   $actualQty,
        int     $userId,
        string  $reason = ''
    ): array {
        $db = db();
        self::ensureTable($db);
        $ownTx = !$db->inTransaction();

        try {
            if ($ownTx) $db->beginTransaction();

            // Get current stock
            $stmt = $db->prepare("SELECT s.*, p.product_code, p.product_name, p.uom_type
                    FROM stock s
                    JOIN products p ON s.product_id = p.id
                    WHERE s.id = ?");
            $stmt->execute([$stockId]);
            $stock = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$stock) {
                throw new \Exception("Stock record #{$stockId} not found");
            }

            $oldQty    = floatval($stock['quantity']);
            $variance  = $actualQty - $oldQty;
            $oldPallet = floatval($stock['pallet']);

            if (abs($variance) < 0.001 && $oldQty > 0) {
                throw new \Exception("No variance detected — quantity unchanged");
            }

            // Update stock qty and pallet
            $uomPerPallet = floatval($stock['uom_per_pallet'] ?? 4);
            $newPallet = $actualQty > 0 ? ceil($actualQty / $uomPerPallet) : 0;

            $stmt = $db->prepare("UPDATE stock SET quantity = ?, pallet = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$actualQty, $newPallet, $stockId]);

            // Create adjustment record
            $variancePercent = $oldQty != 0
                ? round(($variance / abs($oldQty)) * 100, 2)
                : 0;

            $stmt = $db->prepare("INSERT INTO stock_adjustments
                (stock_id, product_id, batch_number, location, old_qty, new_qty,
                 variance, variance_percent, reason, adjustment_type, adjusted_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?)");
            $stmt->execute([
                $stockId,
                $stock['product_id'],
                $stock['batch_number'],
                $stock['location'],
                $oldQty,
                $actualQty,
                $variance,
                $variancePercent,
                $reason ?: "Manual reconciliation by user #{$userId}",
                $userId,
            ]);
            $adjustmentId = $db->lastInsertId();

            // Ledger entry
            $type  = $variance > 0 ? 'IN' : 'OUT';
            $refNo = 'RECON-' . date('Ymd') . '-' . sprintf('%04d', $adjustmentId);

            $balStmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0)
                    AS running_balance FROM stock_ledger WHERE product_id = ?");
            $balStmt->execute([$stock['product_id']]);
            $balance = floatval($balStmt->fetch()['running_balance'] ?? 0);
            $balance += $variance;

            $stmt = $db->prepare("INSERT INTO stock_ledger
                (transaction_date, product_id, batch_number, transaction_type,
                 reference_type, reference_id, reference_number,
                 quantity_in, quantity_out, uom, pallet, balance, location, notes)
                VALUES (CURDATE(), ?, ?, 'RECONCILIATION', 'StockAdjustment', ?, ?,
                        ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $stock['product_id'],
                $stock['batch_number'],
                $adjustmentId,
                $refNo,
                $type === 'IN'  ? abs($variance) : 0,
                $type === 'OUT' ? abs($variance) : 0,
                $stock['uom_type'] ?? $stock['uom'] ?? 'Drum',
                $variance / max($uomPerPallet, 1),
                $balance,
                $stock['location'],
                "Reconciliation: {$reason}" . ($reason ? " ({$reason})" : ''),
            ]);

            // Activity log
            ActivityLogger::log(
                'STOCK_RECONCILIATION',
                'stock',
                'Stock',
                $stockId,
                $refNo,
                "Reconciled {$stock['product_code']} at {$stock['location']}: {$oldQty} → {$actualQty} (Δ{$variance})",
                ['qty' => $oldQty, 'pallet' => $oldPallet],
                ['qty' => $actualQty, 'pallet' => $newPallet, 'reason' => $reason]
            );

            if ($ownTx) $db->commit();

            return [
                'adjustment_id'   => (int)$adjustmentId,
                'stock_id'        => $stockId,
                'product_code'    => $stock['product_code'],
                'location'        => $stock['location'],
                'old_qty'         => $oldQty,
                'new_qty'         => $actualQty,
                'variance'        => $variance,
                'variance_percent'=> $variancePercent,
                'reference_no'    => $refNo,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /* autoAdjust                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Auto-adjust small discrepancies (within threshold).
     * Creates adjustment records and updates stock.
     */
    public static function autoAdjust(
        float $thresholdPercent = 0.5,
        int   $userId           = 1
    ): array {
        $db = db();
        self::ensureTable($db);

        $discrepancies = self::detectDiscrepancies();
        $adjusted = [];

        foreach ($discrepancies as $d) {
            $pct = floatval($d['variance_percent']);
            if (abs($pct) > $thresholdPercent) {
                continue; // Skip — above threshold, needs manual review
            }

            try {
                $db->beginTransaction();

                $stockId  = (int)$d['stock_id'];
                $oldQty   = floatval($d['current_qty']);
                $newQty   = floatval($d['ledger_balance']); // Trust the ledger
                $variance = $newQty - $oldQty;

                if (abs($variance) < 0.001) {
                    $db->rollBack();
                    continue;
                }

                // Update stock
                $uomPerPallet = 4;
                $uStmt = $db->prepare("SELECT uom_per_pallet FROM products WHERE id = ?");
                $uStmt->execute([$d['product_id']]);
                $uRow = $uStmt->fetch();
                if ($uRow) $uomPerPallet = floatval($uRow['uom_per_pallet'] ?? 4);

                $newPallet = $newQty > 0 ? ceil($newQty / $uomPerPallet) : 0;
                $db->prepare("UPDATE stock SET quantity = ?, pallet = ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$newQty, $newPallet, $stockId]);

                // Adjustment record
                $db->prepare("INSERT INTO stock_adjustments
                    (stock_id, product_id, batch_number, location, old_qty, new_qty,
                     variance, variance_percent, reason, adjustment_type, adjusted_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'auto_adjust', ?)")
                   ->execute([
                       $stockId,
                       $d['product_id'],
                       $d['batch_number'],
                       $d['location'],
                       $oldQty,
                       $newQty,
                       $variance,
                       $pct,
                       "Auto-adjust: within {$thresholdPercent}% threshold",
                       $userId,
                   ]);
                $adjId = $db->lastInsertId();

                // Ledger entry
                $type  = $variance > 0 ? 'IN' : 'OUT';
                $refNo = 'AUTO-' . date('Ymd') . '-' . sprintf('%04d', $adjId);

                $balStmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0)
                        AS running_balance FROM stock_ledger WHERE product_id = ?");
                $balStmt->execute([$d['product_id']]);
                $balance = floatval($balStmt->fetch()['running_balance'] ?? 0);
                $balance += $variance;

                $db->prepare("INSERT INTO stock_ledger
                    (transaction_date, product_id, batch_number, transaction_type,
                     reference_type, reference_id, reference_number,
                     quantity_in, quantity_out, uom, pallet, balance, location, notes)
                    VALUES (CURDATE(), ?, ?, 'RECONCILIATION', 'StockAdjustment', ?, ?,
                            ?, ?, ?, ?, ?, ?, ?)")
                   ->execute([
                       $d['product_id'],
                       $d['batch_number'],
                       $adjId,
                       $refNo,
                       $type === 'IN'  ? abs($variance) : 0,
                       $type === 'OUT' ? abs($variance) : 0,
                       $d['uom'] ?? 'Drum',
                       $variance / max($uomPerPallet, 1),
                       $balance,
                       $d['location'],
                       "Auto-reconciled ({$pct}%)",
                   ]);

                ActivityLogger::log(
                    'STOCK_AUTO_ADJUST',
                    'stock',
                    'Stock',
                    $stockId,
                    $refNo,
                    "Auto-adjusted {$d['product_code']}: {$oldQty} → {$newQty} (Δ{$variance}, {$pct}%)",
                    ['qty' => $oldQty],
                    ['qty' => $newQty]
                );

                $db->commit();

                $adjusted[] = [
                    'adjustment_id'    => (int)$adjId,
                    'stock_id'         => $stockId,
                    'product_code'     => $d['product_code'],
                    'location'         => $d['location'],
                    'old_qty'          => $oldQty,
                    'new_qty'          => $newQty,
                    'variance'         => $variance,
                    'variance_percent' => $pct,
                    'reference_no'     => $refNo,
                ];
            } catch (\Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                error_log("[StockReconciliation::autoAdjust] stock_id={$d['stock_id']}: " . $e->getMessage());
            }
        }

        return $adjusted;
    }

    /* ------------------------------------------------------------------ */
    /* getHistory                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Get reconciliation history from stock_adjustments.
     */
    public static function getHistory(
        ?string $dateFrom = null,
        ?string $dateTo   = null,
        int     $limit    = 100
    ): array {
        try {
            $db = db();
            self::ensureTable($db);

            $sql = "SELECT sa.*, p.product_code, p.product_name,
                           u.full_name AS adjusted_by_name
                    FROM stock_adjustments sa
                    JOIN products p ON sa.product_id = p.id
                    LEFT JOIN users u ON sa.adjusted_by = u.id
                    WHERE 1=1";
            $params = [];

            if ($dateFrom) {
                $sql .= " AND sa.adjusted_at >= ?";
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $sql .= " AND sa.adjusted_at <= ?";
                $params[] = $dateTo;
            }

            $sql .= " ORDER BY sa.adjusted_at DESC LIMIT " . intval($limit);

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            error_log('[StockReconciliation::getHistory] ' . $e->getMessage());
            return [];
        }
    }

    /* ------------------------------------------------------------------ */
    /* dailyReconciliation (cron)                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Daily reconciliation job — run via cron.
     * 1. Detect all discrepancies
     * 2. Auto-adjust small ones
     * 3. Flag large ones for manual review
     * 4. Return results summary
     */
    public static function dailyReconciliation(float $thresholdPercent = 0.5): array {
        try {
            $discrepancies = self::detectDiscrepancies();

            $autoAdjusted   = [];
            $manualReview   = [];
            $skipped        = 0;

            foreach ($discrepancies as $d) {
                $pct = floatval($d['variance_percent']);
                if (abs($pct) <= $thresholdPercent) {
                    $autoAdjusted[] = $d; // Will be processed by autoAdjust
                } else {
                    $manualReview[] = $d;
                }
            }

            // Actually auto-adjust the small discrepancies
            $adjusted = [];
            if (!empty($autoAdjusted)) {
                $adjusted = self::autoAdjust($thresholdPercent);
            }

            // Log daily reconciliation summary
            $summary = [
                'date'                => date('Y-m-d'),
                'total_discrepancies' => count($discrepancies),
                'auto_adjusted'       => count($adjusted),
                'needs_manual_review' => count($manualReview),
                'adjustments'         => $adjusted,
                'manual_review_items' => $manualReview,
            ];

            // Log summary to activity log
            ActivityLogger::log(
                'DAILY_RECONCILIATION',
                'stock',
                'StockReconciliation',
                null,
                null,
                "Daily reconciliation: " . count($discrepancies) . " discrepancies found, "
                . count($adjusted) . " auto-adjusted, "
                . count($manualReview) . " need manual review",
                null,
                $summary
            );

            return $summary;
        } catch (\Throwable $e) {
            error_log('[StockReconciliation::dailyReconciliation] ' . $e->getMessage());
            return [
                'date'                => date('Y-m-d'),
                'error'               => $e->getMessage(),
                'total_discrepancies' => 0,
                'auto_adjusted'       => 0,
                'needs_manual_review' => 0,
            ];
        }
    }
}
?>
