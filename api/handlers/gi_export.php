<?php
declare(strict_types=1);

/**
 * GiExport handler — module 'gi_export' for the gateway.
 * Actions: export.
 */
function handle_gi_export($action)
{
    switch ($action) {

        case 'export':
            api_require_auth();
            $filters = [
                'status'    => query('status') ?: null,
                'from_date' => query('from_date') ?: null,
                'to_date'   => query('to_date') ?: null,
            ];
            try {
                $result = GiExportService::export($filters);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 500);
            }
            json_out($result);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
