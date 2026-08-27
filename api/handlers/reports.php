<?php

function handle_report($action) {
    switch ($action) {
        case 'daily':
            api_require_auth();
            $date = query('date') ?: null;
            $dateTo = query('date_to') ?: null;
            $report = Report::getDailyReport($date, $dateTo);
            json_out(['report' => $report]);
            break;

        case 'products':
            api_require_auth();
            json_out(['rows' => Report::reportProducts()]);
            break;

        case 'inbound':
            api_require_auth();
            $start = query('start_date') ?: null;
            $end = query('end_date') ?: null;
            $status = query('status') ?: null;
            $rows = Inbound::getAll($status, 2000, 0, null);
            if ($start) $rows = array_values(array_filter($rows, fn($r) => ($r['order_date'] ?? '') >= $start));
            if ($end) $rows = array_values(array_filter($rows, fn($r) => ($r['order_date'] ?? '') <= $end));
            json_out(['rows' => $rows]);
            break;

        case 'outbound':
            api_require_auth();
            $start = query('start_date') ?: null;
            $end = query('end_date') ?: null;
            $status = query('status') ?: null;
            $rows = Outbound::getAll($status, 2000, 0, null);
            if ($start) $rows = array_values(array_filter($rows, fn($r) => ($r['order_date'] ?? '') >= $start));
            if ($end) $rows = array_values(array_filter($rows, fn($r) => ($r['order_date'] ?? '') <= $end));
            json_out(['rows' => $rows]);
            break;

        case 'stock':
            api_require_auth();
            json_out(['rows' => Stock::getAll()]);
            break;

        case 'ledger':
            api_require_auth();
            $start = query('start_date') ?: null;
            $end = query('end_date') ?: null;
            $rows = Stock::getMovement(null, $start, $end, 5000);
            json_out(['rows' => $rows]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}

function handle_activitylog($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $module = query('module') ?: null;
            $actionFilter = query('action') ?: null;
            $limit = (int)(query('limit') ?: 200);
            $rows = Report::activityLogList($module, $actionFilter, $limit);
            json_out(['rows' => $rows]);
            break;

        case 'modules':
            api_require_auth();
            $rows = Report::activityModules();
            json_out(['rows' => $rows]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
