<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Auth.php';
require_once __DIR__ . '/classes/Picklist.php';

Auth::requireAuth();

$outboundId = (int)($_GET['outbound_id'] ?? 0);
if (!$outboundId) {
    header('Location: outbound.php'); exit;
}

try {
    $picklistId = Picklist::createFromOutbound($outboundId);
    header('Location: print_picklist.php?id=' . $picklistId);
    exit;
} catch (Exception $e) {
    ?>
    <!DOCTYPE html>
    <html lang="id">
    <head>
    <meta charset="UTF-8">
    <title>Error — Picklist</title>
    <style>
      body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f1f5f5}
      .box{background:#fff;border-radius:10px;padding:32px 40px;box-shadow:0 2px 16px rgba(0,0,0,.1);max-width:480px;text-align:center}
      h2{color:#b91c1c;margin-bottom:8px}
      p{color:#374151;font-size:.95rem;margin-bottom:20px}
      .msg{background:#fee2e2;color:#b91c1c;border-radius:6px;padding:10px 14px;font-size:.85rem;margin-bottom:20px;text-align:left}
      a{display:inline-block;padding:8px 20px;background:#026766;color:#fff;border-radius:6px;text-decoration:none;font-weight:600}
    </style>
    </head>
    <body>
    <div class="box">
      <h2>Gagal membuat Picklist</h2>
      <div class="msg"><?= htmlspecialchars($e->getMessage()) ?></div>
      <p>Pastikan outbound sudah memiliki item sebelum membuat picklist.</p>
      <a href="javascript:window.close()">Tutup Tab</a>
    </div>
    </body>
    </html>
    <?php
}
