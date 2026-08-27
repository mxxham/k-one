<?php

function handle_putaway($action) {
    switch ($action) {
        case 'recommend':
            api_require_auth();
            try {
                $data = body();
                if (empty($data)) {
                    $data = [
                        'product_id'     => (int)query('product_id'),
                        'quantity'       => (float)query('quantity'),
                        'uom'            => query('uom'),
                        'uom_per_pallet' => query('uom_per_pallet') ? (int)query('uom_per_pallet') : null,
                        'prefer_pick'    => query('prefer_pick') === '1' || query('prefer_pick') === 'true',
                        'force_level'    => query('force_level'),
                    ];
                }
                json_out(Putaway::recommendLocations($data));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            break;

        case 'validate':
            api_require_auth();
            try {
                $productId = (int)(query('product_id') ?: body()['product_id'] ?? 0);
                $location  = strtoupper(trim((string)(query('location') ?: body()['location'] ?? '')));
                $qty       = floatval(query('quantity') ?: body()['quantity'] ?? 0);
                $uom       = (string)(query('uom') ?: body()['uom'] ?? 'Drum');
                json_out(Putaway::validatePlacement($productId, $location, $qty, $uom));
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            break;

        case 'list_blocks':
            api_require_auth();
            json_out(['rows' => Putaway::listBlocks()]);
            break;

        case 'add_block':
        case 'create_block':
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
        case 'deactivate_block':
            api_require_admin();
            $id = (int)(body()['id'] ?? query('id'));
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

        // ── v2 task queue actions (S49) ────────────────────────────────

        case 'task_list':
            api_require_auth();
            json_out(['rows' => Putaway::listTasks([
                'status' => query('status'),
                'search' => query('search'),
                'mine'   => query('mine'),
            ])]);
            break;

        case 'task_detail':
            api_require_auth();
            $id = (int)(query('id') ?: body()['id'] ?? 0);
            try { json_out(Putaway::taskDetail($id)); }
            catch (Throwable $e) { json_err($e->getMessage(), 404); }
            break;

        case 'task_assign':
            // Self-claim: current user becomes assigned_to
            api_require_write();
            $id = (int)(query('id') ?: body()['id'] ?? 0);
            try { Putaway::assignTask($id, ['assigned_to' => $_SESSION['user_id'] ?? 0]); }
            catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('TASK_SELF_ASSIGN', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'assign_task':
            // Admin assigns 2-person team
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id') ?? 0);
            $forklift = (int)($data['forklift_operator_id'] ?? 0);
            $partner  = (int)($data['checklist_partner_id'] ?? 0);
            try { Putaway::assignTeam($id, $forklift, $partner); }
            catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('TASK_TEAM_ASSIGN', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'unassign_task':
            api_require_write();
            $id = (int)(query('id') ?: body()['id'] ?? 0);
            try { Putaway::unassignTeam($id); }
            catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('TASK_UNASSIGN', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'task_update_pallet':
            api_require_write();
            $data = body();
            $itemId = (int)($data['id'] ?? 0);
            $loc    = (string)($data['location_code'] ?? $data['location'] ?? '');
            try { Putaway::updateTaskPallet($itemId, $loc, $data['reason'] ?? null); }
            catch (Throwable $e) { json_err($e->getMessage(), 409); }
            json_out(['ok' => true]);
            break;

        case 'task_complete_pallet':
            // Partner confirms pallet via dual-scan — open to ANY department
            api_require_write();
            $data = body();
            try {
                Putaway::completeTaskPallet(
                    (int)($data['id'] ?? 0),
                    $data['scan_override_reason'] ?? null
                );
            } catch (Throwable $e) { json_err($e->getMessage(), 409); }
            json_out(['ok' => true]);
            break;

        case 'task_complete':
            api_require_write();
            $id = (int)(query('id') ?: body()['id'] ?? 0);
            try { Putaway::completeTask($id); }
            catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('TASK_COMPLETE', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'task_cancel':
            api_require_write();
            $id = (int)(query('id') ?: body()['id'] ?? 0);
            try { Putaway::cancelTask($id); }
            catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('TASK_CANCEL', 'putaway', 'PutawayTask', $id);
            json_out(['id' => $id]);
            break;

        case 'assignable_users':
            api_require_auth();
            json_out(['rows' => Putaway::listAssignableUsers()]);
            break;

        case 'get_lpn_label_data':
            api_require_auth();
            $id = (int)(query('id') ?: body()['id'] ?? 0);
            $data = Putaway::getLpnLabelData($id);
            if (!$data) json_err('Item tidak ditemukan.', 404);
            json_out($data);
            break;

        case 'print_lpn_label':
            api_require_write();
            $id = (int)(query('id') ?: body()['id'] ?? 0);
            $labelData = Putaway::getLpnLabelData($id);
            if (!$labelData) json_err('Item tidak ditemukan.', 404);
            // Return label data for client-side barcode rendering (LpnLabel.tsx)
            $labelData['task_number'] = $labelData['task_number'] ?? null;
            $labelData['order_number'] = $labelData['order_number'] ?? null;
            $labelData['expiry_date'] = $labelData['expiry_date'] ?? null;
            json_out(['label' => $labelData]);
            break;

        case 'my_tasks':
            // Mobile: tasks for current user — open to ANY department
            api_require_auth();
            json_out(['rows' => Putaway::myTasks()]);
            break;

        case 'scan_override':
            // Scan mismatch with typed reason — open to ANY department
            api_require_write();
            $data = body();
            $itemId = (int)($data['id'] ?? 0);
            $loc    = (string)($data['location_code'] ?? '');
            $reason = (string)($data['reason'] ?? '');
            try { Putaway::scanOverride($itemId, $loc, $reason); }
            catch (Throwable $e) { json_err($e->getMessage(), 409); }
            ActivityLogger::log('SCAN_OVERRIDE', 'putaway', 'PutawayTaskItem', $itemId);
            json_out(['ok' => true]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
?>