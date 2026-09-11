<?php
/**
 * Download Picklist File
 */

session_start();
require_once __DIR__ . '/../config/database.php';

// Standalone — no auth required

// Get file path from request
$file = $_GET['file'] ?? '';

if (empty($file) || !file_exists($file)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

// Security: only allow temp files
$realPath = realpath($file);
$tempDir = realpath(sys_get_temp_dir());

if ($realPath === false || strpos($realPath, $tempDir) !== 0) {
    http_response_code(403);
    echo 'Access denied';
    exit;
}

// Download file
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="picklist_' . date('Y-m-d_His') . '.xlsx"');
header('Cache-Control: max-age=0');

readfile($realPath);
unlink($realPath);
exit;
