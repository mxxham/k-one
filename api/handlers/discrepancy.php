<?php
declare(strict_types=1);

/**
 * Discrepancy handler — module 'discrepancy' for the gateway.
 * Actions: log, get, list.
 */
function handle_discrepancy($action)
{
    switch ($action) {

        case 'log':
            api_require_write();
            $data = body();
            $data['operator_id'] = $_SESSION['user_id'] ?? 0;
            try {
                $id = DiscrepancyService::logDiscrepancy($data);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }
            ActivityLogger::log(
                'DISCREPANCY', 'discrepancy', 'Discrepancy', $id,
                null, 'Discrepancy reported: type=' . ($data['type'] ?? '')
            );
            json_out(['id' => $id]);
            break;

        case 'get':
            api_require_auth();
            $id = (int)query('id');
            try {
                json_out(DiscrepancyService::get($id));
            } catch (\Throwable $e) {
                json_err($e->getMessage(), 404);
            }
            break;

        case 'list':
            api_require_auth();
            $page    = max(1, (int)(query('page') ?: 1));
            $perPage = max(1, min(200, (int)(query('per_page') ?: 50)));
            $type    = query('type') ?: null;
            json_out(DiscrepancyService::list($page, $perPage, $type));
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
