<?php
/**
 * Action registry — mirror of v2 apps/api/src/dispatcher/registry.ts.
 * Stores per-action permission level + department requirements. The gateway
 * (api/index.php) consults these before dispatching to handle_<module>($action).
 */

/** Actions that skip auth entirely (v2 PUBLIC_ACTIONS). */
const PUBLIC_ACTIONS = [
    'auth::login',
    'import::tpl_inbound',
    'import::tpl_outbound',
    'import::tpl_stock',
];

$GLOBALS['__api_permissions'] = [];
$GLOBALS['__api_module_departments'] = [];
$GLOBALS['__api_action_departments'] = [];

function set_permission(string $module, string $action, string $level): void {
    $GLOBALS['__api_permissions'][$module . '::' . $action] = $level;
}

function get_permission(string $module, string $action): string {
    return $GLOBALS['__api_permissions'][$module . '::' . $action] ?? 'any';
}

function set_module_departments(string $module, array $departments): void {
    $GLOBALS['__api_module_departments'][$module] = $departments;
}

function set_action_departments(string $module, string $action, array $departments): void {
    $GLOBALS['__api_action_departments'][$module . '::' . $action] = $departments;
}

function get_departments(string $module, string $action): ?array {
    $key = $module . '::' . $action;
    if (array_key_exists($key, $GLOBALS['__api_action_departments'])) {
        $o = $GLOBALS['__api_action_departments'][$key];
        return ($o && count($o) > 0) ? $o : null;
    }
    return $GLOBALS['__api_module_departments'][$module] ?? null;
}

/* ------------------------------------------------------------------ */
/* Registrations — mirror of v2 action files (setPermission /          */
/* setModuleDepartments / setActionDepartments calls, verbatim).       */
/* ------------------------------------------------------------------ */

// abc (abc.actions.ts): recompute/status admin; module ['all']
set_permission('abc', 'recompute', 'admin');
set_permission('abc', 'status', 'admin');
set_module_departments('abc', ['all']);

// asn (asn.actions.ts): create/update/cancel write; module ['inbound']
set_permission('asn', 'create', 'write');
set_permission('asn', 'update', 'write');
set_permission('asn', 'cancel', 'write');
set_module_departments('asn', ['inbound']);

// bintransfer (bintransfer.actions.ts): create/execute/cancel write; ['inventory']
set_permission('bintransfer', 'create', 'write');
set_permission('bintransfer', 'execute', 'write');
set_permission('bintransfer', 'cancel', 'write');
set_module_departments('bintransfer', ['inventory']);

// cyclecount (cyclecount.actions.ts): create/update/delete write, run_due/run_now admin; ['inventory']
set_permission('cyclecount', 'create', 'write');
set_permission('cyclecount', 'update', 'write');
set_permission('cyclecount', 'delete', 'write');
set_permission('cyclecount', 'run_due', 'admin');
set_permission('cyclecount', 'run_now', 'admin');
set_module_departments('cyclecount', ['inventory']);

// export (export.actions.ts): module ['all']
set_module_departments('export', ['all']);

// import (import.actions.ts): inbound/outbound/stock_preview/stock_commit/auto/auto_async write; ['all']
set_permission('import', 'inbound', 'write');
set_permission('import', 'outbound', 'write');
set_permission('import', 'stock_preview', 'write');
set_permission('import', 'stock_commit', 'write');
set_permission('import', 'auto', 'write');
set_permission('import', 'auto_async', 'write');
set_module_departments('import', ['all']);

// inbound (inbound.actions.ts): delete write; ['inbound']; search_products override
set_permission('inbound', 'delete', 'write');
set_module_departments('inbound', ['inbound']);
set_action_departments('inbound', 'search_products', ['inbound', 'inventory']);

