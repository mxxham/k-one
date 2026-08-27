<?php
function api($module, $action, $data = [], $token = null, $method = 'POST') {
    // For GET requests, append data as query parameters
    if ($method === 'GET' && !empty($data)) {
        $queryString = http_build_query($data);
        $url = 'http://localhost/k-one/api/index.php?module=' . $module . '&action=' . $action . '&' . $queryString;
    } else {
        $url = 'http://localhost/k-one/api/index.php?module=' . $module . '&action=' . $action;
    }
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    }
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($res, true);
    return ['code' => $code, 'body' => $body, 'raw' => $res];
}

function test($name, $module, $action, $data = [], $token = null, $method = 'POST', $expect = 200) {
    $res = api($module, $action, $data, $token, $method);
    $pass = $res['code'] === $expect;
    echo "  $name: HTTP {$res['code']} " . ($pass ? "PASS" : "FAIL") . "\n";
    if (!$pass) echo "    Response: " . $res['raw'] . "\n";
    return $pass ? $res['body'] : null;
}

$login = test("Login", 'auth', 'login', ['username' => 'admin', 'password' => 'admin123'], null, 'POST');
if (!$login) { echo "Login failed\n"; exit; }
$token = $login['token'] ?? null;

echo "\n=== AUTH ===\n";
test("/me", 'auth', 'me', [], $token, 'GET');

echo "\n=== DASHBOARD ===\n";
test("Stats", 'dashboard', 'stats', [], $token, 'GET');

echo "\n=== INBOUND ===\n";
test("List", 'inbound', 'list', [], $token, 'GET');
$inboundCreate = test("Create", 'inbound', 'create', [
    'supplier_id' => 1,
    'order_date' => date('Y-m-d'),
    'so_number' => 'SO-TEST-' . time(),
    'items' => [
        ['product_id' => 13338, 'quantity' => 100, 'batch_no' => 'TEST-BATCH-' . time(), 'exp_date' => '2027-12-31'],
    ]
], $token, 'POST');
$inboundId = $inboundCreate['id'] ?? 0;

if ($inboundId) {
    test("Detail", 'inbound', 'detail', ['id' => $inboundId], $token, 'GET');
    test("Advance to Receiving", 'inbound', 'advance_status', ['id' => $inboundId, 'status' => 'Receiving', 'received_by_id' => 1, 'received_date' => date('Y-m-d')], $token, 'POST');
    // Update item status to ATP for complete test
    test("Update item status", 'inbound', 'update_item_status', ['item_id' => 1, 'status' => 'ATP'], $token, 'POST');
    test("Complete", 'inbound', 'complete', ['id' => $inboundId], $token, 'POST');
}

echo "\n=== OUTBOUND ===\n";
test("List", 'outbound', 'list', [], $token, 'GET');

// First check available customers
$cust = test("Customers for outbound", 'customers', 'all', [], $token, 'GET');
$validCustomerId = 1;
if ($cust && isset($cust['rows'][0]['id'])) $validCustomerId = $cust['rows'][0]['id'];

$outboundCreate = test("Create", 'outbound', 'create', [
    'customer_id' => $validCustomerId,
    'order_date' => date('Y-m-d'),
    'so_number' => 'SO-TEST-' . time(),
    'items' => [
        ['product_id' => 13338, 'quantity' => 50, 'batch_no' => 'TEST-BATCH-' . time()],
    ]
], $token, 'POST');
$outboundId = $outboundCreate['id'] ?? 0;

if ($outboundId) {
    test("Detail", 'outbound', 'detail', ['id' => $outboundId], $token, 'GET');
    test("Pick Items", 'outbound', 'pick_items', ['id' => $outboundId], $token, 'POST');
    test("Ship", 'outbound', 'ship', ['id' => $outboundId], $token, 'POST');
    test("Complete", 'outbound', 'complete', ['id' => $outboundId], $token, 'POST');
}

