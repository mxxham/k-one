<?php
declare(strict_types=1);

/**
 * Consolidation handler — module 'consolidation' for the gateway.
 * Actions: consolidate, status.
 */
function handle_consolidation($action)
{
    switch ($action) {

        case 'consolidate':
            api_require_write();
            $data = body();
            $picklistId  = (int)($data['picklist_id'] ?? 0);
            $targetLpn   = trim((string)($data['target_lpn'] ?? ''));
            $operator    = (int)($data['operator_id'] ?? $_SESSION['user_id'] ?? 0);
            $stagingIds  = $data['staging_ids'] ?? [];
            if ($picklistId <= 0 || $targetLpn === '' || empty($stagingIds)) {
                json_err('picklist_id, target_lpn, and staging_ids are required', 400);
            }
            try {
                $result = ConsolidationService::consolidate($picklistId, $targetLpn, $operator, $stagingIds);
            } catch (\Throwable $e) {
                json_err($e->getMessage(), $e->getCode() ?: 400);
            }
            ActivityLogger::log(
                'CONSOLIDATE', 'consolidation', 'Picklist', $picklistId,
                null, 'Consolidate to ' . $targetLpn . ', qty ' . $result['total_qty']
            );
            json_out($result);
            break;

        case 'status':
            api_require_auth();
            $picklistId = (int)(query('picklist_id') ?: 0);
            if ($picklistId <= 0) json_err('picklist_id is required', 400);
            json_out(ConsolidationService::getStatus($picklistId));
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
