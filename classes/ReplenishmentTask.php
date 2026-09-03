<?php

declare(strict_types=1);

/**
 * ReplenishmentTask — Manages replenishment task lifecycle and print functionality.
 *
 * Status flow: pending → printed → in_progress → completed
 *
 * - create():            Insert new task with status='pending'
 * - printReplenSheet():  Generate printable HTML sheet, transitions pending → printed
 * - scanConfirm():       First scan: pending/printed → in_progress, Second scan: in_progress → completed
 * - getTask():           Retrieve full task data with product & location joins
 * - updateStatus():      Validate and apply any allowed status transition
 */
class ReplenishmentTask {

    /** Valid status values matching ENUM in replen_task table */
    private const STATUSES = ['pending', 'printed', 'in_progress', 'completed'];

    /** Allowed transitions: from_status => [to_status, ...] */
    private const TRANSITIONS = [
        'pending'     => ['printed', 'in_progress'],
        'printed'     => ['in_progress'],
        'in_progress' => ['completed'],
        'completed'   => [],
    ];

    // ─── Create ────────────────────────────────────────────────────────────

    /**
     * Insert a new replenishment task.
     *
     * @param array $data  Must contain: sku_id, source_bin_id, destination_bin_id, qty
     *                     Optional: triggering_order_id
     * @param PDO   $db    Database connection
     * @return int         Inserted task ID
     * @throws Exception   On missing required fields or FK violations
     */
    public static function create(array $data, PDO $db): int {
        $skuId            = (int)($data['sku_id'] ?? 0);
        $sourceBinId      = (int)($data['source_bin_id'] ?? 0);
        $destBinId        = (int)($data['destination_bin_id'] ?? 0);
        $qty              = (int)($data['qty'] ?? 0);
        $triggerOrderId   = !empty($data['triggering_order_id']) ? (int)$data['triggering_order_id'] : null;

        if ($skuId <= 0) {
            throw new Exception("sku_id wajib diisi.");
        }
        if ($sourceBinId <= 0) {
            throw new Exception("source_bin_id wajib diisi.");
        }
        if ($destBinId <= 0) {
            throw new Exception("destination_bin_id wajib diisi.");
        }
        if ($qty <= 0) {
            throw new Exception("qty harus lebih besar dari 0.");
        }

        // Validate FK: products
        $stmt = $db->prepare("SELECT id FROM products WHERE id = ?");
        $stmt->execute([$skuId]);
        if (!$stmt->fetch()) {
            throw new Exception("Produk tidak ditemukan.");
        }

        // Validate FK: source location
        $stmt = $db->prepare("SELECT id FROM location_master WHERE id = ?");
        $stmt->execute([$sourceBinId]);
        if (!$stmt->fetch()) {
            throw new Exception("Lokasi sumber tidak ditemukan.");
        }

        // Validate FK: destination location
        $stmt = $db->prepare("SELECT id FROM location_master WHERE id = ?");
        $stmt->execute([$destBinId]);
        if (!$stmt->fetch()) {
            throw new Exception("Lokasi tujuan tidak ditemukan.");
        }

        // Validate FK: triggering order (if provided)
        if ($triggerOrderId !== null) {
            $stmt = $db->prepare("SELECT id FROM outbound_orders WHERE id = ?");
            $stmt->execute([$triggerOrderId]);
            if (!$stmt->fetch()) {
                throw new Exception("Outbound order tidak ditemukan.");
            }
        }

        $sql = "INSERT INTO replen_task (sku_id, source_bin_id, destination_bin_id, qty, triggering_order_id, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
        $stmt = $db->prepare($sql);
        $stmt->execute([$skuId, $sourceBinId, $destBinId, $qty, $triggerOrderId]);

        return (int)$db->lastInsertId();
    }

    // ─── Print Replenishment Sheet ─────────────────────────────────────────

