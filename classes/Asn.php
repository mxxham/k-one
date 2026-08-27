<?php

class Asn {

    /* ------------------------------------------------------------------ */
    /* S24 — Advance Shipping Notice                                       */
    /* ------------------------------------------------------------------ */

    public static function list(array $params = []): array {
        $db = db();
        $sql = "SELECT a.*, u.full_name AS created_by_name,
                       COUNT(ai.id) AS item_count,
                       COALESCE(SUM(ai.expected_qty),0) AS expected_total
                FROM asn a
                LEFT JOIN users u ON a.created_by = u.id
                LEFT JOIN asn_items ai ON ai.asn_id = a.id";
        $args = [];
        $where = [];
        if (!empty($params['status'])) { $where[] = "a.status = ?"; $args[] = $params['status']; }
        if (!empty($params['from']))   { $where[] = "a.expected_arrival_date >= ?"; $args[] = $params['from']; }
        if (!empty($params['to']))     { $where[] = "a.expected_arrival_date <= ?"; $args[] = $params['to']; }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " GROUP BY a.id
                  ORDER BY CASE WHEN a.status = 'Pending' THEN 0 ELSE 1 END,
                           a.expected_arrival_date ASC, a.created_at DESC";
        if (!empty($params['limit'])) {
            $sql .= " LIMIT ?";
            $args[] = (int)$params['limit'];
        }
        if (!empty($params['offset'])) {
            $sql .= " OFFSET ?";
            $args[] = (int)$params['offset'];
        }
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['item_count']     = (int)$r['item_count'];
            $r['expected_total'] = floatval($r['expected_total']);
        }
        unset($r);
        return $rows;
    }

    public static function countAll(?string $status = null): int {
        $db = db();
        if ($status) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM asn WHERE status = ?");
            $stmt->execute([$status]);
        } else {
            $stmt = $db->query("SELECT COUNT(*) FROM asn");
        }
        return (int)$stmt->fetchColumn();
    }

    public static function detail(int $id): array {
        $db = db();
        $stmt = $db->prepare("SELECT a.*, u.full_name AS created_by_name
                FROM asn a LEFT JOIN users u ON a.created_by = u.id WHERE a.id = ?");
        $stmt->execute([$id]);
        $header = $stmt->fetch();
        if (!$header) throw new Exception("ASN tidak ditemukan.");

        $itemsStmt = $db->prepare("SELECT ai.*, p.product_code, p.product_name, p.uom_type
                FROM asn_items ai
                JOIN products p ON ai.product_id = p.id
                WHERE ai.asn_id = ? ORDER BY ai.id");
        $itemsStmt->execute([$id]);
        return ['asn' => $header, 'items' => $itemsStmt->fetchAll(), 'inbound_orders' => []];
    }

    public static function createWithNumber(array $data): array {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $supplier = trim($data['supplier_name'] ?? '');
            if ($supplier === '') throw new Exception("supplier_name wajib diisi.");
            if (empty($data['expected_arrival_date'])) throw new Exception("expected_arrival_date wajib diisi.");
            if (empty($data['items']) || !is_array($data['items'])) throw new Exception("Minimal satu item wajib diisi.");

            $asnNumber = self::generateNumber();
            $stmt = $db->prepare("INSERT INTO asn
                    (asn_number, supplier_name, supplier_reference, expected_arrival_date,
                     status, notes, created_by)
                    VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
            $stmt->execute([
                $asnNumber, $supplier,
                $data['supplier_reference'] ?? null,
                $data['expected_arrival_date'],
                $data['notes'] ?? null,
                $_SESSION['user_id'] ?? null,
            ]);
            $asnId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO asn_items
                    (asn_id, product_id, expected_qty, uom, batch_number, exp_date)
                    VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($data['items'] as $item) {
                $pid  = (int)($item['product_id'] ?? 0);
                $qty  = floatval($item['expected_qty'] ?? 0);
                if (!$pid) throw new Exception("product_id item wajib diisi.");
                if ($qty <= 0) throw new Exception("expected_qty harus lebih dari 0.");

                $chk = $db->prepare("SELECT uom_type FROM products WHERE id = ?");
                $chk->execute([$pid]);
                $prod = $chk->fetch();
                if (!$prod) throw new Exception("Produk ID {$pid} tidak ditemukan.");

                $itemStmt->execute([
                    $asnId, $pid, $qty,
                    $item['uom'] ?? $prod['uom_type'] ?? 'Drum',
                    $item['batch_number'] ?? null,
                    $item['exp_date'] ?? null,
                ]);
            }

            if ($ownTx) $db->commit();
            return ['id' => $asnId, 'asn_number' => $asnNumber];
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function create(array $data): int {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $supplier = trim($data['supplier_name'] ?? '');
            if ($supplier === '') throw new Exception("supplier_name wajib diisi.");
            if (empty($data['expected_arrival_date'])) throw new Exception("expected_arrival_date wajib diisi.");
            if (empty($data['items']) || !is_array($data['items'])) throw new Exception("Minimal satu item wajib diisi.");

            $asnNumber = self::generateNumber();
            $stmt = $db->prepare("INSERT INTO asn
                    (asn_number, supplier_name, supplier_reference, expected_arrival_date,
                     status, notes, created_by)
                    VALUES (?, ?, ?, ?, 'Pending', ?, ?)");
            $stmt->execute([
                $asnNumber, $supplier,
                $data['supplier_reference'] ?? null,
                $data['expected_arrival_date'],
                $data['notes'] ?? null,
                $_SESSION['user_id'] ?? null,
            ]);
            $asnId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO asn_items
                    (asn_id, product_id, expected_qty, uom, batch_number, exp_date)
                    VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($data['items'] as $item) {
                $pid  = (int)($item['product_id'] ?? 0);
                $qty  = floatval($item['expected_qty'] ?? 0);
                if (!$pid) throw new Exception("product_id item wajib diisi.");
                if ($qty <= 0) throw new Exception("expected_qty harus lebih dari 0.");

                $chk = $db->prepare("SELECT uom_type FROM products WHERE id = ?");
                $chk->execute([$pid]);
                $prod = $chk->fetch();
                if (!$prod) throw new Exception("Produk ID {$pid} tidak ditemukan.");

                $itemStmt->execute([
                    $asnId, $pid, $qty,
                    $item['uom'] ?? $prod['uom_type'] ?? 'Drum',
                    $item['batch_number'] ?? null,
                    $item['exp_date'] ?? null,
                ]);
            }

            if ($ownTx) $db->commit();
            return $asnId;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function generateNumber(): string {
        $db = db();
        $prefix = 'ASN-' . date('Ym') . '-';
        $stmt = $db->prepare("SELECT asn_number FROM asn
                WHERE asn_number LIKE ? ORDER BY asn_number DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq = $last ? ((int)substr($last, strrpos($last, '-') + 1)) + 1 : 1;
        for ($i = 0; $i < 20; $i++) {
            $num = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM asn WHERE asn_number = ?");
            $chk->execute([$num]);
            if (!$chk->fetch()) return $num;
            $seq++;
        }
        return $prefix . date('His') . rand(10, 99);
    }

    public static function update(int $id, array $data): bool {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $header = $db->prepare("SELECT * FROM asn WHERE id = ?");
            $header->execute([$id]);
            $asn = $header->fetch();
            if (!$asn) throw new Exception("ASN tidak ditemukan.");
            if ($asn['status'] !== 'Pending') throw new Exception("Hanya ASN berstatus Pending yang bisa diedit.");

            $db->prepare("UPDATE asn SET
                    supplier_name = COALESCE(?, supplier_name),
                    supplier_reference = COALESCE(?, supplier_reference),
                    expected_arrival_date = COALESCE(?, expected_arrival_date),
                    notes = COALESCE(?, notes),
                    updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?")
               ->execute([
                   $data['supplier_name'] ?? null,
                   $data['supplier_reference'] ?? null,
                   $data['expected_arrival_date'] ?? null,
                   $data['notes'] ?? null,
                   $id,
               ]);

            if (isset($data['items']) && is_array($data['items'])) {
                $db->prepare("DELETE FROM asn_items WHERE asn_id = ?")->execute([$id]);
                $itemStmt = $db->prepare("INSERT INTO asn_items
                        (asn_id, product_id, expected_qty, uom, batch_number, exp_date)
                        VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($data['items'] as $item) {
                    $pid = (int)($item['product_id'] ?? 0);
                    $qty = floatval($item['expected_qty'] ?? 0);
                    if (!$pid || $qty <= 0) throw new Exception("Item tidak valid.");
                    $chk = $db->prepare("SELECT uom_type FROM products WHERE id = ?");
                    $chk->execute([$pid]);
                    $prod = $chk->fetch();
                    $itemStmt->execute([
                        $id, $pid, $qty,
                        $item['uom'] ?? $prod['uom_type'] ?? 'Drum',
                        $item['batch_number'] ?? null,
                        $item['exp_date'] ?? null,
                    ]);
                }
            }

            if ($ownTx) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function cancel(int $id): bool {
        $db = db();
        $header = $db->prepare("SELECT status FROM asn WHERE id = ?");
        $header->execute([$id]);
        $asn = $header->fetch();
        if (!$asn) throw new Exception("ASN tidak ditemukan.");
        if ($asn['status'] !== 'Pending') throw new Exception("Hanya ASN berstatus Pending yang bisa dibatalkan.");
        $db->prepare("UPDATE asn SET status = 'Cancelled', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$id]);
        return true;
    }

    /** Called by Inbound integration when inbound completes. */
    public static function markReceived(int $id): bool {
        $db = db();
        $header = $db->prepare("SELECT status FROM asn WHERE id = ?");
        $header->execute([$id]);
        $asn = $header->fetch();
        if (!$asn) throw new Exception("ASN tidak ditemukan.");
        if ($asn['status'] === 'Cancelled') throw new Exception("ASN yang dibatalkan tidak bisa ditandai Received.");
        $db->prepare("UPDATE asn SET status = 'Received', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
           ->execute([$id]);
        return true;
    }

    /** Get linked inbound orders for an ASN. */
    public static function linkedInbounds(int $asnId): array {
        $db = db();
        $stmt = $db->prepare("SELECT id, order_number, shipment_no, do_number, status
                FROM inbound_orders WHERE asn_id = ? ORDER BY id");
        $stmt->execute([$asnId]);
        return $stmt->fetchAll();
    }
}
?>