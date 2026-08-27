<?php

function handle_asn($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $page = max(1, (int)(query('page') ?? 1));
            $perPage = min(100, max(1, (int)(query('per_page') ?? 50)));
            $offset = ($page - 1) * $perPage;
            $params = [
                'status' => query('status'),
                'from'   => query('from'),
                'to'     => query('to'),
                'limit'  => $perPage,
                'offset' => $offset,
            ];
            $rows = Asn::list($params);
            $total = Asn::countAll(query('status'));
            json_out([
                'success' => true,
                'rows' => $rows,
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $perPage,
                'statuses' => ['Pending', 'Received', 'Cancelled'],
            ]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            try {
                $detail = Asn::detail($id);
                $inbounds = Asn::linkedInbounds($id);
                json_out(array_merge(['success' => true], $detail, ['inbound_orders' => $inbounds]));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 404);
            }
            break;

        case 'create':
            api_require_write();
            try {
                $result = Asn::createWithNumber(body());
            } catch (Throwable $e) {
                json_err($e->getMessage(), 400);
            }
            ActivityLogger::log('CREATE_ASN', 'asn', 'Asn', $result['id'], null, 'Buat ASN ID ' . $result['id']);
            json_out(['success' => true, 'id' => (int)$result['id'], 'asn_number' => $result['asn_number']]);
            break;

        case 'update':
            api_require_write();
            $id = (int)query('id');
            try {
                Asn::update($id, body());
            } catch (Throwable $e) {
                json_err($e->getMessage(), 400);
            }
            ActivityLogger::log('UPDATE_ASN', 'asn', 'Asn', $id, null, 'Edit ASN ID ' . $id);
            json_out(['success' => true, 'id' => $id]);
            break;

        case 'cancel':
            api_require_write();
            $id = (int)query('id');
            try {
                Asn::cancel($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 400);
            }
            ActivityLogger::log('CANCEL_ASN', 'asn', 'Asn', $id, null, 'Batalkan ASN ID ' . $id);
            json_out(['success' => true, 'id' => $id]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
?>