<?php

function handle_cyclecount($action) {
    switch ($action) {
        case 'list':
            api_require_auth();
            json_out(['success' => true, 'rows' => CycleCount::listSchedules([
                'is_active' => query('is_active'),
            ])]);
            break;

        case 'save':
        case 'create':
        case 'update':
            api_require_write();
            try {
                $id = CycleCount::saveSchedule(body());
            } catch (Throwable $e) {
                json_err($e->getMessage(), 400);
            }
            ActivityLogger::log('SAVE_CYCLE_COUNT_SCHEDULE', 'cyclecount', 'CycleCountSchedule', (int)$id);
            json_out(['success' => true, 'id' => (int)$id]);
            break;

        case 'delete':
            api_require_write();
            $id = (int)query('id');
            CycleCount::deleteSchedule($id);
            ActivityLogger::log('DELETE_CYCLE_COUNT_SCHEDULE', 'cyclecount', 'CycleCountSchedule', $id);
            json_out(['success' => true, 'id' => $id]);
            break;

        case 'run_due':
            api_require_write();
            try {
                $generated = CycleCount::runDue();
            } catch (Throwable $e) {
                json_err($e->getMessage(), 400);
            }
            ActivityLogger::log('RUN_CYCLE_COUNT_DUE', 'cyclecount', null, null, null,
                'Cycle count run_due: ' . count($generated) . ' stock take dibuat');
            json_out(['success' => true, 'count' => count($generated), 'generated' => $generated]);
            break;

        case 'run_now':
            api_require_write();
            $id = (int)query('id');
            try {
                $generated = CycleCount::runNow($id);
            } catch (Throwable $e) {
                json_err($e->getMessage(), 400);
            }
            ActivityLogger::log('RUN_CYCLE_COUNT_NOW', 'cyclecount', 'CycleCountSchedule', $id,
                null, 'Cycle count run_now → stock take ' . $generated[0]['stock_take_id'] ?? '');
            json_out(['success' => true, 'count' => count($generated), 'generated' => $generated]);
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
?>