<?php

function handle_replenishment($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            $rows = Replenishment::list([
                'product_id'    => query('product_id'),
                'location_code' => query('location_code'),
            ]);
            json_out(['suggestions' => $rows]);
            break;

        case 'suggestions':
            api_require_auth();
            $rows = Replenishment::list([
                'product_id'    => query('product_id'),
                'location_code' => query('location_code'),
            ]);
            json_out(['suggestions' => $rows]);
            break;

        case 'detect':
            api_require_auth();
            $rows = Replenishment::detectShortages();
            json_out(['shortages' => $rows]);
            break;

        case 'suggest':
            api_require_auth();
            $result = Replenishment::suggestTransfers();
            json_out($result);
            break;

        case 'generate':
            api_require_write();
            try {
                $result = Replenishment::generateTransfersFull($_SESSION['user_id'] ?? 0);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log(
                'GENERATE_REPLENISHMENT', 'replenishment', 'Replenishment', null, null,
                "Generate replenishment: " . count($result['generated']) . " transfer dibuat, "
                . count($result['insufficient']) . " stok kurang, " . count($result['skipped']) . " skip"
            );
            json_out($result);
            break;

        case 'for_demand':
            api_require_write();
            $d = body();
            $productId = (int)($d['product_id'] ?? 0);
            $demandQty = floatval($d['quantity'] ?? 0);
            $create    = ($d['create_transfer'] ?? false) === true
                || ($d['create_transfer'] ?? '') === '1'
                || ($d['create_transfer'] ?? '') === 'true';

            if (!$productId) json_err('product_id wajib diisi.', 400);
            if (!is_finite($demandQty) || $demandQty <= 0) json_err('quantity harus lebih dari 0.', 400);

            try {
                $result = Replenishment::demandReplenishment($_SESSION['user_id'] ?? 0, $productId, $demandQty, $create);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            if ($create && $result['transfer_id']) {
                ActivityLogger::log(
                    'DEMAND_REPLENISHMENT', 'replenishment', 'Replenishment', $result['transfer_id'], null,
                    "Replenishment demand produk #{$productId} {$demandQty} (pick {$result['pick_available']}, shortage {$result['shortage']}, transfer {$result['transfer_number']})"
                );
            }
            json_out($result);
            break;

        case 'targets':
            api_require_auth();
            $rows = Replenishment::targets(['location_code' => query('location_code')]);
            json_out(['targets' => $rows]);
            break;

        case 'save_target':
            api_require_write();
            try {
                $id = Replenishment::saveTarget(body());
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log(
                'SAVE_PICK_FACE_TARGET', 'replenishment', 'PickFaceTarget', (int)$id, null,
                "Simpan target pick-face lokasi #" . (body()['location_id'] ?? '?') . " produk #" . (body()['product_id'] ?? '?') . " (min " . (body()['min_qty'] ?? '?') . ", max " . (body()['max_qty'] ?? '?') . ")"
            );
            json_out(['id' => (int)$id]);
            break;

        case 'delete_target':
            api_require_write();
            $id = (int)query('id');
            Replenishment::deleteTarget($id);
            ActivityLogger::log('DELETE_PICK_FACE_TARGET', 'replenishment', 'Replenishment', $id, null, 'Hapus target pick-face ID ' . $id);
            json_out(['id' => $id]);
            break;

        /* ---- Auto-Replenishment System ---- */

        case 'run_cycle':
            api_require_write();
            $trigger = body()['trigger'] ?? 'manual';
            try {
                $result = AutoReplenishment::runCycle($trigger);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log(
                'AUTO_REPLENISH_CYCLE', 'replenishment', 'AutoReplenishment', null, null,
                "Auto-replenishment cycle ({$trigger}): "
                . count($result['generated']) . " generated, "
                . count($result['skipped']) . " skipped, "
                . count($result['failed']) . " failed"
            );
            json_out($result);
            break;

        case 'task_status':
            api_require_auth();
            $taskId = (int)(query('task_id') ?? body()['task_id'] ?? 0);
            if ($taskId <= 0) json_err('Task ID wajib diisi.', 400);
            $db = db();
            $stmt = $db->prepare(
                "SELECT t.*,
                        p.product_code, p.product_name,
                        lm_src.location_code AS source_location,
                        lm_dst.location_code AS dest_location,
                        oo.order_number
                 FROM replen_task t
                 JOIN products p ON p.id = t.sku_id
                 JOIN location_master lm_src ON lm_src.id = t.source_bin_id
                 JOIN location_master lm_dst ON lm_dst.id = t.destination_bin_id
                 LEFT JOIN outbound_orders oo ON oo.id = t.triggering_order_id
                 WHERE t.id = ?"
            );
            $stmt->execute([$taskId]);
            $task = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$task) json_err('Replenishment task tidak ditemukan.', 404);
            json_out(['task' => $task]);
            break;

        case 'auto_status':
            api_require_auth();
            $result = AutoReplenishment::getStatus();
            json_out($result);
            break;

        case 'auto_config':
            api_require_auth();
            $result = AutoReplenishment::getConfig();
            json_out(['config' => $result]);
            break;

        case 'update_auto_config':
            api_require_admin();
            $updates = [];
            foreach (body() as $key => $value) {
                if ($key !== 'action') {
                    $updates[$key] = $value;
                }
            }
            $ok = AutoReplenishment::updateConfig($updates);
            ActivityLogger::log(
                'UPDATE_REPLENISH_CONFIG', 'replenishment', 'AutoReplenishment', null, null,
                "Config updated: " . implode(', ', array_keys($updates))
            );
            json_out(['success' => $ok]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}