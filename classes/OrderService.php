<?php
declare(strict_types=1);

/**
 * OrderService — outbound order management.
 * Works with the existing outbound_orders / outbound_items tables.
 */
class OrderService
{
    /**
     * Create a new outbound order with line items.
     */
    public static function create(array $data): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            $orderNumber = self::generateOrderNumber();
            $orderDate   = $data['order_date'] ?? date('Y-m-d');
            $customerId  = (int)($data['customer_id'] ?? 0);
            if ($customerId <= 0) {
                throw new ApiException('customer_id is required', 400);
            }
            $expectedDate = $data['expected_date'] ?? null;
            $notes        = $data['notes'] ?? null;
            $soNumber     = $data['so_number'] ?? null;
            $doNumber     = $data['do_number'] ?? null;
            $createdBy    = (int)($data['created_by'] ?? 0);

            $stmt = $db->prepare(
                "INSERT INTO outbound_orders
                    (order_number, order_date, customer_id, so_number, do_number,
                     expected_date, status, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, 'Open', ?, ?)"
            );
            $stmt->execute([
                $orderNumber, $orderDate, $customerId,
                $soNumber, $doNumber, $expectedDate, $notes, $createdBy,
            ]);
            $orderId = (int)$db->lastInsertId();

            // Insert line items
            $items = $data['items'] ?? [];
            foreach ($items as $item) {
                $productId   = (int)($item['product_id'] ?? 0);
                $quantity    = (float)($item['quantity'] ?? 0);
                if ($productId <= 0 || $quantity <= 0) continue;

                $uom    = $item['uom'] ?? 'Drum';
                $batch  = $item['batch_no'] ?? $item['batch_number'] ?? null;
                $exp    = $item['exp_date'] ?? $item['expiry_date'] ?? null;

                $itemStmt = $db->prepare(
                    "INSERT INTO outbound_items
                        (outbound_order_id, product_id, quantity, uom, batch_no, exp_date,
                         in_process_status, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'Goods Received', NOW())"
                );
                $itemStmt->execute([$orderId, $productId, $quantity, $uom, $batch, $exp]);
            }

            $db->commit();
            return ['id' => $orderId, 'order_number' => $orderNumber];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get a single order with its line items.
     */
    public static function get(int $id): array
    {
        $db = db();
        $stmt = $db->prepare(
            "SELECT o.*, c.customer_name
             FROM outbound_orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ?"
        );
        $stmt->execute([$id]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new ApiException('Order not found', 404);
        }
        $order['display_order_no'] = Outbound::displayOrderNo($order);

        $itemStmt = $db->prepare(
            "SELECT * FROM outbound_items WHERE outbound_order_id = ? ORDER BY id"
        );
        $itemStmt->execute([$id]);
        $order['items'] = $itemStmt->fetchAll();

        return $order;
    }

    /**
     * List orders with pagination and optional status filter.
     */
    public static function list(int $page = 1, int $perPage = 50, ?string $status = null): array
    {
        $total = Outbound::countAll($status, null);
        $rows  = Outbound::getAll($status, $perPage, ($page - 1) * $perPage, null);
        foreach ($rows as &$r) {
            $r['id'] = (int)$r['id'];
            $r['display_order_no'] = Outbound::displayOrderNo($r);
        }
        unset($r);
        return [
            'rows'     => $rows,
            'total'    => (int)$total,
            'page'     => $page,
            'per_page' => $perPage,
            'statuses' => ['Open', 'Picking', 'Picked', 'Shipped', 'Delivered', 'Completed', 'Cancelled'],
        ];
    }

    /**
     * Mark an order as Shipped.
     */
    public static function markShipped(int $id): void
    {
        $db = db();
        $stmt = $db->prepare(
            "UPDATE outbound_orders SET status = 'Shipped', shipped_date = CURDATE() WHERE id = ? AND status NOT IN ('Completed','Cancelled','Shipped')"
        );
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) {
            throw new ApiException('Order cannot be shipped (wrong status or not found)', 409);
        }
    }

    /**
     * Mark an order as Partially Shipped.
     */
    public static function markPartiallyShipped(int $id, array $itemQtys): void
    {
        $db = db();
        $db->beginTransaction();
        try {
            foreach ($itemQtys as $itemId => $qtyShipped) {
                $qty = (float)$qtyShipped;
                if ($qty <= 0) continue;
                $db->prepare(
                    "UPDATE outbound_items SET qty_shipped = qty_shipped + ? WHERE id = ? AND outbound_order_id = ?"
                )->execute([$qty, (int)$itemId, $id]);
            }
            $db->prepare(
                "UPDATE outbound_orders SET status = 'Picking' WHERE id = ? AND status = 'Open'"
            )->execute([$id]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Generate next order number: OB-YYYYMM-NNNN.
     */
    private static function generateOrderNumber(): string
    {
        return generate_number(
            'outbound_orders', 'order_number',
            'OB-' . date('Ym') . '-', 'OB-' . date('Ym') . '-', 4
        );
    }
}
