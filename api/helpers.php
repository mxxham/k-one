<?php

/** Pagination params from request (positional: [$page, $perPage, $offset]). */
function page_params(int $defaultPerPage = 50): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, (int)($_GET['per_page'] ?? $defaultPerPage));
    if ($perPage > 500) $perPage = 500;
    return [$page, $perPage, ($page - 1) * $perPage];
}

/**
 * Extract page/perPage from the GET request as an associative array.
 * Drop-in replacement for page_params() with named keys.
 *
 * @return array{page: int, per_page: int, offset: int}
 */
function getRequestPagination(int $defaultPerPage = 50): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, (int)($_GET['per_page'] ?? $defaultPerPage));
    if ($perPage > 500) $perPage = 500;
    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
}

/**
 * Append LIMIT / OFFSET to a SQL query string using prepared-statement params.
 *
 * @param  string $query   SQL with any WHERE / ORDER BY already applied (no trailing LIMIT).
 * @param  array  $params  Bound parameters for $query.
 * @param  int    $page    1-based page number.
 * @param  int    $perPage Rows per page (capped at 500).
 * @return array{0: string, 1: array}  The amended query and merged params.
 */
function paginate(string $query, array $params, int $page, int $perPage): array {
    $perPage = max(1, min($perPage, 500));
    $offset  = (max(1, $page) - 1) * $perPage;
    $query  .= " LIMIT " . $perPage . " OFFSET " . $offset;
    return [$query, $params];
}

/**
 * Build the standard pagination metadata object included in every paginated response.
 *
 * @return array{page: int, per_page: int, total: int, total_pages: int, has_next: bool, has_prev: bool}
 */
function paginationMeta(int $total, int $page, int $perPage): array {
    $perPage    = max(1, $perPage);
    $totalPages = (int)ceil($total / $perPage);
    return [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'has_next'    => $page < $totalPages,
        'has_prev'    => $page > 1,
    ];
}

/** Product search used by inbound/outbound forms */
function search_products_json($q, $skuOnly = false) {
    $q = trim($q ?? '');
    $db = db();
    $like = '%' . $q . '%';
    if ($skuOnly) {
        $sql = "SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                       p.max_sku_qty, p.max_trans_qty, p.liters_per_unit,
                       COALESCE(SUM(s.quantity),0) as stock_qty
                FROM products p
                LEFT JOIN stock s ON s.product_id = p.id AND s.stock_status = 'Available'
                WHERE p.is_active = 1
                AND p.product_code LIKE ?
                AND p.product_code REGEXP '^[0-9]+$'
                GROUP BY p.id
                ORDER BY p.product_name
                LIMIT 30";
        $stmt = $db->prepare($sql);
        $stmt->execute([$like]);
    } else {
        $sql = "SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
                       p.max_sku_qty, p.max_trans_qty, p.liters_per_unit,
                       COALESCE(SUM(s.quantity),0) as stock_qty
                FROM products p
                LEFT JOIN stock s ON s.product_id = p.id AND s.stock_status = 'Available'
                WHERE p.is_active = 1
                AND (p.product_code LIKE ? OR p.product_name LIKE ?)
                GROUP BY p.id
                ORDER BY p.product_name
                LIMIT 30";
        $stmt = $db->prepare($sql);
        $stmt->execute([$like, $like]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return array_map(function ($r) {
        return [
            'id' => (int)$r['id'],
            'text' => $r['product_code'] . ' — ' . $r['product_name'],
            'product_code' => $r['product_code'],
            'product_name' => $r['product_name'],
            'uom' => $r['uom_type'],
            'uom_per_pallet' => $r['uom_per_pallet'],
            'liters_per_unit' => $r['liters_per_unit'],
            'stock_qty' => $r['stock_qty'],
            'max_sku_qty' => $r['max_sku_qty'],
            'max_trans_qty' => $r['max_trans_qty'],
        ];
    }, $rows);
}

/** Active users list for received_by / picker selectors */
function active_users_list() {
    $rows = db()->query("SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name")->fetchAll();
    return array_map(fn($u) => [
        'id' => (int)$u['id'],
        'username' => $u['username'],
        'full_name' => $u['full_name'],
        'role' => $u['role'],
    ], $rows);
}

/** All products (light) for selects */
function products_options() {
    $rows = Product::getAll(2000);
    return array_map(fn($p) => [
        'id' => (int)$p['id'],
        'product_code' => $p['product_code'],
        'product_name' => $p['product_name'],
        'uom' => $p['uom_type'],
        'uom_per_pallet' => $p['uom_per_pallet'],
    ], $rows);
}

/** Customers light list for selects */
function customers_options() {
    $rows = Customer::getAll();
    return array_map(fn($c) => [
        'id' => (int)$c['id'],
        'customer_code' => $c['customer_code'],
        'customer_name' => $c['customer_name'],
    ], $rows);
}

function location_options() {
    $rows = LocationManager::getAll();
    return array_map(fn($l) => [
        'id' => (int)$l['id'],
        'location_code' => $l['location_code'],
        'aisle' => $l['aisle'],
        'zone' => $l['zone'],
        'is_active' => (int)$l['is_active'],
    ], $rows);
}

function statuses_for(string $module): array {
    $map = [
        'inbound'  => ['Draft','Dues In','Receiving','Good Received','Goods Received','Unserviceable','Picked','ATP','Completed','Cancelled'],
        'outbound' => ['Open','Picking','Picked','Shipped','Delivered','Completed','Cancelled'],
        'picklist' => ['Draft','Confirmed','Picking','Picked','Completed','Cancelled'],
        'stocktake'=> ['Draft','In Progress','Completed','Cancelled'],
        'bintransfer'=> ['Pending','Completed','Cancelled'],
        'rma'        => ['Pending','Approved','Processed','Completed','Cancelled'],
    ];
    return $map[$module] ?? [];
}

/** Mirrors v2 isDepartment(): validates against the master department list. */
function is_department($value): bool {
    return is_string($value) && in_array($value, ['inbound', 'outbound', 'inventory', 'ops', 'all'], true);
}

/* ------------------------------------------------------------------ */
/* Date utilities — mirrors v2 date-util.ts (Asia/Jakarta, UTC+7)      */
/* ------------------------------------------------------------------ */

function jakarta_now(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
}

/** PHP date('Y-m-d') in Asia/Jakarta. */
function today_str(): string {
    return jakarta_now()->format('Y-m-d');
}

/** PHP date('Ymd') e.g. '20260819'. */
function today_compact(): string {
    return jakarta_now()->format('Ymd');
}

/** PHP date('His') e.g. '143025'. */
function now_compact_time(): string {
    return jakarta_now()->format('His');
}

/** PHP date('Y-m-d H:i:s') in Jakarta. */
function now_datetime(): string {
    return jakarta_now()->format('Y-m-d H:i:s');
}

/** PHP date('Ym') e.g. '202608'. */
function month_compact(): string {
    return jakarta_now()->format('Ym');
}

/** +{years} years on a Y-m-d date (pure calendar arithmetic). Returns null if unparseable. */
function add_years(string $date, int $years = 4): ?string {
    if (!$date) return null;
    $m = [];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($date), $m)) return null;
    $y = (int)$m[1] + $years;
    $month = (int)$m[2];
    $maxDay = (int)date('t', mktime(0, 0, 0, $month, 1, $y));
    $day = min((int)$m[3], $maxDay);
    return sprintf('%d-%02d-%02d', $y, $month, $day);
}

