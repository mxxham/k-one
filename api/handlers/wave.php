<?php

function handle_wave($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            json_out(['rows' => Wave::list(['status' => query('status')])]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            try {
                json_out(Wave::detail($id));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 404);
            }
            break;

        case 'candidate_orders':
            api_require_auth();
            json_out(['rows' => Wave::candidateOrders()]);
            break;

        case 'create':
            api_require_write();
            try {
                $result = Wave::create(body());
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CREATE_WAVE', 'wave', 'Wave', (int)$result['wave_id'],
                $result['wave_number'], 'Buat wave ' . $result['wave_number'] . ' dengan ' . count(body()['order_ids'] ?? []) . ' order');
            json_out($result);
            break;

        case 'cancel':
            api_require_write();
            $id = (int)query('id');
            try {
                $preserved = Wave::cancel($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CANCEL_WAVE', 'wave', 'Wave', $id, null, 'Batalkan wave ID ' . $id);
            json_out(['id' => $id, 'picklists_preserved' => $preserved]);
            break;

        case 'add_order':
            api_require_write();
            $data = body();
            $waveId  = (int)($data['wave_id'] ?? query('wave_id'));
            $orderId = (int)($data['order_id'] ?? query('order_id'));
            if ($waveId <= 0 || $orderId <= 0) json_err('wave_id and order_id are required', 400);
            try {
                Wave::addOrder($waveId, $orderId);
            } catch (Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 409);
            }
            ActivityLogger::log('ADD_ORDER_TO_WAVE', 'wave', 'Wave', $waveId,
                null, 'Add order ID ' . $orderId . ' to wave ID ' . $waveId);
            json_out(['wave_id' => $waveId, 'order_id' => $orderId]);
            break;

        case 'release':
            api_require_write();
            $id = (int)(body()['id'] ?? query('id'));
            if ($id <= 0) json_err('wave id is required', 400);
            try {
                $result = Wave::release($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 409);
            }
            ActivityLogger::log('RELEASE_WAVE', 'wave', 'Wave', $id,
                null, 'Release wave ID ' . $id . ' → Active');
            json_out($result);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
?>