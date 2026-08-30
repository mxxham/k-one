<?php

function handle_dashboard($action) {
    switch ($action) {
        case 'stats':
            api_require_auth();
            json_out(Report::dashboardStats());
            break;

        case 'aisle_detail':
            api_require_auth();
            $aisle = trim(query('aisle') ?: '');
            if (!$aisle) json_err('aisle required');
            $db = db();
            $stmt = $db->prepare("
                SELECT
                    lm.location_code AS code, lm.rack, lm.row_name, lm.zone,
                    COALESCE(s1.quantity, 0) AS qty,
                    COALESCE(s1.pallet, 0) AS pallet,
                    s1.uom AS uom,
                    s1.batch_number AS batch,
                    s1.expiry_date AS expiry,
                    p1.product_name AS product,
                    p1.product_code AS product_code,
                    p1.uom_per_pallet AS uom_per_pallet
                FROM location_master lm
                LEFT JOIN stock_locations sl
                    ON sl.location_code COLLATE utf8mb4_general_ci = lm.location_code COLLATE utf8mb4_general_ci
                    AND sl.status IN ('Available','Reserved')
                LEFT JOIN stock s1 ON sl.stock_id = s1.id AND s1.quantity > 0
                LEFT JOIN products p1 ON s1.product_id = p1.id
                WHERE lm.aisle = ? AND lm.is_active = 1
                ORDER BY lm.rack, lm.row_name, lm.position");
            $stmt->execute([$aisle]);
            $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($locations as &$l) {
                $l['qty'] = (float)$l['qty'];
                $l['pallet'] = (float)$l['pallet'];
                $l['is_eceran'] = ($l['row_name'] === 'A');
                $upp = (int)($l['uom_per_pallet'] ?? 4);
                $l['is_partial'] = $l['is_eceran'] || (!$l['is_eceran'] && $l['qty'] > 0 && $upp > 0 && $l['qty'] < $upp);
                if (!$l['is_eceran'] && $l['pallet'] > 0) $l['pallet'] = (int)ceil($l['pallet']);
                if ($l['expiry']) $l['expiry'] = date('d M Y', strtotime($l['expiry']));
            }
            unset($l);
            $total = count($locations);
            $occupied = count(array_filter($locations, fn($l) => $l['qty'] > 0));
            $totalQty = array_sum(array_column($locations, 'qty'));
            $totalPlt = (int)ceil(array_sum(array_column($locations, 'pallet')));
            json_out([
                'locations' => $locations,
                'stats' => [
                    'aisle' => $aisle,
                    'total' => $total,
                    'occupied' => $occupied,
                    'total_qty' => number_format($totalQty, 0),
                    'total_pallet' => $totalPlt,
                ],
            ]);
            break;

        case 'check_expiry_alerts':
            api_require_auth();
            json_out(Report::checkExpiryAlerts());
            break;

        case 'fefo_queue':
            api_require_auth();
            $limit = (int)(query('limit') ?: 50);
            json_out(Report::fefoQueue($limit));
            break;

        case 'alerts':
            api_require_auth();
            json_out(Report::dashboardAlerts());
            break;

        case 'insights':
            api_require_auth();
            json_out(Report::dashboardInsights());
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
