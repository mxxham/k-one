<?php
declare(strict_types=1);

/**
 * PicklistService — extends Picklist with wave-based generation and FEFO allocation.
 * Depends on Wave, FefoAllocator, InsufficientStockException.
 */
class PicklistService
{
    /**
     * Generate picklists for all orders in a wave.
     * Each wave order gets its own picklist with FEFO-allocated items.
     * Returns array of created picklist IDs.
     */
    public static function generateForWave(int $waveId): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            // Validate wave status
            $waveStmt = $db->prepare("SELECT id, status FROM waves WHERE id = ?");
            $waveStmt->execute([$waveId]);
            $wave = $waveStmt->fetch();
            if (!$wave) throw new ApiException('Wave not found', 404);
            if ($wave['status'] !== 'Active') {
                throw new ApiException('Wave must be Active to generate picklists (current: ' . $wave['status'] . ')', 409);
            }

            // Get orders in wave
            $orderStmt = $db->prepare(
                "SELECT wo.outbound_order_id FROM wave_orders wo WHERE wo.wave_id = ?"
            );
            $orderStmt->execute([$waveId]);
            $orderIds = $orderStmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($orderIds)) throw new ApiException('Wave has no orders', 409);

            $picklistIds = [];
            foreach ($orderIds as $orderId) {
                $picklistId = self::createForOrder((int)$orderId, $waveId);
                $picklistIds[] = $picklistId;
            }

            $db->commit();
            return $picklistIds;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Create a picklist for a single outbound order with FEFO allocation.
     */
    public static function createForOrder(int $orderId, ?int $waveId = null): int
    {
        $db = db();
        $db->beginTransaction();
        try {
            // Get order
            $orderStmt = $db->prepare("SELECT * FROM outbound_orders WHERE id = ?");
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();
            if (!$order) throw new ApiException('Outbound order not found', 404);

            // Check for existing picklist
            $existStmt = $db->prepare("SELECT id FROM picklists WHERE outbound_order_id = ? AND status != 'Cancelled'");
            $existStmt->execute([$orderId]);
            $existing = $existStmt->fetch();
            if ($existing) throw new ApiException('Picklist already exists for order', 409);

            // Create picklist
            $picklistNumber = Picklist::generateNumber();
            $insertStmt = $db->prepare(
                "INSERT INTO picklists (outbound_order_id, picklist_number, wave_id, created_date, status, created_by)
                 VALUES (?, ?, ?, CURDATE(), 'Draft', ?)"
            );
            $insertStmt->execute([$orderId, $picklistNumber, $waveId, $_SESSION['user_id'] ?? 0]);
            $picklistId = (int)$db->lastInsertId();

            // Get order items
            $itemStmt = $db->prepare("SELECT * FROM outbound_items WHERE outbound_order_id = ?");
            $itemStmt->execute([$orderId]);
            $orderItems = $itemStmt->fetchAll();

            // Allocate each item via FEFO
            foreach ($orderItems as $item) {
                $productStmt = $db->prepare("SELECT product_code FROM products WHERE id = ?");
                $productStmt->execute([(int)$item['product_id']]);
                $product = $productStmt->fetch();
                if (!$product) continue;

                $sku       = $product['product_code'];
                $qtyNeeded = (float)$item['quantity'];
                $uom       = $item['uom'] ?? 'Drum';
                $batch     = $item['batch_no'] ?? $item['batch_number'] ?? null;
                $expDate   = $item['exp_date'] ?? $item['expiry_date'] ?? null;

                try {
                    $result = FefoAllocator::allocate($sku, $qtyNeeded);
                } catch (InsufficientStockException $e) {
                    // Log shortage but continue — item will show as short
                    $result = ['allocation' => [], 'sufficient' => false, 'shortage' => $qtyNeeded];
                }

                if (!empty($result['allocation'])) {
                    foreach ($result['allocation'] as $alloc) {
                        $db->prepare(
                            "INSERT INTO picklist_items
                                (picklist_id, outbound_item_id, product_id, batch_no, batch_number,
                                 lpn_code, bin_location,
                                 quantity, uom, pallet, status, created_at)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
                        )->execute([
                            $picklistId,
                            (int)$item['id'],
                            (int)$item['product_id'],
                            $alloc['batch_number'] ?? $batch,
                            $alloc['batch_number'] ?? $batch,
                            $alloc['lpn_code'],
                            $alloc['bin_location'],
                            $alloc['qty_to_take'],
                            $uom,
                            1,
                        ]);
                    }
                } else {
                    // No stock available — create item with shortage
                    $db->prepare(
                        "INSERT INTO picklist_items
                            (picklist_id, outbound_item_id, product_id, lpn_code, bin_location,
                             quantity, status, created_at)
                         VALUES (?, ?, ?, NULL, NULL, ?, 'Pending', NOW())"
                    )->execute([$picklistId, (int)$item['id'], (int)$item['product_id'], $qtyNeeded]);
                }
            }

            $db->commit();
            return $picklistId;
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
