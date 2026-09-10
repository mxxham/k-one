<?php

class Checker
{
    public static function pendingLines(?int $picklistId = null): array
    {
        $db = db();
        $sql = "SELECT pi.id, pi.outbound_item_id, pi.picklist_id, oo.id AS outbound_id, oo.order_number,
                       pl.picklist_number, pi.product_id,
                       p.product_code, p.product_name, pi.lpn_code, pi.location,
                       pi.quantity AS qty, pi.check_status
                FROM picklist_items pi
                JOIN outbound_items oi ON oi.id = pi.outbound_item_id
                JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
                JOIN products p ON p.id = pi.product_id
                JOIN picklists pl ON pl.id = pi.picklist_id
                WHERE pi.check_status = 'Pending'";
        $params = [];
        if ($picklistId !== null) {
            $sql .= " AND pi.picklist_id = ?";
            $params[] = $picklistId;
        }
        $sql .= " ORDER BY pi.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function confirmLine(
        int $itemId,
        string $scannedLocation,
        string $scannedLpn,
        string $scannedSku,
        float $scannedQty,
        ?string $scannedBatch = null,
        ?string $scannedExpiry = null,
        ?string $overrideReason = null
    ): bool {
        $db = db();
        $userId = $_SESSION['user_id'] ?? null;
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $stmt = $db->prepare(
                "SELECT pi.*, p.product_code, oo.id AS outbound_id, oo.status AS order_status,
                        s.expiry_date
                 FROM picklist_items pi
                 JOIN outbound_items oi ON oi.id = pi.outbound_item_id
                 JOIN products p ON p.id = pi.product_id
                 JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
                 LEFT JOIN stock s ON s.id = pi.stock_location_id
                 WHERE pi.id = ?"
            );
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) throw new Exception("Item tidak ditemukan.");
            if ($item['check_status'] !== 'Pending') throw new Exception("Item sudah dicek.");
            if (!in_array($item['order_status'], ['Picking', 'Picked'], true)) {
                throw new Exception("Order belum siap untuk dicek.");
            }
            if (!empty($item['picked_by']) && (int)$item['picked_by'] === (int)$userId) {
                throw new Exception("Checker tidak boleh sama dengan picker.");
            }

            // --- Six checks, each independently logged ---
            $checks = [];

            $locScan = strtoupper(trim($scannedLocation));
            $checks['LOCATION'] = ($locScan === strtoupper($item['location'] ?? ''));
            self::_logScan('picking', $itemId, 'LOCATION', $item['location'], $locScan, $checks['LOCATION'] ? 'MATCH' : 'MISMATCH', $userId);

            $lpnScan = strtoupper(trim($scannedLpn));
            $checks['LPN'] = ($lpnScan === strtoupper($item['lpn_code'] ?? ''));
            self::_logScan('picking', $itemId, 'LPN', $item['lpn_code'], $lpnScan, $checks['LPN'] ? 'MATCH' : 'MISMATCH', $userId);

            $skuScan = strtoupper(trim($scannedSku));
            $checks['SKU'] = ($skuScan === strtoupper($item['product_code'] ?? ''));
            self::_logScan('picking', $itemId, 'SKU', $item['product_code'], $skuScan, $checks['SKU'] ? 'MATCH' : 'MISMATCH', $userId);

            // Qty: exact match required — a checker isn't a re-count/adjustment tool
            $checks['QTY'] = (abs($scannedQty - (float)$item['quantity']) < 0.001);
            self::_logScan('picking', $itemId, 'QTY', (string)$item['quantity'], (string)$scannedQty, $checks['QTY'] ? 'MATCH' : 'MISMATCH', $userId);

            // Lot/batch — optional check, only runs if a batch was actually scanned/entered
            if ($scannedBatch !== null && trim($scannedBatch) !== '') {
                $batchScan = strtoupper(trim($scannedBatch));
                $checks['LOT'] = ($batchScan === strtoupper($item['batch_number'] ?? ''));
                self::_logScan('picking', $itemId, 'LOT', $item['batch_number'] ?? '', $batchScan, $checks['LOT'] ? 'MATCH' : 'MISMATCH', $userId);
            }

            // Expiry — optional check, same pattern
            if ($scannedExpiry !== null && trim($scannedExpiry) !== '') {
                $checks['EXPIRY'] = ($scannedExpiry === $item['expiry_date']);
                self::_logScan('picking', $itemId, 'EXPIRY', $item['expiry_date'] ?? '', $scannedExpiry, $checks['EXPIRY'] ? 'MATCH' : 'MISMATCH', $userId);
            }

            $anyMismatch = in_array(false, $checks, true);

            if ($anyMismatch) {
                if (trim($overrideReason ?? '') === '') {
                    $failed = implode(', ', array_keys(array_filter($checks, fn($v) => $v === false)));
                    throw new Exception("Data tidak sesuai ({$failed}). Alasan override wajib diisi.");
                }
                $role = $_SESSION['role'] ?? '';
                if (!in_array($role, ['supervisor', 'admin'], true)) {
                    throw new Exception("Override memerlukan izin supervisor.");
                }
            }

            $db->prepare(
                "UPDATE picklist_items SET
                    check_status = ?, scanned_lpn = ?, checked_by = ?, checked_at = NOW(),
                    check_override_reason = ?
                 WHERE id = ?"
            )->execute([
                $anyMismatch ? 'Discrepancy' : 'Checked',
                $lpnScan, $userId,
                $anyMismatch ? $overrideReason : null,
                $itemId,
            ]);

            self::_refreshOrderCheckStatus((int)$item['outbound_id'], $db);
            if ($ownTx) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function _logScan(string $context, int $contextId, string $type, string $expected, string $scanned, string $result, ?int $userId): void
    {
        db()->prepare(
            "INSERT INTO check_scans (context_type, context_id, scan_type, expected_value, scanned_value, result, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$context, $contextId, $type, $expected, $scanned, $result, $userId]);
    }

    private static function _refreshOrderCheckStatus(int $outboundId, \PDO $db): void
    {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total, SUM(pi.check_status = 'Pending') AS pending
             FROM picklist_items pi
             JOIN outbound_items oi ON oi.id = pi.outbound_item_id
             WHERE oi.outbound_order_id = ?"
        );
        $stmt->execute([$outboundId]);
        $row = $stmt->fetch();
        $allChecked = $row && (int)$row['pending'] === 0 && (int)$row['total'] > 0;
        $db->prepare("UPDATE outbound_orders SET all_lines_checked = ? WHERE id = ?")
           ->execute([$allChecked ? 1 : 0, $outboundId]);
    }
}