echo "\n=== PICKLIST ===\n";
test("List", 'picklist', 'list', [], $token, 'GET');
if ($outboundId) {
    $plCreate = test("Create from outbound", 'picklist', 'create_from_outbound', ['outbound_id' => $outboundId], $token, 'POST');
    $plId = $plCreate['id'] ?? 0;
    if ($plId) {
        test("Detail", 'picklist', 'detail', ['id' => $plId], $token, 'GET');
        test("Export for print", 'picklist', 'export_data', ['id' => $plId], $token, 'GET');
        test("Confirm", 'picklist', 'confirm', ['id' => $plId], $token, 'POST');
        test("Complete", 'picklist', 'complete', ['id' => $plId], $token, 'POST');
    }
}

echo "\n=== STOCK ===\n";
test("List", 'stock', 'list', [], $token, 'GET');
test("List grouped", 'stock', 'list_grouped', [], $token, 'GET');
test("Summary", 'stock', 'summary', [], $token, 'GET');
test("Expiring", 'stock', 'expiring', ['days' => 90], $token, 'GET');
test("By location", 'stock', 'by_location', [], $token, 'GET');
test("Locations", 'stock', 'locations', [], $token, 'GET');

echo "\n=== BIN TRANSFER ===\n";
test("List", 'bintransfer', 'list', [], $token, 'GET');
test("Locations with stock", 'bintransfer', 'locations_with_stock', ['product_id' => 13338], $token, 'GET');
// Find a location with stock for this product
$locs = test("Stock at location", 'stock', 'detail', ['id' => 10321], $token, 'GET');
if ($locs && isset($locs['stock']['location'])) {
    $btCreate = test("Create", 'bintransfer', 'create', [
        'from_location' => $locs['stock']['location'],
        'to_location' => 'CA01A02',
        'product_id' => 13338,
        'quantity' => 10,
        'batch_no' => 'TEST-BATCH-' . time(),
    ], $token, 'POST');
    $btId = $btCreate['id'] ?? 0;
    if ($btId) {
        test("Execute", 'bintransfer', 'execute', ['id' => $btId], $token, 'POST');
    }
} else {
    echo "  Create: SKIPPED (no valid source location)\n";
}

echo "\n=== STOCK TAKE ===\n";
test("List", 'stocktake', 'list', [], $token, 'GET');
$stCreate = test("Create", 'stocktake', 'create', ['name' => 'Test Stocktake ' . time()], $token, 'POST');
$stId = $stCreate['id'] ?? 0;
if ($stId) {
    test("Start", 'stocktake', 'start', ['id' => $stId], $token, 'POST');
    test("Count", 'stocktake', 'count', ['id' => $stId, 'product_id' => 13338, 'counted_qty' => 95], $token, 'POST');
    test("Complete", 'stocktake', 'complete', ['id' => $stId], $token, 'POST');
}

echo "\n=== LOCATIONS ===\n";
test("List", 'locations', 'list', [], $token, 'GET');
test("All", 'locations', 'all', [], $token, 'GET');
test("Zone summary", 'locations', 'zone_summary', [], $token, 'GET');
test("Available", 'locations', 'available', ['count' => 10], $token, 'GET');
test("Suggest", 'locations', 'suggest', ['qty' => 100, 'uom' => 'Drum', 'uom_per_pallet' => 4], $token, 'GET');
test("Print labels", 'locations', 'print_labels', [], $token, 'GET');

echo "\n=== MASTER DATA ===\n";
test("Products list", 'products', 'list', [], $token, 'GET');
test("Products all", 'products', 'all', [], $token, 'GET');
test("Customers list", 'customers', 'list', [], $token, 'GET');
test("Customers all", 'customers', 'all', [], $token, 'GET');

