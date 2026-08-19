<?php

function handle_bintransfer($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            [$page, $perPage, $offset] = page_params(200);
            $status = query('status') ?: null;
            $total = BinTransfer::countAll($status);
            $rows = BinTransfer::getAll($status, $perPage, $offset);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            json_out(['rows' => $rows, 'total' => (int)$total, 'page' => $page, 'per_page' => $perPage, 'statuses' => statuses_for('bintransfer')]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            $transfer = BinTransfer::getById($id);
            if (!$transfer) json_err('Transfer tidak ditemukan', 404);
            json_out(['transfer' => $transfer]);
            break;

        case 'locations_with_stock':
            api_require_auth();
            $pid = (int)query('product_id');
            json_out(['rows' => BinTransfer::getLocationsWithStock($pid)]);
            break;

        case 'stock_at_location':
            api_require_auth();
            $pid = (int)query('product_id');
            $loc = query('location') ?: '';
            json_out(['rows' => BinTransfer::getStockAtLocation($pid, $loc)]);
            break;

        case 'create':
            api_require_write();
            $data = body();
            try {
                $id = BinTransfer::create($data);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CREATE_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', (int)$id, null,
                'Buat transfer ' . ($data['from_location'] ?? '') . ' → ' . ($data['to_location'] ?? '') . ' qty ' . ($data['quantity'] ?? 0));
            json_out(['id' => (int)$id]);
            break;

        case 'execute':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            try {
                BinTransfer::execute($id, $_SESSION['user_id'] ?? null);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('EXECUTE_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', $id, null, 'Eksekusi transfer ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'cancel':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            try {
                BinTransfer::cancel($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CANCEL_BIN_TRANSFER', 'bin_transfer', 'BinTransfer', $id, null, 'Batalkan transfer ID ' . $id);
            json_out(['id' => $id]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