// master (master.actions.ts)
set_permission('products', 'create', 'write');
set_permission('products', 'update', 'write');
set_permission('products', 'delete', 'admin');
set_module_departments('products', ['all']);
set_permission('customers', 'create', 'write');
set_permission('customers', 'update', 'write');
set_permission('customers', 'delete', 'write');
set_module_departments('customers', ['all']);
set_action_departments('customers', 'all', ['outbound']);
set_permission('locations', 'create', 'write');
set_permission('locations', 'update', 'write');
set_permission('locations', 'delete', 'admin');
set_module_departments('locations', ['all']);
set_action_departments('locations', 'all', ['inventory']);
set_action_departments('locations', 'print_labels', ['inventory']);
set_permission('users', 'list', 'admin');
set_permission('users', 'create', 'admin');
set_permission('users', 'update', 'admin');
set_permission('users', 'delete', 'admin');
set_module_departments('users', ['all']);

// outbound (outbound.actions.ts): delete/pick_items/ship write; ['outbound','ops']
set_permission('outbound', 'delete', 'write');
set_permission('outbound', 'pick_items', 'write');
set_permission('outbound', 'ship', 'write');
set_module_departments('outbound', ['outbound', 'ops']);
set_action_departments('outbound', 'search_products', ['all']);

// picklist (picklist.actions.ts): create_from_outbound/confirm/complete/delete/update_item write; ['outbound','ops']
set_permission('picklist', 'create_from_outbound', 'write');
set_permission('picklist', 'confirm', 'write');
set_permission('picklist', 'complete', 'write');
set_permission('picklist', 'delete', 'write');
set_permission('picklist', 'update_item', 'write');
set_module_departments('picklist', ['outbound', 'ops']);

// print (export.actions.ts): picklist write; ['all']
set_permission('print', 'picklist', 'write');
set_module_departments('print', ['all']);

// putaway (putaway.actions.ts)
set_permission('putaway', 'save_zone', 'write');
set_permission('putaway', 'delete_zone', 'admin');
set_permission('putaway', 'save_zone_aisle', 'write');
set_permission('putaway', 'delete_zone_aisle', 'write');
set_permission('putaway', 'save_uom_limit', 'write');
set_permission('putaway', 'save_product_rule', 'write');
set_permission('putaway', 'delete_product_rule', 'write');
set_permission('putaway', 'create_block', 'admin');
set_permission('putaway', 'deactivate_block', 'admin');
set_action_departments('putaway', 'list_blocks', ['all']);
set_action_departments('putaway', 'create_block', ['all']);
set_action_departments('putaway', 'deactivate_block', ['all']);
set_module_departments('putaway', ['inbound', 'inventory', 'ops']);
set_permission('putaway', 'task_assign', 'write');
set_permission('putaway', 'task_update_pallet', 'write');
set_permission('putaway', 'task_complete_pallet', 'write');
set_permission('putaway', 'task_complete', 'write');
set_permission('putaway', 'task_cancel', 'write');
set_action_departments('putaway', 'task_complete_pallet', []);
set_permission('putaway', 'assign_task', 'write');
set_permission('putaway', 'unassign_task', 'write');
set_permission('putaway', 'print_lpn_label', 'write');
set_permission('putaway', 'scan_override', 'write');
set_action_departments('putaway', 'my_tasks', []);
set_action_departments('putaway', 'scan_override', []);

// replenishment (replenishment.actions.ts): 4 writes; ['inventory']
set_permission('replenishment', 'save_target', 'write');
set_permission('replenishment', 'delete_target', 'write');
set_permission('replenishment', 'generate', 'write');
set_permission('replenishment', 'for_demand', 'write');
set_module_departments('replenishment', ['inventory']);

// pickface (pickface.actions.ts): create/update/delete write; ['inventory']
set_permission('pickface', 'create', 'write');
set_permission('pickface', 'update', 'write');
set_permission('pickface', 'delete', 'write');
set_module_departments('pickface', ['inventory']);

// replenishment_auto (auto replenishment): run_cycle/update_config write; ['inventory']
set_permission('replenishment_auto', 'run_cycle', 'write');
set_permission('replenishment_auto', 'update_config', 'admin');
set_module_departments('replenishment_auto', ['inventory']);

