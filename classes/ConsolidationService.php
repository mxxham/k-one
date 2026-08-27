<?php
declare(strict_types=1);

/**
 * ConsolidationService — consolidate staged items into dispatch-ready pallets.
 */
class ConsolidationService
{
    /**
     * Consolidate staged items for a picklist into a single dispatch group.
     * Scans multiple staged LPNs into one pallet for dispatch.
     */
    public static function consolidate(int $picklistId, string $targetLpn, int $operatorId, array $stagingIds): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            // Validate picklist
            $plStmt = $db->prepare("SELECT id, status, outbound_order_id FROM picklists WHERE id = ?");
            $plStmt->execute([$picklistId]);
            $picklist = $plStmt->fetch();
            if (!$picklist) throw new ApiException('Picklist not found', 404);
            if (!in_array($picklist['status'], ['Picking', 'Picked', 'Staging'])) {
                throw new ApiException('Picklist cannot be consolidated (status: ' . $picklist['status'] . ')', 409);
            }

            // Validate target LPN format
            if (!preg_match('/^LPN-\d{8}-\d{5}$/', $targetLpn)) {
                throw new InvalidLpnException($targetLpn, 'LPN-YYYYMMDD-NNNNN');
            }

            // Check target LPN doesn't already exist
            $existStmt = $db->prepare("SELECT id FROM stock_locations WHERE lpn_code = ? LIMIT 1");
            $existStmt->execute([$targetLpn]);
            if ($existStmt->fetch()) {
                throw new PickReferenceConflictException($targetLpn, 'Target LPN already exists');
            }

            // Get staged items
            $stagingStmt = $db->prepare(
                "SELECT s.*, pi.lpn_code AS item_lpn
                 FROM staging s
                 JOIN picklist_items pi ON pi.id = s.picklist_item_id
                 WHERE s.id IN (" . implode(',', array_fill(0, count($stagingIds), '?')) . ")
                   AND pi.picklist_id = ?"
            );
            $params = array_merge($stagingIds, [$picklistId]);
            $stagingStmt->execute($params);
            $stagedItems = $stagingStmt->fetchAll();

            if (empty($stagedItems)) {
                throw new ApiException('No valid staged items found', 409);
            }

            $totalQty = 0.0;
            $totalLiters = 0.0;
            foreach ($stagedItems as $item) {
                $totalQty += (float)$item['quantity'];
                // Get liters_per_unit from product via stock_locations → stock → products
                $prodStmt = $db->prepare(
                    "SELECT p.liters_per_unit FROM products p
                     JOIN stock s ON s.product_id = p.id
                     JOIN stock_locations sl ON sl.stock_id = s.id
                     WHERE sl.lpn_code = ? LIMIT 1"
                );
                $prodStmt->execute([$item['item_lpn']]);
                $liters = (float)($prodStmt->fetchColumn() ?: 0);
                $totalLiters += $liters * (float)$item['quantity'];
            }

            // Create consolidated stock entry
            $db->prepare(
                "INSERT INTO stock_locations (stock_id, location_code, lpn_code, quantity, original_quantity, uom, is_full_pallet, batch_number, status)
                 SELECT stock_id, 'STAGING', ?, quantity, original_quantity, uom, is_full_pallet, batch_number, 'Consolidated'
                 FROM stock_locations WHERE lpn_code = ? LIMIT 1"
            )->execute([$targetLpn, $stagedItems[0]['item_lpn']]);

            // Remove staged items
            $delStmt = $db->prepare("DELETE FROM staging WHERE id IN (" . implode(',', array_fill(0, count($stagingIds), '?')) . ")");
            $delStmt->execute($stagingIds);

            // Audit log — resolve operator → user_id for FK
            $opStmt = $db->prepare("SELECT user_id FROM operators WHERE id = ?");
            $opStmt->execute([$operatorId]);
            $userIdForAudit = (int)($opStmt->fetchColumn() ?: $operatorId);

            $db->prepare(
                "INSERT INTO audit_log (module, module_id, action, user_id, details, created_at)
                 VALUES ('consolidation', ?, 'CONSOLIDATE', ?, ?, NOW())"
            )->execute([
                $picklistId,
                $userIdForAudit,
                json_encode([
                    'target_lpn'    => $targetLpn,
                    'staged_count'  => count($stagedItems),
                    'total_qty'     => round($totalQty, 2),
                    'total_liters'  => round($totalLiters, 2),
                ]),
            ]);

            $db->commit();
            return [
                'picklist_id'   => $picklistId,
                'target_lpn'    => $targetLpn,
                'total_qty'     => round($totalQty, 2),
                'total_liters'  => round($totalLiters, 2),
                'items_count'   => count($stagedItems),
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get consolidation status for a picklist.
     */
    public static function getStatus(int $picklistId): array
    {
        $db = db();
        $staged = $db->prepare(
            "SELECT COUNT(*) as count, COALESCE(SUM(quantity), 0) as total_qty
             FROM staging s
             JOIN picklist_items pi ON pi.id = s.picklist_item_id
             WHERE pi.picklist_id = ?"
        );
        $staged->execute([$picklistId]);
        $stagedRow = $staged->fetch();

        $consolidated = $db->prepare(
            "SELECT lpn_code, quantity FROM stock_locations
             WHERE location_code = 'STAGING' AND lpn_code LIKE 'LPN-%'
             ORDER BY id DESC LIMIT 10"
        );
        $consolidated->execute();
        $consolidatedRows = $consolidated->fetchAll();

        return [
            'staged_count'  => (int)$stagedRow['count'],
            'staged_qty'    => round((float)$stagedRow['total_qty'], 2),
            'consolidated'  => $consolidatedRows,
        ];
    }
}
