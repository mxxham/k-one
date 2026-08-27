<?php
require_once __DIR__ . '/config/database.php';

echo "=== K-one Feature Smoke Test ===\n\n";
$db = db();

// Helper: API call
function api($module, $action, $data = [], $token = null, $method = 'POST') {
    $url = 'http://localhost/k-one/api/index.php?module=' . $module . '&action=' . $action;
    $ch = curl_init($url);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
    ];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_POSTFIELDS => $method === 'POST' ? json_encode($data) : null,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($res, true);
    return ['code' => $code, 'body' => $body];
}

// 1. AUTH TEST
echo "1. AUTH TEST\n";
$login = api('auth', 'login', ['username' => 'admin', 'password' => 'admin123']);
echo "  Login: " . ($login['code'] === 200 && isset($login['body']['token']) ? "PASS" : "FAIL") . "\n";
if ($login['code'] !== 200 || !isset($login['body']['token'])) { echo "  Login failed: " . json_encode($login) . "\n"; exit; }
$token = $login['body']['token'];

$me = api('auth', 'me', [], $token, 'GET');
echo "  /me: " . ($me['code'] === 200 ? "PASS" : "FAIL") . "\n";

// 2. DASHBOARD STATS
echo "\n2. DASHBOARD STATS\n";
$stats = api('dashboard', 'stats', [], $token, 'GET');
echo "  Stats: " . ($stats['code'] === 200 ? "PASS" : "FAIL") . "\n";

// 3. INBOUND TESTS
echo "\n3. INBOUND TESTS\n";
$inboundList = api('inbound', 'list', [], $token, 'GET');
echo "  List: " . ($inboundList['code'] === 200 ? "PASS" : "FAIL") . "\n";

$createInbound = api('inbound', 'create', [
    'supplier_id' => 1,
    'so_number' => 'SO-TEST-' . time(),
    'items' => [
        ['product_id' => 13338, 'quantity' => 100, 'batch_no' => 'TEST-BATCH-' . time(), 'exp_date' => '2027-12-31'],
    ]
], $token, 'POST');
echo "  Create: " . ($createInbound['code'] === 200 && isset($createInbound['body']['id']) ? "PASS" : "FAIL") . "\n";
$inboundId = $createInbound['body']['id'] ?? 0;

if ($inboundId) {
    $receive = api('inbound', 'receive', ['id' => $inboundId], $token, 'POST');
    echo "  Receive: " . ($receive['code'] === 200 ? "PASS" : "FAIL") . "\n";
    
    $putaway = api('inbound', 'putaway', ['id' => $inboundId, 'items' => [['product_id' => 13338, 'quantity' => 100, 'location_code' => 'CA01A01']]], $token, 'POST');
    echo "  Putaway: " . ($putaway['code'] === 200 ? "PASS" : "FAIL") . "\n";
    
    $complete = api('inbound', 'complete', ['id' => $inboundId], $token, 'POST');
    echo "  Complete: " . ($complete['code'] === 200 ? "PASS" : "FAIL") . "\n";
}

// 4. OUTBOUND TESTS
echo "\n4. OUTBOUND TESTS\n";
$outboundList = api('outbound', 'list', [], $token, 'GET');
echo "  List: " . ($outboundList['code'] === 200 ? "PASS" : "FAIL") . "\n";

$createOutbound = api('outbound', 'create', [
    'customer_id' => 1,
    'so_number' => 'SO-TEST-' . time(),
    'items' => [
        ['product_id' => 13338, 'quantity' => 50, 'batch_no' => 'TEST-BATCH-' . time()],
    ]
], $token, 'POST');
echo "  Create: " . ($createOutbound['code'] === 200 && isset($createOutbound['body']['id']) ? "PASS" : "FAIL") . "\n";
$outboundId = $createOutbound['body']['id'] ?? 0;

