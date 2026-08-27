<?php

class Wave {

    public static function generateNumber(): string {
        $db = db();
        $prefix = 'WAV-' . date('Ym') . '-';
        $stmt = $db->prepare("SELECT wave_number FROM waves
                WHERE wave_number LIKE ? ORDER BY wave_number DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq = $last ? ((int)substr($last, strrpos($last, '-') + 1)) + 1 : 1;
        for ($i = 0; $i < 20; $i++) {
            $num = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM waves WHERE wave_number = ?");
            $chk->execute([$num]);
            if (!$chk->fetch()) return $num;
            $seq++;
        }
        return $prefix . date('His') . rand(10, 99);
    }

    public static function countAll(?string $status = null): int {
        $db = db();
        if ($status) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM waves WHERE status = ?");
            $stmt->execute([$status]);
        } else {
            $stmt = $db->prepare("SELECT COUNT(*) FROM waves");
            $stmt->execute();
        }
        return (int)$stmt->fetchColumn();
    }

    public static function getAll(?string $status = null, ?int $limit = null, int $offset = 0): array {
        $db = db();
        $conditions = [];
        $args = [];
        if ($status) {
            $args[] = $status;
            $conditions[] = 'w.status = ?';
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT w.*,
                u.full_name AS created_by_name,
                COUNT(DISTINCT wo.outbound_order_id) AS order_count,
                COUNT(DISTINCT pki.id) AS item_count,
                pkl.id AS picklist_id,
                pkl.picklist_number,
                pkl.status AS picklist_status
           FROM waves w
           LEFT JOIN users u ON w.created_by = u.id
           LEFT JOIN wave_orders wo ON wo.wave_id = w.id
           LEFT JOIN picklists pkl ON pkl.wave_id = w.id
           LEFT JOIN picklist_items pki ON pki.picklist_id = pkl.id
           $where
           GROUP BY w.id, u.full_name, pkl.id, pkl.picklist_number, pkl.status
           ORDER BY w.created_at DESC";
        if ($limit) {
            $args[] = $limit;
            $args[] = $offset;
            $sql .= " LIMIT ? OFFSET ?";
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public static function getById(int $id): ?array {
        $db = db();
        $stmt = $db->prepare("SELECT w.*, u.full_name AS created_by_name
                FROM waves w LEFT JOIN users u ON w.created_by = u.id WHERE w.id = ?");
        $stmt->execute([$id]);
        $wave = $stmt->fetch() ?: null;
        if (!$wave) return null;
        $ordersR = $db->prepare("SELECT o.*, c.customer_name, c.customer_code, c.city,
                COUNT(DISTINCT oi.id) AS total_items,
                SUM(oi.actual_qty) AS total_qty
            FROM wave_orders wo
            JOIN outbound_orders o ON wo.outbound_order_id = o.id
            LEFT JOIN customers c ON o.customer_id = c.id
            LEFT JOIN outbound_items oi ON oi.outbound_order_id = o.id
            WHERE wo.wave_id = ?
            GROUP BY o.id, c.customer_name, c.customer_code, c.city
            ORDER BY o.order_number");
        $ordersR->execute([$id]);
        $orders = $ordersR->fetchAll();
        foreach ($orders as &$o) {
            $o['id'] = (int)$o['id'];
            $o['total_items'] = (int)($o['total_items'] ?? 0);
        }
        unset($o);
        $plR = $db->prepare("SELECT id, picklist_number, status FROM picklists WHERE wave_id = ?");
        $plR->execute([$id]);
        $wave['orders'] = $orders;
        $wave['picklist'] = $plR->fetch() ?: null;
        return $wave;
    }

    public static function detail(int $id): array {
        $wave = self::getById($id);
        if (!$wave) throw new \ApiException('Wave tidak ditemukan', 404);
        return ['wave' => $wave];
    }

    public static function create(array $data, int $createdBy): array {
        $rawIds = $data['order_ids'] ?? [];
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $rawIds), fn($n) => $n > 0)));
        if (empty($orderIds)) throw new \ApiException('order_ids wajib diisi.', 400);

        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $existStmt = $db->prepare("SELECT id FROM outbound_orders WHERE id IN ($ph)");
            $existStmt->execute($orderIds);
            $foundSet = array_flip(array_map('intval', array_column($existStmt->fetchAll(), 'id')));
            $missing = array_filter($orderIds, fn($id) => !isset($foundSet[$id]));
            if (!empty($missing)) {
                throw new \ApiException('Terdapat outbound order tidak ditemukan: ' . implode(', ', $missing), 400);
            }

            $ineligStmt = $db->prepare("SELECT id FROM outbound_orders o
                WHERE o.id IN ($ph)
                  AND (o.status <> 'Open'
                       OR EXISTS (SELECT 1 FROM picklists pl WHERE pl.outbound_order_id = o.id))");
            $ineligStmt->execute($orderIds);
            $ineligSet = array_flip(array_map('intval', array_column($ineligStmt->fetchAll(), 'id')));
            $validIds = array_values(array_filter($orderIds, fn($id) => !isset($ineligSet[$id])));
            if (empty($validIds)) {
                throw new \ApiException('Tidak ada outbound order yang memenuhi syarat (status Open dan belum memiliki picklist).', 400);
            }
            $skipped = array_values(array_filter($orderIds, fn($id) => isset($ineligSet[$id])));

            $waveNumber = self::generateNumber();
            $stmt = $db->prepare("INSERT INTO waves (wave_number, status, carrier, cutoff_time, created_by)
                    VALUES (?, 'Planning', ?, ?, ?)");
            $stmt->execute([
                $waveNumber,
                $data['carrier'] ?? null,
                !empty($data['cutoff_time']) ? $data['cutoff_time'] : null,
                $createdBy,
            ]);
            $waveId = (int)$db->lastInsertId();

            $woStmt = $db->prepare("INSERT INTO wave_orders (wave_id, outbound_order_id) VALUES (?, ?)");
            foreach ($validIds as $oid) {
                $woStmt->execute([$waveId, $oid]);
            }

            $picklistId = Picklist::createFromOrders($validIds, $createdBy, $waveId, $db);

            if ($ownTx) $db->commit();
            return ['wave_id' => $waveId, 'picklist_id' => $picklistId, 'skipped' => $skipped];
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function cancel(int $waveId): void {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $wave = self::getById($waveId);
            if (!$wave) throw new \ApiException('Wave tidak ditemukan', 404);
            if ($wave['status'] === 'Completed') {
                throw new \ApiException('Wave yang sudah Completed tidak dapat dibatalkan.', 400);
            }

            $plStmt = $db->prepare("SELECT id, status FROM picklists WHERE wave_id = ?");
            $plStmt->execute([$waveId]);
            foreach ($plStmt->fetchAll() as $pl) {
                if ($pl['status'] === 'Draft') {
                    $db->prepare("DELETE FROM picklist_items WHERE picklist_id = ?")->execute([$pl['id']]);
                    $db->prepare("DELETE FROM picklists WHERE id = ?")->execute([$pl['id']]);
                }
            }

            $db->prepare("UPDATE waves SET status = 'Cancelled' WHERE id = ?")->execute([$waveId]);

            if ($ownTx) $db->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function candidateOrders(): array {
        $db = db();
        $stmt = $db->prepare("SELECT o.id, o.order_number, o.order_date, o.so_number, o.do_number,
                o.destination, o.kota, o.armada_no, o.container_no, o.expected_date,
                c.customer_name, c.customer_code, c.city,
                COUNT(DISTINCT oi.id) AS total_items,
                SUM(oi.actual_qty) AS total_qty
           FROM outbound_orders o
           JOIN customers c ON o.customer_id = c.id
           LEFT JOIN outbound_items oi ON oi.outbound_order_id = o.id
           WHERE o.status = 'Open'
             AND NOT EXISTS (SELECT 1 FROM picklists pl WHERE pl.outbound_order_id = o.id)
           GROUP BY o.id, c.customer_name, c.customer_code, c.city
           ORDER BY o.order_number");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Add an outbound order to a wave (must be in Planning status).
     * Idempotent: adding an order already in the wave is a no-op.
     */
    public static function addOrder(int $waveId, int $orderId): void
    {
        $db = db();
        $db->beginTransaction();
        try {
            $wave = $db->prepare("SELECT id, status FROM waves WHERE id = ?");
            $wave->execute([$waveId]);
            $w = $wave->fetch();
            if (!$w) throw new ApiException('Wave not found', 404);
            if ($w['status'] !== 'Planning') {
                throw new ApiException('Cannot add order to wave in status ' . $w['status'], 409);
            }
            $order = $db->prepare("SELECT id, status FROM outbound_orders WHERE id = ?");
            $order->execute([$orderId]);
            $o = $order->fetch();
            if (!$o) throw new ApiException('Outbound order not found', 404);
            if ($o['status'] !== 'Open') {
                throw new ApiException('Order must be Open to add to wave (current: ' . $o['status'] . ')', 409);
            }
            // Idempotent insert
            $db->prepare(
                "INSERT IGNORE INTO wave_orders (wave_id, outbound_order_id) VALUES (?, ?)"
            )->execute([$waveId, $orderId]);
            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * Release a wave — set status to Active and generate picklists for all wave orders.
     */
    public static function release(int $waveId): array
    {
        $db = db();
        $db->beginTransaction();
        try {
            $wave = $db->prepare("SELECT id, status FROM waves WHERE id = ?");
            $wave->execute([$waveId]);
            $w = $wave->fetch();
            if (!$w) throw new ApiException('Wave not found', 404);
            if ($w['status'] !== 'Planning') {
                throw new ApiException('Wave must be in Planning to release (current: ' . $w['status'] . ')', 409);
            }
            $db->prepare("UPDATE waves SET status = 'Active' WHERE id = ?")->execute([$waveId]);
            $db->commit();
            return ['wave_id' => $waveId, 'status' => 'Active'];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }
}
