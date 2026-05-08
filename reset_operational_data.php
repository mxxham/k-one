<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';

Auth::requireAuth();
if (!Auth::canAdmin()) {
    http_response_code(403);
    echo 'Akses ditolak. Hanya admin yang bisa reset data.';
    exit;
}

$confirm = $_GET['confirm'] ?? '';
if ($confirm !== 'YES_RESET') {
    echo "Gunakan URL ini untuk eksekusi reset:\n";
    echo "reset_operational_data.php?confirm=YES_RESET\n\n";
    echo "Aksi ini akan menghapus semua data transaksi/log dan menyisakan master data.";
    exit;
}

$db = db();
$tables = [
    'activity_log',
    'stock_ledger',
    'outbound_item_locations',
    'picklist_items',
    'picklists',
    'location_allocations',
    'stock_take_items',
    'stock_take',
    'bin_transfers',
    'outbound_destinations',
    'outbound_items',
    'outbound_orders',
    'inbound_items',
    'inbound_orders',
    'stock_locations',
    'stock',
];

try {
    $db->beginTransaction();
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    $existsStmt = $db->prepare("
        SELECT COUNT(*) AS cnt
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");

    foreach ($tables as $tbl) {
        $existsStmt->execute([$tbl]);
        $exists = (int)($existsStmt->fetch()['cnt'] ?? 0) > 0;
        if ($exists) {
            $db->exec("TRUNCATE TABLE `{$tbl}`");
        }
    }

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    $db->commit();

    echo "RESET BERHASIL.\n";
    echo "Data transaksi/log dibersihkan. Master data tetap aman.\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    } catch (Throwable $ignore) {}
    http_response_code(500);
    echo "RESET GAGAL: " . $e->getMessage();
}
?>