/** Parse a date string that may be empty/null/'0'. Returns null for empty inputs. */
function parse_date_literal($v): ?string {
    if ($v === null) return null;
    $s = trim((string)$v);
    if ($s === '' || $s === '0') return null;
    if (preg_match('/^(\d{4})[-\/](\d{2})[-\/](\d{2})/', $s, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return null;
}

/** Days until an expiry date. Negative = already expired. null = unparseable. */
function days_until(?string $dateStr): ?int {
    if (!$dateStr) return null;
    $m = [];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $dateStr, $m)) return null;
    $expiry = new DateTimeImmutable($dateStr);
    $today = jakarta_now()->setTime(0, 0, 0);
    return (int)$today->diff($expiry)->days * ($expiry < $today ? -1 : 1);
}

/* ------------------------------------------------------------------ */
/* Number generator — mirrors v2 number-gen.ts (20-try race-safe)      */
/* ------------------------------------------------------------------ */

/**
 * Race-safe sequence number generator.
 * Finds latest LIKE prefix, seq = suffix+1, up to 20 tries with
 * existence check, then fallback prefix+His+rand(10,99).
 *
 * @param string $table       Table name (e.g. 'inbound_orders')
 * @param string $column      Number column (e.g. 'order_no')
 * @param string $prefix      Static prefix (e.g. 'IN-')
 * @param string $searchPrefix Date prefix for LIKE search (e.g. 'IN-202608-')
 * @param int    $pad         Zero-padded width for counter (default 4)
 */
function generate_number(string $table, string $column, string $prefix, string $searchPrefix, int $pad = 4): string {
    $db = db();
    $like = $searchPrefix . '%';
    $stmt = $db->prepare("SELECT `$column` AS v FROM `$table` WHERE `$column` LIKE ? ORDER BY `$column` DESC LIMIT 1");
    $stmt->execute([$like]);
    $seq = 1;
    if ($row = $stmt->fetch()) {
        $last = $row['v'];
        $idx = strrpos($last, '-');
        $suffix = $idx !== false ? substr($last, $idx + 1) : $last;
        $n = (int)$suffix;
        if ($n > 0 || ltrim($suffix, '0') !== '') {
            $seq = $n + 1;
        }
    }
    for ($i = 0; $i < 20; $i++) {
        $candidate = $prefix . str_pad((string)$seq, $pad, '0', STR_PAD_LEFT);
        $chk = $db->prepare("SELECT 1 FROM `$table` WHERE `$column` = ? LIMIT 1");
        $chk->execute([$candidate]);
        if (!$chk->fetch()) return $candidate;
        $seq++;
    }
    // Fallback: prefix + His + rand(10,99)
    $rand = random_int(10, 99);
    return $prefix . now_compact_time() . $rand;
}

/** ST-YYYYMMDD-NNNN (random suffix, matches v2 stockTakeNumber). */
function stock_take_number(): string {
    return 'ST-' . today_compact() . '-' . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT);
}

/** ADJ-YYYYMMDDHHmiss (matches v2 adjustmentReference). */
function adjustment_reference(): string {
    return 'ADJ-' . today_compact() . now_compact_time();
}

/** IST-YYYYMMDD-NNNN (matches v2 stockImportReference). */
function stock_import_reference(int $seq): string {
    return 'IST-' . today_compact() . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
}
