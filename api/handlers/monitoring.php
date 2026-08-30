<?php

function handle_monitoring($action) {
    switch ($action) {
        case 'health':
            api_require_auth();
            json_out(MonitoringService::getHealthCheck());
            break;

        case 'metrics':
            api_require_auth();
            json_out(MonitoringService::getSystemMetrics());
            break;

        case 'alerts':
            api_require_auth();
            json_out(MonitoringService::getAlerts());
            break;

        case 'performance':
            api_require_auth();
            json_out(MonitoringService::getPerformanceStats());
            break;

        default:
            json_err('Invalid action: ' . $action, 404);
    }
}
