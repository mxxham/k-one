<?php

class Checker
{
    public static function pendingLines(): array
    {
        $db = db();
        $stmt = $db->query(
            "SELECT pi.id, pi.outbound_item_id, oo.id AS outbound_id, oo.order_number, pi.product_id,
                    p.product_code, p.product_name, pi.lpn_code, pi.location,
                    pi.quantity AS qty, pi.check_status
             FROM picklist_items pi
             JOIN outbound_items oi ON oi.id = pi.outbound_item_id
             JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
             JOIN products p ON p.id = pi.product_id
             WHERE pi.check_status = 'Pending'
             ORDER BY pi.id ASC"
        );
        return $stmt->fetchAll();
    }

    public static function confirmLine(int $itemId, string $scannedLpn, string $scannedSku, ?string $overrideReason = null): bool
    {
        $db = db();
        $userId = $_SESSION['user_id'] ?? null;
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $stmt = $db->prepare(
                "SELECT pi.*, p.product_code, oo.id AS outbound_id, oo.status AS order_status
                 FROM picklist_items pi
                 JOIN outbound_items oi ON oi.id = pi.outbound_item_id
                 JOIN products p ON p.id = pi.product_id
                 JOIN outbound_orders oo ON oo.id = oi.outbound_order_id
                 WHERE pi.id = ?"
            );
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();

            if (!$item) throw new Exception("Item tidak ditemukan.");
            if ($item['check_status'] !== 'Pending') throw new Exception("Item sudah dicek.");
            if (!in_array($item['order_status'], ['Picking', 'Picked'], true)) {
                throw new Exception("Order belum siap untuk dicek.");
            }

            // Segregation of duties: checker cannot be the picker.
            if (!empty($item['picked_by']) && (int)$item['picked_by'] === (int)$userId) {
                throw new Exception("Checker tidak boleh sama dengan picker.");
            }

            $lpnScan = strtoupper(trim($scannedLpn));
            $skuScan = strtoupper(trim($scannedSku));
            $lpnMismatch = ($lpnScan !== strtoupper($item['lpn_code'] ?? ''));
            $skuMismatch = ($skuScan !== strtoupper($item['product_code'] ?? ''));
            $anyMismatch = $lpnMismatch || $skuMismatch;

            self::_logScan('picking', $itemId, 'LPN', $item['lpn_code'], $lpnScan, $lpnMismatch ? 'MISMATCH' : 'MATCH', $userId);
            self::_logScan('picking', $itemId, 'SKU', $item['product_code'], $skuScan, $skuMismatch ? 'MISMATCH' : 'MATCH', $userId);

            if ($anyMismatch) {
                if (trim($overrideReason ?? '') === '') {
                    throw new Exception("Data tidak sesuai. Alasan override wajib diisi.");
                }
                // Override requires supervisor/admin.
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
