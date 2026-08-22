<?php

function handle_waves($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $perPage = max(1, (int)(query('per_page') ?? 50));
            $page = max(1, (int)(query('page') ?? 1));
            $offset = ($page - 1) * $perPage;
            $status = query('status') ?: null;
            $total = Wave::countAll($status);
            $rows = Wave::getAll($status, $perPage, $offset);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id'];
                $r['order_count'] = (int)($r['order_count'] ?? 0);
                $r['item_count'] = (int)($r['item_count'] ?? 0);
            }
            unset($r);
            json_out([
                'rows' => $rows,
                'total' => (int)$total,
                'page' => $page,
                'per_page' => $perPage,
                'statuses' => ['Planning', 'Active', 'Completed', 'Cancelled'],
            ]);
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
            json_out(['orders' => Wave::candidateOrders()]);
            break;

        case 'create':
            api_require_write();
            try {
                $result = Wave::create(body(), (int)($_SESSION['user_id'] ?? 0));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            $orderIds = body()['order_ids'] ?? [];
            $skippedCount = count($result['skipped'] ?? []);
            $msg = 'Buat wave ' . count($orderIds) . ' order \x2192 picklist ID ' . $result['picklist_id'];
            if ($skippedCount) {
                $msg .= ' (dilewati: ' . implode(', ', $result['skipped']) . ')';
            }
            ActivityLogger::log('CREATE_WAVE', 'waves', 'Wave', (int)$result['wave_id'], null, $msg);
            json_out($result);
            break;

        case 'cancel':
            api_require_write();
            $id = (int)(body()['id'] ?? query('id'));
            try {
                Wave::cancel($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CANCEL_WAVE', 'waves', 'Wave', $id, null, 'Batalkan wave ID ' . $id);
            json_out(['id' => $id]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}