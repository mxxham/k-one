<?php
/**
 * Import module (module=import) — dispatches the Excel import actions for the SPA.
 *
 * Actions:
 *   tpl_inbound / tpl_outbound / tpl_stock  -> download .xlsx template (binary)
 *   inbound                                  -> direct Excel import of inbound orders
 *   outbound                                 -> direct Excel import of outbound orders
 *   stock_preview                            -> parse + validate uploaded stock file
 *   stock_commit                             -> commit a validated stock import (mode: add|replace|skip)
 */

require_once __DIR__ . '/import_helpers.php';
require_once __DIR__ . '/import_inbound.php';
require_once __DIR__ . '/import_outbound.php';
require_once __DIR__ . '/import_stock.php';
require_once __DIR__ . '/import_auto.php';
require_once __DIR__ . '/import_templates.php';

function handle_import($action) {
    try {
        switch ($action) {
            case 'tpl_inbound':
                import_tpl_inbound();
                return;
            case 'tpl_outbound':
                import_tpl_outbound();
                return;
            case 'tpl_stock':
                import_tpl_stock();
                return;
            case 'inbound':
                api_require_write();
                import_run_inbound();
                return;
            case 'outbound':
                api_require_write();
                import_run_outbound();
                return;
            case 'stock_preview':
                api_require_write();
                import_stock_preview();
                return;
            case 'stock_commit':
                api_require_write();
                import_stock_commit_action();
                return;
            case 'auto':
                api_require_write();
                import_auto_run();
                return;
            case 'auto_async':
                api_require_write();
                import_auto_run();
                return;
            default:
                json_err('Invalid action: ' . $action, 404);
        }
    } catch (Throwable $e) {
        json_err($e->getMessage(), 400);
    }
}

/** Read uploaded sheet, parse + validate rows, return them for preview. */
function import_stock_preview(): void {
    $fileKey = isset($_FILES['excel_file']) ? 'excel_file' : 'csv_file';
    $allRows = import_read_sheet($fileKey);
    if (empty($allRows)) throw new Exception('File kosong atau tidak bisa dibaca');
    $rows = import_stock_parse($allRows);
    $rows = import_stock_validate($rows);
    json_out([
        'message' => count($rows) . ' baris dibaca dari file.',
        'stats'   => [
            'total_rows' => count($rows),
            'has_errors' => count(array_filter($rows, fn($r) => !empty($r['_errors']))),
        ],
        'rows' => $rows,
    ]);
}

/** Commit previously previewed rows (validation still enforces product_id). */
function import_stock_commit_action(): void {
    $data = body();
    $rows = $data['rows'] ?? [];
    $mode = in_array($data['mode'] ?? '', ['add', 'replace', 'skip']) ? $data['mode'] : 'add';
    if (empty($rows) || !is_array($rows)) json_err('Tidak ada data untuk di-commit');
    $result = import_stock_commit($rows, $mode);
    $autoTxt = !empty($result['auto_created']) ? ", {$result['auto_created']} produk baru dibuat otomatis." : '.';
    json_out([
        'message' => "Import selesai: {$result['imported']} diimport, {$result['skipped']} dilewati" . $autoTxt,
        'stats'   => $result,
    ]);
}