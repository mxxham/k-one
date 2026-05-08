<?php

class StockTake {

    

    public static function getAll($limit = null) {
        try {
            $pdo = db();
            $sql = "SELECT st.*, u.full_name as created_by_name,
                    COUNT(sti.id) as total_items,
                    SUM(CASE WHEN sti.status = 'Plus' THEN 1 ELSE 0 END) as plus_count,
                    SUM(CASE WHEN sti.status = 'Minus' THEN 1 ELSE 0 END) as minus_count,
                    SUM(CASE WHEN sti.status = 'Clear' THEN 1 ELSE 0 END) as clear_count
                   FROM stock_take st
                   LEFT JOIN users u ON st.created_by = u.id
                   LEFT JOIN stock_take_items sti ON st.id = sti.stock_take_id
                   GROUP BY st.id
                   ORDER BY st.take_date DESC, st.created_at DESC";

            if ($limit !== null) {
                $sql .= " LIMIT " . intval($limit);
            }

            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting stock takes: " . $e->getMessage());
            return [];
        }
    }

    

    public static function getById($id) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT st.*, u.full_name as created_by_name
                                   FROM stock_take st
                                   LEFT JOIN users u ON st.created_by = u.id
                                   WHERE st.id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting stock take: " . $e->getMessage());
            return null;
        }
    }

    

    public static function getItems($stockTakeId) {
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT sti.*, p.product_code, p.product_name
                                   FROM stock_take_items sti
                                   LEFT JOIN products p ON sti.product_id = p.id
                                   WHERE sti.stock_take_id = ?
                                   ORDER BY p.product_code, sti.location");
            $stmt->execute([$stockTakeId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error getting stock take items: " . $e->getMessage());
            return [];
        }
    }

    

    public static function calculateAccuracy($stockTakeId) {
        try {
            $items = self::getItems($stockTakeId);

            if (empty($items)) {
                return [
                    'total_stock_take' => 0,
                    'plus' => 0,
                    'minus' => 0,
                    'clear' => 0,
                    'accuracy' => 100
                ];
            }

            $totalStockTake = 0;
            $plus = 0;
            $minus = 0;
            $clear = 0;

            foreach ($items as $item) {
                $totalStockTake += $item['qty_physical'];

                if ($item['status'] == 'Plus') {
                    $plus += abs($item['difference']);
                } elseif ($item['status'] == 'Minus') {
                    $minus += abs($item['difference']);
                } else {
                    $clear += $item['qty_physical'];
                }
            }

            
            $accuracy = $totalStockTake > 0 ? round(($clear / $totalStockTake) * 100, 2) : 100;

            return [
                'total_stock_take' => $totalStockTake,
                'plus' => $plus,
                'minus' => $minus,
                'clear' => $clear,
                'accuracy' => $accuracy
            ];
        } catch (Exception $e) {
            error_log("Error calculating accuracy: " . $e->getMessage());
            return [
                'total_stock_take' => 0,
                'plus' => 0,
                'minus' => 0,
                'clear' => 0,
                'accuracy' => 0
            ];
        }
    }

    

    public static function create($data) {
        try {
            $pdo = db();

            $takeNumber = 'ST-' . date('Ymd') . '-' . sprintf('%04d', rand(0, 9999));
            $scopeLocs  = $data['scope_locations'] ?? null; // JSON string or null
            $scopeType  = ($scopeLocs !== null && $scopeLocs !== '[]') ? 'location' : 'full';

            $stmt = $pdo->prepare("INSERT INTO stock_take
                    (take_number, take_date, status, notes, scope_locations, scope_type, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $takeNumber,
                $data['take_date'],
                $data['status'] ?? 'Draft',
                $data['notes'] ?? null,
                $scopeLocs,
                $scopeType,
                $_SESSION['user_id'],
            ]);

            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error creating stock take: " . $e->getMessage());
            return false;
        }
    }

    public static function autoLoadByLocations(int $stockTakeId, ?array $locations): void {
        $db = db();
        $sql    = "SELECT s.product_id, s.batch_number, s.location, s.quantity, s.uom
                   FROM stock s
                   WHERE s.stock_status='Available' AND s.quantity>0
                     AND s.location IS NOT NULL
                     AND s.location NOT IN ('QUA_SHELL','STAGING')";
        $params = [];

        if (!empty($locations)) {
            $ph     = implode(',', array_fill(0, count($locations), '?'));
            $sql   .= " AND s.location IN ($ph)";
            $params = array_values($locations);
        }

        $sql .= " ORDER BY s.location, s.product_id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $stocks = $stmt->fetchAll();

        foreach ($stocks as $s) {
            self::addItemFull($stockTakeId, [
                'product_id'   => $s['product_id'],
                'batch_number' => $s['batch_number'],
                'location'     => $s['location'],
                'uom'          => $s['uom'],
                'qty_system'   => $s['quantity'],
                'qty_physical' => 0,
                'counter_1'    => null,
                'counter_2'    => null,
                'counter_3'    => null,
            ]);
        }
    }

    public static function getActiveLockedLocations(): array {
        $db   = db();
        $stmt = $db->query("SELECT DISTINCT sti.location
                FROM stock_take_items sti
                JOIN stock_take st ON st.id = sti.stock_take_id
                WHERE st.status IN ('Counting','Review')
                  AND sti.location IS NOT NULL");
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    

    public static function addItem($stockTakeId, $data) {
        try {
            $pdo = db();

            $qtySystem = floatval($data['qty_system']);
            $qtyPhysical = floatval($data['qty_physical']);
            $difference = $qtyPhysical - $qtySystem;

            
            if ($difference > 0) {
                $status = 'Plus';
            } elseif ($difference < 0) {
                $status = 'Minus';
            } else {
                $status = 'Clear';
            }

            $stmt = $pdo->prepare("INSERT INTO stock_take_items
                                   (stock_take_id, product_id, batch_number, location, qty_system, qty_physical, difference, status, notes)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

            $stmt->execute([
                $stockTakeId,
                $data['product_id'],
                $data['batch_number'] ?? null,
                $data['location'] ?? null,
                $qtySystem,
                $qtyPhysical,
                $difference,
                $status,
                $data['notes'] ?? null
            ]);

            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error adding stock take item: " . $e->getMessage());
            return false;
        }
    }

    

    public static function update($id, $data) {
        try {
            $pdo = db();

            $stmt = $pdo->prepare("UPDATE stock_take
                                   SET take_date = ?, status = ?, notes = ?
                                   WHERE id = ?");

            return $stmt->execute([
                $data['take_date'],
                $data['status'],
                $data['notes'] ?? null,
                $id
            ]);
        } catch (PDOException $e) {
            error_log("Error updating stock take: " . $e->getMessage());
            return false;
        }
    }

    

    public static function delete($id) {
        try {
            $pdo = db();
            $pdo->beginTransaction();

            
            $stmt = $pdo->prepare("DELETE FROM stock_take_items WHERE stock_take_id = ?");
            $stmt->execute([$id]);

            
            $stmt = $pdo->prepare("DELETE FROM stock_take WHERE id = ?");
            $result = $stmt->execute([$id]);

            $pdo->commit();
            return $result;
        } catch (PDOException $e) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Error deleting stock take: " . $e->getMessage());
            return false;
        }
    }

    

    public static function getSystemStock($productId, $location = null, $batchNumber = null) {
        try {
            $pdo = db();

            $sql = "SELECT COALESCE(SUM(quantity), 0) as total_qty
                    FROM stock
                    WHERE product_id = ?";
            $params = [$productId];

            if ($location !== null && $location !== '') {
                $sql .= " AND location = ?";
                $params[] = $location;
            }

            if ($batchNumber !== null && $batchNumber !== '') {
                $sql .= " AND batch_number = ?";
                $params[] = $batchNumber;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return floatval($result['total_qty']);
        } catch (PDOException $e) {
            error_log("Error getting system stock: " . $e->getMessage());
            return 0;
        }
    }

    

    public static function getStats() {
        try {
            $pdo = db();

            $stats = [];

            
            $result = $pdo->query("SELECT COUNT(*) as count FROM stock_take")->fetch(PDO::FETCH_ASSOC);
            $stats['total'] = $result['count'];

            
            $stmt = $pdo->query("SELECT COUNT(*) as count
                                FROM stock_take
                                WHERE MONTH(take_date) = MONTH(CURDATE())
                                AND YEAR(take_date) = YEAR(CURDATE())");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['this_month'] = $result['count'];

            
            $stmt = $pdo->query("SELECT COUNT(*) as count
                                FROM stock_take
                                WHERE YEAR(take_date) = YEAR(CURDATE())");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['this_year'] = $result['count'];

            
            $stmt = $pdo->query("SELECT AVG(
                                    CASE
                                        WHEN (SELECT COUNT(*) FROM stock_take_items WHERE stock_take_id = st.id) > 0 THEN
                                            ((SELECT SUM(qty_physical) FROM stock_take_items WHERE stock_take_id = st.id AND status = 'Clear') /
                                             (SELECT SUM(qty_physical) FROM stock_take_items WHERE stock_take_id = st.id)) * 100
                                        ELSE 100
                                    END
                                ) as avg_accuracy
                                FROM stock_take st
                                WHERE YEAR(take_date) = YEAR(CURDATE())
                                AND st.status = 'Adjusted'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['avg_accuracy'] = round($result['avg_accuracy'] ?? 100, 2);

            return $stats;
        } catch (PDOException $e) {
            error_log("Error getting stock take stats: " . $e->getMessage());
            return [
                'total' => 0,
                'this_month' => 0,
                'this_year' => 0,
                'avg_accuracy' => 100
            ];
        }
    }
    

    public static function startCounting(int $id): void {
        $db = db();
        $st = self::getById($id);
        if (!$st) throw new \Exception("Stock take tidak ditemukan");
        if ($st['status'] !== 'Draft') throw new \Exception("Status harus Draft untuk memulai Counting");
        $db->prepare("UPDATE stock_take SET status='Counting', counting_round='c1', updated_at=NOW() WHERE id=?")->execute([$id]);
    }

    public static function saveC1(int $id, array $values): void {
        $db   = db();
        $st   = self::getById($id);
        if (!$st) throw new \Exception("Stock take tidak ditemukan");
        if ($st['status'] !== 'Counting' || $st['counting_round'] !== 'c1')
            throw new \Exception("Tidak bisa simpan Counter 1 — bukan giliran C1");
        $stmt = $db->prepare("UPDATE stock_take_items SET counter_1=? WHERE id=? AND stock_take_id=?");
        foreach ($values as $itemId => $val) {
            $c1 = ($val !== '' && $val !== null) ? floatval($val) : null;
            $stmt->execute([$c1, (int)$itemId, $id]);
        }
    }

    public static function advanceToC2(int $id, array $c1Values): void {
        $db = db();
        $st = self::getById($id);
        if (!$st) throw new \Exception("Stock take tidak ditemukan");
        if ($st['status'] !== 'Counting' || $st['counting_round'] !== 'c1')
            throw new \Exception("Harus di tahap Counter 1 untuk maju ke Counter 2");
        self::saveC1($id, $c1Values);
        $db->prepare("UPDATE stock_take SET counting_round='c2', updated_at=NOW() WHERE id=?")->execute([$id]);
    }

    public static function saveC2(int $id, array $values): void {
        $db   = db();
        $st   = self::getById($id);
        if (!$st) throw new \Exception("Stock take tidak ditemukan");
        if ($st['status'] !== 'Counting' || $st['counting_round'] !== 'c2')
            throw new \Exception("Tidak bisa simpan Counter 2 — bukan giliran C2");
        $stmt = $db->prepare("UPDATE stock_take_items SET counter_2=? WHERE id=? AND stock_take_id=?");
        foreach ($values as $itemId => $val) {
            $c2 = ($val !== '' && $val !== null) ? floatval($val) : null;
            $stmt->execute([$c2, (int)$itemId, $id]);
        }
    }

    public static function saveCounters(int $id, array $counters): void {
        $db   = db();
        $stmt = $db->prepare("UPDATE stock_take_items SET counter_1=?, counter_2=?, counter_3=? WHERE id=? AND stock_take_id=?");
        foreach ($counters as $itemId => $v) {
            $c1 = ($v['c1'] !== '' && $v['c1'] !== null) ? floatval($v['c1']) : null;
            $c2 = ($v['c2'] !== '' && $v['c2'] !== null) ? floatval($v['c2']) : null;
            $c3 = ($v['c3'] !== '' && $v['c3'] !== null) ? floatval($v['c3']) : null;
            $stmt->execute([$c1, $c2, $c3, (int)$itemId, $id]);
        }
    }

    public static function finishCounting(int $id, array $c2Values = []): void {
        $db    = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();
            $st = self::getById($id);
            if (!$st) throw new \Exception("Stock take tidak ditemukan");
            if ($st['status'] !== 'Counting') throw new \Exception("Status harus Counting");
            if ($st['counting_round'] !== 'c2')
                throw new \Exception("Counter 1 belum selesai — selesaikan Counter 1 dulu");
            // Save final C2 values before computing
            if (!empty($c2Values)) self::saveC2($id, $c2Values);

            // ── Opsi C: Re-snapshot qty_system from current stock ──────────────
            // Captures any stock movements (bin transfer, inbound, etc.) that
            // occurred between Draft creation and Counting finalization.
            $snapStmt = $db->prepare("
                UPDATE stock_take_items sti
                SET qty_system = (
                    SELECT COALESCE(SUM(s.quantity), 0)
                    FROM stock s
                    WHERE s.product_id = sti.product_id
                      AND (sti.location    IS NULL OR s.location     = sti.location)
                      AND (sti.batch_number IS NULL OR s.batch_number <=> sti.batch_number)
                      AND s.stock_status = 'Available'
                )
                WHERE stock_take_id = ?");
            $snapStmt->execute([$id]);
            // ───────────────────────────────────────────────────────────────────

            $items = self::getItems($id);
            $stmt  = $db->prepare("UPDATE stock_take_items SET qty_physical=?, difference=?, status=? WHERE id=?");
            foreach ($items as $item) {
                $c1 = $item['counter_1'] !== null ? floatval($item['counter_1']) : null;
                $c2 = $item['counter_2'] !== null ? floatval($item['counter_2']) : null;
                $c3 = $item['counter_3'] !== null ? floatval($item['counter_3']) : null;

                if ($c1 !== null && $c2 !== null && abs($c1 - $c2) < 0.001) {
                    $qtyPhysical = $c1;
                } elseif ($c3 !== null) {
                    $qtyPhysical = $c3;
                } elseif ($c2 !== null) {
                    $qtyPhysical = $c2;
                } elseif ($c1 !== null) {
                    $qtyPhysical = $c1;
                } else {
                    $qtyPhysical = 0;
                }

                $difference = $qtyPhysical - floatval($item['qty_system']);
                $status     = $difference > 0.001 ? 'Plus' : ($difference < -0.001 ? 'Minus' : 'Clear');
                $stmt->execute([$qtyPhysical, $difference, $status, $item['id']]);
            }

            $db->prepare("UPDATE stock_take SET status='Review', updated_at=NOW() WHERE id=?")->execute([$id]);
            if ($ownTx) $db->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function saveReview(int $id, array $physicals): void {
        $db      = db();
        $st      = self::getById($id);
        if (!$st) throw new \Exception("Stock take tidak ditemukan");
        if ($st['status'] !== 'Review') throw new \Exception("Status harus Review");

        $getStmt = $db->prepare("SELECT qty_system FROM stock_take_items WHERE id=? AND stock_take_id=?");
        $updStmt = $db->prepare("UPDATE stock_take_items SET qty_physical=?, difference=?, status=? WHERE id=? AND stock_take_id=?");
        foreach ($physicals as $itemId => $qtyPhysical) {
            $qtyPhysical = floatval($qtyPhysical);
            $getStmt->execute([(int)$itemId, $id]);
            $row = $getStmt->fetch();
            if (!$row) continue;
            $difference = $qtyPhysical - floatval($row['qty_system']);
            $status     = $difference > 0.001 ? 'Plus' : ($difference < -0.001 ? 'Minus' : 'Clear');
            $updStmt->execute([$qtyPhysical, $difference, $status, (int)$itemId, $id]);
        }
    }

    public static function applyAdjustment(int $id): void {
        $db    = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();
            $st = self::getById($id);
            if (!$st) throw new \Exception("Stock take tidak ditemukan");
            if ($st['status'] !== 'Review') throw new \Exception("Status harus Review untuk apply adjustment");

            $items = self::getItems($id);
            foreach ($items as $item) {
                $diff        = floatval($item['difference']);
                if (abs($diff) < 0.001) continue;
                $productId   = (int)$item['product_id'];
                $location    = $item['location'];
                $batch       = $item['batch_number'];
                $uom         = $item['uom'] ?? 'Drum';
                $qtyPhysical = floatval($item['qty_physical']);

                $existing = $db->prepare("SELECT id FROM stock
                        WHERE product_id=? AND location=? AND batch_number<=>?
                          AND stock_status='Available' LIMIT 1");
                $existing->execute([$productId, $location, $batch]);
                $stockRow = $existing->fetch();

                if ($stockRow) {
                    if ($qtyPhysical <= 0.001) {
                        $db->prepare("DELETE FROM stock WHERE id=?")->execute([$stockRow['id']]);
                    } else {
                        $db->prepare("UPDATE stock SET quantity=?, updated_at=NOW() WHERE id=?")
                           ->execute([$qtyPhysical, $stockRow['id']]);
                    }
                } elseif ($qtyPhysical > 0.001) {
                    $db->prepare("INSERT INTO stock (product_id, batch_number, location, quantity, uom, stock_status) VALUES (?,?,?,?,?,'Available')")
                       ->execute([$productId, $batch, $location, $qtyPhysical, $uom]);
                }

                $balStmt = $db->prepare("SELECT balance FROM stock_ledger WHERE product_id=? ORDER BY id DESC LIMIT 1");
                $balStmt->execute([$productId]);
                $balance = floatval($balStmt->fetchColumn() ?: 0);
                $qIn     = $diff > 0 ? $diff   : 0;
                $qOut    = $diff < 0 ? abs($diff) : 0;
                $balance += $qIn - $qOut;

                $db->prepare("INSERT INTO stock_ledger
                        (transaction_date, product_id, transaction_type, reference_type,
                         reference_id, reference_number, batch_number,
                         quantity_in, quantity_out, uom, balance, location, notes)
                        VALUES (CURDATE(),?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([
                       $productId, 'ADJUSTMENT', 'StockTake', $id, $st['take_number'],
                       $batch, $qIn, $qOut, $uom, $balance, $location,
                       "Stock Take Adjustment " . ($diff > 0 ? "+$diff" : "$diff"),
                   ]);
            }

            $db->prepare("UPDATE stock_take SET status='Adjusted', updated_at=NOW() WHERE id=?")->execute([$id]);
            if ($ownTx) $db->commit();
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    public static function addItemFull($stockTakeId, $data) {
        try {
            $pdo = db();
            $qtySystem   = floatval($data['qty_system']);
            $qtyPhysical = floatval($data['qty_physical']);
            $difference  = $qtyPhysical - $qtySystem;

            if ($difference > 0)      $status = 'Plus';
            elseif ($difference < 0)  $status = 'Minus';
            else                      $status = 'Clear';

            $stmt = $pdo->prepare("INSERT INTO stock_take_items
                (stock_take_id, product_id, batch_number, uom, location,
                 qty_system, counter_1, counter_2, counter_3,
                 qty_physical, difference, status, notes, counter_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

            $stmt->execute([
                $stockTakeId,
                $data['product_id'],
                $data['batch_number'] ?? null,
                $data['uom'] ?? null,
                $data['location'] ?? null,
                $qtySystem,
                $data['counter_1'] ?? null,
                $data['counter_2'] ?? null,
                $data['counter_3'] ?? null,
                $qtyPhysical,
                $difference,
                $status,
                $data['notes'] ?? null,
                $data['counter_by'] ?? null,
            ]);

            return $pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Error adding stock take item: " . $e->getMessage());
            return false;
        }
    }

}
?>
