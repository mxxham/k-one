<?php
declare(strict_types=1);

/**
 * Picking handler — module 'picking' for the gateway.
 * Actions: confirm_pick, pending_picks.
 */
function handle_picking($action)
{
    switch ($action) {

        case 'confirm_pick':
            api_require_write();
            $data = body();
            $itemId   = (int)($data['picklist_item_id'] ?? 0);
            $operator = (int)($data['operator_id'] ?? $_SESSION['user_id'] ?? 0);
            $qty      = (float)($data['qty'] ?? 0);
            $scanLpn  = (string)($data['scan_lpn'] ?? '');
            if ($itemId <= 0 || $operator <= 0) json_err('picklist_item_id and operator_id required', 400);
            try {
                $result = PickingService::confirmPick($itemId, $operator, $qty, $scanLpn);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }
            ActivityLogger::log(
                'PICK_CONFIRMED', 'picklist_item', 'Picklist', $itemId,
                null, 'Pick confirmed: LPN ' . ($result['lpn_code'] ?? '') . ' qty ' . $qty
            );
            json_out($result);
            break;

        case 'pending_picks':
            api_require_auth();
            $operatorId = (int)(query('operator_id') ?: $_SESSION['user_id'] ?? 0);
            if ($operatorId <= 0) json_err('operator_id is required', 400);
            json_out(['rows' => PickingService::getPendingPicks($operatorId)]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
