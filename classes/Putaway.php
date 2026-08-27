<?php

class Putaway {

    const PICK_LEVEL = 'A';

    /* ------------------------------------------------------------------ */
    /* S33 — Putaway recommendation engine                                 */
    /* ------------------------------------------------------------------ */

    public static function recommend(array $data): array {
        $db = db();
        $productId = (int)($data['product_id'] ?? 0);
        $qty       = floatval($data['quantity'] ?? 0);
        if (!$productId) throw new Exception("product_id wajib diisi.");
        if ($qty <= 0)  throw new Exception("quantity harus lebih dari 0.");

        $stmt = $db->prepare("SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
                FROM products p WHERE p.id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        if (!$product) throw new Exception("Produk tidak ditemukan.");

        // Rules
        $rulesStmt = $db->prepare("SELECT * FROM product_putaway_rules WHERE product_id = ?");
        $rulesStmt->execute([$productId]);
        $rules = $rulesStmt->fetch() ?: [];

        $limitsStmt = $db->prepare("SELECT * FROM uom_physical_limits WHERE uom_type = ?");
        $limitsStmt->execute([$product['uom_type'] ?? 'Drum']);
        $limits = $limitsStmt->fetch() ?: ['min_level'=>'A','max_level'=>'E','allow_pick_face'=>1,'requires_equipment'=>0];

        $upp          = max(1, intval($product['uom_per_pallet'] ?? 4));
        $fullPallets  = intdiv((int)$qty, $upp);
        $remainder    = (int)$qty % $upp;

        $preferredZone = $rules['preferred_zone_code'] ?? 'RESERVE';
        $maxLevel      = $rules['max_level'] ?? ($limits['max_level'] ?? 'E');
        $allowPickFace = ($rules['allow_pick_face'] ?? null) !== null
            ? (int)$rules['allow_pick_face']
            : (int)($limits['allow_pick_face'] ?? 1);
        $fullToPick    = (int)($rules['full_pallet_to_pick'] ?? 0) === 1;
        $requiresEquip = (int)($limits['requires_equipment'] ?? 0) === 1;

        $blockedClause = self::_blockedClause();

        $fullBin = null;
        if ($fullPallets > 0) {
            // Full pallets: reserve levels B..max unless rule allows pick-face
            $minLevel = $fullToPick ? 'A' : 'B';
            $fullBin = self::_bestBin(
                $productId,
                self::_zoneCandidates($preferredZone, !$fullToPick),
                $minLevel, $maxLevel, $limits, $blockedClause,
                null, $requiresEquip
            );
        }

        $pickFaceBin = null;
        if ($remainder > 0 && $allowPickFace) {
            $pfMax = isset($rules['max_pick_face_qty']) && floatval($rules['max_pick_face_qty']) > 0
                ? floatval($rules['max_pick_face_qty']) : null;
            $pickFaceBin = self::_bestBin(
                $productId,
                self::_zoneCandidates('PICK_FAST', false),
                'A', 'A', $limits, $blockedClause,
                $pfMax, $requiresEquip
            );
        }

        return [
            'product'         => $product,
            'upp'             => $upp,
            'full_pallets'    => $fullPallets,
            'remainder'       => $remainder,
            'full_pallet_bin' => $fullBin,
            'pick_face_bin'   => $pickFaceBin,
            'blocked_locations' => self::_blockedLocations(),
        ];
    }

    /**
     * Ordered zone candidates: preferred first, then remaining active zones
     * by priority ASC. Non-storage zones (STAGING/UNALLOCATED/QUARANTINE) are
     * never storage targets; PICK_FAST is dropped for full pallets unless the
     * product rule allows pick-face placement.
     */
    private static function _zoneCandidates(string $preferred, bool $excludePickFast): array {
        $db = db();
        $rows = $db->query("SELECT zone_code, zone_type, priority
                FROM zones WHERE is_active = 1
                ORDER BY priority ASC, zone_code ASC")->fetchAll();

        $special = ['STAGING', 'UNALLOCATED', 'QUARANTINE'];
        $list = [];
        foreach ($rows as $z) {
            $code = strtoupper($z['zone_code']);
            if (in_array($code, $special, true)) continue;
            if ($excludePickFast
                && (strtoupper($z['zone_type'] ?? '') === 'PICK_FAST' || $code === 'PICK_FAST')) continue;
            $list[] = $z['zone_code'];
        }

        $idx = -1;
        foreach ($list as $i => $code) {
            if (strcasecmp($code, $preferred) === 0) { $idx = $i; break; }
        }
        if ($idx > 0) {
            $pref = $list[$idx];
            array_splice($list, $idx, 1);
            array_unshift($list, $pref);
        }
        return $list;
    }

    /**
     * Best available bin: same-product consolidation first, then occupancy asc,
     * level asc, code asc. Level comes from COALESCE(level, row_name).
     * zone_aisles bindings (aisle + level range) are respected per zone when
     * configured; unbound zones stay unrestricted.
     * v2 parity: joins zone_aisles on aisle+level, filters by zone_aisles.zone_code IN (...).
     */
    private static function _bestBin(
        int $productId,
        array $zoneCodes,
        string $minLevel,
        string $maxLevel,
        array $limits,
        string $blockedClause,
        ?float $maxQty = null,
        bool $requiresEquipment = false
    ): ?array {
        $db = db();
        if (!$zoneCodes) return null;

        $levelExpr = "COALESCE(NULLIF(lm.level,''), lm.row_name)";

        // ── zone_aisles bindings ──────────────────────────────────────────
        $ph = implode(',', array_fill(0, count($zoneCodes), '?'));
        $bStmt = $db->prepare("SELECT za.zone_code, za.aisle, za.min_level, za.max_level
                FROM zone_aisles za JOIN zones z ON z.zone_code = za.zone_code
                WHERE za.is_active = 1 AND za.zone_code IN ($ph)");
        $bStmt->execute($zoneCodes);
        $bindings = $bStmt->fetchAll();

        $boundZones  = array_values(array_unique(array_column($bindings, 'zone_code')));
        $unboundZones = array_values(array_diff($zoneCodes, $boundZones));

        $params  = [];
        $joinSQL = '';

        if ($bindings) {
            // v2 parity: INNER JOIN on zone_aisles — each binding contributes
            // one (aisle, min_level, max_level) OR condition.
            foreach ($zoneCodes as $z) $params[] = $z;

            $conditions = [];
            foreach ($bindings as $b) {
                $params[]      = $b['aisle'];
                $params[]      = $b['min_level'];
                $params[]      = $b['max_level'];
                $conditions[]  = "(za.aisle = ? AND $levelExpr BETWEEN ? AND ?)";
            }
            $joinSQL = "
                JOIN zone_aisles za
                    ON za.is_active = 1
                    AND za.zone_code IN ($ph)
                    AND (" . implode(' OR ', $conditions) . ")";
        }

        // ── zone filter ───────────────────────────────────────────────────
        // If EVERY requested zone has bindings → the JOIN already restricts
        // to exactly the right locations; no extra WHERE needed.
        // If SOME zones lack bindings → we must also allow locations whose
        // zone_code matches an unbound zone.  To combine correctly with the
        // JOIN we wrap the whole zone test in a single OR group:
        //   (zone_aisles_matched OR zone_code_in_unbound)
        // The INNER JOIN already eliminated non-matching aisles for bound
        // zones, so the OR only opens the door for unbound zone locations.
        $whereSQL = '';
        if ($unboundZones) {
            $ph2 = implode(',', array_fill(0, count($unboundZones), '?'));
            foreach ($unboundZones as $z) $params[] = $z;
            // When bindings exist the JOIN restricts rows; adding a plain AND
            // for unbound zones would kill bound-zone rows.  We therefore
            // convert the zone_aisles JOIN to a LEFT JOIN and test in WHERE:
            //   (za.id IS NOT NULL  OR  zone IN unbound)
            // When there are no bindings at all, a simple zone_code IN works.
            if ($bindings) {
                $joinSQL = str_replace('JOIN zone_aisles za', 'LEFT JOIN zone_aisles za', $joinSQL);
                $whereSQL = " AND (za.id IS NOT NULL OR COALESCE(lm.zone_code, lm.zone) IN ($ph2))";
            } else {
                $whereSQL = " AND COALESCE(lm.zone_code, lm.zone) IN ($ph2)";
            }
        }

        $equipCond = $requiresEquipment
            ? "AND (lm.equipment_accessible = 1 OR $levelExpr IN ('A','B','C'))"
            : "";

        $sql = "SELECT lm.id, lm.location_code, $levelExpr AS level,
                       COALESCE(lm.zone_code, lm.zone) AS zone_code, lm.is_pick_face,
                       COALESCE(SUM(s.quantity),0) AS current_qty,
                       MAX(CASE WHEN sme.id IS NOT NULL THEN 1 ELSE 0 END) AS has_same_product,
                       MAX(CASE WHEN sa.aisle IS NOT NULL THEN 1 ELSE 0 END) AS has_same_aisle
                FROM location_master lm
                LEFT JOIN stock s ON s.location = lm.location_code
                    AND s.quantity > 0
                    AND (s.stock_status = 'Available' OR s.stock_status IS NULL OR s.stock_status = '')
                LEFT JOIN stock sme ON sme.location = lm.location_code
                    AND sme.product_id = ?
                    AND sme.quantity > 0
                    AND (sme.stock_status = 'Available' OR sme.stock_status IS NULL OR sme.stock_status = '')
                LEFT JOIN (
                    SELECT DISTINCT lm2.aisle
                    FROM location_master lm2
                    JOIN stock s2 ON s2.location = lm2.location_code
                    WHERE s2.product_id = ? AND s2.quantity > 0
                      AND (s2.stock_status = 'Available' OR s2.stock_status IS NULL OR s2.stock_status = '')
                      AND lm2.aisle IS NOT NULL AND lm2.aisle != ''
                ) sa ON sa.aisle = lm.aisle
                $joinSQL
                WHERE lm.is_active = 1
                  $whereSQL
                  AND $levelExpr BETWEEN ? AND ?
                  AND lm.location_code NOT IN ('STAGING','UNALLOCATED','QUA_SHELL')
                  $equipCond
                  $blockedClause
                GROUP BY lm.id, lm.location_code, $levelExpr, zone_code, lm.is_pick_face
                ORDER BY has_same_product DESC, has_same_aisle DESC, current_qty ASC, level ASC, lm.location_code ASC
                LIMIT 1";

        $stmt = $db->prepare($sql);
        $args = [$productId, $productId];
        array_push($args, ...$params);
        array_push($args, $minLevel, $maxLevel);
        $stmt->execute($args);
        $bin = $stmt->fetch();
        if (!$bin) return null;

        $current = floatval($bin['current_qty']);
        if ($maxQty !== null && $current >= $maxQty) return null;

        $bin['current_qty'] = $current;
        return $bin;
    }

    /* ------------------------------------------------------------------ */
    /* v2 parity helpers for recommendLocations / validatePlacement        */
    /* ------------------------------------------------------------------ */

    private static function _levelHeight(string $level): int {
        $map = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        return $map[strtoupper($level)] ?? 0;
    }

    private static function _effectiveMaxLevel(?array $rule, array $limits): string {
        $rl = strtoupper(trim((string)($rule['max_level'] ?? '')));
        $map = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        if ($rl && isset($map[$rl])) return $rl;
        return strtoupper((string)($limits['max_level'] ?? 'E'));
    }

    private static function _getProduct(int $productId): ?array {
        $db = db();
        $stmt = $db->prepare("SELECT id, product_code, product_name, uom_type, uom_per_pallet FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        return $stmt->fetch() ?: null;
    }

    private static function _getLocation(string $code): ?array {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM location_master WHERE location_code = ? LIMIT 1");
        $stmt->execute([$code]);
        return $stmt->fetch() ?: null;
    }

    private static function _getUomLimit(string $uomType): array {
        $db = db();
        $aliases = ['CAR' => 'Carton'];
        $candidates = [$uomType, $aliases[$uomType] ?? null];
        foreach ($candidates as $c) {
            if (!$c) continue;
            $stmt = $db->prepare("SELECT * FROM uom_physical_limits WHERE UPPER(uom_type) = UPPER(?)");
            $stmt->execute([$c]);
            $row = $stmt->fetch();
            if ($row) return $row;
        }
        return ['min_level'=>'A','max_level'=>'E','allow_pick_face'=>1,'max_weight_kg'=>null,'max_height_cm'=>null,'requires_equipment'=>0];
    }

    private static function _getPutawayRule(int $productId): ?array {
        $db = db();
        $stmt = $db->prepare("SELECT * FROM product_putaway_rules WHERE product_id = ?");
        $stmt->execute([$productId]);
        return $stmt->fetch() ?: null;
    }

    private static function _activeBlocks(): array {
        $db = db();
        return $db->query("SELECT * FROM putaway_location_blocks WHERE is_active = 1")->fetchAll();
    }

    private static function _blockHit(array $blocks, string $code): array {
        foreach ($blocks as $b) {
            if ($b['scope_type'] === 'location' && strtoupper($b['location_code'] ?? '') === $code) {
                return ['blocked' => true, 'reason' => $b['reason'] ?? ''];
            }
            if ($b['scope_type'] === 'aisle' && strpos($code, strtoupper($b['aisle_prefix'] ?? '')) === 0) {
                return ['blocked' => true, 'reason' => $b['reason'] ?? ''];
            }
        }
        return ['blocked' => false, 'reason' => null];
    }

    private static function _zoneBindings(string $zoneCode): array {
        $db = db();
        $stmt = $db->prepare("SELECT za.aisle, za.min_level, za.max_level
                FROM zone_aisles za JOIN zones z ON z.zone_code = za.zone_code
                WHERE za.zone_code = ? AND za.is_active = 1");
        $stmt->execute([$zoneCode]);
        return $stmt->fetchAll();
    }

    private static function _getExistingRacks(int $productId): array {
        $db = db();
        $stmt = $db->prepare("SELECT DISTINCT lm.rack
                FROM stock_locations sl
                JOIN stock s ON s.id = sl.stock_id
                JOIN location_master lm ON lm.location_code = sl.location_code
                WHERE s.product_id = ? AND sl.status IN ('Available','Reserved') AND lm.rack IS NOT NULL");
        $stmt->execute([$productId]);
        return array_column($stmt->fetchAll(), 'rack');
    }

    private static function _currentPickQty(int $productId): float {
        $db = db();
        $stmt = $db->prepare("SELECT COALESCE(SUM(sl.quantity), 0) AS qty
                FROM stock_locations sl
                JOIN stock s ON s.id = sl.stock_id
                JOIN location_master lm ON lm.location_code = sl.location_code
                WHERE s.product_id = ? AND lm.is_pick_face = 1
                  AND sl.status IN ('Available','Reserved')");
        $stmt->execute([$productId]);
        return floatval($stmt->fetchColumn() ?: 0);
    }

    /**
     * Direct pick-face slot finder — used as primary before _findAvailable fallback.
     * Bypasses the complex _findAvailable zone_aisles JOIN which has issues with
     * the current zone binding setup.
     */
    private static function _findPickFaceSlot(string $level, array $zones, array $existingRacks, array $blocks, array $takenLocations, array $limits): ?array {
        if ($level !== self::PICK_LEVEL || empty($zones)) return null;
        $db = db();
        $equipCond = (int)($limits['requires_equipment'] ?? 0) === 1
            ? "AND (lm.equipment_accessible = 1 OR lm.level IN ('A','B','C'))" : '';
        $phZones = implode(',', array_fill(0, count($zones), '?'));
        $params = array_merge([], $zones);
        // level param comes right after zone in the SQL WHERE clause
        $params[] = $level;
        $notIn = '';
        if (!empty($takenLocations)) {
            $ph3 = implode(',', array_fill(0, count($takenLocations), '?'));
            $params = array_merge($params, $takenLocations);
            $notIn = " AND lm.location_code NOT IN ($ph3)";
        }
        $blockCond = '';
        foreach ($blocks as $b) {
            if ($b['scope_type'] === 'aisle' && !empty($b['aisle_prefix'])) {
                $params[] = strtoupper($b['aisle_prefix']) . '%';
                $blockCond .= " AND lm.location_code NOT LIKE ?";
            } elseif ($b['scope_type'] === 'location' && !empty($b['location_code'])) {
                $params[] = strtoupper($b['location_code']);
                $blockCond .= " AND lm.location_code <> ?";
            }
        }
        $sql = "SELECT lm.location_code, COALESCE(lm.zone_code, lm.zone) AS zone_code, lm.level
                FROM location_master lm
                LEFT JOIN zones z ON z.zone_code = COALESCE(lm.zone_code, lm.zone) AND z.is_active = 1
                JOIN zone_aisles za ON za.zone_code IN ($phZones) AND za.is_active = 1
                    AND za.aisle = lm.aisle AND lm.level BETWEEN za.min_level AND za.max_level
                WHERE lm.is_active = 1 AND lm.level = ?
                  AND lm.location_code NOT IN (
                      SELECT DISTINCT location_code FROM stock_locations WHERE status IN ('Available','Reserved')
                  )
                  AND lm.location_code NOT IN (
                      SELECT DISTINCT actual_location FROM putaway_task_items WHERE actual_location IS NOT NULL AND actual_location != '' AND status IN ('Pending','Confirmed')
                  )
                  AND lm.location_code NOT IN (
                      SELECT DISTINCT suggested_location FROM putaway_task_items WHERE suggested_location IS NOT NULL AND suggested_location != '' AND status = 'Pending'
                  )
                  $equipCond $notIn $blockCond
                ORDER BY z.priority ASC, lm.location_code ASC LIMIT 1";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Find free (unoccupied) locations on the requested levels.
     * Ordering: zone priority -> same-rack clustering -> level asc -> position.
     */
    private static function _findAvailable(array $opts): array {
        $db = db();
        $levels = $opts['levels'] ?? [];
        $limit  = $opts['limit'] ?? 1;
        if (empty($levels) || $limit <= 0) return [];

        $existingRacks  = $opts['existingRacks'] ?? [];
        $zones          = $opts['zones'] ?? [];
        $heavyOnly      = $opts['heavyOnly'] ?? false;
        $blocks         = $opts['blocks'] ?? [];
        $takenLocations = $opts['takenLocations'] ?? [];

        $levelExpr = "COALESCE(NULLIF(lm.level,''), lm.row_name)";
        $joinSQL = '';
        $params  = [];

        if (!empty($zones)) {
            $ph = implode(',', array_fill(0, count($zones), '?'));
            $params = array_merge($params, $zones);
            $joinSQL = "JOIN zone_aisles za ON za.zone_code IN ($ph)
                    AND za.is_active = 1 AND za.aisle = lm.aisle
                    AND $levelExpr BETWEEN za.min_level AND za.max_level";
        }

        $whereZone = '';
        // NOTE: $whereZone ? placeholders are NOT added to $params yet.
        // They must come AFTER $phLv in $params because the SQL string places
        // $phLv (levels) BEFORE $whereZone. Deferred below after $phLv is appended.

        $blockCond = '';
        foreach ($blocks as $b) {
            if ($b['scope_type'] === 'aisle' && !empty($b['aisle_prefix'])) {
                $params[] = strtoupper($b['aisle_prefix']) . '%';
                $blockCond .= " AND lm.location_code NOT LIKE ?";
            } elseif ($b['scope_type'] === 'location' && !empty($b['location_code'])) {
                $params[] = strtoupper($b['location_code']);
                $blockCond .= " AND lm.location_code <> ?";
            }
        }
        if (!empty($takenLocations)) {
            $ph3 = implode(',', array_fill(0, count($takenLocations), '?'));
            $params = array_merge($params, $takenLocations);
            $blockCond .= " AND lm.location_code NOT IN ($ph3)";
        }

        $equipCond = $heavyOnly ? "AND (lm.equipment_accessible = 1 OR $levelExpr IN ('A','B','C'))" : '';
        // Levels params MUST come before whereZone params to match SQL placeholder order
        $phLv = implode(',', array_fill(0, count($levels), '?'));
        $params = array_merge($params, $levels);

        // Now add whereZone params (appears AFTER $phLv in the SQL string)
        if (!empty($zones)) {
            $ph2 = implode(',', array_fill(0, count($zones), '?'));
            $params = array_merge($params, $zones);
            $whereZone = " AND (za.id IS NOT NULL OR COALESCE(lm.zone_code, lm.zone) IN ($ph2))";
        }

        $orderExtra = '';
        if (!empty($existingRacks)) {
            $rph = implode(',', array_fill(0, count($existingRacks), '?'));
            $orderExtra = "CASE WHEN lm.rack IN ($rph) THEN 0 ELSE 1 END, ";
            $params = array_merge($params, $existingRacks);
        }

        $sql = "SELECT lm.location_code, COALESCE(lm.zone_code, lm.zone) AS zone_code, $levelExpr AS level
                FROM location_master lm
                LEFT JOIN zones z ON z.zone_code = COALESCE(lm.zone_code, lm.zone) AND z.is_active = 1
                $joinSQL
                WHERE lm.is_active = 1 AND $levelExpr IN ($phLv) $equipCond
                  AND lm.location_code NOT IN (SELECT DISTINCT location_code FROM stock_locations WHERE status IN ('Available','Reserved'))
                  AND lm.location_code NOT IN (SELECT DISTINCT actual_location FROM putaway_task_items WHERE actual_location IS NOT NULL AND actual_location != '' AND status IN ('Pending','Confirmed'))
                  AND lm.location_code NOT IN (SELECT DISTINCT suggested_location FROM putaway_task_items WHERE suggested_location IS NOT NULL AND suggested_location != '' AND status = 'Pending')
                  $whereZone $blockCond
                ORDER BY z.priority ASC, {$orderExtra}$levelExpr ASC, lm.location_code ASC
                LIMIT " . (int)$limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Full v2-parity putaway recommendation.
     * Returns pallets[] array with per-pallet placements, zone, level, reason.
     * v2 parity of PutawayService.recommendLocations().
     */
    public static function recommendLocations(array $data): array {
        $productId  = (int)($data['product_id'] ?? 0);
        $qty        = max(0, floatval($data['quantity'] ?? 0));
        $reqUom     = strtoupper(trim((string)($data['uom'] ?? '')));
        $uppReq     = !empty($data['uom_per_pallet']) ? (int)$data['uom_per_pallet'] : null;
        $preferPick = ($data['prefer_pick'] ?? false) === true || ($data['prefer_pick'] ?? '') === '1';
        $forceLevel = strtoupper(trim((string)($data['force_level'] ?? '')));
        if ($forceLevel === '') $forceLevel = null;

        $product = self::_getProduct($productId);
        if (!$product) throw new Exception("Produk tidak ditemukan.");

        $blocks = self::_activeBlocks();
        $uom    = $reqUom !== '' ? $reqUom : strtoupper((string)($product['uom_type'] ?? 'Drum'));
        $derivedUpp = self::deriveUppFromPackSize($product['product_name']);
        $storedUpp  = max(1, intval($product['uom_per_pallet'] ?? 4));
        // Stored UPP always takes priority; derived is fallback only when stored is missing/default
        $upp = max(1, $uppReq ?? $storedUpp);

        $limits = self::_getUomLimit($uom);
        $rule   = self::_getPutawayRule($productId);
        $maxLevel = self::_effectiveMaxLevel($rule, $limits);

        $map = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        $allowPick = (int)($rule['allow_pick_face'] ?? $limits['allow_pick_face'] ?? 1) === 1
                     && isset($map[self::PICK_LEVEL]) && $map[self::PICK_LEVEL] >= ($map[strtoupper($limits['min_level'] ?? 'A')] ?? 1);

        $fullPallets = (int)floor($qty / $upp);
        $remainder   = round($qty % $upp, 2);
        $totalPallets = $fullPallets + ($remainder > 0 ? 1 : 0);

        // Allowed reserve levels for this UOM (never above maxLevel)
        $reserveLevels = array_filter(['B','C','D','E'], function ($l) use ($map, $maxLevel) {
            return ($map[$l] ?? 0) <= ($map[$maxLevel] ?? 5);
        });
        $reserveLevels = array_values($reserveLevels);

        // Zone bindings
        $reserveZone = strtoupper((string)($rule['preferred_zone_code'] ?? 'RESERVE'));
        $reserveBindings = self::_zoneBindings($reserveZone);
        $reserveZones = [];
        if (!empty($reserveBindings)) {
            $lvSet = [];
            foreach ($reserveBindings as $b) {
                $lo = $map[strtoupper($b['min_level'] ?? 'B')] ?? 2;
                $hi = $map[strtoupper($b['max_level'] ?? 'E')] ?? 5;
                foreach ($reserveLevels as $l) {
                    $h = $map[$l] ?? 0;
                    if ($h >= $lo && $h <= $hi) $lvSet[$l] = true;
                }
            }
            if (!empty($lvSet)) $reserveLevels = array_values(array_keys($lvSet));
            $reserveZones[] = $reserveZone;
        }

        $pickBindings = self::_zoneBindings('PICK_FAST');
        $pickZones = !empty($pickBindings) ? ['PICK_FAST'] : [];

        $existingRacks = (($rule['consolidate'] ?? 1) == 1) ? self::_getExistingRacks($productId) : [];

        $placements      = [];
        $takenLocations  = [];
        $seq             = 1;

        // 1) Full pallets -> reserve. If prefer_pick or full_pallet_to_pick and
        //    current pick qty < min_pick_face_qty, hold one full pallet at Level A.
        $fullToReserve = $fullPallets;
        if ($allowPick && ($preferPick || ($rule['full_pallet_to_pick'] ?? 0) == 1) && $fullPallets > 0) {
            $pickQty = self::_currentPickQty($productId);
            if ($pickQty < floatval($rule['min_pick_face_qty'] ?? 0)) {
                $slots = self::_findAvailable([
                    'levels' => [self::PICK_LEVEL], 'limit' => 1,
                    'existingRacks' => $existingRacks, 'zones' => $pickZones,
                    'heavyOnly' => (int)($limits['requires_equipment'] ?? 0) === 1,
                    'blocks' => $blocks, 'takenLocations' => $takenLocations,
                ]);
                if (!empty($slots[0])) {
                    $s = $slots[0];
                    $placements[] = [
                        'pallet_seq' => $seq++, 'quantity' => $upp, 'is_full' => true,
                        'location_code' => $s['location_code'], 'zone_code' => $s['zone_code'],
                        'level' => $s['level'], 'reason' => 'PICK_FACE_FULL',
                    ];
                    $takenLocations[] = $s['location_code'];
                    $fullToReserve -= 1;
                }
            }
        }

        if ($fullToReserve > 0) {
            // Special case: Pail & IBC UOM always go to level A (pick face).
            // Use _findAvailable (which COALESCEs row_name) instead of
            // _findPickFaceSlot (which only matches lm.level='A' literally).
            if ($uom === 'PAIL' || $uom === 'IBC') {
                $slots = self::_findAvailable([
                    'levels' => [self::PICK_LEVEL], 'limit' => $fullToReserve,
                    'existingRacks' => $existingRacks, 'zones' => $pickZones,
                    'heavyOnly' => false, 'blocks' => $blocks,
                    'takenLocations' => $takenLocations,
                ]);
                for ($i = 0; $i < $fullToReserve; $i++) {
                    if (empty($slots[$i])) break;
                    $s = $slots[$i];
                    $placements[] = [
                        'pallet_seq' => $seq++, 'quantity' => $upp, 'is_full' => true,
                        'location_code' => $s['location_code'], 'zone_code' => $s['zone_code'],
                        'level' => $s['level'], 'reason' => 'RESERVE_FULL',
                    ];
                    $takenLocations[] = $s['location_code'];
                }
            } else {
                $slots = self::_findAvailable([
                    'levels' => $reserveLevels, 'limit' => $fullToReserve,
                    'existingRacks' => $existingRacks, 'zones' => $reserveZones,
                    'heavyOnly' => (int)($limits['requires_equipment'] ?? 0) === 1,
                    'blocks' => $blocks, 'takenLocations' => $takenLocations,
                ]);
                for ($i = 0; $i < $fullToReserve; $i++) {
                    if (empty($slots[$i])) break;
                    $s = $slots[$i];
                    $placements[] = [
                        'pallet_seq' => $seq++, 'quantity' => $upp, 'is_full' => true,
                        'location_code' => $s['location_code'], 'zone_code' => $s['zone_code'],
                        'level' => $s['level'], 'reason' => 'RESERVE_FULL',
                    ];
                    $takenLocations[] = $s['location_code'];
                }
            }
        }

        // 2) Remainder / partial pallet -> pick face (Level A), fallback STAGING
        if ($remainder > 0) {
            $targetLevel = $forceLevel ?? self::PICK_LEVEL;
            $targetZones = ($targetLevel === self::PICK_LEVEL) ? $pickZones : $reserveZones;
            $s = self::_findPickFaceSlot($targetLevel, $targetZones, $existingRacks, $blocks, $takenLocations, $limits);
            if ($s) {
                $placements[] = [
                    'pallet_seq' => $seq++, 'quantity' => $remainder, 'is_full' => false,
                    'location_code' => $s['location_code'], 'zone_code' => $s['zone_code'],
                    'level' => $s['level'], 'reason' => 'PICK_FACE_REMAINDER',
                ];
            } else {
                $slots = self::_findAvailable([
                    'levels' => [$targetLevel], 'limit' => 1,
                    'existingRacks' => $existingRacks, 'zones' => $targetZones,
                    'heavyOnly' => (int)($limits['requires_equipment'] ?? 0) === 1,
                    'blocks' => $blocks, 'takenLocations' => $takenLocations,
                ]);
                if (!empty($slots[0])) {
                    $s = $slots[0];
                    $placements[] = [
                        'pallet_seq' => $seq++, 'quantity' => $remainder, 'is_full' => false,
                        'location_code' => $s['location_code'], 'zone_code' => $s['zone_code'],
                        'level' => $s['level'], 'reason' => 'PICK_FACE_REMAINDER',
                    ];
                } else {
                    $placements[] = [
                        'pallet_seq' => $seq++, 'quantity' => $remainder, 'is_full' => false,
                        'location_code' => 'STAGING', 'zone_code' => 'STAGING',
                        'level' => '-', 'reason' => 'NO_SLOT_STAGING',
                    ];
                }
            }
        }

        $unassignedFull = max(0, $fullToReserve - count(array_filter($placements, fn($p) => $p['reason'] === 'RESERVE_FULL')));
        $success = $unassignedFull === 0 && ($remainder === 0 || !empty(array_filter($placements, fn($p) => $p['reason'] === 'PICK_FACE_REMAINDER')));

        return [
            'success'       => $success,
            'message'       => $success ? '' : "Hanya " . ($fullPallets - $unassignedFull) . "/$fullPallets lokasi full pallet tersedia di level " . implode('/', $reserveLevels) . " — sisa diarahkan ke staging.",
            'product'       => $product,
            'uom'           => $uom,
            'uom_per_pallet'=> $upp,
            'limits'        => $limits,
            'rule'          => $rule,
            'total_pallets' => count($placements),
            'pallets'       => $placements,
        ];
    }

    /** S40 — validate a manual location save: blocked or out of limits → reject */
    public static function validate(string $locationCode, int $productId, float $qty): array {
        $db = db();
        $loc = strtoupper(trim($locationCode));

        $stmt = $db->prepare("SELECT * FROM location_master WHERE location_code = ?");
        $stmt->execute([$loc]);
        $bin = $stmt->fetch();
        if (!$bin) return ['valid' => false, 'reason' => "Lokasi '{$loc}' tidak ditemukan di master lokasi."];
        if (!$bin['is_active']) return ['valid' => false, 'reason' => "Lokasi '{$loc}' tidak aktif."];

        // Blocked?
        $blk = $db->prepare("SELECT reason FROM putaway_location_blocks
                WHERE is_active = 1 AND (
                    (scope_type = 'location' AND location_code = ?)
                    OR (scope_type = 'aisle' AND ? LIKE CONCAT(aisle_prefix, '%'))
                ) LIMIT 1");
        $blk->execute([$loc, $loc]);
        if ($b = $blk->fetch()) {
            return ['valid' => false, 'reason' => "Lokasi '{$loc}' diblokir: {$b['reason']}"];
        }

        $limitsStmt = $db->prepare("SELECT * FROM uom_physical_limits WHERE uom_type = ?");
        $limitsStmt->execute([$productId ? self::_productUom($productId) : 'Drum']);
        $limits = $limitsStmt->fetch() ?: [];

        $level = strtoupper($bin['level'] ?? (isset($loc[4]) ? $loc[4] : 'B'));
        if (!empty($limits)) {
            if ($level < strtoupper($limits['min_level'] ?? 'A') || $level > strtoupper($limits['max_level'] ?? 'E')) {
                return ['valid' => false, 'reason' => "Level {$level} di luar batas fisik UOM (min {$limits['min_level']} max {$limits['max_level']})."];
            }
            if (!(int)($limits['allow_pick_face'] ?? 1) && $level === 'A') {
                return ['valid' => false, 'reason' => "UOM tidak diizinkan di pick-face (Level A)."];
            }
        }

        return ['valid' => true];
    }

    private static function _productUom(int $productId): string {
        $db = db();
        $stmt = $db->prepare("SELECT uom_type FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        return $stmt->fetchColumn() ?: 'Drum';
    }

    /* ------------------------------------------------------------------ */
    /* S40 — Location blocking CRUD                                        */
    /* ------------------------------------------------------------------ */

    public static function listBlocks(): array {
        $db = db();
        $rows = $db->query("SELECT b.*, u.full_name AS blocked_by_name
                FROM putaway_location_blocks b
                LEFT JOIN users u ON b.blocked_by = u.id
                ORDER BY b.is_active DESC, b.created_at DESC")->fetchAll();
        return $rows;
    }

    public static function addBlock(array $data): int {
        $db = db();
        $scopeType = $data['scope_type'] ?? '';
        $reason    = trim($data['reason'] ?? '');
        if (!in_array($scopeType, ['aisle','location'], true)) throw new Exception("scope_type harus aisle atau location.");
        if ($reason === '') throw new Exception("reason wajib diisi.");

        if ($scopeType === 'aisle') {
            $prefix = strtoupper(trim($data['aisle_prefix'] ?? ''));
            if ($prefix === '') throw new Exception("aisle_prefix wajib diisi.");
            $db->prepare("INSERT INTO putaway_location_blocks (scope_type, aisle_prefix, reason, blocked_by)
                    VALUES ('aisle', ?, ?, ?)")
               ->execute([$prefix, $reason, $_SESSION['user_id'] ?? null]);
        } else {
            $loc = strtoupper(trim($data['location_code'] ?? ''));
            if ($loc === '') throw new Exception("location_code wajib diisi.");
            $db->prepare("INSERT INTO putaway_location_blocks (scope_type, location_code, reason, blocked_by)
                    VALUES ('location', ?, ?, ?)")
               ->execute([$loc, $reason, $_SESSION['user_id'] ?? null]);
        }
        return (int)$db->lastInsertId();
    }

    public static function removeBlock(int $id): bool {
        $db = db();
        $stmt = $db->prepare("UPDATE putaway_location_blocks SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    /** WHERE fragment excluding blocked bins (used by recommend). */
    private static function _blockedClause(): string {
        return "AND NOT EXISTS (
            SELECT 1 FROM putaway_location_blocks b
            WHERE b.is_active = 1
              AND ( (b.scope_type = 'location' AND b.location_code = lm.location_code)
                 OR (b.scope_type = 'aisle'   AND lm.location_code LIKE CONCAT(b.aisle_prefix, '%')) )
        )";
    }

    private static function _blockedLocations(): array {
        $db = db();
        return $db->query("SELECT location_code, aisle_prefix, reason, scope_type FROM putaway_location_blocks WHERE is_active = 1")->fetchAll();
    }

    /* ------------------------------------------------------------------ */
    /* S42/S49 — Putaway task queue + LPN                                  */
    /* ------------------------------------------------------------------ */

    public static function createTask(int $inboundId): int {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $inboundStmt = $db->prepare("SELECT io.* FROM inbound_orders io WHERE io.id = ?");
            $inboundStmt->execute([$inboundId]);
            $inbound = $inboundStmt->fetch();
            if (!$inbound) throw new Exception("Inbound tidak ditemukan.");

            $itemsStmt = $db->prepare("SELECT ii.*, p.uom_per_pallet, p.uom_type
                    FROM inbound_items ii
                    JOIN products p ON ii.product_id = p.id
                    WHERE ii.inbound_order_id = ?");
            $itemsStmt->execute([$inboundId]);
            $items = $itemsStmt->fetchAll();

            $taskNumber = self::_generateTaskNumber();
            $db->prepare("INSERT INTO putaway_tasks (task_number, inbound_order_id, status, created_by)
                    VALUES (?, ?, 'Open', ?)")
               ->execute([$taskNumber, $inboundId, $_SESSION['user_id'] ?? null]);
            $taskId = (int)$db->lastInsertId();

            $itemStmt = $db->prepare("INSERT INTO putaway_task_items
                    (task_id, inbound_item_id, product_id, batch_number, quantity, uom,
                     from_location, suggested_location, lpn_code, pallet_seq, status)
                    VALUES (?, ?, ?, ?, ?, ?, 'STAGING', ?, ?, ?, 'Pending')");

            // Task-wide LPN sequence: unique across ALL items in this task.
            // pallet_seq stays per-item; LPN uses a running task counter so
            // multi-item tasks never collide (B5: task-scoped, collision-free).
            $lpnSeq = (int)$db->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(lpn_code, '-', -1) AS UNSIGNED)), 0)
                    FROM putaway_task_items WHERE task_id = " . (int)$taskId)->fetchColumn();

            foreach ($items as $item) {
                $upp = max(1, intval($item['uom_per_pallet'] ?? 4));
                $qty = floatval($item['actual_qty'] ?: $item['quantity']);
                $dist = self::_palletDistribution($qty, $upp);

                // Per-pallet suggestion: full pallets -> full_pallet_bin (reserve),
                // remainder -> pick_face_bin (Level A), fallback null (B2).
                $fullSuggestion = null;
                $pickSuggestion = null;
                try {
                    $rec = self::recommend(['product_id' => $item['product_id'], 'quantity' => $qty]);
                    $fullSuggestion = $rec['full_pallet_bin']['location_code'] ?? null;
                    $pickSuggestion = $rec['pick_face_bin']['location_code'] ?? null;
                } catch (Throwable $e) {
                    // no suggestion — task item stays nullable
                }

                $palletSeq = 1;
                foreach ($dist as $d) {
                    $lpn = sprintf('LPN-%s-%03d', $taskNumber, ++$lpnSeq);
                    $suggestion = !empty($d['is_full']) ? $fullSuggestion : $pickSuggestion;
                    $itemStmt->execute([
                        $taskId, $item['id'], $item['product_id'],
                        $item['batch_number'] ?? $item['batch_no'] ?? null,
                        $d['quantity'], $item['uom_type'] ?? $item['uom'] ?? 'Drum',
                        $suggestion, $lpn, $palletSeq++,
                    ]);
                }
            }

            if ($ownTx) $db->commit();
            return $taskId;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * v2 — enqueue one GR'd item's pallet rows into an Open putaway task.
     * Creates the task if none exists for the inbound; skips items already
     * enqueued. Each row carries its suggested bin + LPN. NO stock or
     * stock_locations are written at Goods Received — the write is deferred
     * to putaway completion / inbound complete() (v2 parity).
     */
    public static function enqueueForPutaway(int $inboundId, int $itemId, array $pallets = [], ?int $userId = null): int {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            // Skip if this item already has pending pallets in an open task
            $dupStmt = $db->prepare("SELECT pti.id FROM putaway_task_items pti
                    JOIN putaway_tasks pt ON pti.task_id = pt.id
                    WHERE pti.inbound_item_id = ? AND pt.status IN ('Open','In Progress')
                    LIMIT 1");
            $dupStmt->execute([$itemId]);
            if ($dupStmt->fetch()) {
                if ($ownTx) $db->commit();
                return 0;
            }

            // Find or create the Open task for this inbound
            $taskStmt = $db->prepare("SELECT id FROM putaway_tasks
                    WHERE inbound_order_id = ? AND status IN ('Open','In Progress')
                    ORDER BY id DESC LIMIT 1");
            $taskStmt->execute([$inboundId]);
            $taskId = (int)$taskStmt->fetchColumn();

            if (!$taskId) {
                $taskNumber = self::_generateTaskNumber();
                $db->prepare("INSERT INTO putaway_tasks (task_number, inbound_order_id, status, created_by)
                        VALUES (?, ?, 'Open', ?)")
                   ->execute([$taskNumber, $inboundId, $userId ?: ($_SESSION['user_id'] ?? null)]);
                $taskId = (int)$db->lastInsertId();
            }

            if (empty($pallets)) {
                if ($ownTx) $db->commit();
                return $taskId;
            }

            $itemStmt = $db->prepare("SELECT ii.*, p.uom_per_pallet, p.uom_type
                    FROM inbound_items ii JOIN products p ON ii.product_id = p.id
                    WHERE ii.id = ? AND ii.inbound_order_id = ?");
            $itemStmt->execute([$itemId, $inboundId]);
            $item = $itemStmt->fetch();
            if (!$item) throw new Exception("Inbound item tidak ditemukan.");

            $uom = $item['uom_type'] ?? $item['uom'] ?? 'Drum';
            $batch = $item['batch_number'] ?? $item['batch_no'] ?? null;

            $palletStmt = $db->prepare("INSERT INTO putaway_task_items
                    (task_id, inbound_item_id, product_id, batch_number, uom,
                     pallet_seq, quantity, suggested_location, lpn_code, pallet_function, reason, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");

            foreach ($pallets as $p) {
                $loc = strtoupper(trim((string)($p['location_code'] ?? '')));
                $lpn = self::_generateLpn();
                $palletStmt->execute([
                    $taskId, $itemId, $item['product_id'],
                    $batch, $uom,
                    $p['pallet_seq'] ?? 1,
                    floatval($p['quantity'] ?? 0),
                    $loc !== '' ? $loc : null,
                    $lpn,
                    self::palletFunctionFor($loc),
                    $p['reason'] ?? null,
                ]);
            }

            if ($ownTx) $db->commit();
            return $taskId;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /**
     * LPN label barcode — LPN-YYYYMMDD-NNNNN (v2 format, 5-digit sequence,
     * unique across putaway_task_items).
     */
    private static function _generateLpn(): string {
        return generate_number('putaway_task_items', 'lpn_code', 'LPN-' . today_compact() . '-', 'LPN-' . today_compact() . '-', 5);
    }

    /** PICK_FACE at Level A (5th char of the location code), else RESERVE. */
    public static function palletFunctionFor(string $locationCode): string {
        $code = strtoupper(trim($locationCode));
        if ($code === '') return 'RESERVE';
        return (($code[4] ?? '') === 'A') ? 'PICK_FACE' : 'RESERVE';
    }

    /** Open tasks (Pending/In Progress with Pending pallets) for an inbound — gates completion. */
    public static function openTaskNumbers(int $inboundId): array {
        $db = db();
        $stmt = $db->prepare("SELECT DISTINCT t.task_number
                FROM putaway_tasks t JOIN putaway_task_items ti ON ti.task_id = t.id
                WHERE t.inbound_order_id = ? AND t.status IN ('Open','In Progress') AND ti.status = 'Pending'");
        $stmt->execute([$inboundId]);
        return array_column($stmt->fetchAll(), 'task_number');
    }

    /** Mark an item's open task rows resolved (manual Manage Pallet Locations path). */
    public static function reconcileItemRows(int $itemId, int $userId): void {
        $db = db();
        $db->prepare("UPDATE putaway_task_items ti
                JOIN putaway_tasks t ON t.id = ti.task_id
                SET ti.status = 'Confirmed', ti.confirmed_by = ?, ti.confirmed_at = NOW(),
                    ti.completed_by = ?, ti.completed_at = NOW(), ti.updated_at = NOW()
                WHERE ti.inbound_item_id = ? AND ti.status = 'Pending'
                  AND t.status IN ('Open','In Progress')")
           ->execute([$userId ?: null, $userId ?: null, $itemId]);
    }

    /** Open putaway task + rows for an inbound — fed into inbound detail. */
    public static function getInboundOpenTask(int $inboundId): ?array {
        $db = db();
        $t = $db->prepare("SELECT t.id, t.task_number, t.status, t.priority, t.assigned_to,
                    a.full_name AS assigned_name,
                    t.forklift_operator_id, fo.full_name AS forklift_operator_name,
                    t.checklist_partner_id, cp.full_name AS checklist_partner_name,
                    COUNT(ti.id) AS pallet_count,
                    SUM(CASE WHEN ti.status = 'Confirmed' THEN 1 ELSE 0 END) AS done_count
                FROM putaway_tasks t
                LEFT JOIN users a ON a.id = t.assigned_to
                LEFT JOIN users fo ON fo.id = t.forklift_operator_id
                LEFT JOIN users cp ON cp.id = t.checklist_partner_id
                LEFT JOIN putaway_task_items ti ON ti.task_id = t.id
                WHERE t.inbound_order_id = ? AND t.status IN ('Open','In Progress','Completed')
                GROUP BY t.id, a.full_name, fo.full_name, cp.full_name
                ORDER BY t.id DESC
                LIMIT 1");
        $t->execute([$inboundId]);
        $task = $t->fetch();
        if (!$task) return null;

        $r = $db->prepare("SELECT ti.id, ti.inbound_item_id, ti.product_id, p.product_code, p.product_name,
                    ti.batch_number, ti.uom, ti.pallet_seq, ti.quantity, ti.suggested_location,
                    ti.actual_location, ti.status, ti.lpn_code
                FROM putaway_task_items ti
                LEFT JOIN products p ON p.id = ti.product_id
                WHERE ti.task_id = ?
                ORDER BY ti.pallet_seq");
        $r->execute([(int)$task['id']]);

        return [
            'task' => [
                'id'                     => (int)$task['id'],
                'task_number'            => $task['task_number'],
                'status'                 => $task['status'],
                'priority'               => (int)$task['priority'],
                'assigned_to'            => $task['assigned_to'] !== null ? (int)$task['assigned_to'] : null,
                'assigned_name'          => $task['assigned_name'],
                'forklift_operator_id'   => $task['forklift_operator_id'] !== null ? (int)$task['forklift_operator_id'] : null,
                'forklift_operator_name' => $task['forklift_operator_name'],
                'checklist_partner_id'   => $task['checklist_partner_id'] !== null ? (int)$task['checklist_partner_id'] : null,
                'checklist_partner_name' => $task['checklist_partner_name'],
                'pallet_count'           => (int)$task['pallet_count'],
                'done_count'             => (int)$task['done_count'],
            ],
            'rows' => array_map(function ($x) {
                return [
                    'id'                 => (int)$x['id'],
                    'inbound_item_id'    => $x['inbound_item_id'] !== null ? (int)$x['inbound_item_id'] : null,
                    'product_id'         => $x['product_id'] !== null ? (int)$x['product_id'] : null,
                    'product_code'       => $x['product_code'],
                    'product_name'       => $x['product_name'],
                    'batch_number'       => $x['batch_number'],
                    'uom'                => $x['uom'],
                    'pallet_seq'         => (int)$x['pallet_seq'],
                    'quantity'           => (float)$x['quantity'],
                    'suggested_location' => $x['suggested_location'],
                    'actual_location'    => $x['actual_location'],
                    'status'             => $x['status'],
                    'lpn_code'           => $x['lpn_code'],
                ];
            }, $r->fetchAll()),
        ];
    }

    /**
     * v2 — validate a proposed placement against zone / UOM / level rules.
     * Used by inbound save_pallet_locations (manual placement confirmation).
     */
    public static function validatePlacement(int $productId, string $locationCode, float $qty, string $uom): array {
        $db = db();
        $reasons = [];
        $code = strtoupper(trim($locationCode));
        $specialLocs = ['QUA_SHELL', 'STAGING'];
        if (in_array($code, $specialLocs, true)) {
            return ['valid' => true, 'reasons' => ['Virtual location — rule checks skipped.']];
        }

        // Manual-save safety net: reject saves into an active putaway block.
        $blk = $db->prepare("SELECT reason FROM putaway_location_blocks
                WHERE is_active = 1 AND (
                    (scope_type = 'location' AND location_code = ?)
                    OR (scope_type = 'aisle' AND ? LIKE CONCAT(aisle_prefix, '%'))
                ) LIMIT 1");
        $blk->execute([$code, $code]);
        if ($b = $blk->fetch()) {
            return ['valid' => false, 'reasons' => ["Lokasi $code diblokir untuk putaway: " . trim((string)($b['reason'] ?? ''))]];
        }

        $locStmt = $db->prepare("SELECT * FROM location_master WHERE location_code = ? LIMIT 1");
        $locStmt->execute([$code]);
        $loc = $locStmt->fetch();
        if (!$loc || (int)($loc['is_active'] ?? 0) !== 1) {
            return ['valid' => false, 'reasons' => ["Lokasi '$code' tidak ditemukan / nonaktif."]];
        }
        $level = strtoupper((string)($loc['row_name'] ?? $loc['level'] ?? ''));
        if ($level === '') {
            return ['valid' => false, 'reasons' => ["Lokasi '$code' tidak memiliki level."]];
        }

        $prodStmt = $db->prepare("SELECT uom_type, uom_per_pallet FROM products WHERE id = ?");
        $prodStmt->execute([$productId]);
        $product = $prodStmt->fetch();
        if (!$product) return ['valid' => false, 'reasons' => ['Produk tidak ditemukan.']];
        $uomType = strtoupper((string)($uom ?: ($product['uom_type'] ?? 'Drum')));

        $limitsStmt = $db->prepare("SELECT * FROM uom_physical_limits WHERE uom_type = ?");
        $limitsStmt->execute([$uomType]);
        $limits = $limitsStmt->fetch() ?: [];
        $ruleStmt = $db->prepare("SELECT * FROM product_putaway_rules WHERE product_id = ?");
        $ruleStmt->execute([$productId]);
        $rule = $ruleStmt->fetch() ?: [];

        $ruleMax = strtoupper(trim((string)($rule['max_level'] ?? '')));
        $levels = ['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4, 'E' => 5];
        $maxLevel = (isset($levels[$ruleMax])) ? $ruleMax : strtoupper((string)($limits['max_level'] ?? 'E'));
        $minLevel = strtoupper((string)($limits['min_level'] ?? 'A'));
        $allowPickFace = $rule['allow_pick_face'] !== null ? (int)$rule['allow_pick_face'] : (int)($limits['allow_pick_face'] ?? 1);
        $requiresEquip = (int)($limits['requires_equipment'] ?? 0) === 1;

        $h = function (string $l) use ($levels): int {
            return $levels[strtoupper($l)] ?? 0;
        };

        if ($h($level) > $h($maxLevel)) {
            $reasons[] = "UOM $uomType tidak boleh melebihi level $maxLevel (lokasi berada di level $level).";
        }
        if ($h($level) < $h($minLevel)) {
            $reasons[] = "UOM $uomType tidak boleh di bawah level $minLevel.";
        }
        if ($level === 'A' && !$allowPickFace) {
            $reasons[] = "UOM $uomType tidak diizinkan di pick-face (Level A).";
        }
        if ($requiresEquip && $h($level) >= 4 && (int)($loc['equipment_accessible'] ?? 0) !== 1) {
            $reasons[] = "UOM $uomType memerlukan heavy equipment — level $level (D/E) hanya boleh dipakai jika lokasi ditandai 'akses alat berat'.";
        }
        if ((float)($loc['max_weight_kg'] ?? 0) > 0 && (float)($limits['max_weight_kg'] ?? 0) > (float)$loc['max_weight_kg']) {
            $reasons[] = "Berat pallet ({$limits['max_weight_kg']} kg) melebihi kapasitas lokasi ({$loc['max_weight_kg']} kg).";
        }

        return $reasons ? ['valid' => false, 'reasons' => $reasons] : ['valid' => true, 'reasons' => $reasons];
    }

    private static function _generateTaskNumber(): string {
        $db = db();
        $prefix = 'PKA-' . date('Ymd') . '-';
        $stmt = $db->prepare("SELECT task_number FROM putaway_tasks
                WHERE task_number LIKE ? ORDER BY task_number DESC LIMIT 1");
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();
        $seq = $last ? ((int)substr($last, strrpos($last, '-') + 1)) + 1 : 1;
        for ($i = 0; $i < 20; $i++) {
            $num = $prefix . str_pad($seq, 4, '0', STR_PAD_LEFT);
            $chk = $db->prepare("SELECT id FROM putaway_tasks WHERE task_number = ?");
            $chk->execute([$num]);
            if (!$chk->fetch()) return $num;
            $seq++;
        }
        return $prefix . date('His') . rand(10, 99);
    }

    private static function _palletDistribution(float $qty, int $upp): array {
        $full = intdiv((int)$qty, $upp);
        $rem  = (int)$qty % $upp;
        $dist = [];
        for ($i = 0; $i < $full; $i++) {
            $dist[] = ['quantity' => $upp, 'is_full' => true];
        }
        if ($rem > 0) {
            $dist[] = ['quantity' => $rem, 'is_full' => false];
        }
        return $dist;
    }

    public static function listTasks(array $params = []): array {
        $db = db();
        $sql = "SELECT pt.*, io.order_number, io.shipment_no,
                       u1.full_name AS assigned_name,
                       u2.full_name AS partner_name,
                       u3.full_name AS created_by_name,
                       COUNT(pti.id) AS item_count,
                       SUM(CASE WHEN pti.status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_count
                FROM putaway_tasks pt
                JOIN inbound_orders io ON pt.inbound_order_id = io.id
                LEFT JOIN users u1 ON pt.assigned_to = u1.id
                LEFT JOIN users u2 ON pt.team_partner = u2.id
                LEFT JOIN users u3 ON pt.created_by = u3.id
                LEFT JOIN putaway_task_items pti ON pti.task_id = pt.id";
        $where = [];
        $args = [];
        if (!empty($params['status'])) { $where[] = "pt.status = ?"; $args[] = $params['status']; }
        if (!empty($params['mine'])) {
            $uid = $_SESSION['user_id'] ?? 0;
            $where[] = "(pt.assigned_to = ? OR pt.team_partner = ? OR pt.forklift_operator_id = ? OR pt.checklist_partner_id = ?)";
            $args[] = $uid; $args[] = $uid; $args[] = $uid; $args[] = $uid;
        }
        if ($where) $sql .= " WHERE " . implode(" AND ", $where);
        $sql .= " GROUP BY pt.id ORDER BY pt.created_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$r) {
            $r['item_count']       = (int)$r['item_count'];
            $r['confirmed_count']  = (int)$r['confirmed_count'];
        }
        unset($r);
        return $rows;
    }

    public static function taskDetail(int $id): array {
        $db = db();
        $stmt = $db->prepare("SELECT pt.*, io.order_number, io.shipment_no,
                        u1.full_name AS assigned_name,
                        u2.full_name AS partner_name,
                        u3.full_name AS created_by_name,
                        u4.full_name AS completed_by_name
                FROM putaway_tasks pt
                JOIN inbound_orders io ON pt.inbound_order_id = io.id
                LEFT JOIN users u1 ON pt.assigned_to = u1.id
                LEFT JOIN users u2 ON pt.team_partner = u2.id
                LEFT JOIN users u3 ON pt.created_by = u3.id
                LEFT JOIN users u4 ON pt.completed_by = u4.id
                WHERE pt.id = ?");
        $stmt->execute([$id]);
        $task = $stmt->fetch();
        if (!$task) throw new Exception("Task tidak ditemukan.");

        $itemsStmt = $db->prepare("SELECT pti.*, p.product_code, p.product_name,
                        u.full_name AS confirmed_by_name
                FROM putaway_task_items pti
                JOIN products p ON pti.product_id = p.id
                LEFT JOIN users u ON pti.confirmed_by = u.id
                WHERE pti.task_id = ? ORDER BY pti.pallet_seq, pti.id");
        $itemsStmt->execute([$id]);
        $items = $itemsStmt->fetchAll();

        // Reconcile scan_override_count for partner report
        foreach ($items as &$i) {
            $i['scan_override'] = !empty($i['scan_override_reason']) ? 1 : 0;
        }
        unset($i);

        return ['task' => $task, 'items' => $items];
    }

    public static function assignTask(int $id, array $data): bool {
        $db = db();
        $taskStmt = $db->prepare("SELECT status FROM putaway_tasks WHERE id = ?");
        $taskStmt->execute([$id]);
        $task = $taskStmt->fetch();
        if (!$task) throw new Exception("Task tidak ditemukan.");
        if (!in_array($task['status'], ['Open','In Progress'], true)) throw new Exception("Task sudah selesai/dibatalkan.");

        $assigned = (int)($data['assigned_to'] ?? 0);
        $partner  = (int)($data['team_partner'] ?? 0);
        if (!$assigned) throw new Exception("assigned_to (forklift operator) wajib diisi.");
        if (!$partner)  throw new Exception("team_partner (checklist partner) wajib diisi.");
        if ($assigned === $partner) throw new Exception("Operator dan partner tidak boleh orang yang sama.");

        $db->prepare("UPDATE putaway_tasks SET
                assigned_to = ?, team_partner = ?, status = 'In Progress', updated_at = NOW()
                WHERE id = ?")
           ->execute([$assigned, $partner, $id]);
        return true;
    }

    /** S49 — dual-scan pallet confirmation with override reason. */
    public static function confirmPallet(int $itemId, string $scannedLocation, ?string $scanOverrideReason = null): bool {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $stmt = $db->prepare("SELECT pti.*, pt.status AS task_status
                    FROM putaway_task_items pti
                    JOIN putaway_tasks pt ON pti.task_id = pt.id
                    WHERE pti.id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) throw new Exception("Pallet item tidak ditemukan.");
            if ($item['status'] !== 'Pending') throw new Exception("Pallet sudah dikonfirmasi/dibatalkan.");
            if ($item['task_status'] === 'Completed') throw new Exception("Task sudah selesai.");
            if ($item['task_status'] === 'Cancelled') throw new Exception("Task dibatalkan.");

            $scanned = strtoupper(trim($scannedLocation));
            if ($scanned === '') throw new Exception("Lokasi hasil scan wajib diisi.");

            $mismatch = (strtoupper($item['suggested_location'] ?? '') !== $scanned);
            if ($mismatch && (trim($scanOverrideReason ?? '') === '')) {
                throw new Exception("Lokasi tidak sesuai saran ({$item['suggested_location']}). Alasan override wajib diisi.");
            }

            $db->prepare("UPDATE putaway_task_items SET
                    suggested_location = ?, status = 'Confirmed',
                    confirmed_by = ?, confirmed_at = NOW(),
                    scan_override_reason = ?
                    WHERE id = ?")
               ->execute([
                   $scanned,
                   $_SESSION['user_id'] ?? null,
                   $mismatch ? $scanOverrideReason : null,
                   $itemId,
               ]);

            if ($mismatch) {
                Stock::scanOverride(
                    (int)$item['product_id'],
                    $scanned,
                    $item['suggested_location'] ?? '',
                    $scanOverrideReason
                );
            }

            if ($ownTx) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** S42 — materialize stock_locations + move stock once ALL pallets confirmed. */
    public static function completeTask(int $id): bool {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $detail = self::taskDetail($id);
            $task = $detail['task'];
            if (!in_array($task['status'], ['In Progress','Open'], true)) {
                throw new Exception("Task harus In Progress/Open untuk diselesaikan.");
            }
            foreach ($detail['items'] as $i) {
                if ($i['status'] !== 'Confirmed') {
                    throw new Exception("Belum semua pallet dikonfirmasi (" . $i['lpn_code'] . " masih " . $i['status'] . ").");
                }
            }

            // Move stock STAGING -> target bin, write stock_locations with LPN
            foreach ($detail['items'] as $i) {
                $target = $i['suggested_location'];
                $qty    = floatval($i['quantity']);
                $pid    = (int)$i['product_id'];
                $batch  = $i['batch_number'];
                $stockId = (int)($i['stock_id'] ?? 0);

                // Rows resolved via the manual Manage Pallet Locations path
                // (save_pallet_locations) already have their stock_locations —
                // skip the stock move so we never double-create stock.
                // Check by putaway_task_item id (not inbound_item_id) since
                // multiple pallets can come from the same inbound item.
                $placedStmt = $db->prepare("SELECT COUNT(*) FROM stock_locations WHERE lpn_code = ?");
                $placedStmt->execute([$i['lpn_code'] ?? '']);
                if ((int)$placedStmt->fetchColumn() > 0) {
                    continue;
                }

                // Source STAGING row: pinned stock_id (legacy flow) with fallback.
                // v2 writes NO stock at Goods Received, so when no source exists
                // we create the destination stock directly (no deduction).
                $src = null;
                if ($stockId > 0) {
                    $srcStmt = $db->prepare("SELECT id, quantity, uom, pallet, manufacture_date, expiry_date
                            FROM stock WHERE id = ? AND location = 'STAGING' AND quantity > 0");
                    $srcStmt->execute([$stockId]);
                    $src = $srcStmt->fetch();
                }
                if (!$src) {
                    $srcStmt = $db->prepare("SELECT id, quantity, uom, pallet, manufacture_date, expiry_date
                            FROM stock
                            WHERE product_id = ? AND batch_number <=> ? AND location = 'STAGING'
                              AND stock_status = 'Available' AND quantity > 0
                            ORDER BY CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END, expiry_date ASC, id ASC
                            LIMIT 1");
                    $srcStmt->execute([$pid, $batch]);
                    $src = $srcStmt->fetch();
                }
                if ($src) {
                    $stockId = (int)$src['id'];

                    // Deduct from STAGING
                    $newQty = floatval($src['quantity']) - $qty;
                    if ($newQty <= 0.001) {
                        $db->prepare("DELETE FROM stock WHERE id = ?")->execute([$stockId]);
                    } else {
                        $db->prepare("UPDATE stock SET quantity = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$newQty, $stockId]);
                    }

                    // Drop the GR-time STAGING stock_locations row for the consumed stock
                    $db->prepare("DELETE FROM stock_locations WHERE stock_id = ? AND location_code = 'STAGING'")
                       ->execute([$stockId]);
                }

                // Destination stock (merge with same product+batch+loc)
                $destStmt = $db->prepare("SELECT id FROM stock
                        WHERE product_id = ? AND batch_number <=> ? AND location = ? AND stock_status = 'Available'
                        LIMIT 1");
                $destStmt->execute([$pid, $batch, $target]);
                $dest = $destStmt->fetch();
                if ($dest) {
                    $db->prepare("UPDATE stock SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?")
                       ->execute([$qty, $dest['id']]);
                    $stockId = (int)$dest['id'];
                } else {
                    $upp = max(1, intval($db->query("SELECT uom_per_pallet FROM products WHERE id = " . (int)$pid)->fetchColumn() ?: 4));
                    $db->prepare("INSERT INTO stock
                            (product_id, batch_number, location, quantity, uom, pallet,
                             manufacture_date, expiry_date, stock_status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Available')")
                       ->execute([
                           $pid, $batch, $target, $qty,
                           $i['uom'] ?? 'Drum',
                           max(1, (int)ceil($qty / $upp)),
                           $src['manufacture_date'] ?? null,
                           $src['expiry_date'] ?? null,
                       ]);
                    $stockId = (int)$db->lastInsertId();
                }

                // stock_locations row with LPN + pallet function (PICK_FACE at level A,
                // else RESERVE — derived from the target bin's real level).
                $lvlStmt = $db->prepare("SELECT COALESCE(NULLIF(level,''), row_name) FROM location_master WHERE location_code = ?");
                $lvlStmt->execute([$target]);
                $binLevel = strtoupper((string)$lvlStmt->fetchColumn());
                $palletFunction = ($binLevel === 'A') ? 'PICK_FACE' : 'RESERVE';
                $db->prepare("INSERT INTO stock_locations
                        (stock_id, location_code, pallet_seq, quantity, original_quantity,
                         uom, is_full_pallet, batch_number, lpn_code, inbound_item_id, status, pallet_function)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available', ?)")
                   ->execute([
                       $stockId, $target, $i['pallet_seq'] ?? 1, $qty, $qty,
                       $i['uom'] ?? 'Drum',
                       (int)$i['quantity'] >= max(1, intval($db->query("SELECT uom_per_pallet FROM products WHERE id = " . (int)$pid)->fetchColumn() ?: 4)) ? 1 : 0,
                       $batch, $i['lpn_code'], $i['inbound_item_id'] ?? null,
                       $palletFunction,
                   ]);

                // Ledger: TRANSFER_OUT (STAGING) + TRANSFER_IN (target)
                self::_taskLedger($pid, 'TRANSFER_OUT', $task['task_number'], $batch, 0, $qty, $i['uom'] ?? 'Drum', 'STAGING', 'Putaway task ke ' . $target);
                self::_taskLedger($pid, 'TRANSFER_IN', $task['task_number'], $batch, $qty, 0, $i['uom'] ?? 'Drum', $target, 'Putaway task dari STAGING');
            }

            $db->prepare("UPDATE putaway_tasks SET
                    status = 'Completed', completed_by = ?, completed_at = NOW(), updated_at = NOW()
                    WHERE id = ?")
               ->execute([$_SESSION['user_id'] ?? null, $id]);

            // ATP loop: items reach ATP once no Pending pallets remain in any open task
            $inboundItemIds = array_values(array_unique(array_map(
                fn($i) => (int)$i['inbound_item_id'], $detail['items']
            )));
            foreach ($inboundItemIds as $iid) {
                $pendingStmt = $db->prepare("SELECT COUNT(*) FROM putaway_task_items pti
                        JOIN putaway_tasks pt ON pti.task_id = pt.id
                        WHERE pti.inbound_item_id = ? AND pt.status IN ('Open','In Progress')
                          AND pti.status = 'Pending'");
                $pendingStmt->execute([$iid]);
                if ((int)$pendingStmt->fetchColumn() === 0) {
                    $db->prepare("UPDATE inbound_items SET
                            in_process_status = 'ATP', stock_status = 'Accepted'
                            WHERE id = ?")
                       ->execute([$iid]);
                }
            }

            // Auto-complete the inbound when all items are ATP/Unserviceable + no open pallets
            if (class_exists('Inbound')) {
                Inbound::autoComplete((int)$task['inbound_order_id']);
            }

            if ($ownTx) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    private static function _taskLedger(int $productId, string $txType, string $refNo, ?string $batch, float $qIn, float $qOut, string $uom, string $location, string $notes): void {
        $db = db();
        $balStmt = $db->prepare("SELECT balance FROM stock_ledger WHERE product_id = ? ORDER BY id DESC LIMIT 1");
        $balStmt->execute([$productId]);
        $balance = floatval($balStmt->fetchColumn() ?: 0) + $qIn - $qOut;

        $db->prepare("INSERT INTO stock_ledger
                (transaction_date, product_id, transaction_type, reference_type,
                 reference_id, reference_number, batch_number,
                 quantity_in, quantity_out, uom, balance, location, notes)
                VALUES (CURDATE(), ?, ?, 'PutawayTask', NULL, ?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([
               $productId, $txType, $refNo, $batch, $qIn, $qOut, $uom, $balance, $location, $notes
           ]);
    }

    public static function cancelTask(int $id): bool {
        $db = db();
        $stmt = $db->prepare("SELECT status FROM putaway_tasks WHERE id = ?");
        $stmt->execute([$id]);
        $task = $stmt->fetch();
        if (!$task) throw new Exception("Task tidak ditemukan.");
        if ($task['status'] !== 'Open') throw new Exception("Hanya task Open yang bisa dibatalkan.");
        $db->prepare("UPDATE putaway_tasks SET status = 'Cancelled', updated_at = NOW() WHERE id = ?")
           ->execute([$id]);
        return true;
    }

    /** S42 — block inbound complete while open tasks have Pending pallets. */
    public static function hasOpenPallets(int $inboundId): bool {
        $db = db();
        $stmt = $db->prepare("SELECT COUNT(*) FROM putaway_task_items pti
                JOIN putaway_tasks pt ON pti.task_id = pt.id
                WHERE pt.inbound_order_id = ?
                  AND pt.status IN ('Open','In Progress')
                  AND pti.status = 'Pending'");
        $stmt->execute([$inboundId]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /* ------------------------------------------------------------------ */
    /* S49 — v2 task queue actions (team, pallet, mobile, labels)          */
    /* ------------------------------------------------------------------ */

    /** Admin assigns 2-person team (forklift operator + checklist partner). */
    public static function assignTeam(int $taskId, int $forkliftId, int $partnerId): bool {
        $db = db();
        if ($forkliftId === $partnerId) throw new Exception("Operator dan partner tidak boleh orang yang sama.");

        $chk = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
        $chk->execute([$forkliftId]);
        if (!$chk->fetch()) throw new Exception("Forklift operator tidak ditemukan / nonaktif.");
        $chk->execute([$partnerId]);
        if (!$chk->fetch()) throw new Exception("Checklist partner tidak ditemukan / nonaktif.");

        $stmt = $db->prepare("SELECT status FROM putaway_tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) throw new Exception("Task tidak ditemukan.");
        if (!in_array($task['status'], ['Open','In Progress'], true)) {
            throw new Exception("Task sudah selesai/dibatalkan.");
        }

        $db->prepare("UPDATE putaway_tasks
                SET forklift_operator_id = ?, checklist_partner_id = ?,
                    assigned_to = ?, status = 'In Progress', updated_at = NOW()
                WHERE id = ?")
           ->execute([$forkliftId, $partnerId, $forkliftId, $taskId]);
        return true;
    }

    /** Remove team assignment from a task. */
    public static function unassignTeam(int $taskId): bool {
        $db = db();
        $stmt = $db->prepare("SELECT status FROM putaway_tasks WHERE id = ?");
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();
        if (!$task) throw new Exception("Task tidak ditemukan.");
        if (!in_array($task['status'], ['Open','In Progress'], true)) {
            throw new Exception("Task sudah selesai/dibatalkan.");
        }

        $db->prepare("UPDATE putaway_tasks
                SET forklift_operator_id = NULL, checklist_partner_id = NULL,
                    assigned_to = NULL, status = 'Open', updated_at = NOW()
                WHERE id = ?")
           ->execute([$taskId]);
        return true;
    }

    /** Change the suggested_location of a Pending pallet row. */
    public static function updateTaskPallet(int $itemId, string $newLocation, ?string $reason = null): bool {
        $db = db();
        $stmt = $db->prepare("SELECT pti.*, pt.status AS task_status
                FROM putaway_task_items pti
                JOIN putaway_tasks pt ON pti.task_id = pt.id
                WHERE pti.id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) throw new Exception("Pallet item tidak ditemukan.");
        if ($item['status'] !== 'Pending') throw new Exception("Hanya pallet Pending yang bisa diubah.");

        $loc = strtoupper(trim($newLocation));
        if ($loc === '') throw new Exception("location_code wajib diisi.");

        $db->prepare("UPDATE putaway_task_items
                SET suggested_location = ?, actual_location = ?, reason = ?, updated_at = NOW()
                WHERE id = ?")
           ->execute([$loc, $loc, $reason, $itemId]);
        return true;
    }

    /**
     * v2 — Partner confirms a pallet via dual-scan (LPN + location).
     * Open to ANY department (empty override in v2).
     */
    public static function completeTaskPallet(int $itemId, ?string $scanOverrideReason = null): bool {
        $db = db();
        $ownTx = !$db->inTransaction();
        try {
            if ($ownTx) $db->beginTransaction();

            $stmt = $db->prepare("SELECT pti.*, pt.status AS task_status
                    FROM putaway_task_items pti
                    JOIN putaway_tasks pt ON pti.task_id = pt.id
                    WHERE pti.id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch();
            if (!$item) throw new Exception("Pallet item tidak ditemukan.");
            if ($item['status'] !== 'Pending') throw new Exception("Pallet sudah dikonfirmasi/dibatalkan.");
            if ($item['task_status'] === 'Completed') throw new Exception("Task sudah selesai.");
            if ($item['task_status'] === 'Cancelled') throw new Exception("Task dibatalkan.");

            // The actual_location should already be set by the mobile dual-scan
            // (scan LPN → scan location → set actual_location → call this action).
            // Validate that actual_location is set.
            $actual = strtoupper(trim((string)($item['actual_location'] ?? '')));
            if ($actual === '') {
                // Fallback: if actual_location not yet set, use suggested_location
                $actual = strtoupper(trim((string)($item['suggested_location'] ?? '')));
            }

            $mismatch = false;
            if ($actual !== '' && strtoupper((string)($item['suggested_location'] ?? '')) !== $actual) {
                $mismatch = true;
                if (trim((string)($scanOverrideReason ?? '')) === '') {
                    throw new Exception("Lokasi tidak sesuai saran ({$item['suggested_location']}). Alasan override wajib diisi.");
                }
            }

            // Validate placement
            $validation = self::validatePlacement(
                (int)$item['product_id'], $actual, floatval($item['quantity']), $item['uom'] ?? 'Drum'
            );
            if (!$validation['valid'] && !$mismatch) {
                throw new Exception("Validasi lokasi gagal: " . implode('; ', $validation['reasons']));
            }

            $db->prepare("UPDATE putaway_task_items SET
                    actual_location = ?, status = 'Confirmed',
                    confirmed_by = ?, confirmed_at = NOW(),
                    scan_override_reason = ?, updated_at = NOW()
                    WHERE id = ?")
               ->execute([
                   $actual,
                   $_SESSION['user_id'] ?? null,
                   $mismatch ? $scanOverrideReason : null,
                   $itemId,
               ]);

            if ($mismatch) {
                self::_logActivity('SCAN_OVERRIDE', 'putaway', 'PutawayTaskItem', $itemId,
                    "Override lokasi {$item['suggested_location']} → $actual: $scanOverrideReason");
            }

            if ($ownTx) $db->commit();
            return true;
        } catch (Throwable $e) {
            if ($ownTx && $db->inTransaction()) $db->rollBack();
            throw $e;
        }
    }

    /** Active users for team assignment dropdown. */
    public static function listAssignableUsers(): array {
        $db = db();
        return $db->query("SELECT id, username, full_name, department
                FROM users WHERE is_active = 1
                ORDER BY full_name ASC")->fetchAll();
    }

    /** Label data for LPN printing. */
    public static function getLpnLabelData(int $itemId): ?array {
        $db = db();
        $stmt = $db->prepare("SELECT pti.*, p.product_code, p.product_name,
                    st.expiry_date, st.manufacture_date,
                    pt.task_number, io.order_number
                FROM putaway_task_items pti
                JOIN putaway_tasks pt ON pt.id = pti.task_id
                LEFT JOIN inbound_orders io ON io.id = pt.inbound_order_id
                JOIN products p ON p.id = pti.product_id
                LEFT JOIN stock st ON st.product_id = pti.product_id
                    AND st.batch_number <=> pti.batch_number
                WHERE pti.id = ?");
        $stmt->execute([$itemId]);
        $row = $stmt->fetch();
        if (!$row) return null;

        return [
            'lpn_code'           => $row['lpn_code'],
            'product_code'       => $row['product_code'],
            'product_name'       => $row['product_name'],
            'batch_number'       => $row['batch_number'],
            'uom'                => $row['uom'],
            'quantity'           => floatval($row['quantity']),
            'pallet_seq'         => (int)$row['pallet_seq'],
            'suggested_location' => $row['suggested_location'],
            'actual_location'    => $row['actual_location'],
            'expiry_date'        => $row['expiry_date'],
            'manufacture_date'   => $row['manufacture_date'],
            'task_number'        => $row['task_number'],
            'order_number'       => $row['order_number'],
        ];
    }

    /**
     * Fallback HTML string for LPN label when LabelPrinter class is unavailable.
     * Matches the frontend LpnLabel.tsx structure (CODE128 barcode text placeholder,
     * product name, batch, expiry, qty, pallet #, suggested location).
     */
    public static function fallbackLpnLabel(array $data): string {
        $lpn        = htmlspecialchars($data['lpn_code'] ?? '');
        $prodCode   = htmlspecialchars($data['product_code'] ?? '');
        $prodName   = htmlspecialchars($data['product_name'] ?? '');
        $batch      = htmlspecialchars($data['batch_number'] ?? '—');
        $expiry     = htmlspecialchars($data['expiry_date'] ?? '—');
        $qty        = number_format((float)($data['quantity'] ?? 0), 0, ',', '.');
        $uom        = htmlspecialchars($data['uom'] ?? '');
        $pallet     = (int)($data['pallet_seq'] ?? 0);
        $loc        = htmlspecialchars($data['suggested_location'] ?? '—');
        $taskNum    = htmlspecialchars($data['task_number'] ?? '—');
        $mfg        = htmlspecialchars($data['manufacture_date'] ?? '');

        return <<<HTML
<div style="font-family:monospace;width:320px;border:2px solid #111;padding:12px;margin:0 auto;background:#fff;color:#000">
  <div style="display:flex;justify-content:space-between;border-bottom:1px solid #999;padding-bottom:6px;margin-bottom:8px">
    <div style="font-weight:900;font-size:14px;letter-spacing:.08em">
      {$lpn}<br><span style="font-size:9px;font-weight:700;color:#666">LABEL PALLET / LPN</span>
    </div>
    <div style="font-size:9px;font-weight:700;color:#666;text-align:right;line-height:1.3">
      PT. K-ONE<br>WAREHOUSE
    </div>
  </div>
  <div style="font-size:11px;line-height:1.4">
    <div style="font-weight:700">{$prodName}</div>
    <div style="font-size:10px;color:#666">{$prodCode}</div>
    <div style="display:flex;justify-content:space-between;margin-top:4px">
      <span>Batch: <b>{$batch}</b></span>
      <span>Exp: <b>{$expiry}</b></span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span>Qty: <b>{$qty} {$uom}</b></span>
      <span>Pallet: <b>#{$pallet}</b></span>
    </div>
    <div style="display:flex;justify-content:space-between">
      <span>Lokasi: <b>{$loc}</b></span>
      <span>Task: <b>{$taskNum}</b></span>
    </div>
  </div>
  <div style="text-align:center;font-weight:700;letter-spacing:.35em;font-size:11px;margin-top:6px">{$lpn}</div>
</div>
HTML;
    }

    /** Mobile: tasks assigned to the current user (forklift operator or partner). */
    public static function myTasks(): array {
        $db = db();
        $uid = $_SESSION['user_id'] ?? 0;
        $stmt = $db->prepare("SELECT pt.*, io.order_number, io.shipment_no,
                    u1.full_name AS assigned_name,
                    u2.full_name AS partner_name,
                    COUNT(pti.id) AS pallet_count,
                    SUM(CASE WHEN pti.status = 'Confirmed' THEN 1 ELSE 0 END) AS confirmed_count
                FROM putaway_tasks pt
                JOIN inbound_orders io ON pt.inbound_order_id = io.id
                LEFT JOIN users u1 ON pt.forklift_operator_id = u1.id
                LEFT JOIN users u2 ON pt.checklist_partner_id = u2.id
                LEFT JOIN putaway_task_items pti ON pti.task_id = pt.id
                WHERE pt.status IN ('Open','In Progress')
                  AND (pt.forklift_operator_id = ? OR pt.checklist_partner_id = ?)
                GROUP BY pt.id
                ORDER BY pt.created_at DESC");
        $stmt->execute([$uid, $uid]);
        $tasks = $stmt->fetchAll();

        // Attach item rows to each task
        foreach ($tasks as &$task) {
            $itemStmt = $db->prepare("SELECT pti.id, pti.lpn_code, p.product_code, p.product_name,
                        pti.batch_number, pti.uom, pti.pallet_seq, pti.quantity,
                        pti.suggested_location, pti.actual_location, pti.status
                    FROM putaway_task_items pti
                    LEFT JOIN products p ON p.id = pti.product_id
                    WHERE pti.task_id = ?
                    ORDER BY pti.pallet_seq");
            $itemStmt->execute([(int)$task['id']]);
            $task['rows'] = $itemStmt->fetchAll();
            $task['forklift_operator_name'] = $task['assigned_name'] ?? null;
        }
        unset($task);
        return $tasks;
    }

    /** Scan override: allow mismatch with typed reason. */
    public static function scanOverride(int $itemId, string $scannedLocation, string $reason): bool {
        if (trim($reason) === '') throw new Exception("Alasan override wajib diisi.");
        $db = db();
        $stmt = $db->prepare("SELECT pti.id, pti.suggested_location, pt.status AS task_status
                FROM putaway_task_items pti
                JOIN putaway_tasks pt ON pti.task_id = pt.id
                WHERE pti.id = ?");
        $stmt->execute([$itemId]);
        $item = $stmt->fetch();
        if (!$item) throw new Exception("Pallet item tidak ditemukan.");
        if ($item['task_status'] !== 'In Progress') throw new Exception("Task tidak dalam status In Progress.");

        $loc = strtoupper(trim($scannedLocation));
        $db->prepare("UPDATE putaway_task_items
                SET actual_location = ?, scan_override_reason = ?, updated_at = NOW()
                WHERE id = ?")
           ->execute([$loc, $reason, $itemId]);
        return true;
    }

    private static function _logActivity(string $type, string $module, string $entityType, int $entityId, ?string $description): void {
        if (class_exists('ActivityLogger')) {
            ActivityLogger::log($type, $module, $entityType, $entityId, null, $description);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Zoning config CRUD                                                  */
    /* ------------------------------------------------------------------ */

    public static function listZones(): array {
        $db = db();
        return $db->query("SELECT z.*, COUNT(lm.id) AS location_count
                FROM zones z
                LEFT JOIN location_master lm ON lm.zone_code = z.zone_code
                GROUP BY z.id
                ORDER BY z.priority ASC, z.zone_code")->fetchAll();
    }

    public static function saveZone(array $data): int {
        $db = db();
        $code = strtoupper(trim($data['zone_code'] ?? ''));
        if ($code === '') throw new Exception("zone_code wajib diisi.");
        $zoneType = strtoupper($data['zone_type'] ?? 'RESERVE');
        $validTypes = ['PICK_FAST','RESERVE','BULK','QUARANTINE','STAGING','UNALLOCATED'];
        if (!in_array($zoneType, $validTypes, true)) throw new Exception("zone_type tidak valid.");
        $priority = max(0, intval($data['priority'] ?? 10));
        $isActive = $data['is_active'] ?? 1;
        $id = intval($data['id'] ?? 0);

        if ($id > 0) {
            $db->prepare("UPDATE zones SET zone_code=?, zone_name=?, zone_type=?, priority=?, is_active=? WHERE id=?")
               ->execute([$code, $data['zone_name'] ?? '', $zoneType, $priority, $isActive, $id]);
            return $id;
        }
        $db->prepare("INSERT INTO zones (zone_code, zone_name, zone_type, priority, is_active) VALUES (?,?,?,?,?)")
           ->execute([$code, $data['zone_name'] ?? '', $zoneType, $priority, $isActive]);
        return (int)$db->lastInsertId();
    }

    public static function deleteZone(int $id): bool {
        $db = db();
        $stmt = $db->prepare("DELETE FROM zones WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function listZoneAisles(): array {
        $db = db();
        return $db->query("SELECT za.*, z.zone_name, z.zone_type
                FROM zone_aisles za
                JOIN zones z ON z.zone_code = za.zone_code
                ORDER BY za.zone_code, za.aisle, za.min_level, za.max_level")->fetchAll();
    }

    public static function saveZoneAisle(array $data): int {
        $db = db();
        $zoneCode = strtoupper(trim($data['zone_code'] ?? ''));
        $aisle    = strtoupper(trim($data['aisle'] ?? ''));
        $minLevel = strtoupper($data['min_level'] ?? 'A');
        $maxLevel = strtoupper($data['max_level'] ?? 'E');
        if ($zoneCode === '') throw new Exception("zone_code wajib diisi.");
        if ($aisle === '')    throw new Exception("aisle wajib diisi.");
        $levels = ['A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5];
        if (!isset($levels[$minLevel]) || !isset($levels[$maxLevel])) throw new Exception("min_level / max_level tidak valid (A–E).");
        if ($levels[$minLevel] > $levels[$maxLevel]) throw new Exception("min_level tidak boleh lebih tinggi dari max_level.");

        $z = $db->prepare("SELECT id FROM zones WHERE zone_code = ? AND is_active = 1");
        $z->execute([$zoneCode]);
        if (!$z->fetch()) throw new Exception("Zone tidak ditemukan / nonaktif.");

        $isActive = $data['is_active'] ?? 1;
        $id = intval($data['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("UPDATE zone_aisles SET zone_code=?, aisle=?, min_level=?, max_level=?, is_active=? WHERE id=?")
               ->execute([$zoneCode, $aisle, $minLevel, $maxLevel, $isActive, $id]);
            return $id;
        }
        $db->prepare("INSERT INTO zone_aisles (zone_code, aisle, min_level, max_level, is_active) VALUES (?,?,?,?,?)")
           ->execute([$zoneCode, $aisle, $minLevel, $maxLevel, $isActive]);
        return (int)$db->lastInsertId();
    }

    public static function deleteZoneAisle(int $id): bool {
        $db = db();
        $stmt = $db->prepare("DELETE FROM zone_aisles WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public static function listUomLimits(): array {
        $db = db();
        return $db->query("SELECT * FROM uom_physical_limits ORDER BY uom_type")->fetchAll();
    }

    public static function saveUomLimit(array $data): string {
        $db = db();
        $uomType = trim($data['uom_type'] ?? '');
        if ($uomType === '') throw new Exception("uom_type wajib diisi.");
        $minLevel = strtoupper($data['min_level'] ?? 'A');
        $maxLevel = strtoupper($data['max_level'] ?? 'E');
        $levels = ['A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5];
        if (!isset($levels[$minLevel]) || !isset($levels[$maxLevel])) throw new Exception("min_level / max_level tidak valid (A–E).");
        if ($levels[$minLevel] > $levels[$maxLevel]) throw new Exception("min_level tidak boleh lebih tinggi dari max_level.");

        $allowPick = $data['allow_pick_face'] ?? 1;
        $maxWeight = ($data['max_weight_kg'] ?? '') !== '' ? floatval($data['max_weight_kg']) : null;
        $maxHeight = ($data['max_height_cm'] ?? '') !== '' ? floatval($data['max_height_cm']) : null;
        $reqEquip  = $data['requires_equipment'] ?? 0;

        // UPSERT: check existing
        $chk = $db->prepare("SELECT uom_type FROM uom_physical_limits WHERE UPPER(uom_type) = UPPER(?)");
        $chk->execute([$uomType]);
        if ($chk->fetch()) {
            $db->prepare("UPDATE uom_physical_limits SET min_level=?, max_level=?, allow_pick_face=?, max_weight_kg=?, max_height_cm=?, requires_equipment=?, updated_at=NOW() WHERE UPPER(uom_type)=UPPER(?)")
               ->execute([$minLevel, $maxLevel, $allowPick, $maxWeight, $maxHeight, $reqEquip, $uomType]);
        } else {
            $db->prepare("INSERT INTO uom_physical_limits (uom_type, min_level, max_level, allow_pick_face, max_weight_kg, max_height_cm, requires_equipment, updated_at) VALUES (?,?,?,?,?,?,?,NOW())")
               ->execute([$uomType, $minLevel, $maxLevel, $allowPick, $maxWeight, $maxHeight, $reqEquip]);
        }
        return $uomType;
    }

    public static function listProductRules(?int $productId = null): array {
        $db = db();
        $where = '';
        $args = [];
        if ($productId && $productId > 0) {
            $where = 'WHERE ppr.product_id = ?';
            $args[] = $productId;
        }
        $sql = "SELECT ppr.*, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
                FROM product_putaway_rules ppr
                JOIN products p ON p.id = ppr.product_id
                {$where}
                ORDER BY p.product_name";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    public static function saveProductRule(array $data): int {
        $db = db();
        $productId = intval($data['product_id'] ?? 0);
        if (!$productId) throw new Exception("product_id wajib diisi.");
        $chk = $db->prepare("SELECT id FROM products WHERE id = ?");
        $chk->execute([$productId]);
        if (!$chk->fetch()) throw new Exception("Produk tidak ditemukan.");

        $maxLevel = !empty($data['max_level']) ? strtoupper($data['max_level']) : null;
        if ($maxLevel) {
            $levels = ['A'=>1,'B'=>2,'C'=>3,'D'=>4,'E'=>5];
            if (!isset($levels[$maxLevel])) throw new Exception("max_level tidak valid (A–E).");
        }

        $zoneCode  = strtoupper($data['preferred_zone_code'] ?? 'RESERVE');
        $allowPick = ($data['allow_pick_face'] ?? '') !== '' ? intval($data['allow_pick_face']) : null;
        $ftp       = $data['full_pallet_to_pick'] ?? 0;
        $minPf     = $data['min_pick_face_qty'] ?? 0;
        $maxPf     = $data['max_pick_face_qty'] ?? 0;
        $consolid  = $data['consolidate'] ?? 1;

        $chk2 = $db->prepare("SELECT product_id FROM product_putaway_rules WHERE product_id = ?");
        $chk2->execute([$productId]);
        if ($chk2->fetch()) {
            $db->prepare("UPDATE product_putaway_rules SET preferred_zone_code=?, max_level=?, allow_pick_face=?, full_pallet_to_pick=?, min_pick_face_qty=?, max_pick_face_qty=?, consolidate=?, updated_at=NOW() WHERE product_id=?")
               ->execute([$zoneCode, $maxLevel, $allowPick, $ftp, $minPf, $maxPf, $consolid, $productId]);
        } else {
            $db->prepare("INSERT INTO product_putaway_rules (product_id, preferred_zone_code, max_level, allow_pick_face, full_pallet_to_pick, min_pick_face_qty, max_pick_face_qty, consolidate, updated_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
               ->execute([$productId, $zoneCode, $maxLevel, $allowPick, $ftp, $minPf, $maxPf, $consolid]);
        }
        return $productId;
    }

    public static function deleteProductRule(int $productId): bool {
        $db = db();
        $stmt = $db->prepare("DELETE FROM product_putaway_rules WHERE product_id = ?");
        $stmt->execute([$productId]);
        return $stmt->rowCount() > 0;
    }

    public static function listAisleMap(?string $aisle = null, ?string $level = null): array {
        $db = db();
        $where = ['lm.is_active = 1'];
        $args = [];
        if ($aisle) { $where[] = 'lm.aisle = ?'; $args[] = $aisle; }
        if ($level) { $where[] = 'lm.row_name = ?'; $args[] = $level; }

        $sql = "SELECT lm.aisle,
                       lm.row_name AS level,
                       COUNT(lm.id) AS total,
                       COUNT(CASE WHEN sl.id IS NOT NULL THEN 1 END) AS occupied,
                       COUNT(CASE WHEN sl.id IS NULL THEN 1 END) AS free,
                       COALESCE(lm.zone_code, lm.zone) AS zone_code,
                       MAX(CASE WHEN lm.is_pick_face = 1 THEN 1 ELSE 0 END) AS is_pick_face,
                       SUM(CASE WHEN lm.equipment_accessible = 1 THEN 1 ELSE 0 END) AS equip_accessible
                FROM location_master lm
                LEFT JOIN stock_locations sl ON sl.location_code = lm.location_code AND sl.status IN ('Available','Reserved')
                WHERE " . implode(' AND ', $where) . "
                GROUP BY lm.aisle, lm.row_name, COALESCE(lm.zone_code, lm.zone)
                ORDER BY lm.aisle, lm.row_name";
        $stmt = $db->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        $locations = null;
        if ($aisle && $level) {
            $locSql = "SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name AS level, lm.position,
                              COALESCE(lm.zone_code, lm.zone) AS zone_code, lm.is_pick_face, lm.equipment_accessible,
                              sl.quantity, sl.batch_number, sl.pallet_function,
                              st.expiry_date, p.product_code, p.product_name
                       FROM location_master lm
                       LEFT JOIN stock_locations sl ON sl.location_code = lm.location_code AND sl.status IN ('Available','Reserved')
                       LEFT JOIN stock st ON st.id = sl.stock_id
                       LEFT JOIN products p ON p.id = st.product_id
                       WHERE lm.is_active = 1 AND lm.aisle = ? AND lm.row_name = ?
                       ORDER BY lm.rack, lm.position";
            $locStmt = $db->prepare($locSql);
            $locStmt->execute([$aisle, $level]);
            $locations = $locStmt->fetchAll();
        }

        return ['rows' => $rows, 'locations' => $locations];
    }

    public static function listAllBins(): array {
        $db = db();
        $sql = "SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name AS level, lm.position,
                       COALESCE(lm.zone_code, lm.zone) AS zone_code, lm.is_pick_face, lm.equipment_accessible,
                       SUM(sl.quantity) AS quantity,
                       MAX(sl.pallet_function) AS pallet_function,
                       MAX(sl.batch_number) AS batch_number,
                       MAX(st.product_id) AS product_id,
                       MAX(p.product_code) AS product_code,
                       MAX(p.product_name) AS product_name,
                       MAX(st.expiry_date) AS expiry_date
                FROM location_master lm
                LEFT JOIN stock_locations sl ON sl.location_code = lm.location_code AND sl.status IN ('Available','Reserved')
                LEFT JOIN stock st ON st.id = sl.stock_id
                LEFT JOIN products p ON p.id = st.product_id
                WHERE lm.is_active = 1
                GROUP BY lm.id, lm.location_code, lm.aisle, lm.rack, lm.row_name, lm.position,
                         COALESCE(lm.zone_code, lm.zone), lm.is_pick_face, lm.equipment_accessible
                ORDER BY lm.aisle, lm.rack, lm.row_name, lm.position";
        $rows = $db->query($sql)->fetchAll();

        $blocks = self::_blockedLocations();
        foreach ($rows as &$x) {
            $x['occupied'] = ($x['quantity'] != null && floatval($x['quantity']) > 0) ? 1 : 0;
            $x['quantity'] = $x['quantity'] != null ? floatval($x['quantity']) : 0;
            $x['is_pick_face'] = intval($x['is_pick_face']);
            $x['equipment_accessible'] = intval($x['equipment_accessible']);
            // Check block
            $x['blocked'] = 0;
            $x['block_reason'] = null;
            $locCode = $x['location_code'];
            foreach ($blocks as $b) {
                if ($b['scope_type'] === 'location' && $b['location_code'] === $locCode) {
                    $x['blocked'] = 1;
                    $x['block_reason'] = $b['reason'];
                    break;
                }
                if ($b['scope_type'] === 'aisle' && strpos($locCode, $b['aisle_prefix']) === 0) {
                    $x['blocked'] = 1;
                    $x['block_reason'] = $b['reason'];
                    break;
                }
            }
        }
        return $rows;
    }

    /** Cancel still-Pending pallets of an item across open tasks (rollback path). */
    public static function cancelItemPallets(int $itemId): void {
        $db = db();
        $db->prepare("UPDATE putaway_task_items pti
                JOIN putaway_tasks pt ON pti.task_id = pt.id
                SET pti.status = 'Cancelled'
                WHERE pti.inbound_item_id = ? AND pt.status IN ('Open','In Progress') AND pti.status = 'Pending'")
           ->execute([$itemId]);
    }

    /* ------------------------------------------------------------------ */
    /* Pallet helpers (mirror v2 apps/api/src/common/pallet.ts)           */
    /* ------------------------------------------------------------------ */

    /**
     * Derive units-per-pallet (UPP) from a product name pack-size pattern.
     * "12*0.8L" → 48, "1*209L" → 4, "24*0.12L" → 80, etc.
     */
    public static function deriveUppFromPackSize(?string $text): ?int {
        if (!$text) return null;
        $m = null;
        if (!preg_match('/(\d+)\s*\*\s*(\d+(?:\.\d+)?)\s*(l|kg)/i', $text, $m)) {
            return null;
        }
        $qty  = (int)$m[1];
        $size = (float)$m[2];
        $unit = strtolower($m[3]);

        // kg variants
        if ($unit === 'kg') {
            if ($size === 770) return 1;          // fluid bag
            if ($size >= 100)  return 4;          // 180 kg drum
            if ($size === 18 || $size === 15) return 24; // pail
            return null;
        }
        // 1 × large containers
        if ($qty === 1) {
            if ($size >= 1000) return 1;          // IBC
            if ($size >= 200)  return 4;          // 200/209 L drum
            if ($size === 20)  return 24;         // 20 L pail
            return null;
        }
        if ($qty === 12) {
            if ($size === 1) return 44;           // 12*1 L
            if (in_array($size, [0.8, 0.7, 0.65], true)) return 48; // 12*0.8 L
            return null;
        }
        if ($qty === 4 && $size === 4) return 36;
        if ($qty === 3 && $size === 5) return 36;
        if ($qty === 24 && $size === 0.12) return 80;
        if ($qty === 24 && $size === 1)   return 16;
        if ($qty === 10 && $size === 0.25) return 100;

        return null;
    }

    /** Level character = 5th char of location code (A-E). */
    public static function levelOf(?string $locationCode): string {
        if (!$locationCode || strlen($locationCode) < 5) return 'B';
        return strtoupper($locationCode[4]);
    }

    /** 1 when qty >= UPP (tolerance 0.001). */
    public static function isFullPallet(float $qty, ?int $uomPerPallet): int {
        $upp = (int)($uomPerPallet ?? 4);
        if ($upp <= 0) return 1;
        return ($qty >= $upp - 0.001) ? 1 : 0;
    }

    /** Level A → fractional pallets (round 2dp); other levels → ceil. */
    public static function calcPalletByLocation(float $qty, int $upp, ?string $loc): int {
        if ($upp <= 0) return 0;
        if (self::levelOf($loc) === 'A') {
            return (int)round($qty / $upp, 2);
        }
        return (int)ceil($qty / $upp);
    }
}
?>