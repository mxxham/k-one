<?php

class RmaService {

    /* ------------------------------------------------------------------ */
    /* Create RMA                                                          */
    /* ------------------------------------------------------------------ */

    public static function create(int $outboundOrderId, array $items, string $reason, ?int $userId = null): int {
        $db = db();

        if (empty($items)) {
            throw new \InvalidArgumentException('items tidak boleh kosong');
        }
        if ($outboundOrderId <= 0) {
            throw new \InvalidArgumentException('outbound_order_id tidak valid');
        }

        // Validate outbound order exists
        $stmt = $db->prepare("SELECT id FROM outbound_orders WHERE id = ?");
        $stmt->execute([$outboundOrderId]);
        if (!$stmt->fetch()) {
            throw new \RuntimeException('Outbound order tidak ditemukan');
        }

        // Validate all products
        foreach ($items as $item) {
            $pid = (int)($item['product_id'] ?? 0);
            $qty = (float)($item['quantity'] ?? 0);
            if ($pid <= 0) {
                throw new \InvalidArgumentException('product_id tidak valid pada item');
            }
            if ($qty <= 0) {
                throw new \InvalidArgumentException('quantity harus lebih dari 0 pada item product_id=' . $pid);
            }
            $chk = $db->prepare("SELECT id FROM products WHERE id = ?");
            $chk->execute([$pid]);
            if (!$chk->fetch()) {
                throw new \RuntimeException('Produk #' . $pid . ' tidak ditemukan');
            }
        }

        try {
            $db->beginTransaction();

            $rmaNumber = generate_number('rma', 'rma_number', 'RMA-' . date('Ymd') . '-', 'RMA-' . date('Ymd') . '-');
            $userId = $userId ?? ($_SESSION['user_id'] ?? null);

            $stmt = $db->prepare("INSERT INTO rma (rma_number, outbound_order_id, status, reason, created_by)
                                  VALUES (?, ?, 'Pending', ?, ?)");
            $stmt->execute([$rmaNumber, $outboundOrderId, $reason, $userId]);
            $rmaId = (int)$db->lastInsertId();

            // Insert items
            $itemStmt = $db->prepare("INSERT INTO rma_items (rma_id, product_id, quantity, condition_type, notes)
                                      VALUES (?, ?, ?, ?, ?)");
            foreach ($items as $item) {
                $itemStmt->execute([
                    $rmaId,
                    (int)$item['product_id'],
                    (float)$item['quantity'],
                    trim($item['condition_type'] ?? 'Good'),
                    $item['notes'] ?? null,
                ]);
            }

            $db->commit();
            return $rmaId;

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Approve RMA                                                         */
    /* ------------------------------------------------------------------ */

    public static function approve(int $rmaId, ?int $userId = null): void {
        $db = db();
        $rma = self::getById($rmaId, $db);
        if (!$rma) {
            throw new \RuntimeException('RMA tidak ditemukan');
        }
        if ($rma['status'] !== 'Pending') {
            throw new \RuntimeException('RMA hanya dapat di-approve dari status Pending. Status saat ini: ' . $rma['status']);
        }

        $userId = $userId ?? ($_SESSION['user_id'] ?? null);
        $stmt = $db->prepare("UPDATE rma SET status = 'Approved', approved_by = ?, approved_at = NOW() WHERE id = ?");
        $stmt->execute([$userId, $rmaId]);
    }

    /* ------------------------------------------------------------------ */
    /* Receive RMA — record received items, add stock back                 */
    /* ------------------------------------------------------------------ */

    public static function receive(int $rmaId, array $receivedItems, ?int $userId = null): void {
        $db = db();
        $rma = self::getById($rmaId, $db);
        if (!$rma) {
            throw new \RuntimeException('RMA tidak ditemukan');
        }
        if ($rma['status'] !== 'Approved') {
            throw new \RuntimeException('RMA hanya dapat diterima dari status Approved. Status saat ini: ' . $rma['status']);
        }
        if (empty($receivedItems)) {
            throw new \InvalidArgumentException('receivedItems tidak boleh kosong');
        }

        try {
            $db->beginTransaction();

            $updStmt = $db->prepare("UPDATE rma_items SET received_qty = ?, location = ?, notes = ? WHERE id = ? AND rma_id = ?");
            foreach ($receivedItems as $ri) {
                $itemId = (int)($ri['item_id'] ?? 0);
                $receivedQty = (float)($ri['received_qty'] ?? 0);
                $location = $ri['location'] ?? null;
                $notes = $ri['notes'] ?? null;

                if ($itemId <= 0) {
                    throw new \InvalidArgumentException('item_id tidak valid pada received item');
                }
                if ($receivedQty < 0) {
                    throw new \InvalidArgumentException('received_qty tidak boleh negatif');
                }

                // Verify item belongs to this RMA
                $chk = $db->prepare("SELECT id, product_id FROM rma_items WHERE id = ? AND rma_id = ?");
                $chk->execute([$itemId, $rmaId]);
                $itemRow = $chk->fetch();
                if (!$itemRow) {
                    throw new \RuntimeException('Item #' . $itemId . ' tidak ditemukan pada RMA #' . $rmaId);
                }

                $updStmt->execute([$receivedQty, $location, $notes, $itemId, $rmaId]);

                // Add stock back for received items
                if ($receivedQty > 0) {
                    $productId = (int)$itemRow['product_id'];
                    $stockLocation = $location ?: 'RMA_AREA';

                    // Check for existing stock at this location
                    $stockStmt = $db->prepare("SELECT id, quantity FROM stock WHERE product_id = ? AND location = ? AND stock_status = 'Available' LIMIT 1");
                    $stockStmt->execute([$productId, $stockLocation]);
                    $stock = $stockStmt->fetch();

                    if ($stock) {
                        $newQty = (float)$stock['quantity'] + $receivedQty;
                        $updStock = $db->prepare("UPDATE stock SET quantity = ? WHERE id = ?");
                        $updStock->execute([$newQty, $stock['id']]);
                    } else {
                        // Get product UOM info
                        $prodStmt = $db->prepare("SELECT uom_type, uom_per_pallet FROM products WHERE id = ?");
                        $prodStmt->execute([$productId]);
                        $prod = $prodStmt->fetch();
                        $uomType = $prod['uom_type'] ?? 'Drum';
                        $uomPerPallet = (int)($prod['uom_per_pallet'] ?? 4);
                        $pallet = (int)ceil($receivedQty / max($uomPerPallet, 1));

                        $insStock = $db->prepare("INSERT INTO stock (product_id, location, quantity, uom, uom_type, uom_per_pallet, pallet, stock_status)
                                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'Available')");
                        $insStock->execute([$productId, $stockLocation, $receivedQty, $uomType, $uomType, $uomPerPallet, $pallet]);
                    }

                    // Create stock ledger entry
                    $pcStmt = $db->prepare("SELECT product_code FROM products WHERE id = ?");
                    $pcStmt->execute([$productId]);
                    $productCode = $pcStmt->fetchColumn() ?: '';

                    $balStmt = $db->prepare("SELECT COALESCE(SUM(quantity_in),0) - COALESCE(SUM(quantity_out),0) AS running_balance
                                             FROM stock_ledger WHERE product_id = ?");
                    $balStmt->execute([$productId]);
                    $balance = (float)($balStmt->fetch()['running_balance'] ?? 0);

                    $refNo = 'RMA-' . $rma['rma_number'];
                    $ledgerStmt = $db->prepare("INSERT INTO stock_ledger
                        (transaction_date, product_id, transaction_type, quantity_in, quantity_out, reference_number, reference_type, reference_id, balance, location, notes)
                        VALUES (CURDATE(), ?, 'IN', ?, 0, ?, 'RMA', ?, ?, ?, ?)");
                    $ledgerStmt->execute([
                        $productId,
                        $receivedQty,
                        $refNo,
                        $rmaId,
                        $balance + $receivedQty,
                        $stockLocation,
                        'RMA Return: ' . $rma['reason'],
                    ]);
                }
            }

            // Update RMA status to Received
            $stmt = $db->prepare("UPDATE rma SET status = 'Received' WHERE id = ?");
            $stmt->execute([$rmaId]);

            $db->commit();

        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /* ------------------------------------------------------------------ */
    /* Complete RMA                                                        */
    /* ------------------------------------------------------------------ */

    public static function complete(int $rmaId): void {
        $db = db();
        $rma = self::getById($rmaId, $db);
        if (!$rma) {
            throw new \RuntimeException('RMA tidak ditemukan');
        }
        if ($rma['status'] !== 'Received') {
            throw new \RuntimeException('RMA hanya dapat diselesaikan dari status Received. Status saat ini: ' . $rma['status']);
        }

        $stmt = $db->prepare("UPDATE rma SET status = 'Completed', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$rmaId]);
    }

    /* ------------------------------------------------------------------ */
    /* Reject RMA                                                          */
    /* ------------------------------------------------------------------ */

    public static function reject(int $rmaId, string $reason, ?int $userId = null): void {
        $db = db();
        $rma = self::getById($rmaId, $db);
        if (!$rma) {
            throw new \RuntimeException('RMA tidak ditemukan');
        }
        if (!in_array($rma['status'], ['Pending', 'Approved'], true)) {
            throw new \RuntimeException('RMA hanya dapat ditolak dari status Pending atau Approved. Status saat ini: ' . $rma['status']);
        }

        if ($reason === '') {
            throw new \InvalidArgumentException('reason tidak boleh kosong');
        }

        $stmt = $db->prepare("UPDATE rma SET status = 'Rejected', reason = CONCAT(COALESCE(reason, ''), ' | Rejected: ', ?) WHERE id = ?");
        $stmt->execute([$reason, $rmaId]);
    }

    /* ------------------------------------------------------------------ */
    /* Get RMA by ID with items                                            */
    /* ------------------------------------------------------------------ */

    public static function getById(int $rmaId, $db = null): ?array {
        $db = $db ?? db();
        $stmt = $db->prepare("SELECT r.*,
                u1.full_name AS created_by_name,
                u2.full_name AS approved_by_name
                FROM rma r
                LEFT JOIN users u1 ON r.created_by = u1.id
                LEFT JOIN users u2 ON r.approved_by = u2.id
                WHERE r.id = ?");
        $stmt->execute([$rmaId]);
        $rma = $stmt->fetch();
        if (!$rma) return null;

        // Attach items
        $itemStmt = $db->prepare("SELECT ri.*, p.product_code, p.product_name, p.uom_type
                FROM rma_items ri
                JOIN products p ON ri.product_id = p.id
                WHERE ri.rma_id = ?
                ORDER BY ri.id");
        $itemStmt->execute([$rmaId]);
        $rma['items'] = $itemStmt->fetchAll();

        return $rma;
    }

    /* ------------------------------------------------------------------ */
    /* Get all RMAs with filters                                           */
    /* ------------------------------------------------------------------ */

    public static function getAll(array $filters = [], $db = null): array {
        $db = $db ?? db();
        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = "r.status = ?";
            $params[] = $filters['status'];
        }

        $where = $conditions ? "WHERE " . implode(" AND ", $conditions) : "";

        // Count total
        $countSql = "SELECT COUNT(*) FROM rma r $where";
        $countStmt = $db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        // Fetch rows
        $page = max(1, (int)($filters['page'] ?? 1));
        $perPage = max(1, min((int)($filters['per_page'] ?? 50), 500));
        $offset = ($page - 1) * $perPage;

        $sql = "SELECT r.*,
                u1.full_name AS created_by_name,
                u2.full_name AS approved_by_name,
                COUNT(DISTINCT ri.id) AS total_items,
                SUM(ri.quantity) AS total_qty,
                SUM(COALESCE(ri.received_qty, 0)) AS total_received_qty
                FROM rma r
                LEFT JOIN users u1 ON r.created_by = u1.id
                LEFT JOIN users u2 ON r.approved_by = u2.id
                LEFT JOIN rma_items ri ON r.id = ri.rma_id
                $where
                GROUP BY r.id
                ORDER BY r.created_at DESC
                LIMIT $perPage OFFSET $offset";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $totalPages = (int)ceil($total / $perPage);

        return [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1,
        ];
    }
}
