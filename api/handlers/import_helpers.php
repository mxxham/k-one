<?php
/** Shared helpers for the import API handler (module = import). */

function import_parse_date($val): ?string {
    if ($val === null || $val === '' || $val === '0') return null;
    if (is_numeric($val) && (float)$val > 40000) {
        try {
            $dt = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float)$val);
            $y = (int)$dt->format('Y');
            if ($y < 1990 || $y > 2100) return null;
            return $dt->format('Y-m-d');
        } catch (\Exception $e) {}
    }
    $val = trim((string)$val);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $val, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if ($d > 12 && $mo <= 12) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        if ($mo > 12 && $d <= 12) return sprintf('%04d-%02d-%02d', $y, $d, $mo);
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $val, $m)) {
        return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    }
    $ts = strtotime($val);
    if ($ts && $ts > 0) {
        $y = (int)date('Y', $ts);
        if ($y >= 1990 && $y <= 2100) return date('Y-m-d', $ts);
    }
    return null;
}

function import_normalize_uom(string $raw, string $fallback = 'Drum'): string {
    $map = ['car' => 'Carton', 'ctn' => 'Carton', 'carton' => 'Carton',
            'drm' => 'Drum', 'drum' => 'Drum',
            'pail' => 'Pail', 'pal' => 'Pail',
            'bag' => 'Bags', 'bags' => 'Bags',
            'ea' => 'EA', 'each' => 'EA', 'pcs' => 'EA'];
    $k = strtolower(trim($raw));
    return $map[$k] ?? ($k ? ucfirst($k) : $fallback);
}

function import_uom_per_pallet(string $uom, int $productUpp = 4): int {
    switch (strtolower(trim($uom))) {
        case 'drum': case 'drm':  return 4;
        case 'carton': case 'car': return max(1, $productUpp ?: 44);
        case 'pail': case 'pal':  return 24;
        case 'bags': case 'bag':  return 1;
        case 'ea': case 'each': case 'pcs': return 4;
        default:       return max(1, $productUpp ?: 4);
    }
}

/** Read the uploaded file into a grid of rows (first worksheet). */
function import_read_sheet(string $fileKey = 'excel_file'): array {
    import_raise_memory_limit();
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    $file = $_FILES[$fileKey];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
        throw new Exception('Format file harus .xlsx, .xls, atau .csv');
    }
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) throw new Exception('Tidak bisa membaca file');
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) $rows[] = $row;
        fclose($handle);
        return $rows;
    }
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
    return $spreadsheet->getActiveSheet()->toArray(null, true, false, false);
}

/** Normalise a table header into a lowercased column-name map. */
function import_header_index(array $row): array {
    $map = [];
    foreach ($row as $i => $h) {
        $key = strtolower(preg_replace('/^\s*\*\s*/', '', trim((string)$h)));
        if ($key !== '') $map[$key] = $i;
    }
    return $map;
}

/**
 * Pick the best matching header column for a field, honouring pattern priority.
 * Patterns earlier in the list win over later ones; an exact header match beats a
 * substring match at the same priority. Returns the column index or null.
 */
function import_resolve_col(array $headers, array $patterns): ?int {
    $best = null;
    $bestPri  = PHP_INT_MAX;
    $bestExact = false;
    foreach ($patterns as $pri => $pattern) {
        $p = strtolower(trim((string)$pattern));
        if ($p === '') continue;
        foreach ($headers as $ci => $h) {
            $hl = strtolower(trim((string)$h));
            if ($hl === '') continue;
            $exact = ($hl === $p);
            if ($exact || str_contains($hl, $p)) {
                if ($pri < $bestPri || ($pri === $bestPri && $exact && !$bestExact)) {
                    $best = $ci;
                    $bestPri = $pri;
                    $bestExact = $exact;
                }
            }
        }
    }
    return $best;
}

/**
 * Find the row that most looks like a data table header by counting known
 * column-name keywords. Handles files with title/meta rows above the header.
 */
function import_detect_header(array $allRows): array {
    $keywords = ['product','qty','batch','item','lokasi','location','on hand','uom','unit','shipment','material','sku','expiry','exp date','expired date','gr date','quantity','actual qty','volume','batch no'];
    $bestIdx = 0;
    $bestScore = -1;
    foreach ($allRows as $idx => $row) {
        $rowStr = strtolower(implode(' ', array_map(fn($v) => (string)($v ?? ''), $row)));
        $score = 0;
        foreach ($keywords as $kw) if (str_contains($rowStr, $kw)) $score++;
        if ($score > $bestScore) { $bestScore = $score; $bestIdx = $idx; }
    }
    if ($bestScore < 1) $bestIdx = 0;
    return ['index' => $bestIdx, 'row' => $allRows[$bestIdx]];
}

