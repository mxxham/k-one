<?php
/**
 * Allocator API Handler
 * Processes Excel upload and generates picklist
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/classes/ExcelParser.php';
require_once __DIR__ . '/classes/Allocator.php';
require_once __DIR__ . '/classes/PicklistGenerator.php';

// Standalone — no auth required

// Set JSON response header
header('Content-Type: application/json');

try {
    // Check if file uploaded
    if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File tidak diupload atau error upload');
    }

    $file = $_FILES['excel_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['xlsx', 'xls'])) {
        throw new Exception('Format file harus .xlsx atau .xls');
    }

    // Raise memory limit for large files
    @ini_set('memory_limit', '512M');
    @set_time_limit(300);

    // Step 1: Parse Excel
    $parser = new ExcelParser();
    if (!$parser->load($file['tmp_name'])) {
        throw new Exception('Gagal membaca file: ' . implode(', ', $parser->getErrors()));
    }

    // Step 2: Extract data from sheets
    $schedule = $parser->parseSchedule();
    $putaway = $parser->parsePutaway();
    $masterSku = $parser->parseMasterSku();
    $wmsLocations = $parser->parseWmsLocations();

    // Step 3: Initialize allocator
    $allocator = new Allocator();
    $allocator->loadProducts($masterSku);
    $allocator->loadStock($putaway);
    $allocator->loadWmsLocations($wmsLocations);

    // Step 4: Run allocation
    $result = $allocator->allocate($schedule);

    // Step 5: Generate picklist Excel
    $generator = new PicklistGenerator();
    $tempFile = $generator->generate($result);

    // Step 6: Save results to JSON for print access
    $resultId = uniqid('alloc_', true);
    $resultFile = sys_get_temp_dir() . '/allocator_' . $resultId . '.json';
    file_put_contents($resultFile, json_encode([
        'picks' => $result['picks'],
        'replenishments' => $result['replenishments'],
        'errors' => $result['errors'],
        'summary' => $result['summary'],
        'created_at' => date('Y-m-d H:i:s'),
    ]));

    // Step 7: Return results
    $response = [
        'success' => true,
        'message' => 'Allocation selesai',
        'summary' => $result['summary'],
        'errors' => $result['errors'],
        'picks' => $result['picks'],
        'replenishments' => $result['replenishments'],
        'picklist_file' => $tempFile,
        'result_id' => $resultId,
        'stats' => [
            'sheets_processed' => count($parser->getSheets()),
            'schedule_lines' => count($schedule),
            'putaway_stock' => count($putaway),
            'master_sku_products' => count($masterSku),
            'wms_locations' => count($wmsLocations),
        ],
    ];

    echo json_encode($response);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
