<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
Auth::requireAuth();
if (!Auth::hasRole('admin')) die('Admin only.');

$db = db();
$results = [];

$queries = [
    // Step 1: Ubah ke VARCHAR dulu agar bebas UPDATE nilai apapun
    "ALTER TABLE `inbound_orders` MODIFY COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'Planned'",
    // Step 2: Konversi semua nilai lama
    "UPDATE `inbound_orders` SET status = 'Planned'   WHERE status IN ('Draft', 'Dues In')",
    "UPDATE `inbound_orders` SET status = 'Receiving'  WHERE status IN ('Good Received')",
    "UPDATE `inbound_orders` SET status = 'Received'   WHERE status IN ('Goods Received')",
    "UPDATE `inbound_orders` SET status = 'Completed'  WHERE status IN ('ATP', 'Unserviceable', 'Picked')",
    // Step 3: Baru ubah ke ENUM setelah semua data bersih
    "ALTER TABLE `inbound_orders` MODIFY COLUMN `status` ENUM('Planned','Receiving','Received','Completed','Cancelled') NOT NULL DEFAULT 'Planned'",
];

foreach ($queries as $sql) {
    try {
        $db->exec($sql);
        $results[] = ['ok' => true, 'sql' => $sql];
    } catch (Exception $e) {
        $results[] = ['ok' => false, 'sql' => $sql, 'err' => $e->getMessage()];
    }
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8"><title>Migration</title>
<style>body{font-family:sans-serif;padding:30px;background:#f1f5f5}
.box{background:#fff;border-radius:10px;padding:24px;max-width:700px;margin:auto;box-shadow:0 2px 12px rgba(0,0,0,.08)}
h2{color:#026766;margin-bottom:16px}.ok{color:#166534;background:#dcfce7;padding:8px 12px;border-radius:6px;margin-bottom:8px}
.err{color:#b91c1c;background:#fee2e2;padding:8px 12px;border-radius:6px;margin-bottom:8px}
code{font-size:.8rem;display:block;margin-top:4px;opacity:.7}
a{display:inline-block;margin-top:16px;padding:8px 20px;background:#026766;color:#fff;border-radius:6px;text-decoration:none;font-weight:600}
</style></head><body>
<div class="box">
  <h2>Migration: Inbound Status Flow</h2>
  <?php foreach ($results as $r): ?>
    <div class="<?= $r['ok'] ? 'ok' : 'err' ?>">
      <?= $r['ok'] ? '✓' : '✗' ?> <?= $r['ok'] ? 'Berhasil' : 'Error: '.htmlspecialchars($r['err']) ?>
      <code><?= htmlspecialchars($r['sql']) ?></code>
    </div>
  <?php endforeach; ?>
  <a href="inbound.php">← Kembali ke Inbound</a>
</div>
</body></html>
<?php
// Self-delete after run for cleanliness
// unlink(__FILE__);
?>
