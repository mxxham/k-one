<?php

function handle_putaway($action) {
    switch ($action) {
        case 'recommend':
            api_require_auth();
            try {
                $data = body();
                if (empty($data)) {
                    $data = [
                        'product_id' => (int)query('product_id'),
                        'quantity'   => (float)query('quantity'),
                    ];
                }
                json_out(Putaway::recommend($data));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            break;

        case 'validate':
            api_require_auth();
            try {
                $result = Putaway::validate(
                    (string)(query('location') ?: body()['location'] ?? ''),
                    (int)(query('product_id') ?: body()['product_id'] ?? 0),
                    (float)(query('quantity') ?: body()['quantity'] ?? 0)
                );
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            json_out($result);
            break;

        case 'list_blocks':
            api_require_auth();
            json_out(['rows' => Putaway::listBlocks()]);
            break;

        case 'add_block':
            api_require_admin();
            try {
                $id = Putaway::addBlock(body());
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('ADD_PUTAWAY_BLOCK', 'putaway', 'PutawayBlock', (int)$id);
            json_out(['id' => (int)$id]);
            break;

        case 'remove_block':
            api_require_admin();
            $id = (int)query('id');
            Putaway::removeBlock($id);
            ActivityLogger::log('REMOVE_PUTAWAY_BLOCK', 'putaway', 'PutawayBlock', $id);
            json_out(['id' => $id]);
            break;

        case 'create_task':
            api_require_write();
            try {
                $id = Putaway::createTask((int)(body()['inbound_order_id'] ?? query('inbound_order_id')));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CREATE_PUTAWAY_TASK', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'list_tasks':
            api_require_auth();
            json_out(['rows' => Putaway::listTasks([
                'status' => query('status'),
                'mine'   => query('mine'),
            ])]);
            break;

        case 'task_detail':
            api_require_auth();
            $id = (int)query('id');
            try {
                json_out(Putaway::taskDetail($id));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 404);
            }
            break;

        case 'assign':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            try {
                Putaway::assignTask($id, $data);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('ASSIGN_PUTAWAY_TASK', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'confirm_pallet':
            api_require_write();
            $data = body();
            try {
                Putaway::confirmPallet(
                    (int)($data['item_id'] ?? 0),
                    (string)($data['scanned_location'] ?? ''),
                    $data['scan_override_reason'] ?? null
                );
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            json_out(['ok' => true]);
            break;

        case 'complete_task':
            api_require_write();
            $id = (int)query('id');
            try {
                Putaway::completeTask($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('COMPLETE_PUTAWAY_TASK', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'cancel_task':
            api_require_write();
            $id = (int)query('id');
            try {
                Putaway::cancelTask($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log('CANCEL_PUTAWAY_TASK', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'has_open_pallets':
            api_require_auth();
            $id = (int)query('inbound_order_id');
            json_out(['has_open' => Putaway::hasOpenPallets($id)]);
            break;

        // Zoning config CRUD
        case 'zones':
            api_require_auth();
            json_out(['rows' => Putaway::listZones()]);
            break;

        case 'save_zone':
            api_require_admin();
            try { $id = Putaway::saveZone(body()); } catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('SAVE_ZONE', 'putaway', 'Zone', (int)$id);
            json_out(['id' => (int)$id]);
            break;

        case 'delete_zone':
            api_require_admin();
            $id = (int)(body()['id'] ?? query('id'));
            if (!Putaway::deleteZone($id)) json_err('Zone tidak ditemukan.', 404);
            ActivityLogger::log('DELETE_ZONE', 'putaway', 'Zone', $id);
            json_out(['id' => $id]);
            break;

        case 'zone_aisles':
            api_require_auth();
            json_out(['rows' => Putaway::listZoneAisles()]);
            break;

        case 'save_zone_aisle':
            api_require_write();
            try { $id = Putaway::saveZoneAisle(body()); } catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('SAVE_ZONE_AISLE', 'putaway', 'ZoneAisle', (int)$id);
            json_out(['id' => (int)$id]);
            break;

        case 'delete_zone_aisle':
            api_require_write();
            $id = (int)(body()['id'] ?? query('id'));
            if (!Putaway::deleteZoneAisle($id)) json_err('Binding zone-aisle tidak ditemukan.', 404);
            ActivityLogger::log('DELETE_ZONE_AISLE', 'putaway', 'ZoneAisle', $id);
            json_out(['id' => $id]);
            break;

        case 'uom_limits':
            api_require_auth();
            json_out(['rows' => Putaway::listUomLimits()]);
            break;

        case 'save_uom_limit':
            api_require_write();
            try { $uomType = Putaway::saveUomLimit(body()); } catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('SAVE_UOM_LIMIT', 'putaway', 'UomLimit', null, $uomType);
            json_out(['uom_type' => $uomType]);
            break;

        case 'product_rules':
            api_require_auth();
            $pid = (int)(query('product_id') ?: 0);
            json_out(['rows' => Putaway::listProductRules($pid ?: null)]);
            break;

        case 'save_product_rule':
            api_require_write();
            try { $pid = Putaway::saveProductRule(body()); } catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('SAVE_PRODUCT_PUTAWAY_RULE', 'putaway', 'ProductPutawayRule', (int)$pid);
            json_out(['product_id' => (int)$pid]);
            break;

        case 'delete_product_rule':
            api_require_write();
            $pid = (int)(body()['product_id'] ?? query('product_id'));
            if (!Putaway::deleteProductRule($pid)) json_err('Aturan produk tidak ditemukan.', 404);
            ActivityLogger::log('DELETE_PRODUCT_PUTAWAY_RULE', 'putaway', 'ProductPutawayRule', $pid);
            json_out(['product_id' => $pid]);
            break;

        case 'aisle_map':
            api_require_auth();
            $aisle = query('aisle') ? strtoupper(query('aisle')) : null;
            $level = query('level') ? strtoupper(query('level')) : null;
            json_out(Putaway::listAisleMap($aisle, $level));
            break;

        case 'bins':
            api_require_auth();
            json_out(['rows' => Putaway::listAllBins()]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
?>