    /**
     * Generate a printable replenishment sheet HTML document.
     *
     * Includes: SKU, description, source bulk location, destination pickface bin,
     * qty to move, linked order ID, and a CODE128 barcode of the task ID.
     *
     * Also transitions status from 'pending' → 'printed' (if currently pending).
     *
     * @param int $taskId  Task ID
     * @param PDO $db      Database connection
     * @return string      Complete HTML document ready for window.print()
     * @throws Exception   If task not found
     */
    public static function printReplenSheet(int $taskId, PDO $db): string {
        $task = self::_fetchTask($taskId, $db);

        // Auto-transition: pending → printed
        if ($task['status'] === 'pending') {
            self::updateStatus($taskId, 'printed', $db);
            $task['status'] = 'printed';
        }

        // Escape all values for safe HTML output
        $taskNum     = htmlspecialchars($task['id']);
        $skuCode     = htmlspecialchars($task['product_code'] ?? '—');
        $skuName     = htmlspecialchars($task['product_name'] ?? '—');
        $sourceLoc   = htmlspecialchars($task['source_location'] ?? '—');
        $destLoc     = htmlspecialchars($task['dest_location'] ?? '—');
        $qty         = number_format((int)$task['qty'], 0, ',', '.');
        $orderNum    = htmlspecialchars($task['order_number'] ?? '—');
        $createdAt   = htmlspecialchars($task['created_at'] ?? '—');
        $barcodeData = htmlspecialchars((string)$taskId, ENT_QUOTES);

        $orderSection = '';
        if (!empty($task['triggering_order_id'])) {
            $orderSection = <<<HTML
    <div style="display:flex;justify-content:space-between">
      <span>Order: <b>{$orderNum}</b></span>
      <span>Order ID: <b>{$task['triggering_order_id']}</b></span>
    </div>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>K-one Replenishment Sheet — Task #{$taskNum}</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: monospace; background: #f3f4f6; padding: 20px; }
  .sheet {
    width: 210mm;
    max-width: 100%;
    margin: 0 auto;
    background: #fff;
    border: 2px solid #111;
    padding: 16px;
    color: #000;
  }
  .sheet-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-bottom: 2px solid #111;
    padding-bottom: 8px;
    margin-bottom: 12px;
  }
  .sheet-title {
    font-weight: 900;
    font-size: 16px;
    letter-spacing: 0.08em;
  }
  .sheet-subtitle {
    font-size: 9px;
    font-weight: 700;
    color: #666;
  }
  .sheet-meta {
    font-size: 10px;
    color: #666;
    text-align: right;
    line-height: 1.4;
  }
  .field-group {
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px dashed #d1d5db;
  }
  .field-row {
    display: flex;
    justify-content: space-between;
    font-size: 12px;
    line-height: 1.6;
  }
  .field-label {
    color: #666;
    font-weight: 600;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.12em;
  }
  .field-value {
    font-weight: 700;
    font-size: 13px;
  }
  .field-value-large {
    font-weight: 900;
    font-size: 18px;
  }
  .barcode-container {
    text-align: center;
    margin: 12px 0 6px 0;
    padding: 8px 0;
    border-top: 1px solid #ccc;
    border-bottom: 1px solid #ccc;
  }
  .footer {
    display: flex;
    justify-content: space-between;
    margin-top: 16px;
    padding-top: 8px;
    border-top: 1px solid #999;
    font-size: 9px;
    color: #666;
  }
  @media print {
    body { background: white; padding: 0; }
    .sheet {
      border: 2px solid #000;
      width: 100%;
      page-break-inside: avoid;
    }
    @page {
      size: A4 portrait;
      margin: 10mm;
    }
  }
</style>
</head>
<body>
<div class="sheet">
  <!-- Header -->
  <div class="sheet-header">
    <div>
      <div class="sheet-title">REPLENISHMENT SHEET</div>
      <div class="sheet-subtitle">REPLENISHMENT TASK — MOVE STOCK TO PICKFACE</div>
    </div>
    <div class="sheet-meta">
      Task #{$taskNum}<br>
      {$createdAt}
    </div>
  </div>

  <!-- Product Info -->
  <div class="field-group">
    <div class="field-row">
      <span class="field-label">SKU Code</span>
      <span class="field-value-large">{$skuCode}</span>
    </div>
    <div class="field-row">
      <span class="field-label">Description</span>
      <span class="field-value">{$skuName}</span>
    </div>
  </div>

  <!-- Location Info -->
  <div class="field-group">
    <div class="field-row">
      <span class="field-label">Source (Bulk)</span>
      <span class="field-value">{$sourceLoc}</span>
    </div>
    <div class="field-row">
      <span class="field-label">Destination (Pickface)</span>
      <span class="field-value">{$destLoc}</span>
    </div>
  </div>

  <!-- Qty & Order -->
  <div class="field-group">
    <div class="field-row">
      <span class="field-label">Qty to Move</span>
      <span class="field-value-large">{$qty}</span>
    </div>
    {$orderSection}
  </div>

  <!-- Barcode -->
  <div class="barcode-container">
    <svg class="barcode-placeholder" data-barcode="{$barcodeData}" data-format="CODE128" data-height="50"></svg>
    <div style="font-size:8px;color:#666;margin-top:4px;font-family:monospace">Task #{$taskNum}</div>
  </div>

  <!-- Footer -->
  <div class="footer">
    <span>PT. K-ONE Warehouse</span>
    <span>Printed: {$createdAt}</span>
  </div>
</div>
</body>
</html>
HTML;
    }

