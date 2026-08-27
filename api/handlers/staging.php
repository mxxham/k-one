<?php
declare(strict_types=1);

/**
 * Staging handler — module 'staging' for the gateway.
 * Actions: scan_staging, staged_items.
 */
function handle_staging($action)
{
    switch ($action) {

        case 'scan_staging':
            api_require_write();
            $data = body();
            $itemId     = (int)($data['picklist_item_id'] ?? 0);
            $stagingBin = trim((string)($data['staging_bin'] ?? ''));
            $operator   = (int)($data['operator_id'] ?? $_SESSION['user_id'] ?? 0);
            $qty        = (float)($data['qty'] ?? 0);
            if ($itemId <= 0 || $stagingBin === '' || $qty <= 0) {
                json_err('picklist_item_id, staging_bin, and qty are required', 400);
            }
            try {
                $result = StagingService::scanStaging($itemId, $stagingBin, $operator, $qty);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }
            ActivityLogger::log(
                'STAGE_SCAN', 'staging', 'Picklist', $itemId,
                null, 'Stage LPN to ' . $stagingBin . ', qty ' . $qty
            );
            json_out($result);
            break;

        case 'staged_items':
            api_require_auth();
            $picklistId = (int)(query('picklist_id') ?: 0);
            if ($picklistId <= 0) json_err('picklist_id is required', 400);
            json_out(['rows' => StagingService::getStagedItems($picklistId)]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
