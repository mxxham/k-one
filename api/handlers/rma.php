<?php
declare(strict_types=1);

/**
 * RMA handler — module 'rma' for the gateway.
 * Actions: create, approve, receive, complete, reject, get, list.
 */
function handle_rma($action)
{
    switch ($action) {

        case 'create':
            $user = api_require_write();
            $data = body();
            $outboundOrderId = (int)($data['outbound_order_id'] ?? 0);
            $items  = $data['items'] ?? [];
            $reason = trim((string)($data['reason'] ?? ''));

            if ($outboundOrderId <= 0) json_err('outbound_order_id is required');
            if (empty($items) || !is_array($items)) json_err('items must be a non-empty array');
            if ($reason === '') json_err('reason is required');

            try {
                $rmaId = RmaService::create($outboundOrderId, $items, $reason, (int)$user['id']);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log('CREATE_RMA', 'rma', 'RMA', $rmaId, null, 'RMA created for outbound order #' . $outboundOrderId);

            $rma = RmaService::getById($rmaId);
            json_out(['id' => $rmaId, 'rma_number' => $rma['rma_number'] ?? '']);
            break;

        case 'approve':
            $user = api_require_write();
            $data = body();
            $rmaId = (int)($data['rma_id'] ?? 0);

            if ($rmaId <= 0) json_err('rma_id is required');

            try {
                RmaService::approve($rmaId, (int)$user['id']);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log('APPROVE_RMA', 'rma', 'RMA', $rmaId, null, 'RMA #' . $rmaId . ' approved');
            json_out(['id' => $rmaId]);
            break;

        case 'receive':
            $user = api_require_write();
            $data = body();
            $rmaId         = (int)($data['rma_id'] ?? 0);
            $receivedItems = $data['received_items'] ?? [];

            if ($rmaId <= 0) json_err('rma_id is required');
            if (empty($receivedItems) || !is_array($receivedItems)) json_err('received_items must be a non-empty array');

            try {
                RmaService::receive($rmaId, $receivedItems, (int)$user['id']);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log('RECEIVE_RMA', 'rma', 'RMA', $rmaId, null, 'RMA #' . $rmaId . ' items received');
            json_out(['id' => $rmaId]);
            break;

        case 'complete':
            $user = api_require_write();
            $data = body();
            $rmaId = (int)($data['rma_id'] ?? 0);

            if ($rmaId <= 0) json_err('rma_id is required');

            try {
                RmaService::complete($rmaId);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log('COMPLETE_RMA', 'rma', 'RMA', $rmaId, null, 'RMA #' . $rmaId . ' completed');
            json_out(['id' => $rmaId]);
            break;

        case 'reject':
            $user = api_require_write();
            $data = body();
            $rmaId = (int)($data['rma_id'] ?? 0);
            $reason = trim((string)($data['reason'] ?? ''));

            if ($rmaId <= 0) json_err('rma_id is required');
            if ($reason === '') json_err('reason is required');

            try {
                RmaService::reject($rmaId, $reason, (int)$user['id']);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }

            ActivityLogger::log('REJECT_RMA', 'rma', 'RMA', $rmaId, null, 'RMA #' . $rmaId . ' rejected: ' . $reason);
            json_out(['id' => $rmaId]);
            break;

        case 'get':
            api_require_auth();
            $id = (int)query('id');
            if ($id <= 0) json_err('id is required');

            $rma = RmaService::getById($id);
            if (!$rma) json_err('RMA tidak ditemukan', 404);

            json_out(['rma' => $rma]);
            break;

        case 'list':
            api_require_auth();
            $filters = [
                'status'   => query('status') ?: null,
                'page'     => query('page') ?: 1,
                'per_page' => query('per_page') ?: 50,
            ];
            json_out(RmaService::getAll($filters));
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
