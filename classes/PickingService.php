<?php
declare(strict_types=1);

/**
 * PickingService — handles pick confirmations, carton allocation, and stock deductions.
 */
class PickingService
{
    /**
     * Confirm a pick for a picklist item — deducts stock and logs audit trail.
     */
    public static function confirmPick(int $picklistItemId, int $operatorId, float $qty, string $scanLpn = ''): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            // Get picklist item
            $itemStmt = $db->prepare(
                "SELECT pi.*, p.picklist_number, p.outbound_order_id
                 FROM picklist_items pi
                 JOIN picklists p ON p.id = pi.picklist_id
                 WHERE pi.id = ?"
            );
            $itemStmt->execute([$picklistItemId]);
            $item = $itemStmt->fetch();
            if (!$item) throw new ApiException('Picklist item not found', 404);

            if (!in_array($item['status'], ['Pending', 'In Progress'])) {
                throw new ApiException('Item cannot be picked (status: ' . $item['status'] . ')', 409);
            }

            $allocatedLpn = $item['lpn_code'] ?? '';
            if ($scanLpn !== '' && $scanLpn !== $allocatedLpn) {
                throw new InvalidLpnException($scanLpn, $allocatedLpn);
            }

            if ($qty <= 0) throw new ApiException('Pick quantity must be positive', 400);
            if ($qty > (float)$item['quantity']) {
                throw new ApiException('Pick quantity exceeds allocated quantity', 409);
            }

            // Deduct from stock_locations
            $stockLocStmt = $db->prepare(
                "SELECT id, quantity FROM stock_locations
                 WHERE lpn_code = ? AND location_code = ? AND status = 'Available' AND quantity >= ?"
            );
            $stockLocStmt->execute([$allocatedLpn, $item['bin_location'], $qty]);
            $stockLoc = $stockLocStmt->fetch();
            if (!$stockLoc) {
                throw new InsufficientStockException($allocatedLpn, $qty, 0.0);
            }

            $newQty = round((float)$stockLoc['quantity'] - $qty, 6);
            if ($newQty < 1e-9) {
                $db->prepare("UPDATE stock_locations SET quantity = 0, status = 'Empty' WHERE id = ?")
                   ->execute([(int)$stockLoc['id']]);
            } else {
                $db->prepare("UPDATE stock_locations SET quantity = ? WHERE id = ?")
                   ->execute([$newQty, (int)$stockLoc['id']]);
            }

            // Update picklist item
            $db->prepare(
                "UPDATE picklist_items
                 SET status = 'Picked', qty_picked = ?, picked_at = NOW(), picked_by = ?
                 WHERE id = ?"
            )->execute([$qty, $operatorId, $picklistItemId]);

            // Audit log — resolve operator → user_id for FK
            $opStmt = $db->prepare("SELECT user_id FROM operators WHERE id = ?");
            $opStmt->execute([$operatorId]);
            $userIdForAudit = (int)($opStmt->fetchColumn() ?: $operatorId);

            $db->prepare(
                "INSERT INTO audit_log (module, module_id, action, user_id, details, created_at)
                 VALUES ('picklist_item', ?, 'PICK_CONFIRMED', ?, ?, NOW())"
            )->execute([
                $picklistItemId,
                $userIdForAudit,
                json_encode([
                    'lpn_code'   => $allocatedLpn,
                    'bin'        => $item['bin_location'],
                    'qty_picked' => $qty,
                ]),
            ]);

            $db->commit();
            return [
                'picklist_item_id' => $picklistItemId,
                'qty_picked'       => $qty,
                'status'           => 'Picked',
            ];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get pending picks for an operator.
     */
    public static function getPendingPicks(int $operatorId): array
    {
        $db = db();
        $stmt = $db->prepare(
            "SELECT pi.*, p.picklist_number, p.outbound_order_id, o.order_number
             FROM picklist_items pi
             JOIN picklists p ON p.id = pi.picklist_id
             JOIN outbound_orders o ON o.id = p.outbound_order_id
             WHERE pi.assigned_to = ? AND pi.status IN ('Pending', 'In Progress')
             ORDER BY pi.id"
        );
        $stmt->execute([$operatorId]);
        return $stmt->fetchAll();
    }
}