// report (report.actions.ts)
set_permission('system', 'reset_operational_data', 'admin');
set_permission('system', 'security_audit', 'admin');
set_module_departments('dashboard', ['inbound', 'outbound', 'inventory', 'all']);
set_module_departments('report', ['all']);
set_module_departments('activitylog', ['all']);
set_module_departments('system', ['all']);

// stock (stock.actions.ts): transfer/hold/release write, adjust admin, scan any, scan_override write
set_permission('stock', 'transfer', 'write');
set_permission('stock', 'adjust', 'admin');
set_permission('stock', 'hold', 'write');
set_permission('stock', 'release', 'write');
set_permission('stock', 'scan', 'any');
set_permission('stock', 'scan_override', 'write');
set_module_departments('stock', ['inventory']);
set_action_departments('stock', 'scan', ['inbound', 'outbound', 'inventory']);
set_action_departments('stock', 'scan_override', ['inbound', 'outbound', 'inventory']);

// stock reconciliation
set_permission('stock', 'reconcile', 'write');
set_permission('stock', 'reconcile_report', 'any');
set_permission('stock', 'discrepancies', 'any');
set_permission('stock', 'zone_stats', 'any');
set_permission('stock', 'allocate_zone', 'write');
set_action_departments('stock', 'reconcile', ['inventory']);
set_action_departments('stock', 'reconcile_report', ['inventory']);
set_action_departments('stock', 'discrepancies', ['inventory']);
set_action_departments('stock', 'zone_stats', ['inventory']);
set_action_departments('stock', 'allocate_zone', ['inventory']);

set_permission('ledger', 'repair_all', 'admin');
set_module_departments('ledger', ['inventory']);

// stocktake (stocktake.actions.ts): 11 writes + apply_adjustment admin; ['inventory']
set_permission('stocktake', 'create', 'write');
set_permission('stocktake', 'add_item', 'write');
set_permission('stocktake', 'auto_load', 'write');
set_permission('stocktake', 'update', 'write');
set_permission('stocktake', 'delete_item', 'write');
set_permission('stocktake', 'delete', 'write');
set_permission('stocktake', 'start_counting', 'write');
set_permission('stocktake', 'save_counters', 'write');
set_permission('stocktake', 'advance_to_c2', 'write');
set_permission('stocktake', 'finish_counting', 'write');
set_permission('stocktake', 'save_review', 'write');
set_permission('stocktake', 'apply_adjustment', 'admin');
set_module_departments('stocktake', ['inventory']);

// waves (waves.actions.ts): create/cancel/release/complete write; ['outbound']
set_permission('waves', 'create', 'write');
set_permission('waves', 'cancel', 'write');
set_permission('waves', 'release', 'write');
set_permission('waves', 'complete', 'write');
set_module_departments('waves', ['outbound']);

// wave (outbound-module): extend with add_order/release; ['outbound','ops']
set_permission('wave', 'add_order', 'write');
set_permission('wave', 'release', 'write');

// order (outbound-module): create write, get/list any; ['outbound','ops']
set_permission('order', 'create', 'write');
set_permission('order', 'get', 'any');
set_permission('order', 'list', 'any');
set_module_departments('order', ['outbound', 'ops']);

// task_assignment (outbound-module): assign/create_operator/list_operators; ['outbound']
set_permission('task_assignment', 'assign', 'write');
set_permission('task_assignment', 'create_operator', 'write');
set_permission('task_assignment', 'list_operators', 'any');
set_module_departments('task_assignment', ['outbound']);

// picklist (outbound-module): extend with generate_for_wave; ['outbound','ops']
set_permission('picklist', 'generate_for_wave', 'write');

// picking (outbound-module): confirm_pick write, pending_picks any; ['outbound']
set_permission('picking', 'confirm_pick', 'write');
set_permission('picking', 'pending_picks', 'any');
set_module_departments('picking', ['outbound']);

