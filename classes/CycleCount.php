<?php

class CycleCount {

    /* ------------------------------------------------------------------ */
    /* S28 — Cycle count scheduling (reuses StockTake)                     */
    /* ------------------------------------------------------------------ */

    public static function listSchedules(array $params = []): array {
        $db = db();
        $sql = "SELECT ccs.*, u.full_name AS created_by_name,
                       COUNT(st.id) AS run_count,
                       MAX(st.id) AS last_run_stock_take_id
                FROM cycle_count_schedules ccs
                LEFT JOIN users u ON ccs.created_by = u.id
                LEFT JOIN stock_take st ON st.schedule_id = ccs.id";
        $args = [];
        if (isset($params['is_active'])) {
            $sql .= " WHERE ccs.is_active = ?";
            $args[] = (int)$params['is_active'];
        }
        $sql .= " GROUP BY ccs.id ORDER BY ccs.next_run_date ASC, ccs.id DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['run_count'] = (int)$r['run_count'];
            $r['due'] = ($r['is_active'] && $r['next_run_date'] <= date('Y-m-d')) ? 1 : 0;
        }
        unset($r);
        return $rows;
    }

    public static function saveSchedule(array $data): int {
        $db = db();

        $name = trim($data['schedule_name'] ?? '');
        $frequency = $data['frequency'] ?? 'monthly';
        $scopeType = $data['scope_type'] ?? 'full';
        $nextRun   = $data['next_run_date'] ?? null;

        if ($name === '') throw new Exception("schedule_name wajib diisi.");
        if (!in_array($frequency, ['weekly','monthly','quarterly'], true)) throw new Exception("frequency tidak valid.");
        if (!in_array($scopeType, ['full','location','velocity'], true)) throw new Exception("scope_type tidak valid.");
        if (!$nextRun) throw new Exception("next_run_date wajib diisi.");
        if ($scopeType === 'velocity') {
            $vc = strtoupper($data['velocity_class'] ?? '');
            if (!in_array($vc, ['A','B','C'], true)) throw new Exception("velocity_class (A/B/C) wajib untuk scope velocity.");
        }

        $scopeLocs = null;
        if ($scopeType === 'location' && !empty($data['scope_locations'])) {
            $locs = is_array($data['scope_locations'])
                ? $data['scope_locations']
                : array_map('trim', explode(',', $data['scope_locations']));
            $scopeLocs = json_encode(array_values(array_filter($locs)));
        }

        $id = (int)($data['id'] ?? 0);
        if ($id > 0) {
            $stmt = $db->prepare("UPDATE cycle_count_schedules SET
                    schedule_name = ?, frequency = ?, scope_type = ?,
                    scope_locations = ?, velocity_class = ?, next_run_date = ?,
                    is_active = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?");
            $stmt->execute([
                $name, $frequency, $scopeType, $scopeLocs,
                $scopeType === 'velocity' ? strtoupper($data['velocity_class']) : null,
                $nextRun, (int)($data['is_active'] ?? 1), $id,
            ]);
            return $id;
        }

        $stmt = $db->prepare("INSERT INTO cycle_count_schedules
                (schedule_name, frequency, scope_type, scope_locations, velocity_class,
                 next_run_date, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $name, $frequency, $scopeType, $scopeLocs,
            $scopeType === 'velocity' ? strtoupper($data['velocity_class']) : null,
            $nextRun, (int)($data['is_active'] ?? 1), $_SESSION['user_id'] ?? null,
        ]);
        return (int)$db->lastInsertId();
    }

    public static function deleteSchedule(int $id): bool {
        $db = db();
        $stmt = $db->prepare("DELETE FROM cycle_count_schedules WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function runDue(): array {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM cycle_count_schedules
                WHERE is_active = 1 AND next_run_date <= CURDATE()");
        $stmt->execute();
        $due = $stmt->fetchAll();

        $generated = [];
        foreach ($due as $schedule) {
            $generated[] = self::_runSchedule($schedule, false);
        }
        return $generated;
    }

    public static function runNow(int $id): array {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM cycle_count_schedules WHERE id = ?");
        $stmt->execute([$id]);
        $schedule = $stmt->fetch();
        if (!$schedule) throw new Exception("Schedule tidak ditemukan.");
        return [self::_runSchedule($schedule, true)];
    }

    private static function _runSchedule(array $schedule, bool $preserveNextRun): array {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $takeNumber = 'ST-' . date('Ymd') . '-' . sprintf('%04d', rand(0, 9999));

            // Reuse StockTake::create() for the header, then patch schedule fields.
            $stockTakeId = StockTake::create([
                'take_date' => date('Y-m-d'),
                'status'    => 'Draft',
                'notes'     => 'Cycle count: ' . $schedule['schedule_name'],
                'scope_locations' => $schedule['scope_locations'],
            ]);
            if (!$stockTakeId) throw new Exception("Gagal membuat stock take.");

            $scopeType = $schedule['scope_type'] ?? 'full';
            $db->prepare("UPDATE stock_take SET schedule_id = ?, scope_type = ? WHERE id = ?")
               ->execute([$schedule['id'], $scopeType, $stockTakeId]);

            // Scope the take:
            //  full     -> all locations
            //  location -> specific locations
            //  velocity -> products of the ABC class only
            if ($scopeType === 'velocity' && !empty($schedule['velocity_class'])) {
                self::_autoLoadByVelocity($stockTakeId, $schedule['velocity_class']);
            } else {
                $locs = null;
                if ($scopeType === 'location' && !empty($schedule['scope_locations'])) {
                    $decoded = json_decode($schedule['scope_locations'], true);
                    $locs = is_array($decoded) ? $decoded : null;
                }
                StockTake::autoLoadByLocations($stockTakeId, $locs);
            }

            if (!$preserveNextRun) {
                $next = self::_advanceNextRun($schedule['next_run_date'], $schedule['frequency']);
                $db->prepare("UPDATE cycle_count_schedules SET next_run_date = ? WHERE id = ?")
                   ->execute([$next, $schedule['id']]);
            }

            if ($ownTx) $db->commit();
            return [
                'schedule_id' => (int)$schedule['id'],
                'schedule_name' => $schedule['schedule_name'],
                'stock_take_id' => (int)$stockTakeId,
                'take_number' => $takeNumber,
                'scope_type' => $schedule['scope_type'],
                'velocity_class' => $schedule['velocity_class'],
            ];
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function _autoLoadByVelocity(int $stockTakeId, string $velocityClass): void {
        $db = db();
        $stmt = $db->prepare("SELECT s.product_id, s.batch_number, s.location, s.quantity, s.uom
                FROM stock s
                JOIN products p ON p.id = s.product_id
                WHERE s.stock_status = 'Available' AND s.quantity > 0
                  AND s.location IS NOT NULL
                  AND s.location NOT IN ('QUA_SHELL','STAGING')
                  AND p.velocity_class = ?
                ORDER BY s.location, s.product_id");
        $stmt->execute([$velocityClass]);
        $stocks = $stmt->fetchAll();
        foreach ($stocks as $s) {
            StockTake::addItemFull($stockTakeId, [
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

    private static function _advanceNextRun(string $current, string $frequency): string {
        $dt = new DateTime($current);
        if ($frequency === 'weekly')      $dt->modify('+7 days');
        elseif ($frequency === 'quarterly') $dt->modify('+3 months');
        else $dt->modify('+1 month');
        return $dt->format('Y-m-d');
    }
}
?>