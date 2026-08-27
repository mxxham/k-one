<?php

function handle_ledger($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $db = db();

            $productId = trim(query('product_id') ?: '');
            $startDate = trim(query('start_date') ?: '');
            $endDate   = trim(query('end_date') ?: '');
            $limit     = (int)query('limit', 200);
            if ($limit <= 0 || $limit > 5000) $limit = 200;

            $where  = [];
            $params = [];

            if ($productId !== '') {
                $where[] = 'sl.product_id = ?';
                $params[] = (int)$productId;
            }
            if ($startDate !== '') {
                $where[] = 'sl.transaction_date >= ?';
                $params[] = $startDate;
            }
            if ($endDate !== '') {
                $where[] = 'sl.transaction_date <= ?';
                $params[] = $endDate;
            }

            $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

            $sql = "SELECT sl.id, sl.transaction_date, sl.product_id,
                        p.product_code, p.product_name,
                        sl.transaction_type, sl.reference_type, sl.reference_number,
                        sl.batch_number, sl.quantity_in, sl.quantity_out,
                        sl.uom, sl.pallet, sl.balance, sl.location, sl.notes, sl.created_at
                    FROM stock_ledger sl
                    JOIN products p ON sl.product_id = p.id
                    $whereSql
                    ORDER BY sl.transaction_date DESC, sl.created_at DESC, sl.id DESC
                    LIMIT $limit";

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            foreach ($rows as &$r) {
                $r['id']            = (int)$r['id'];
                $r['quantity_in']   = (float)$r['quantity_in'];
                $r['quantity_out']  = (float)$r['quantity_out'];
                $r['pallet']        = (float)$r['pallet'];
                $r['balance']       = (float)$r['balance'];
            }
            unset($r);

            json_out([
                'rows'     => $rows,
                'products' => products_options(),
            ]);
            break;

        case 'repair_all':
            api_require_admin();
            $db = db();

            $db->beginTransaction();
            try {
                $productIds = $db->query("SELECT id FROM products ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
                $update = $db->prepare("UPDATE stock_ledger SET balance = ? WHERE id = ?");
                $select = $db->prepare(
                    "SELECT id, quantity_in, quantity_out FROM stock_ledger
                     WHERE product_id = ? ORDER BY transaction_date ASC, created_at ASC, id ASC"
                );

                foreach ($productIds as $pid) {
                    $select->execute([$pid]);
                    $balance = 0.0;
                    foreach ($select->fetchAll() as $m) {
                        $balance += (float)$m['quantity_in'];
                        $balance -= (float)$m['quantity_out'];
                        $update->execute([$balance, (int)$m['id']]);
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                $db->rollBack();
                json_err('Gagal memperbaiki ledger: ' . $e->getMessage());
            }

            ActivityLogger::log('REPAIR_LEDGER', 'ledger', 'Ledger', null, null, 'Perbaiki seluruh data ledger');
            json_out(['ok' => true]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}