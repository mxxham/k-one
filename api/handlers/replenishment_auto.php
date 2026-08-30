<?php
/**
 * API handler for automated replenishment system.
 *
 * Actions:
 *   run_cycle     — POST: trigger (scheduled|stock_drop|inbound|manual)
 *   status        — GET: current auto-replenishment status
 *   config        — GET: current configuration
 *   update_config — POST: key=value pairs to update config
 */

function handle_replenishment_auto($action) {
    switch ($action) {
        case 'run_cycle':
            api_require_write();
            $trigger = body()['trigger'] ?? 'manual';
            try {
                $result = AutoReplenishment::runCycle($trigger);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log(
                'AUTO_REPLENISH_CYCLE', 'replenishment_auto', 'AutoReplenishment', null, null,
                "Auto replenishment cycle triggered: {$trigger}, "
                . (isset($result['transfers_created']) ? $result['transfers_created'] . ' transfers created' : '')
            );
            json_out(['data' => $result]);
            break;

        case 'status':
            api_require_auth();
            $result = AutoReplenishment::getStatus();
            json_out(['data' => $result]);
            break;

        case 'config':
            api_require_auth();
            $result = AutoReplenishment::getConfig();
            json_out(['data' => $result]);
            break;

        case 'update_config':
            api_require_write();
            $updates = body();
            unset($updates['action']);
            if (empty($updates)) {
                json_err('No config updates provided.', 400);
            }
            try {
                $result = AutoReplenishment::updateConfig($updates);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 409);
            }
            ActivityLogger::log(
                'UPDATE_AUTO_REPLENISH_CONFIG', 'replenishment_auto', 'AutoReplenishment', null, null,
                "Config updated: " . implode(', ', array_keys($updates))
            );
            json_out(['success' => true]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