/** Make a value-lookup closure over a normalised header map. */
function import_getter(array $colMap, array $row): callable {
    return function (...$keys) use ($colMap, $row) {
        foreach ($keys as $key) {
            $key = strtolower(trim($key));
            if (isset($colMap[$key])) {
                $v = trim((string)($row[$colMap[$key]] ?? ''));
                if ($v !== '' && $v !== '0') return $v;
            }
        }
        return '';
    };
}

/** True when a data row looks like template notes / meta rows. */
function import_is_meta_row(array $row): bool {
    $first = trim((string)($row[0] ?? ''));
    if ($first === '' || str_starts_with($first, '*')) return true;
    $lower = strtolower($first);
    foreach (['kolom', 'values', 'format', 'uraian', 'petunjuk', 'note', '* nama'] as $kw) {
        if (str_contains($lower, $kw)) return true;
    }
    return false;
}

/** Raise PHP memory limit for Excel processing (idempotent). */
function import_raise_memory_limit(): void {
    static $done = false;
    if ($done) return;
    $limit = (int)ini_get('memory_limit');
    $target = 512 * 1024 * 1024;
    if ($limit === 0) { $done = true; return; }
    if ($limit > 0 && $limit < $target) {
        @ini_set('memory_limit', (string)$target);
    }
    $done = true;
}

/** Load PhpSpreadsheet autoload if present (returns error string or null). */
function import_ensure_spreadsheet(): ?string {    static $resolved = null;
    if ($resolved !== null) return $resolved;
    try {
        require_once __DIR__ . '/../vendor/autoload.php';
        $resolved = null;
    } catch (\Throwable $e) {
        $resolved = 'PhpSpreadsheet tidak tersedia: ' . $e->getMessage();
    }
    return $resolved;
}

/* ------------------------------------------------------------------ */
/*  OPTIMIZATION HELPERS                                              */
/* ------------------------------------------------------------------ */

/** Fetch all products in a single query, keyed by product_code. */
function import_fetch_products(array $codes): array {
    if (empty($codes)) return [];
    $db = db();
    $codes = array_values(array_unique(array_filter($codes)));
    $ph = implode(',', array_fill(0, count($codes), '?'));
    $stmt = $db->prepare("SELECT id, product_code, product_name, uom_type, uom_per_pallet, max_sku_qty, max_trans_qty, liters_per_unit FROM products WHERE product_code IN ($ph) AND is_active = 1");
    $stmt->execute($codes);
    $map = [];
    foreach ($stmt->fetchAll() as $p) {
        $map[$p['product_code']] = $p;
    }
    return $map;
}

/** Chunked XLSX reader — yields rows one by one without loading entire file. */
function import_read_sheet_chunked(string $fileKey = 'excel_file', callable $callback): void {
    import_raise_memory_limit();
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No file uploaded or upload error');
    }
    $file = $_FILES[$fileKey];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls', 'csv'])) {
        throw new Exception('Format file harus .xlsx, .xls, atau .csv');
    }
    if ($ext === 'csv') {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) throw new Exception('Tidak bisa membaca file');
        $rowIdx = 0;
        while (($row = fgetcsv($handle)) !== false) {
            $callback($row, $rowIdx++);
        }
        fclose($handle);
        return;
    }
    $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xlsx' ? 'Xlsx' : 'Xls');
    $reader->setReadDataOnly(true);
    $spreadsheet = $reader->load($file['tmp_name']);
    $sheet = $spreadsheet->getActiveSheet();
    $rowIdx = 0;
    foreach ($sheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);
        $rowData = [];
        foreach ($cellIterator as $cell) {
            $rowData[] = $cell->getValue();
        }
        $callback($rowData, $rowIdx++);
    }
}

/** Cache for resolved column indices per header row. */
function import_get_cached_resolver(array $headers): callable {
    static $cache = [];
    $key = md5(implode('|', $headers));
    if (!isset($cache[$key])) {
        $cache[$key] = function (array $patterns) use ($headers) {
            return import_resolve_col($headers, $patterns);
        };
    }
    return $cache[$key];
}

/** Batch execute helper — accumulates values and flushes at batchSize. */
function import_batch_execute(PDO $db, string $sqlPrefix, array $rows, int $batchSize = 500): int {
    if (empty($rows)) return 0;
    $total = 0;
    $batch = [];
    $params = [];
    foreach ($rows as $row) {
        $batch[] = $row;
        $params = array_merge($params, array_values($row));
        if (count($batch) >= $batchSize) {
            $placeholders = implode(',', array_fill(0, count($batch), '(' . str_repeat('?,', count($batch[0]) - 1) . '?)'));
            $stmt = $db->prepare($sqlPrefix . ' ' . $placeholders);
            $stmt->execute($params);
            $total += count($batch);
            $batch = [];
            $params = [];
        }
    }
    if ($batch) {
        $placeholders = implode(',', array_fill(0, count($batch), '(' . str_repeat('?,', count($batch[0]) - 1) . '?)'));
        $stmt = $db->prepare($sqlPrefix . ' ' . $placeholders);
        $stmt->execute($params);
        $total += count($batch);
    }
    return $total;
}