import {
  body,
  jsonErr,
  jsonOut,
  query,
  apiRequireAuth,
} from '../helpers';
import { dbExec, dbExecFirst } from '../db';
import { activityLog } from '../activityLog';
import { replenishmentQueue } from '../queue/replenishmentQueue';
import type { ReplenishmentJobData } from '../queue/replenishmentQueue';

/** Port of ReplenishmentTask.php — dispatch by action string. */
export async function handleReplenishment(action: string): Promise<void> {
  switch (action) {
    case 'scan_confirm':
      return scanConfirm();

    case 'task_status':
      return getTaskStatus();

    case 'enqueue':
      return enqueue();

    default:
      jsonErr('Invalid action: ' + action, 404);
  }
}

// ─── POST /api/replenishment/enqueue ─────────────────────────────────────

async function enqueue(): Promise<void> {
  const b = body();
  const taskId = Number(b.task_id ?? 0);
  const skuId = Number(b.sku_id ?? 0);
  const sourceBinId = Number(b.source_bin_id ?? 0);
  const destinationBinId = Number(b.destination_bin_id ?? 0);
  const qty = Number(b.qty ?? 0);

  if (!taskId || !skuId || !sourceBinId || !destinationBinId || qty <= 0) {
    jsonErr('Missing required fields: task_id, sku_id, source_bin_id, destination_bin_id, qty', 400);
  }

  const jobData: ReplenishmentJobData = {
    task_id: taskId,
    sku_id: skuId,
    source_bin_id: sourceBinId,
    destination_bin_id: destinationBinId,
    qty,
  };

  await replenishmentQueue.add('replenish', jobData, {
    jobId: `replen-task-${taskId}`,
  });

  jsonOut({ enqueued: true, task_id: taskId });
}

// ─── POST /api/replenishment/:id/scan-confirm ──────────────────────────

/**
 * First scan: pending/printed → in_progress.
 *
 * Mirrors PHP ReplenishmentTask::scanConfirm() first-scan branch.
 * The BullMQ worker handles the second scan (in_progress → completed)
 * and unblocks outbound_items on completion.
 */
async function scanConfirm(): Promise<void> {
  apiRequireAuth();

  const taskId = Number(query('task_id') ?? body().task_id ?? 0);
  if (taskId <= 0) jsonErr('Task ID wajib diisi.', 400);

  const task = await dbExecFirst(
    `SELECT id, status, sku_id, source_bin_id, destination_bin_id, qty
     FROM replen_task WHERE id = ?`,
    [taskId],
  );
  if (!task) jsonErr('Replenishment task tidak ditemukan.', 404);

  const current: string = task.status;

  if (current === 'completed') {
    jsonErr(`Task #${taskId} sudah selesai.`, 409);
  }
  if (current === 'in_progress') {
    jsonErr(`Task #${taskId} sedang diproses.`, 409);
  }
  if (current !== 'pending' && current !== 'printed') {
    jsonErr(`Status '${current}' tidak valid untuk scan confirm.`, 409);
  }

  // Transition pending/printed → in_progress
  await dbExec(
    `UPDATE replen_task SET status = 'in_progress', updated_at = NOW() WHERE id = ?`,
    [taskId],
  );

  await activityLog(
    'SCAN_CONFIRM_REPLEN',
    'replenishment',
    'ReplenishmentTask',
    taskId,
    null,
    `Scan confirm task #${taskId}: ${current} → in_progress`,
  );

  jsonOut({
    task_id: taskId,
    status: 'in_progress',
    message: `Task #${taskId} diproses — status berubah ke in_progress.`,
  });
}

// ─── GET /api/replenishment/tasks/:taskId ──────────────────────────────

/**
 * Return the current task state with product and location details.
 */
async function getTaskStatus(): Promise<void> {
  apiRequireAuth();

  const taskId = Number(query('task_id') ?? body().task_id ?? 0);
  if (taskId <= 0) jsonErr('Task ID wajib diisi.', 400);

  const task = await dbExecFirst(
    `SELECT t.*,
            p.product_code, p.product_name,
            lm_src.location_code AS source_location,
            lm_dst.location_code AS dest_location,
            oo.order_number
     FROM replen_task t
     JOIN products p ON p.id = t.sku_id
     JOIN location_master lm_src ON lm_src.id = t.source_bin_id
     JOIN location_master lm_dst ON lm_dst.id = t.destination_bin_id
     LEFT JOIN outbound_orders oo ON oo.id = t.triggering_order_id
     WHERE t.id = ?`,
    [taskId],
  );

  if (!task) jsonErr('Replenishment task tidak ditemukan.', 404);

  jsonOut({ task });
}
