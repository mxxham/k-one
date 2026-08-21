<?php

class Wave {

    /* ------------------------------------------------------------------ */
    /* S23 — Wave planning (multi-order consolidated picklist)             */
    /* ------------------------------------------------------------------ */

    public static function candidateOrders(): array {
        $db = db();
        $stmt = $db->prepare("SELECT o.*, c.customer_name,
                        COUNT(oi.id) AS total_items
                FROM outbound_orders o
                LEFT JOIN customers c ON o.customer_id = c.id
                LEFT JOIN outbound_items oi ON oi.outbound_order_id = o.id
                WHERE o.status IN ('Open','Draft')
                  AND NOT EXISTS (SELECT 1 FROM picklists p WHERE p.outbound_order_id = o.id)
                GROUP BY o.id
                ORDER BY o.order_date ASC, o.created_at ASC");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['total_items'] = (int)$r['total_items'];
        }
        unset($r);
        return $rows;
    }

    public static function create(array $data): array {
        $orderIds = array_values(array_unique(array_map('intval', $data['order_ids'] ?? [])));
        if (empty($orderIds)) throw new Exception("Pilih minimal satu outbound order.");
        if (count($orderIds) > 50) throw new Exception("Maksimal 50 order per wave.");

        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            // Validate orders: exist, eligible, no existing picklist
            $ph = implode(',', array_fill(0, count($orderIds), '?'));
            $chk = $db->prepare("SELECT id FROM outbound_orders
                    WHERE id IN ($ph) AND status IN ('Open','Draft')
                    AND NOT EXISTS (SELECT 1 FROM picklists p WHERE p.outbound_order_id = outbound_orders.id)");
            $chk->execute($orderIds);
            $valid = array_column($chk->fetchAll(), 'id');
            if (count($valid) !== count($orderIds)) {
                throw new Exception("Beberapa order tidak eligible (status harus Open/Draft dan belum punya picklist).");
            }

            $waveNumber = self::generateNumber();
            $stmt = $db->prepare("INSERT INTO waves
                    (wave_number, status, carrier, cutoff_time, created_by)
                    VALUES (?, 'Planning', ?, ?, ?)");
            $stmt->execute([
                $waveNumber,
                $data['carrier'] ?? null,
                !empty($data['cutoff_time']) ? $data['cutoff_time'] : null,
                $_SESSION['user_id'] ?? null,
            ]);
            $waveId = (int)$db->lastInsertId();

            $woStmt = $db->prepare("INSERT INTO wave_orders (wave_id, outbound_order_id) VALUES (?, ?)");
            foreach ($valid as $oid) {
                $woStmt->execute([$waveId, $oid]);
            }

            $picklistId = self::_buildConsolidatedPicklist($waveId, $valid);

            if ($ownTx) $db->commit();
            return ['wave_id' => $waveId, 'picklist_id' => $picklistId, 'wave_number' => $waveNumber];
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

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

    /** One consolidated picklist for the wave — delegates to Picklist::createFromOrders (TS parity). */
    private static function _buildConsolidatedPicklist(int $waveId, array $orderIds): int {
        $db = db();
        $picklistId = Picklist::createFromOrders(
            $orderIds,
            (int)($_SESSION['user_id'] ?? 0),
            $waveId,
            $db
        );
        return $picklistId;
    }

    public static function cancel(int $waveId): bool {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $wave = self::getById($waveId);
            if (!$wave) throw new Exception("Wave tidak ditemukan.");
            if ($wave['status'] === 'Cancelled') throw new Exception("Wave sudah dibatalkan.");
            if ($wave['status'] === 'Completed') throw new Exception("Wave sudah selesai, tidak bisa dibatalkan.");

            // Delete only Draft picklists; preserve progressed ones
            $plStmt = $db->prepare("SELECT id, status FROM picklists WHERE wave_id = ?");
            $plStmt->execute([$waveId]);
            $picklists = $plStmt->fetchAll();

            $hasProgressed = false;
            foreach ($picklists as $pl) {
                if ($pl['status'] !== 'Draft') {
                    $hasProgressed = true;
                    continue;
                }
                $db->prepare("DELETE FROM picklist_items WHERE picklist_id = ?")->execute([$pl['id']]);
                $db->prepare("DELETE FROM picklists WHERE id = ?")->execute([$pl['id']]);
            }

            $db->prepare("UPDATE waves SET status = 'Cancelled', updated_at = NOW() WHERE id = ?")
               ->execute([$waveId]);

            if ($ownTx) $db->commit();
            return !$hasProgressed; // false = cancelled but some picklists preserved
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function list(array $params = []): array {
        $db = db();
        $sql = "SELECT w.*, u.full_name AS created_by_name,
                       COUNT(DISTINCT wo.outbound_order_id) AS order_count,
                       COUNT(DISTINCT pkl.id) AS picklist_count,
                       COUNT(DISTINCT pki.id) AS total_items
                FROM waves w
                LEFT JOIN users u ON w.created_by = u.id
                LEFT JOIN wave_orders wo ON wo.wave_id = w.id
                LEFT JOIN picklists pkl ON pkl.wave_id = w.id
                LEFT JOIN picklist_items pki ON pki.picklist_id = pkl.id";
        $args = [];
        if (!empty($params['status'])) {
            $sql .= " WHERE w.status = ?";
            $args[] = $params['status'];
        }
        $sql .= " GROUP BY w.id ORDER BY w.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['order_count']    = (int)$r['order_count'];
            $r['picklist_count'] = (int)$r['picklist_count'];
            $r['total_items']    = (int)$r['total_items'];
        }
        unset($r);
        return $rows;
    }

    public static function getById(int $id): ?array {
        $db = db();
        $stmt = $db->prepare("SELECT w.*, u.full_name AS created_by_name
                FROM waves w LEFT JOIN users u ON w.created_by = u.id WHERE w.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function detail(int $id): array {
        $db = db();
        $wave = self::getById($id);
        if (!$wave) throw new Exception("Wave tidak ditemukan.");

        $ordersStmt = $db->prepare("SELECT wo.outbound_order_id, o.order_number,
                        c.customer_name, o.order_date
                FROM wave_orders wo
                JOIN outbound_orders o ON o.id = wo.outbound_order_id
                LEFT JOIN customers c ON o.customer_id = c.id
                WHERE wo.wave_id = ? ORDER BY o.order_date");
        $ordersStmt->execute([$id]);
        $orders = $ordersStmt->fetchAll();

        $picklistsStmt = $db->prepare("SELECT pkl.*, COUNT(pki.id) AS item_count
                FROM picklists pkl
                LEFT JOIN picklist_items pki ON pki.picklist_id = pkl.id
                WHERE pkl.wave_id = ? GROUP BY pkl.id");
        $picklistsStmt->execute([$id]);
        $picklists = $picklistsStmt->fetchAll();

        return ['wave' => $wave, 'orders' => $orders, 'picklists' => $picklists];
    }
}
?>