<?php

class AbcAnalysis {

    /* ------------------------------------------------------------------ */
    /* S26 — ABC / velocity-based ranking (80/15/5 cumulative share)       */
    /* ------------------------------------------------------------------ */

    public static function analyze(?string $dateFrom = null, ?string $dateTo = null, int $splitA = 80, int $splitB = 15): array {
        $db = db();
        $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-90 days'));
        $dateTo = $dateTo ?? date('Y-m-d');
        $stmt = $db->prepare("SELECT sl.product_id,
                        p.product_code, p.product_name,
                        COALESCE(SUM(sl.quantity_out),0) AS qty_out
                FROM stock_ledger sl
                JOIN products p ON sl.product_id = p.id
                WHERE sl.transaction_type = 'OUT'
                  AND sl.transaction_date BETWEEN ? AND ?
                GROUP BY sl.product_id, p.product_code, p.product_name
                HAVING qty_out > 0
                ORDER BY qty_out DESC");
        $stmt->execute([$dateFrom, $dateTo]);
        $rows = $stmt->fetchAll();

        $grandTotal = array_sum(array_column($rows, 'qty_out'));
        if ($grandTotal <= 0) return [];

        $cumulativeQty = 0.0;
        $results = [];
        $aBound = $splitA / 100;
        $abBound = ($splitA + $splitB) / 100;
        $first = true;
        foreach ($rows as $i => $r) {
            $qty = floatval($r['qty_out']);
            $cumulativeQty += $qty;
            $share = $cumulativeQty / $grandTotal;
            $cls = null;
            if ($first && $splitA > 0) {
                $cls = 'A';
            } elseif ($share <= $aBound) {
                $cls = 'A';
            } elseif ($share <= $abBound) {
                $cls = 'B';
            } else {
                $cls = 'C';
            }
            $first = false;
            $results[] = [
                'rank'       => $i + 1,
                'product_id' => (int)$r['product_id'],
                'product_code' => $r['product_code'],
                'product_name' => $r['product_name'],
                'qty_out'      => $qty,
                'share_pct'        => round($share * 100, 2),
                'cumulative_share_pct' => round($share * 100, 2),
                'velocity_class' => $cls,
            ];
        }
        return $results;
    }

    public static function status(): array {
        $db = db();
        $stmt = $db->query("SELECT COUNT(*) AS total,
                SUM(CASE WHEN velocity_class IS NOT NULL THEN 1 ELSE 0 END) AS classified,
                MAX(velocity_class_at) AS last_computed_at
                FROM products");
        $row = $stmt->fetch();
        return [
            'total_products'   => (int)$row['total'],
            'classified'       => (int)$row['classified'],
            'last_computed_at' => $row['last_computed_at'],
        ];
    }

    public static function recompute(?string $dateFrom = null, ?string $dateTo = null, int $splitA = 80, int $splitB = 15): array {
        $db = db();
        $dateFrom = $dateFrom ?? date('Y-m-d', strtotime('-90 days'));
        $dateTo = $dateTo ?? date('Y-m-d');
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $rows = self::analyze($dateFrom, $dateTo, $splitA, $splitB);
            $grandTotal = array_sum(array_column($rows, 'qty_out'));

            $classified = [];
            foreach ($rows as $r) {
                $classified[$r['product_id']] = $r['velocity_class'];
            }

            // Products with no OUT data in window -> NULL (unclassified)
            $allStmt = $db->prepare("SELECT id FROM products");
            $allStmt->execute();
            $allIds = array_column($allStmt->fetchAll(), 'id');

            $updStmt = $db->prepare("UPDATE products SET velocity_class = ?, velocity_class_at = NOW() WHERE id = ?");
            $counts = ['A' => 0, 'B' => 0, 'C' => 0];
            foreach ($allIds as $pid) {
                $cls = $classified[$pid] ?? null;
                if ($cls !== null) {
                    $counts[$cls]++;
                }
                $updStmt->execute([$cls, $pid]);
            }

            if ($ownTx) $db->commit();
            return [
                'counts'   => $counts,
                'grand_total_out' => $grandTotal,
                'computed_at' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
?>