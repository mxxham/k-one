<?php
declare(strict_types=1);

/**
 * StagingService — scan picked items into staging locations before dispatch.
 */
class StagingService
{
    /**
     * Scan a picked item into a staging bay.
     * Moves the stock from its original bin to the staging location.
     */
    public static function scanStaging(int $picklistItemId, string $stagingBin, int $operatorId, float $qty): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            // Validate picklist item
            $itemStmt = $db->prepare(
                "SELECT pi.*, p.picklist_number FROM picklist_items pi
                 JOIN picklists p ON p.id = pi.picklist_id
                 WHERE pi.id = ?"
            );
            $itemStmt->execute([$picklistItemId]);
            $item = $itemStmt->fetch();
            if (!$item) throw new ApiException('Picklist item not found', 404);
            if ($item['status'] !== 'Picked') {
                throw new ApiException('Item must be Picked before staging (current: ' . $item['status'] . ')', 409);
            }

            // Validate staging bin exists
            $binStmt = $db->prepare("SELECT location_code FROM location_master WHERE location_code = ?");
            $binStmt->execute([$stagingBin]);
            if (!$binStmt->fetch()) throw new ApiException('Staging bin not found: ' . $stagingBin, 404);

            // Move stock
            $lpn = $item['lpn_code'] ?? '';
            if ($lpn === '') throw new ApiException('Picklist item has no LPN', 409);

            $stockLocStmt = $db->prepare(
                "SELECT id, quantity FROM stock_locations
                 WHERE lpn_code = ? AND location_code = ? AND status = 'Available' AND quantity >= ?"
            );
            $stockLocStmt->execute([$lpn, $item['bin_location'], $qty]);
            $stockLoc = $stockLocStmt->fetch();
            if (!$stockLoc) throw new InsufficientStockException($lpn, $qty, 0.0);

            $newQty = round((float)$stockLoc['quantity'] - $qty, 6);
            if ($newQty < 1e-9) {
                $db->prepare("UPDATE stock_locations SET quantity = 0, status = 'Empty' WHERE id = ?")
                   ->execute([(int)$stockLoc['id']]);
            } else {
                $db->prepare("UPDATE stock_locations SET quantity = ? WHERE id = ?")
                   ->execute([$newQty, (int)$stockLoc['id']]);
            }

            // Resolve operator → user_id for FK constraints
            $opStmt = $db->prepare("SELECT user_id FROM operators WHERE id = ?");
            $opStmt->execute([$operatorId]);
            $userIdForAudit = (int)($opStmt->fetchColumn() ?: $operatorId);

            // Insert into staging location
            $db->prepare(
                "INSERT INTO staging (picklist_item_id, lpn_code, staging_bin, quantity, operator_id, scanned_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            )->execute([$picklistItemId, $lpn, $stagingBin, $qty, $userIdForAudit]);

            // Update picklist item status
            $db->prepare("UPDATE picklist_items SET status = 'Staged' WHERE id = ?")
               ->execute([$picklistItemId]);

            // Audit log

            $db->prepare(
                "INSERT INTO audit_log (module, module_id, action, user_id, details, created_at)
                 VALUES ('staging', ?, 'STAGE_SCAN', ?, ?, NOW())"
            )->execute([
                $picklistItemId,
                $userIdForAudit,
                json_encode(['lpn' => $lpn, 'staging_bin' => $stagingBin, 'qty' => $qty]),
            ]);

            $db->commit();
            return ['picklist_item_id' => $picklistItemId, 'staging_bin' => $stagingBin, 'qty' => $qty, 'status' => 'Staged'];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get items staged for a picklist.
     */
    public static function getStagedItems(int $picklistId): array
    {
        $db = db();
        $stmt = $db->prepare(
            "SELECT s.*, pi.lpn_code AS item_lpn, pi.bin_location AS original_bin
             FROM staging s
             JOIN picklist_items pi ON pi.id = s.picklist_item_id
             WHERE pi.picklist_id = ?
             ORDER BY s.scanned_at DESC"
        );
        $stmt->execute([$picklistId]);
        return $stmt->fetchAll();
    }
}
