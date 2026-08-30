<?php

function handle_products($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $search = trim(query('search') ?: '');
            $perPage = (int)query('per_page', 25);
            $page = max(1, (int)query('page', 1));
            $offset = ($page - 1) * $perPage;
            $total = Product::getCount($search);
            $totalAll = $search ? Product::getCount() : $total;
            $rows = Product::getPaginated($search, $perPage, $offset);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            json_out([
                'rows' => $rows,
                'total' => (int)$total,
                'total_all' => (int)$totalAll,
                'page' => $page,
                'per_page' => $perPage,
                'uom_stats' => Product::getStatsByUom(),
            ]);
            break;

        case 'all':
            api_require_auth();
            json_out(['rows' => products_options()]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            json_out(['product' => (Product::getById($id) ?: null)]);
            break;

        case 'create':
            api_require_write();
            $data = body();
            $id = Product::create($data);
            ActivityLogger::log('CREATE_PRODUCT', 'stock', 'Product', null,
                $data['product_code'] ?? null, 'Buat produk: ' . ($data['product_name'] ?? '—'));
            json_out(['id' => (int)$id]);
            break;

        case 'update':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            Product::update($id, $data);
            ActivityLogger::log('UPDATE_PRODUCT', 'stock', 'Product', $id,
                $data['product_code'] ?? null, 'Edit produk: ' . ($data['product_name'] ?? '—'));
            json_out(['id' => $id]);
            break;

        case 'delete':
            api_require_admin();
            $data = body();
            $pid = (int)($data['id'] ?? query('id'));
            $db = db();
            $pInfo = $db->prepare("SELECT product_code, product_name FROM products WHERE id=?");
            $pInfo->execute([$pid]);
            $pRow = $pInfo->fetch();
            $db->prepare("DELETE sl FROM stock_locations sl JOIN stock s ON sl.stock_id = s.id WHERE s.product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM stock_locations WHERE inbound_item_id IN (SELECT id FROM inbound_items WHERE product_id=?)")->execute([$pid]);
            $db->prepare("DELETE FROM stock_ledger WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM stock WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM inbound_items WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM outbound_items WHERE product_id=?")->execute([$pid]);
            $db->prepare("DELETE FROM stock_take_items WHERE product_id=?")->execute([$pid]);
            Product::delete($pid);
            ActivityLogger::log('DELETE_PRODUCT', 'stock', 'Product', $pid,
                $pRow['product_code'] ?? null, 'Hapus produk: ' . ($pRow['product_name'] ?? $pid));
            json_out(['id' => $pid]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

function handle_customers($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $search = trim(query('search') ?: '');
            $perPage = (int)query('per_page', 25);
            $page = max(1, (int)query('page', 1));
            $offset = ($page - 1) * $perPage;
            $total = Customer::getCount($search);
            $rows = Customer::getPaginated($search, $perPage, $offset);
            json_out([
                'rows' => $rows,
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $perPage,
                'type_stats' => Customer::getTypeStats(),
            ]);
            break;

        case 'all':
            api_require_auth();
            json_out(['rows' => customers_options()]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            json_out(['customer' => (Customer::getById($id) ?: null)]);
            break;

        case 'create':
            api_require_write();
            $data = body();
            Customer::create($data);
            ActivityLogger::log('CREATE_CUSTOMER', 'customer', 'Customer', null,
                $data['customer_code'] ?? null, 'Buat customer: ' . ($data['customer_name'] ?? '—'));
            json_out(['ok' => true]);
            break;

        case 'update':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            Customer::update($id, $data);
            ActivityLogger::log('UPDATE_CUSTOMER', 'customer', 'Customer', $id,
                $data['customer_code'] ?? null, 'Edit customer: ' . ($data['customer_name'] ?? '—'));
            json_out(['id' => $id]);
            break;

        case 'delete':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            Customer::delete($id);
            ActivityLogger::log('DELETE_CUSTOMER', 'customer', 'Customer', $id, null, 'Hapus customer ID ' . $id);
            json_out(['id' => $id]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

function handle_locations($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $zone = query('zone') ?: null;
            $availableOnly = query('available_only') === '1' || query('available_only') === 'true';
            $perPage = max(1, (int)query('per_page', 25));
            $page = max(1, (int)query('page', 1));
            $offset = ($page - 1) * $perPage;
            $rows = LocationManager::getAll($zone, $availableOnly, $perPage, $offset);
            $total = LocationManager::countAll($zone, $availableOnly);
            json_out([
                'rows' => $rows,
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'zones' => location_zone_summary(),
            ]);
            break;

        case 'all':
            api_require_auth();
            json_out(['rows' => location_options()]);
            break;

        case 'check':
            api_require_auth();
            $code = strtoupper(trim(query('code') ?: ''));
            $info = LocationManager::getLocationInfo($code);
            json_out(['available' => LocationManager::isAvailable($code), 'info' => $info]);
            break;

        case 'available':
            api_require_auth();
            $count = (int)query('count', 20);
            $zone = query('zone') ?: null;
            json_out(['rows' => LocationManager::getAvailableLocations($count, $zone)]);
            break;

        case 'zone_summary':
            api_require_auth();
            json_out(['rows' => LocationManager::getZoneSummary()]);
            break;

        case 'suggest':
            api_require_auth();
            $qty = (float)query('quantity', 0);
            $uom = query('uom', 'Drum');
            $upp = (int)query('uom_per_pallet', 4);
            $zone = query('zone') ?: null;
            json_out(['rows' => LocationManager::suggestLocationsForInbound($qty, $uom, $upp, $zone)]);
            break;

        case 'create':
            api_require_write();
            $data = body();
            $code = strtoupper(trim($data['location_code'] ?? ''));
            if (!$code) json_err('location_code wajib diisi.');
            $exists = db()->prepare("SELECT id FROM location_master WHERE location_code=?");
            $exists->execute([$code]);
            if ($exists->fetch()) json_err("Lokasi '$code' sudah ada.", 409);
            $stmt = db()->prepare("INSERT INTO location_master (location_code, aisle, rack, row_name, position, zone, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $code,
                $data['aisle'] ?? null,
                $data['rack'] ?? null,
                $data['row_name'] ?? null,
                $data['position'] ?? null,
                $data['zone'] ?? null,
                isset($data['is_active']) ? (int)$data['is_active'] : 1,
            ]);
            ActivityLogger::log('ADD_LOCATION', 'location', 'Location', null, $code, 'Tambah lokasi ' . $code);
            json_out(['id' => (int)db()->lastInsertId()]);
            break;

        case 'update':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            $stmt = db()->prepare("UPDATE location_master SET location_code=?, aisle=?, rack=?, row_name=?, position=?, zone=?, is_active=? WHERE id=?");
            $stmt->execute([
                strtoupper(trim($data['location_code'] ?? '')),
                $data['aisle'] ?? null,
                $data['rack'] ?? null,
                $data['row_name'] ?? null,
                $data['position'] ?? null,
                $data['zone'] ?? null,
                isset($data['is_active']) ? (int)$data['is_active'] : 1,
                $id,
            ]);
            ActivityLogger::log('EDIT_LOCATION', 'location', 'Location', $id, null, 'Edit lokasi ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'delete':
            api_require_admin();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            $row = db()->prepare("SELECT location_code FROM location_master WHERE id=?");
            $row->execute([$id]);
            $code = $row->fetchColumn();
            $inUse = db()->prepare("SELECT COUNT(*) FROM stock WHERE location=? AND quantity>0");
            $inUse->execute([$code]);
            if ((int)$inUse->fetchColumn() > 0) {
                json_err("Lokasi '$code' masih memiliki stok dan tidak dapat dihapus.", 409);
            }
            db()->prepare("DELETE FROM location_master WHERE id=?")->execute([$id]);
            ActivityLogger::log('DELETE_LOCATION', 'location', 'Location', $id, $code, 'Hapus lokasi ' . $code);
            json_out(['id' => $id]);
            break;

        case 'parse_codes':
            api_require_auth();
            try {
                $db = db();
                $stmt = $db->prepare("UPDATE location_master
                    SET aisle = SUBSTRING(location_code, 1, 2),
                        rack = SUBSTRING(location_code, 1, 4),
                        row_name = SUBSTRING(location_code, 5, 1),
                        position = SUBSTRING(location_code, 6, 2)
                    WHERE location_code REGEXP '^[A-Z]{2}[0-9]{2}[A-E][0-9]{2}$'
                      AND (aisle IS NULL OR rack IS NULL OR row_name IS NULL OR position IS NULL)");
                $stmt->execute();
                $updated = $stmt->rowCount();
                $sample = $db->query("SELECT location_code, aisle, rack, row_name, position,
                        CASE row_name
                          WHEN 'A' THEN 'Bottom'
                          WHEN 'B' THEN 'Lower'
                          WHEN 'C' THEN 'Middle'
                          WHEN 'D' THEN 'Upper'
                          WHEN 'E' THEN 'Top'
                          ELSE row_name
                        END as level_name
                    FROM location_master
                    WHERE location_code REGEXP '^[A-Z]{2}[0-9]{2}[A-E][0-9]{2}$'
                    ORDER BY aisle, rack, row_name, position
                    LIMIT 10")->fetchAll();
                json_out([
                    'success' => true,
                    'updated' => $updated,
                    'message' => "Successfully parsed and updated {$updated} location(s)",
                    'sample' => $sample,
                ]);
            } catch (Throwable $e) {
                json_out([
                    'success' => false,
                    'updated' => 0,
                    'message' => 'Error parsing locations: ' . $e->getMessage(),
                ]);
            }
            break;

        case 'print_labels':
            api_require_auth();
            $zone = query('zone') ?: null;
            $sql = "SELECT lm.location_code, lm.aisle, lm.rack, lm.row_name, lm.position, lm.zone
                    FROM location_master lm
                    WHERE lm.is_active = 1";
            $params = [];
            if ($zone) {
                $sql .= " AND lm.zone = ?";
                $params[] = $zone;
            }
$sql .= " ORDER BY lm.aisle IS NULL, lm.aisle, lm.rack IS NULL, lm.rack,
                              lm.row_name IS NULL, lm.row_name, lm.position IS NULL, lm.position, lm.location_code";
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            json_out(['rows' => $stmt->fetchAll()]);
            break;

        case 'uom_limits_list':
            api_require_auth();
            $db = db();
            $rows = $db->query("SELECT ul.*, 
                (SELECT COUNT(*) FROM products p WHERE p.uom_type = ul.uom_type AND p.is_active = 1) AS product_count
                FROM uom_physical_limits ul ORDER BY ul.uom_type")->fetchAll();
            json_out(['rows' => $rows]);
            break;

        case 'uom_limits_update':
            api_require_write();
            $data = body();
            $uomType = trim($data['uom_type'] ?? '');
            if (!$uomType) json_err('uom_type wajib diisi.');
            $db = db();
            $validLevels = ['A','B','C','D','E'];
            $minLvl = strtoupper(trim($data['min_level'] ?? 'A'));
            $maxLvl = strtoupper(trim($data['max_level'] ?? 'E'));
            if (!in_array($minLvl, $validLevels)) json_err('min_level tidak valid.');
            if (!in_array($maxLvl, $validLevels)) json_err('max_level tidak valid.');
            $stmt = $db->prepare("UPDATE uom_physical_limits SET 
                min_level=?, max_level=?, allow_pick_face=?, max_weight_kg=?, max_height_cm=?, requires_equipment=?
                WHERE uom_type=?");
            $stmt->execute([
                $minLvl, $maxLvl,
                (int)($data['allow_pick_face'] ?? 1),
                $data['max_weight_kg'] ?? null,
                $data['max_height_cm'] ?? null,
                (int)($data['requires_equipment'] ?? 0),
                $uomType,
            ]);
            if ($stmt->rowCount() === 0) {
                $ins = $db->prepare("INSERT INTO uom_physical_limits (uom_type, min_level, max_level, allow_pick_face, max_weight_kg, max_height_cm, requires_equipment)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$uomType, $minLvl, $maxLvl, (int)($data['allow_pick_face'] ?? 1), $data['max_weight_kg'] ?? null, $data['max_height_cm'] ?? null, (int)($data['requires_equipment'] ?? 0)]);
            }
            ActivityLogger::log('UPDATE_UOM_LIMITS', 'location', 'UOM Limits', null, $uomType, 'Update UOM limits: ' . $uomType);
            json_out(['ok' => true]);
            break;

        case 'product_rules_list':
            api_require_auth();
            $db = db();
            $uomFilter = query('uom_type') ?: null;
            $search = trim(query('search') ?: '');
            $perPage = max(1, (int)query('per_page', 50));
            $page = max(1, (int)query('page', 1));
            $offset = ($page - 1) * $perPage;

            $where = "WHERE p.is_active = 1";
            $params = [];
            if ($uomFilter) { $where .= " AND p.uom_type = ?"; $params[] = $uomFilter; }
            if ($search) { $where .= " AND (p.product_code LIKE ? OR p.product_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM products p $where");
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $sql = "SELECT p.id, p.product_code, p.product_name, p.uom_type, p.drums_per_pallet, p.uom_per_pallet,
                    r.preferred_zone_code, r.max_level AS rule_max_level, r.allow_pick_face, r.full_pallet_to_pick,
                    r.consolidate, ul.max_level AS uom_max_level, ul.min_level AS uom_min_level
                FROM products p
                LEFT JOIN product_putaway_rules r ON r.product_id = p.id
                LEFT JOIN uom_physical_limits ul ON ul.uom_type = p.uom_type
                $where
                ORDER BY p.product_code
                LIMIT $perPage OFFSET $offset";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            json_out(['rows' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
            break;

        case 'product_rules_update':
            api_require_write();
            $data = body();
            $productId = (int)($data['product_id'] ?? 0);
            if (!$productId) json_err('product_id wajib diisi.');
            $db = db();
            $check = $db->prepare("SELECT id FROM products WHERE id = ?");
            $check->execute([$productId]);
            if (!$check->fetch()) json_err('Produk tidak ditemukan.', 404);

            $validLevels = ['A','B','C','D','E'];
            $maxLvl = strtoupper(trim($data['max_level'] ?? 'E'));
            if (!in_array($maxLvl, $validLevels)) json_err('max_level tidak valid.');

            $stmt = $db->prepare("INSERT INTO product_putaway_rules (product_id, preferred_zone_code, max_level, allow_pick_face, full_pallet_to_pick, consolidate)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    preferred_zone_code = VALUES(preferred_zone_code),
                    max_level = VALUES(max_level),
                    allow_pick_face = VALUES(allow_pick_face),
                    full_pallet_to_pick = VALUES(full_pallet_to_pick),
                    consolidate = VALUES(consolidate)");
            $stmt->execute([
                $productId,
                strtoupper(trim($data['preferred_zone_code'] ?? 'RESERVE')),
                $maxLvl,
                (int)($data['allow_pick_face'] ?? 1),
                (int)($data['full_pallet_to_pick'] ?? 0),
                (int)($data['consolidate'] ?? 1),
            ]);
            json_out(['ok' => true]);
            break;

        case 'product_rules_delete':
            api_require_write();
            $data = body();
            $productId = (int)($data['product_id'] ?? 0);
            if (!$productId) json_err('product_id wajib diisi.');
            $db = db();
            $db->prepare("DELETE FROM product_putaway_rules WHERE product_id = ?")->execute([$productId]);
            json_out(['ok' => true]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

function location_zone_summary(): array {
    $db = db();
    $rows = $db->query("SELECT zone, COUNT(*) as total, SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) as active
        FROM location_master GROUP BY zone ORDER BY zone")->fetchAll();
    return $rows;
}

function handle_users($action) {
    switch ($action) {
        case 'list':
            api_require_admin();
            $db = db();
            $rows = $db->query("SELECT id, username, full_name, email, role, department, is_active, created_at, updated_at FROM users ORDER BY full_name")->fetchAll();
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            json_out(['rows' => $rows, 'roles' => [
                ['key' => 'admin', 'label' => 'Admin'],
                ['key' => 'operator', 'label' => 'Operator'],
                ['key' => 'viewer', 'label' => 'Viewer'],
            ], 'departments' => [
                ['key' => 'inbound', 'label' => 'Inbound'],
                ['key' => 'outbound', 'label' => 'Outbound'],
                ['key' => 'inventory', 'label' => 'Inventory'],
                ['key' => 'ops', 'label' => 'Operations'],
                ['key' => 'all', 'label' => 'Semua Departemen (Supervisor)'],
            ]]);
            break;

        case 'create':
            api_require_admin();
            $data = body();
            if (empty(trim($data['password'] ?? ''))) json_err('Password is required.');
            if (empty(trim($data['username'] ?? '')) || empty(trim($data['full_name'] ?? ''))) json_err('Username dan full name wajib diisi.');
            $department = $data['department'] ?? 'all';
            if (!is_department($department)) json_err('Department tidak valid. Pilih inbound, outbound, inventory, atau all.');
            $db = db();
            $stmt = $db->prepare("INSERT INTO users (username, password, full_name, email, role, department, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($data['username']),
                password_hash($data['password'], PASSWORD_DEFAULT),
                trim($data['full_name']),
                trim($data['email'] ?? ''),
                $data['role'] ?? 'viewer',
                $department,
                isset($data['is_active']) ? (int)$data['is_active'] : 1,
            ]);
            $newId = (int)$db->lastInsertId();
            ActivityLogger::log('CREATE_USER', 'user', 'User', $newId, trim($data['username']),
                'Buat user baru: ' . trim($data['full_name']) . ' (' . ($data['role'] ?? 'viewer') . ' / ' . $department . ')');
            json_out(['id' => $newId]);
            break;

        case 'update':
            api_require_admin();
            $data = body();
            $id = (int)($data['id'] ?? 0);
            $department = $data['department'] ?? 'all';
            if (!is_department($department)) json_err('Department tidak valid. Pilih inbound, outbound, inventory, atau all.');
            $db = db();
            $sql = "UPDATE users SET username = ?, full_name = ?, email = ?, role = ?, department = ?, is_active = ?";
            $params = [trim($data['username'] ?? ''), trim($data['full_name'] ?? ''), trim($data['email'] ?? ''), $data['role'] ?? 'viewer', $department, isset($data['is_active']) ? (int)$data['is_active'] : 1];
            if (!empty($data['password'])) {
                $sql .= ", password = ?";
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            $sql .= " WHERE id = ?";
            $params[] = $id;
            $db->prepare($sql)->execute($params);
            ActivityLogger::log('UPDATE_USER', 'user', 'User', $id, trim($data['username'] ?? ''),
                'Edit user: ' . trim($data['full_name'] ?? '') . ' → role ' . ($data['role'] ?? 'viewer') . ' / ' . $department . (!empty($data['password']) ? ', password diubah' : ''));
            json_out(['id' => $id]);
            break;

        case 'delete':
            api_require_admin();
            $data = body();
            $userId = (int)($data['id'] ?? query('id'));
            $me = api_require_admin();
            if ($userId === (int)$me['id']) json_err('Anda tidak dapat menghapus akun sendiri.', 403);
            $db = db();
            foreach (['inbound_orders' => ['created_by', 'received_by'], 'outbound_orders' => ['created_by', 'shipped_by'], 'picklists' => ['created_by']] as $tbl => $cols) {
                foreach ($cols as $col) {
                    try {
                        $db->prepare("UPDATE `$tbl` SET `$col` = NULL WHERE `$col` = ?")->execute([$userId]);
                    } catch (PDOException $e) {}
                }
            }
            $db->prepare("DELETE FROM auth_tokens WHERE user_id=?")->execute([$userId]);
            $db->prepare("DELETE FROM users WHERE id=?")->execute([$userId]);
            ActivityLogger::log('DELETE_USER', 'user', 'User', $userId, null, 'Hapus user ID ' . $userId);
            json_out(['id' => $userId]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
