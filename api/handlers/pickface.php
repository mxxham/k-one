<?php
/**
 * Pickface management API handler.
 *
 * Actions: list, get, create, update, delete
 * All actions require admin role.
 */

require_once __DIR__ . '/../../classes/Auth.php';

function handle_pickface($action) {
    $db = db();

    switch ($action) {
        case 'list': {
            $search = query('search') ?? '';
            $where = '';
            $args = [];
            if ($search !== '') {
                $where = 'WHERE p.product_code LIKE ? OR p.product_name LIKE ? OR lm.location_code LIKE ?';
                $searchTerm = '%' . $search . '%';
                $args = [$searchTerm, $searchTerm, $searchTerm];
            }
            $sql = "SELECT c.id, c.sku_id, c.pickface_bin_id, c.inbound_pickface_bin_id,
                           c.pickface_min, c.pickface_max,
                           c.created_at, c.updated_at,
                           p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                           lm.location_code AS pickface_location_code,
                           lm2.location_code AS inbound_pickface_location_code,
                           lm.row_name, lm.aisle, lm.zone
                    FROM sku_pickface_config c
                    JOIN products p ON p.id = c.sku_id
                    LEFT JOIN location_master lm ON lm.id = c.pickface_bin_id
                    LEFT JOIN location_master lm2 ON lm2.id = c.inbound_pickface_bin_id
                    $where
                    ORDER BY p.product_code, lm.location_code";
            $stmt = $db->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll();
            foreach ($rows as &$r) {
                $r['assigned'] = $r['pickface_bin_id'] !== null;
                $r['pickface_min'] = (int)$r['pickface_min'];
                $r['pickface_max'] = (int)$r['pickface_max'];
                $r['sku_id'] = (int)$r['sku_id'];
                $r['pickface_bin_id'] = $r['pickface_bin_id'] !== null ? (int)$r['pickface_bin_id'] : null;
                $r['inbound_pickface_bin_id'] = $r['inbound_pickface_bin_id'] !== null ? (int)$r['inbound_pickface_bin_id'] : null;
            }
            unset($r);
            json_out(['configs' => $rows]);
            break;
        }

        case 'get': {
            $id = (int)query('id');
            if ($id <= 0) json_err('Invalid config ID.', 400);
            $stmt = $db->prepare("SELECT c.id, c.sku_id, c.pickface_bin_id, c.pickface_min, c.pickface_max,
                                         p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                                         lm.location_code AS pickface_location_code,
                                         lm.row_name, lm.aisle, lm.zone
                                  FROM sku_pickface_config c
                                  JOIN products p ON p.id = c.sku_id
                                  LEFT JOIN location_master lm ON lm.id = c.pickface_bin_id
                                  WHERE c.id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) json_err('Pickface config not found.', 404);
            $row['assigned'] = $row['pickface_bin_id'] !== null;
            $row['pickface_min'] = (int)$row['pickface_min'];
            $row['pickface_max'] = (int)$row['pickface_max'];
            json_out(['config' => $row]);
            break;
        }

        case 'create': {
            $data = body();
            $skuId    = (int)($data['sku_id'] ?? 0);
            $binId    = isset($data['pickface_bin_id']) && $data['pickface_bin_id'] !== '' && $data['pickface_bin_id'] !== null ? (int)$data['pickface_bin_id'] : null;
            $minQty   = (int)($data['pickface_min'] ?? 1);
            $maxQty   = (int)($data['pickface_max'] ?? 0);

            if (!$skuId) json_err('sku_id is required.', 400);
            if ($minQty < 0) json_err('pickface_min must be >= 0.', 400);
            if ($maxQty < 0) json_err('pickface_max must be >= 0.', 400);

            // Validate SKU exists
            $s = $db->prepare("SELECT id FROM products WHERE id = ?");
            $s->execute([$skuId]);
            if (!$s->fetch()) json_err('Product/SKU not found.', 400);

            // If bin is specified, validate it's an active A-level pickface bin
            if ($binId !== null) {
                $v = $db->prepare("SELECT id FROM location_master WHERE id = ? AND is_active = 1 AND is_pick_face = 1 AND row_name = 'A'");
                $v->execute([$binId]);
                if (!$v->fetch()) json_err('Invalid pickface bin: must be an active A-level pickface location.', 400);

                // Check uniqueness: bin already assigned to another SKU
                $u = $db->prepare("SELECT id FROM sku_pickface_config WHERE pickface_bin_id = ? AND sku_id != ?");
                $u->execute([$binId, $skuId]);
                if ($u->fetch()) json_err('This bin is already assigned to another SKU.', 409);

                // Check SKU already has a config (since uk_sku_pickface is on sku_id)
                $e = $db->prepare("SELECT id FROM sku_pickface_config WHERE sku_id = ?");
                $e->execute([$skuId]);
                if ($e->fetch()) json_err('SKU already has a pickface config. Use update to modify it.', 409);
            }

            $stmt = $db->prepare("INSERT INTO sku_pickface_config (sku_id, pickface_bin_id, pickface_min, pickface_max)
                                  VALUES (?, ?, ?, ?)");
            $stmt->execute([$skuId, $binId, $minQty, $maxQty]);
            $id = (int)$db->lastInsertId();

            ActivityLogger::log('CREATE_PICKFACE_CONFIG', 'pickface', 'PickfaceConfig', $id, null,
                "Created pickface config for SKU #{$skuId} (bin: " . ($binId ?? 'unassigned') . ", min: {$minQty}, max: {$maxQty})");
            json_out(['id' => $id, 'success' => true]);
            break;
        }

        case 'update': {
            $data = body();
            $id   = (int)($data['id'] ?? 0);
            $binId = isset($data['pickface_bin_id']) && $data['pickface_bin_id'] !== '' && $data['pickface_bin_id'] !== null ? (int)$data['pickface_bin_id'] : null;
            $minQty = (int)($data['pickface_min'] ?? 1);
            $maxQty = (int)($data['pickface_max'] ?? 0);

            if ($id <= 0) json_err('Invalid config ID.', 400);
            if ($minQty < 0) json_err('pickface_min must be >= 0.', 400);
            if ($maxQty < 0) json_err('pickface_max must be >= 0.', 400);

            // Fetch existing config
            $existing = $db->prepare("SELECT sku_id, pickface_bin_id FROM sku_pickface_config WHERE id = ?");
            $existing->execute([$id]);
            if (!$existing->fetch()) json_err('Pickface config not found.', 404);

            // If bin is being set, validate it's an active A-level pickface bin
            if ($binId !== null) {
                $v = $db->prepare("SELECT id FROM location_master WHERE id = ? AND is_active = 1 AND is_pick_face = 1 AND row_name = 'A'");
                $v->execute([$binId]);
                if (!$v->fetch()) json_err('Invalid pickface bin: must be an active A-level pickface location.', 400);

                // Check bin uniqueness (excluding current row)
                $u = $db->prepare("SELECT id FROM sku_pickface_config WHERE pickface_bin_id = ? AND id != ?");
                $u->execute([$binId, $id]);
                if ($u->fetch()) json_err('This bin is already assigned to another SKU.', 409);
            }

            $stmt = $db->prepare("UPDATE sku_pickface_config SET pickface_bin_id = ?, pickface_min = ?, pickface_max = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$binId, $minQty, $maxQty, $id]);

            ActivityLogger::log('UPDATE_PICKFACE_CONFIG', 'pickface', 'PickfaceConfig', $id, null,
                "Updated pickface config #{$id} (bin: " . ($binId ?? 'unassigned') . ", min: {$minQty}, max: {$maxQty})");
            json_out(['id' => $id, 'success' => true]);
            break;
        }

        case 'delete': {
            $id = (int)query('id');
            if ($id <= 0) json_err('Invalid config ID.', 400);

            // Fetch for logging
            $info = $db->prepare("SELECT sku_id FROM sku_pickface_config WHERE id = ?");
            $info->execute([$id]);
            $row = $info->fetch();
            if (!$row) json_err('Pickface config not found.', 404);

            // Set pickface_bin_id to NULL (unassign) — keep the row for auto-detection fallback
            $stmt = $db->prepare("UPDATE sku_pickface_config SET pickface_bin_id = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$id]);

            ActivityLogger::log('DELETE_PICKFACE_CONFIG', 'pickface', 'PickfaceConfig', $id, null,
                "Unassigned pickface bin for SKU #{$row['sku_id']} (config #{$id})");
            json_out(['id' => $id, 'success' => true]);
            break;
        }

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
