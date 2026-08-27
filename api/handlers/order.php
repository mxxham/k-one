<?php
declare(strict_types=1);

/**
 * Order handler — module 'order' for the gateway.
 * Actions: create, get, list.
 */
function handle_order($action)
{
    switch ($action) {

        case 'create':
            api_require_write();
            $data = body();
            $data['created_by'] = $_SESSION['user_id'] ?? 0;
            try {
                $result = OrderService::create($data);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }
            ActivityLogger::log(
                'CREATE_ORDER', 'order', 'Outbound', (int)$result['id'],
                null, 'Create order ' . $result['order_number']
            );
            json_out($result);
            break;

        case 'get':
            api_require_auth();
            $id = (int)query('id');
            try {
                $order = OrderService::get($id);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 404);
            }
            json_out(['order' => $order]);
            break;

        case 'list':
            api_require_auth();
            $page    = max(1, (int)(query('page') ?: 1));
            $perPage = max(1, min(200, (int)(query('per_page') ?: 50)));
            $status  = query('status') ?: null;
            json_out(OrderService::list($page, $perPage, $status));
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
