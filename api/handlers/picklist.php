<?php

function handle_picklist($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            [$page, $perPage, $offset] = page_params(50);
            $status = query('status') ?: null;
            $total = Picklist::countAll($status);
            $rows = Picklist::getAll($status, $perPage, $offset);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            json_out(['rows' => $rows, 'total' => (int)$total, 'page' => $page, 'per_page' => $perPage, 'statuses' => statuses_for('picklist')]);
            break;

        case 'detail':
            api_require_auth();
            $id = (int)query('id');
            $picklist = Picklist::getById($id);
            if (!$picklist) json_err('Picklist tidak ditemukan', 404);
            $items = Picklist::getItems($id);
            foreach ($items as &$it) $it['id'] = (int)$it['id'];
            unset($it);
            json_out(['picklist' => $picklist, 'items' => $items]);
            break;

        case 'stats':
            api_require_auth();
            json_out(['stats' => Picklist::getStats()]);
            break;

        case 'create_from_outbound':
            api_require_write();
            $data = body();
            $outboundId = (int)($data['outbound_id'] ?? query('outbound_id'));
            if (!$outboundId) json_err('outbound_id wajib diisi.');
            try {
                $id = Picklist::createFromOutbound($outboundId);
            } catch (\ApiException $e) {
                json_err($e->getMessage(), $e->statusCode ?: 400);
            }
            ActivityLogger::log('CREATE_PICKLIST', 'picklist', 'Picklist', (int)$id, null,
                'Buat picklist dari outbound ID ' . $outboundId);
            json_out(['id' => (int)$id]);
            break;

        case 'confirm':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            Picklist::confirm($id);
            ActivityLogger::log('CONFIRM_PICKLIST', 'picklist', 'Picklist', $id, null, 'Konfirmasi picklist ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'complete':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            Picklist::complete($id);
            ActivityLogger::log('COMPLETE_PICKLIST', 'picklist', 'Picklist', $id, null, 'Selesaikan picklist ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'delete':
            api_require_write();
            $data = body();
            $id = (int)($data['id'] ?? query('id'));
            Picklist::delete($id);
            ActivityLogger::log('DELETE_PICKLIST', 'picklist', 'Picklist', $id, null, 'Hapus picklist ID ' . $id);
            json_out(['id' => $id]);
            break;

        case 'update_item':
            api_require_write();
            $data = body();
            $itemId = (int)($data['item_id'] ?? 0);
            Picklist::updateItem($itemId, $data);
            json_out(['item_id' => $itemId]);
            break;

        case 'export_data':
            api_require_auth();
            $id = (int)query('id');
            $data = Picklist::exportForPrint($id);
            json_out(['data' => $data]);
            break;

        case 'generate_for_wave':
            api_require_write();
            $data = body();
            $waveId = (int)($data['wave_id'] ?? query('wave_id'));
            if ($waveId <= 0) json_err('wave_id is required', 400);
            try {
                $ids = PicklistService::generateForWave($waveId);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }
            ActivityLogger::log('GENERATE_PICKLISTS', 'picklist', 'Wave', $waveId,
                null, 'Generate ' . count($ids) . ' picklist(s) for wave ID ' . $waveId);
            json_out(['picklist_ids' => $ids, 'count' => count($ids)]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
