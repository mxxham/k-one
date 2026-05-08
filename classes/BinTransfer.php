<?php

class BinTransfer {

    

    public static function generateNumber(): string {
        $db     = db();
        $prefix = 'BTR-' . date('Ym') . '-';

        $stmt = $db->prepare("SELECT transfer_number FROM bin_transfers
                               WHERE transfer_number LIKE ?
                               ORDER BY transfer_number DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq  = $last ? ((int) substr($last, strrpos($last, '-') + 1)) + 1 : 1;

        for ($i = 0; $i < 20; $i++) {
            $num = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM bin_transfers WHERE transfer_number = ?");
            $chk->execute([$num]);
            if (!$chk->fetch()) return $num;
            $seq++;
        }
        return $prefix . date('His') . rand(10, 99);
    }

    

    public static function getAll(?string $status = null, int $limit = 200, int $offset = 0): array {
        $db     = db();
        $where  = $status ? "WHERE bt.status = ?" : "";
        $params = $status ? [$status] : [];

        $sql = "SELECT bt.*,
                p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                u1.full_name AS created_by_name,
                u2.full_name AS completed_by_name
                FROM bin_transfers bt
                JOIN products p ON bt.product_id = p.id
                LEFT JOIN users u1 ON bt.created_by = u1.id
                LEFT JOIN users u2 ON bt.completed_by = u2.id
                $where
                ORDER BY bt.transfer_date DESC, bt.created_at DESC
                LIMIT " . intval($limit) . " OFFSET " . intval($offset);

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function countAll(?string $status = null): int {
        $db     = db();
        $where  = $status ? "WHERE bt.status = ?" : "";
        $params = $status ? [$status] : [];
        $stmt   = $db->prepare("SELECT COUNT(*) FROM bin_transfers bt $where");
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }



    public static function getById(int $id): ?array {
        $db   = db();
        $stmt = $db->prepare("SELECT bt.*,
                p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                u1.full_name AS created_by_name,
                u2.full_name AS completed_by_name
                FROM bin_transfers bt
                JOIN products p ON bt.product_id = p.id
                LEFT JOIN users u1 ON bt.created_by  = u1.id
                LEFT JOIN users u2 ON bt.completed_by = u2.id
                WHERE bt.id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    

    public static function getStockAtLocation(int $productId, string $location = ''): array {
        $db = db();
        if ($location !== '') {
            $stmt = $db->prepare("SELECT s.*, p.product_name, p.product_code, p.uom_type
                    FROM stock s
                    JOIN products p ON s.product_id = p.id
                    WHERE s.product_id = ?
                      AND s.location = ?
                      AND s.stock_status = 'Available'
                      AND s.quantity > 0
                    ORDER BY
                        CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END,
                        s.expiry_date ASC");
            $stmt->execute([$productId, $location]);
        } else {
            $stmt = $db->prepare("SELECT s.*, p.product_name, p.product_code, p.uom_type
                    FROM stock s
                    JOIN products p ON s.product_id = p.id
                    WHERE s.product_id = ?
                      AND s.stock_status = 'Available'
                      AND s.quantity > 0
                      AND s.location NOT IN ('STAGING')
                    ORDER BY s.location,
                        CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END,
                        s.expiry_date ASC");
            $stmt->execute([$productId]);
        }
        return $stmt->fetchAll();
    }

    

    public static function getLocationsWithStock(int $productId): array {
        $db   = db();
        $stmt = $db->prepare("SELECT s.location,
                SUM(s.quantity) AS total_qty, s.uom,
                MIN(s.expiry_date) AS earliest_expiry,
                COUNT(*) AS batch_count
                FROM stock s
                WHERE s.product_id = ?
                  AND s.stock_status = 'Available'
                  AND s.quantity > 0
                  AND s.location NOT IN ('STAGING')
                GROUP BY s.location, s.uom
                ORDER BY s.location");
        $stmt->execute([$productId]);
        return $stmt->fetchAll();
    }

    

    public static function create(array $data): int {
        $db    = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $number      = self::generateNumber();
            $specialLocs = ['QUA_SHELL', 'STAGING'];
            $fromLocCode = strtoupper(trim($data['from_location'] ?? ''));
            $toLocCode   = strtoupper(trim($data['to_location']   ?? ''));

            // Validate from_location against master
            if (!in_array($fromLocCode, $specialLocs)) {
                $chk = $db->prepare("SELECT id FROM location_master WHERE location_code = ? AND is_active = 1 LIMIT 1");
                $chk->execute([$fromLocCode]);
                if (!$chk->fetch()) {
                    throw new \Exception("Lokasi sumber '{$fromLocCode}' tidak ditemukan di master lokasi.");
                }
            }

            // Validate to_location against master
            if (!in_array($toLocCode, $specialLocs)) {
                $chk = $db->prepare("SELECT id FROM location_master WHERE location_code = ? AND is_active = 1 LIMIT 1");
                $chk->execute([$toLocCode]);
                if (!$chk->fetch()) {
                    throw new \Exception("Lokasi tujuan '{$toLocCode}' tidak ditemukan di master lokasi.");
                }
            }

            // Cannot transfer to the same location
            if ($fromLocCode === $toLocCode) {
                throw new \Exception("Lokasi sumber dan tujuan tidak boleh sama ({$fromLocCode}).");
            }

            $availStmt = $db->prepare("SELECT id, quantity FROM stock
                    WHERE product_id = ?
                      AND location   = ?
                      AND stock_status = 'Available'
                      AND quantity > 0
                    ORDER BY
                        CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
                        expiry_date ASC
                    LIMIT 1");
            $availStmt->execute([$data['product_id'], $fromLocCode]);
            $stockRow = $availStmt->fetch();

            if (!$stockRow) {
                throw new \Exception("Tidak ada stok tersedia di lokasi {$fromLocCode}.");
            }

            $totalStmt = $db->prepare("SELECT SUM(quantity) FROM stock
                    WHERE product_id = ? AND location = ?
                      AND stock_status = 'Available'");
            $totalStmt->execute([$data['product_id'], $fromLocCode]);
            $totalAvail = (float) $totalStmt->fetchColumn();

            if ((float)$data['quantity'] > $totalAvail + 0.001) {
                throw new \Exception(
                    "Stok tidak cukup. Tersedia: " . number_format($totalAvail, 2) .
                    " — Diminta: " . number_format($data['quantity'], 2)
                );
            }

            $stmt = $db->prepare("INSERT INTO bin_transfers
                    (transfer_number, transfer_date, product_id, stock_id,
                     batch_number, from_location, to_location,
                     quantity, uom, reason, status, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?)");

            $stmt->execute([
                $number,
                $data['transfer_date'],
                $data['product_id'],
                $stockRow['id'],
                $data['batch_number'] ?? null,
                $fromLocCode,
                $toLocCode,
                $data['quantity'],
                $data['uom'] ?? 'Drum',
                $data['reason'] ?? null,
                $_SESSION['user_id'] ?? null,
            ]);

            $id = (int) $db->lastInsertId();

            if ($ownTx) $db->commit();
            return $id;

        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    

    public static function execute(int $transferId): bool {
        $db    = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $transfer = self::getById($transferId);
            if (!$transfer) throw new \Exception("Transfer tidak ditemukan");
            if ($transfer['status'] !== 'Pending') {
                throw new \Exception("Transfer status harus Pending (saat ini: {$transfer['status']})");
            }

            $qty       = (float) $transfer['quantity'];
            $productId = (int)   $transfer['product_id'];
            $fromLoc   = $transfer['from_location'];
            $toLoc     = $transfer['to_location'];
            $uom       = $transfer['uom'];

            
            $srcStmt = $db->prepare("SELECT * FROM stock
                    WHERE product_id = ? AND location = ?
                      AND stock_status = 'Available'
                      AND quantity > 0
                    ORDER BY
                        CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END,
                        expiry_date ASC");
            $srcStmt->execute([$productId, $fromLoc]);
            $srcRows = $srcStmt->fetchAll();

            if (empty($srcRows)) {
                throw new \Exception("Tidak ada stok di lokasi sumber: $fromLoc");
            }

            $totalAvail = array_sum(array_column($srcRows, 'quantity'));
            if ($qty > $totalAvail + 0.001) {
                throw new \Exception("Stok tidak cukup: tersedia " . round($totalAvail, 2) . ", diminta " . round($qty, 2));
            }

            $remaining  = $qty;
            $usedBatch  = null;
            $usedExpiry = null;

            foreach ($srcRows as $src) {
                if ($remaining <= 0.001) break;
                $deduct = min($remaining, (float) $src['quantity']);
                $newQty = (float) $src['quantity'] - $deduct;

                if (!$usedBatch) {
                    $usedBatch  = $src['batch_number'];
                    $usedExpiry = $src['expiry_date'];
                }

                
                if ($newQty <= 0.001) {
                    $db->prepare("DELETE FROM stock WHERE id = ?")->execute([$src['id']]);
                } else {
                    $db->prepare("UPDATE stock SET quantity = ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$newQty, $src['id']]);
                }

                
                $slStmt = $db->prepare("SELECT id, quantity FROM stock_locations
                        WHERE stock_id = ? AND status = 'Available' ORDER BY pallet_seq ASC LIMIT 1");
                $slStmt->execute([$src['id']]);
                if ($sl = $slStmt->fetch()) {
                    $slNew = max(0, (float)$sl['quantity'] - $deduct);
                    $db->prepare("UPDATE stock_locations SET quantity = ?, status = ?  WHERE id = ?")
                       ->execute([$slNew, $slNew <= 0 ? 'Picked' : 'Available', $sl['id']]);
                }

                $remaining -= $deduct;
            }

            
            $existing = $db->prepare("SELECT id, quantity FROM stock
                    WHERE product_id = ? AND location = ?
                      AND batch_number <=> ?
                      AND stock_status = 'Available'
                    LIMIT 1");
            $existing->execute([$productId, $toLoc, $usedBatch]);
            $dest = $existing->fetch();

            if ($dest) {
                $db->prepare("UPDATE stock SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?")
                   ->execute([$qty, $dest['id']]);
                $destStockId = $dest['id'];
            } else {
                $insStmt = $db->prepare("INSERT INTO stock
                        (product_id, batch_number, location, quantity, uom,
                         pallet, manufacture_date, expiry_date, stock_status)
                        VALUES (?, ?, ?, ?, ?, ?, NULL, ?, 'Available')");
                $insStmt->execute([
                    $productId, $usedBatch, $toLoc, $qty, $uom,
                    ceil($qty / max(1, intval($transfer['uom_per_pallet'] ?? 4))),
                    $usedExpiry,
                ]);
                $destStockId = (int) $db->lastInsertId();
            }

            
            
            
            $balStmt = $db->prepare("SELECT balance FROM stock_ledger
                    WHERE product_id = ? ORDER BY id DESC LIMIT 1");
            $balStmt->execute([$productId]);
            $currentBalance = (float)($balStmt->fetchColumn() ?: 0);

            
            self::_addLedger($productId, 'TRANSFER_OUT', 'BinTransfer', $transferId,
                $transfer['transfer_number'], $usedBatch, 0, $qty, $uom, $fromLoc,
                "Bin Transfer ke $toLoc", $currentBalance);
            
            self::_addLedger($productId, 'TRANSFER_IN', 'BinTransfer', $transferId,
                $transfer['transfer_number'], $usedBatch, $qty, 0, $uom, $toLoc,
                "Bin Transfer dari $fromLoc", $currentBalance);

            
            $db->prepare("UPDATE bin_transfers SET
                    status       = 'Completed',
                    completed_by = ?,
                    completed_at = NOW(),
                    updated_at   = NOW()
                    WHERE id = ?")
               ->execute([$_SESSION['user_id'] ?? null, $transferId]);

            if ($ownTx) $db->commit();
            return true;

        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    

    public static function cancel(int $transferId): bool {
        $db = db();
        $transfer = self::getById($transferId);
        if (!$transfer) throw new \Exception("Transfer tidak ditemukan");
        if ($transfer['status'] !== 'Pending') {
            throw new \Exception("Hanya transfer berstatus Pending yang dapat dibatalkan");
        }
        $db->prepare("UPDATE bin_transfers SET status = 'Cancelled', updated_at = NOW() WHERE id = ?")
           ->execute([$transferId]);
        return true;
    }

    

    

    private static function _addLedger(
        int $productId, string $txType, string $refType, int $refId,
        string $refNo, ?string $batch, float $qIn, float $qOut,
        string $uom, string $location, string $notes,
        ?float $forceBalance = null
    ): void {
        $db = db();

        if ($forceBalance !== null) {
            
            $balance = $forceBalance;
        } else {
            $balStmt = $db->prepare("SELECT balance FROM stock_ledger
                    WHERE product_id = ? ORDER BY id DESC LIMIT 1");
            $balStmt->execute([$productId]);
            $balance = ((float)$balStmt->fetchColumn()) + $qIn - $qOut;
        }

        $db->prepare("INSERT INTO stock_ledger
                (transaction_date, product_id, transaction_type, reference_type,
                 reference_id, reference_number, batch_number,
                 quantity_in, quantity_out, uom, balance, location, notes)
                VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([
               $productId, $txType, $refType, $refId, $refNo,
               $batch, $qIn, $qOut, $uom, $balance, $location, $notes
           ]);
    }
}
?>