if ($outboundId) {
    $confirm = api('outbound', 'confirm', ['id' => $outboundId], $token, 'POST');
    echo "  Confirm: " . ($confirm['code'] === 200 ? "PASS" : "FAIL") . "\n";
    
    $pick = api('outbound', 'pick', ['id' => $outboundId], $token, 'POST');
    echo "  Pick: " . ($pick['code'] === 200 ? "PASS" : "FAIL") . "\n";
    
    $ship = api('outbound', 'ship', ['id' => $outboundId], $token, 'POST');
    echo "  Ship: " . ($ship['code'] === 200 ? "PASS" : "FAIL") . "\n";
}

// 5. PICKLIST TESTS
echo "\n5. PICKLIST TESTS\n";
$plList = api('picklist', 'list', [], $token, 'GET');
echo "  List: " . ($plList['code'] === 200 ? "PASS" : "FAIL") . "\n";

if ($outboundId) {
    $createPl = api('picklist', 'create_from_outbound', ['outbound_id' => $outboundId], $token, 'POST');
    echo "  Create from outbound: " . ($createPl['code'] === 200 && isset($createPl['body']['id']) ? "PASS" : "FAIL") . "\n";
    $plId = $createPl['body']['id'] ?? 0;
    
    if ($plId) {
        $plDetail = api('picklist', 'detail', ['id' => $plId], $token, 'GET');
        echo "  Detail: " . ($plDetail['code'] === 200 ? "PASS" : "FAIL") . "\n";
        
        $plExport = api('picklist', 'export_data', ['id' => $plId], $token, 'GET');
        echo "  Export for print: " . ($plExport['code'] === 200 ? "PASS" : "FAIL") . "\n";
        
        $plConfirm = api('picklist', 'confirm', ['id' => $plId], $token, 'POST');
        echo "  Confirm: " . ($plConfirm['code'] === 200 ? "PASS" : "FAIL") . "\n";
        
        $plComplete = api('picklist', 'complete', ['id' => $plId], $token, 'POST');
        echo "  Complete: " . ($plComplete['code'] === 200 ? "PASS" : "FAIL") . "\n";
    }
}

// 6. STOCK TESTS
echo "\n6. STOCK TESTS\n";
$stockList = api('stock', 'list', [], $token, 'GET');
echo "  List: " . ($stockList['code'] === 200 ? "PASS" : "FAIL") . "\n";

$stockSummary = api('stock', 'summary', [], $token, 'GET');
echo "  Summary: " . ($stockSummary['code'] === 200 ? "PASS" : "FAIL") . "\n";

$stockExpiry = api('stock', 'expiry', [], $token, 'GET');
echo "  Expiry: " . ($stockExpiry['code'] === 200 ? "PASS" : "FAIL") . "\n";

// 7. BIN TRANSFER TESTS
echo "\n7. BIN TRANSFER TESTS\n";
$btList = api('bin_transfer', 'list', [], $token, 'GET');
echo "  List: " . ($btList['code'] === 200 ? "PASS" : "FAIL") . "\n";

$btCreate = api('bin_transfer', 'create', [
    'from_location' => 'CA01A01',
    'to_location' => 'CA01A02',
    'items' => [['product_id' => 13338, 'quantity' => 10, 'batch_no' => 'TEST-BATCH-' . time()]],
], $token, 'POST');
echo "  Create: " . ($btCreate['code'] === 200 && isset($btCreate['body']['id']) ? "PASS" : "FAIL") . "\n";
$btId = $btCreate['body']['id'] ?? 0;

if ($btId) {
    $btConfirm = api('bin_transfer', 'confirm', ['id' => $btId], $token, 'POST');
    echo "  Confirm: " . ($btConfirm['code'] === 200 ? "PASS" : "FAIL") . "\n";
    
    $btComplete = api('bin_transfer', 'complete', ['id' => $btId], $token, 'POST');
    echo "  Complete: " . ($btComplete['code'] === 200 ? "PASS" : "FAIL") . "\n";
}

// 8. STOCK TAKE TESTS
echo "\n8. STOCK TAKE TESTS\n";
$stList = api('stocktake', 'list', [], $token, 'GET');
echo "  List: " . ($stList['code'] === 200 ? "PASS" : "FAIL") . "\n";