    // ─── Scan Confirm ──────────────────────────────────────────────────────

    /**
     * Progress the task through scan confirmation.
     *
     * - pending / printed → in_progress  (first scan)
     * - in_progress → completed          (second scan)
     *
     * @param int $taskId  Task ID
     * @param PDO $db      Database connection
     * @return array       ['status' => string, 'message' => string]
     * @throws Exception   If task not found or invalid transition
     */
    public static function scanConfirm(int $taskId, PDO $db): array {
        $task = self::_fetchTask($taskId, $db);
        $current = $task['status'];

        if ($current === 'completed') {
            throw new Exception("Task #{$taskId} sudah selesai.");
        }

        if ($current === 'pending' || $current === 'printed') {
            $newStatus = 'in_progress';
            $message = "Task #{$taskId} diproses — status berubah ke in_progress.";
        } elseif ($current === 'in_progress') {
            $newStatus = 'completed';
            $message = "Task #{$taskId} selesai — stock sudah dipindahkan.";
        } else {
            throw new Exception("Status tidak dikenal: {$current}");
        }

        self::_applyStatus($taskId, $newStatus, $db);

        return [
            'status'  => $newStatus,
            'message' => $message,
        ];
    }

    // ─── Get Task ──────────────────────────────────────────────────────────

    /**
     * Retrieve full task data with product and location details.
     *
     * @param int $taskId  Task ID
     * @param PDO $db      Database connection
     * @return array       Full task row with joined fields
     * @throws Exception   If task not found
     */
    public static function getTask(int $taskId, PDO $db): array {
        return self::_fetchTask($taskId, $db);
    }

    // ─── Update Status ─────────────────────────────────────────────────────

    /**
     * Validate and apply a status transition.
     *
     * @param int    $taskId   Task ID
     * @param string $status   Target status
     * @param PDO    $db       Database connection
     * @return bool            True on success
     * @throws Exception       If task not found, invalid transition, or invalid status
     */
    public static function updateStatus(int $taskId, string $status, PDO $db): bool {
        if (!in_array($status, self::STATUSES, true)) {
            throw new Exception("Status tidak valid: {$status}. Valid: " . implode(', ', self::STATUSES));
        }

        $task = self::_fetchTask($taskId, $db);
        $current = $task['status'];

        if (!in_array($status, self::TRANSITIONS[$current] ?? [], true)) {
            throw new Exception(
                "Transisi tidak valid: {$current} → {$status}. "
                . "Transisi yang diizinkan dari {$current}: "
                . implode(', ', self::TRANSITIONS[$current] ?: ['(none — task sudah selesai)'])
            );
        }

        self::_applyStatus($taskId, $status, $db);
        return true;
    }

    // ─── Internal Helpers ──────────────────────────────────────────────────

    /**
     * Fetch task with product and location joins.
     *
     * @param int $taskId
     * @param PDO $db
     * @return array
     * @throws Exception if not found
     */
    private static function _fetchTask(int $taskId, PDO $db): array {
        $sql = "SELECT t.*,
                       p.product_code, p.product_name,
                       lm_src.location_code AS source_location,
                       lm_dst.location_code AS dest_location,
                       oo.order_number
                FROM replen_task t
                JOIN products p ON p.id = t.sku_id
                JOIN location_master lm_src ON lm_src.id = t.source_bin_id
                JOIN location_master lm_dst ON lm_dst.id = t.destination_bin_id
                LEFT JOIN outbound_orders oo ON oo.id = t.triggering_order_id
                WHERE t.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$taskId]);
        $task = $stmt->fetch();

        if (!$task) {
            throw new Exception("Replenishment task #{$taskId} tidak ditemukan.");
        }

        return $task;
    }

    /**
     * Apply status update to database.
     *
     * @param int    $taskId
     * @param string $status
     * @param PDO    $db
     * @return void
     */
    private static function _applyStatus(int $taskId, string $status, PDO $db): void {
        $sql = "UPDATE replen_task SET status = ?";
        $params = [$status];

        if ($status === 'completed') {
            $sql .= ", completed_at = NOW()";
        }

        $sql .= " WHERE id = ?";
        $params[] = $taskId;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
    }
}
