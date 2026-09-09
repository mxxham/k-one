<?php

function handle_checker($action) {
    require_once __DIR__ . '/../../classes/Checker.php';
    switch ($action) {
        case 'pending_lines':
            api_require_auth();
            $picklistId = query('picklist_id') ? (int)query('picklist_id') : null;
            json_out(['lines' => Checker::pendingLines($picklistId)]);
            break;

        case 'confirm_line':
            api_require_auth();
            $data = body();
            try {
                Checker::confirmLine(
                    (int)($data['id'] ?? 0),
                    (string)($data['scanned_lpn'] ?? ''),
                    (string)($data['scanned_sku'] ?? ''),
                    $data['override_reason'] ?? null
                );
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CONFIRM_CHECK_LINE', 'checker', 'PicklistItem', (int)$data['id'], null,
                "Checked picklist item #{$data['id']}");
            json_out(['id' => (int)$data['id']]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