$stCreate = api('stocktake', 'create', ['name' => 'Test Stocktake ' . time()], $token, 'POST');
echo "  Create: " . ($stCreate['code'] === 200 && isset($stCreate['body']['id']) ? "PASS" : "FAIL") . "\n";
$stId = $stCreate['body']['id'] ?? 0;

if ($stId) {
    $stStart = api('stocktake', 'start', ['id' => $stId], $token, 'POST');
    echo "  Start: " . ($stStart['code'] === 200 ? "PASS" : "FAIL") . "\n";
    
    $stCount = api('stocktake', 'count', ['id' => $stId, 'product_id' => 13338, 'counted_qty' => 95], $token, 'POST');
    echo "  Count: " . ($stCount['code'] === 200 ? "PASS" : "FAIL") . "\n";
    
    $stComplete = api('stocktake', 'complete', ['id' => $stId], $token, 'POST');
    echo "  Complete: " . ($stComplete['code'] === 200 ? "PASS" : "FAIL") . "\n";
}

// 9. LOCATION MASTER TESTS
echo "\n9. LOCATION MASTER TESTS\n";
$locList = api('location_master', 'list', [], $token, 'GET');
echo "  List: " . ($locList['code'] === 200 ? "PASS" : "FAIL") . "\n";

// 10. REPORTS TESTS
echo "\n10. REPORTS TESTS\n";
$rptInbound = api('reports', 'inbound', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $token, 'GET');
echo "  Inbound report: " . ($rptInbound['code'] === 200 ? "PASS" : "FAIL") . "\n";

$rptOutbound = api('reports', 'outbound', ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'], $token, 'GET');
echo "  Outbound report: " . ($rptOutbound['code'] === 200 ? "PASS" : "FAIL") . "\n";

$rptStock = api('reports', 'stock', [], $token, 'GET');
echo "  Stock report: " . ($rptStock['code'] === 200 ? "PASS" : "FAIL") . "\n";

// 11. MASTER DATA TESTS
echo "\n11. MASTER DATA TESTS\n";
$prodList = api('products', 'list', [], $token, 'GET');
echo "  Products list: " . ($prodList['code'] === 200 ? "PASS" : "FAIL") . "\n";

$suppList = api('suppliers', 'list', [], $token, 'GET');
echo "  Suppliers list: " . ($suppList['code'] === 200 ? "PASS" : "FAIL") . "\n";

$custList = api('customers', 'list', [], $token, 'GET');
echo "  Customers list: " . ($custList['code'] === 200 ? "PASS" : "FAIL") . "\n";

// 12. PRINT ENDPOINTS TEST
echo "\n12. PRINT ENDPOINTS TEST\n";
if ($inboundId) {
    $printInbound = api('print', 'inbound', ['id' => $inboundId], $token, 'GET');
    echo "  Print inbound: " . ($printInbound['code'] === 200 && isset($printInbound['body']['html']) ? "PASS" : "FAIL") . "\n";
}

if ($outboundId) {
    $printOutbound = api('print', 'outbound', ['id' => $outboundId], $token, 'GET');
    echo "  Print outbound: " . ($printOutbound['code'] === 200 && isset($printOutbound['body']['html']) ? "PASS" : "FAIL") . "\n";
}

if (isset($plId) && $plId) {
    $printPicklist = api('print', 'picklist', ['id' => $plId], $token, 'GET');
    echo "  Print picklist: " . ($printPicklist['code'] === 200 && isset($printPicklist['body']['html']) ? "PASS" : "FAIL") . "\n";
}

if ($inboundId) {
    $printPutaway = api('print', 'putaway_sheet', ['id' => $inboundId], $token, 'GET');
    echo "  Print putaway: " . ($printPutaway['code'] === 200 && isset($printPutaway['body']['html']) ? "PASS" : "FAIL") . "\n";
}

echo "\n=== Smoke Test Complete ===\n";