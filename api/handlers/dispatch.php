<?php
declare(strict_types=1);

/**
 * Dispatch handler — module 'dispatch' for the gateway.
 * Actions: scan_dispatch, get, list.
 */
function handle_dispatch($action)
{
    switch ($action) {

        case 'scan_dispatch':
            api_require_write();
            $data = body();
            $data['operator_id'] = $_SESSION['user_id'] ?? 0;
            try {
                $result = DispatchService::scanDispatch($data);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }
            ActivityLogger::log(
                'DISPATCH', 'dispatch', 'GI Export', $result['gi_id'],
                $result['gi_number'], 'Dispatch LPN ' . $result['lpn_code'] . ' DO ' . $result['do_number']
            );
            json_out($result);
            break;

        case 'get':
            api_require_auth();
            $id = (int)query('id');
            try {
                json_out(DispatchService::getDispatch($id));
            } catch (\Throwable $e) {
                json_err($e->getMessage(), 404);
            }
            break;

        case 'list':
            api_require_auth();
            $page    = max(1, (int)(query('page') ?: 1));
            $perPage = max(1, min(200, (int)(query('per_page') ?: 50)));
            json_out(DispatchService::listDispatches($page, $perPage));
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