echo "\n=== REPORTS ===\n";
test("Daily report", 'report', 'daily', [], $token, 'GET');
test("Products report", 'report', 'products', [], $token, 'GET');
test("Inbound report", 'report', 'inbound', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $token, 'GET');
test("Outbound report", 'report', 'outbound', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $token, 'GET');
test("Stock report", 'report', 'stock', [], $token, 'GET');
test("Ledger report", 'report', 'ledger', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $token, 'GET');

echo "\n=== PRINT ===\n";
if ($inboundId) test("Inbound receipt", 'print', 'inbound_receipt', ['id' => $inboundId], $token, 'GET');
if ($outboundId) test("Outbound DO", 'print', 'outbound_do', ['id' => $outboundId], $token, 'GET');
if (isset($plId) && $plId) test("Picklist", 'print', 'picklist', ['id' => $plId], $token, 'GET');
if ($inboundId) test("Putaway sheet", 'print', 'putaway', ['id' => $inboundId], $token, 'GET');
if ($outboundId) test("Surat Jalan", 'print', 'surat_jalan', ['id' => $outboundId], $token, 'GET');
test("Daily report print", 'print', 'report', ['type' => 'daily'], $token, 'GET');

echo "\n=== ACTIVITY LOG ===\n";
test("Activity list", 'activitylog', 'list', [], $token, 'GET');
test("Activity modules", 'activitylog', 'modules', [], $token, 'GET');

echo "\n=== USERS ===\n";
test("Users list", 'users', 'list', [], $token, 'GET');

echo "\n=== IMPORT/EXPORT ===\n";
test("Export inbound", 'export', 'inbound', [], $token, 'GET');
test("Export outbound", 'export', 'outbound', [], $token, 'GET');
test("Export stock", 'export', 'stock', [], $token, 'GET');

echo "\n=== PUTAWAY ===\n";
test("Recommend", 'putaway', 'recommend', ['product_id' => 13338, 'quantity' => 100, 'uom' => 'Drum', 'uom_per_pallet' => 4], $token, 'POST');
test("Validate", 'putaway', 'validate', ['product_id' => 13338, 'location' => 'CA01A01', 'quantity' => 100, 'uom' => 'Drum'], $token, 'POST');
test("List blocks", 'putaway', 'list_blocks', [], $token, 'GET');
test("Zones", 'putaway', 'zones', [], $token, 'GET');
test("Aisle map", 'putaway', 'aisle_map', [], $token, 'GET');
test("Bins", 'putaway', 'bins', [], $token, 'GET');
test("Task list", 'putaway', 'task_list', [], $token, 'GET');
test("Assignable users", 'putaway', 'assignable_users', [], $token, 'GET');
test("My tasks", 'putaway', 'my_tasks', [], $token, 'GET');

echo "\n=== WAVES ===\n";
test("Waves list", 'wave', 'list', [], $token, 'GET');

echo "\n=== CYCLE COUNT ===\n";
test("Cycle count list", 'cyclecount', 'list', [], $token, 'GET');

echo "\n=== REPLENISHMENT ===\n";
test("Replenishment list", 'replenishment', 'list', [], $token, 'GET');

echo "\n=== DISCREPANCY ===\n";
test("Discrepancy list", 'discrepancy', 'list', [], $token, 'GET');

echo "\n=== LEDGER ===\n";
test("Ledger list", 'ledger', 'list', [], $token, 'GET');

echo "\n=== CONSOLIDATION ===\n";
test("Consolidation list", 'consolidation', 'list', [], $token, 'GET');

echo "\n=== STAGING ===\n";
test("Staging list", 'staging', 'list', [], $token, 'GET');

echo "\n=== DISPATCH ===\n";
test("Dispatch list", 'dispatch', 'list', [], $token, 'GET');

echo "\n=== GI_EXPORT ===\n";
test("GI Export list", 'gi_export', 'list', [], $token, 'GET');

echo "\n=== IMPORT ===\n";
test("Import templates", 'import', 'templates', [], $token, 'GET');

echo "\n=== SMOKE TEST COMPLETE ===\n";