// checker (checker.php): pending_lines any, confirm_line write; ['outbound']
set_permission('checker', 'pending_lines', 'any');
set_permission('checker', 'confirm_line', 'write');
set_module_departments('checker', ['outbound']);

// staging (outbound-module): scan_staging write, staged_items any; ['outbound']
set_permission('staging', 'scan_staging', 'write');
set_permission('staging', 'staged_items', 'any');
set_module_departments('staging', ['outbound']);

// consolidation (outbound-module): consolidate write, status any; ['outbound']
set_permission('consolidation', 'consolidate', 'write');
set_permission('consolidation', 'status', 'any');
set_module_departments('consolidation', ['outbound']);

// dispatch (outbound-module): scan_dispatch write, get/list any; ['outbound']
set_permission('dispatch', 'scan_dispatch', 'write');
set_permission('dispatch', 'get', 'any');
set_permission('dispatch', 'list', 'any');
set_module_departments('dispatch', ['outbound']);

// gi_export (outbound-module): export write; ['outbound']
set_permission('gi_export', 'export', 'any');
set_module_departments('gi_export', ['outbound']);

// discrepancy (outbound-module): log write, get/list any; ['outbound']
set_permission('discrepancy', 'log', 'write');
set_permission('discrepancy', 'get', 'any');
set_permission('discrepancy', 'list', 'any');
set_module_departments('discrepancy', ['outbound']);

// replenishment auto (auto-replenishment system)
set_permission('replenishment', 'run_cycle', 'write');
set_permission('replenishment', 'auto_status', 'any');
set_permission('replenishment', 'auto_config', 'any');
set_permission('replenishment', 'update_auto_config', 'admin');
set_action_departments('replenishment', 'run_cycle', ['inventory']);
set_action_departments('replenishment', 'auto_status', ['inventory']);
set_action_departments('replenishment', 'auto_config', ['inventory']);
set_action_departments('replenishment', 'update_auto_config', ['inventory']);
set_permission('replenishment', 'find_for_picklist', 'any');
set_action_departments('replenishment', 'find_for_picklist', ['inventory']);
set_permission('replenishment', 'task_status', 'any');
set_action_departments('replenishment', 'task_status', ['inventory']);
set_permission('replenishment', 'list_pending', 'any');
set_action_departments('replenishment', 'list_pending', ['inventory']);
set_permission('replenishment', 'confirm_task', 'operator');
set_action_departments('replenishment', 'confirm_task', ['inventory']);
set_permission('replenishment', 'cancel_task', 'operator');
set_action_departments('replenishment', 'cancel_task', ['inventory']);

// quality (quality.php): create/record/approve/reject write; get/list any; ['inventory']
set_permission('quality', 'create', 'write');
set_permission('quality', 'record', 'write');
set_permission('quality', 'approve', 'write');
set_permission('quality', 'reject', 'write');
set_permission('quality', 'get', 'any');
set_permission('quality', 'list', 'any');
set_module_departments('quality', ['inventory']);

// rma (rma.php): create/approve/receive/complete/reject write, get/list any; ['inventory']
set_permission('rma', 'create', 'write');
set_permission('rma', 'approve', 'write');
set_permission('rma', 'receive', 'write');
set_permission('rma', 'complete', 'write');
set_permission('rma', 'reject', 'write');
set_permission('rma', 'get', 'any');
set_permission('rma', 'list', 'any');
set_module_departments('rma', ['inventory']);

// monitoring (monitoring.php): health/metrics/alerts/performance — all read-only
set_permission('monitoring', 'health', 'any');
set_permission('monitoring', 'metrics', 'any');
set_permission('monitoring', 'alerts', 'any');
set_permission('monitoring', 'performance', 'any');
set_module_departments('monitoring', ['all']);

// notification (notification.php): send/mark_read/delete write, list/get any; ['all']
set_permission('notification', 'list', 'any');
set_permission('notification', 'get', 'any');
set_permission('notification', 'send', 'write');
set_permission('notification', 'mark_read', 'write');
set_permission('notification', 'delete', 'write');
set_module_departments('notification', ['all']);
