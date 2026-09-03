import { Worker, Job } from 'bullmq';
import { redisConnection } from './redisConnection';
import { db, dbExec, dbExecFirst, dbScalar, withTransaction } from '../db';
import type { ReplenishmentJobData } from './replenishmentQueue';

/**
 * BullMQ Worker that processes replenishment tasks.
 *
 * Each job corresponds to a `replen_task` row. The handler:
 *  1. Fetches the task + resolves location codes
 *  2. Allocates stock from the source bin using FEFO
 *  3. Executes the bin transfer (deduct source → add destination)
 *  4. Writes stock_ledger entries
 *  5. Marks the task as completed
 */
export const replenishmentWorker = new Worker(
  'replenishment',
  async (job: Job<ReplenishmentJobData>) => {
    const { task_id, sku_id, source_bin_id, destination_bin_id, qty } = job.data;

    error_log(`[ReplenishmentWorker] Processing task #${task_id}: SKU=${sku_id}, `
      + `source=${source_bin_id}, dest=${destination_bin_id}, qty=${qty}`);

    await job.updateProgress(10);

    // ── 1. Fetch task & resolve location codes ───────────────────────────
    const task = await dbExecFirst(
      `SELECT id, sku_id, source_bin_id, destination_bin_id, qty, status
       FROM replen_task WHERE id = ?`,
      [task_id],
    );
    if (!task) throw new Error(`Replenishment task #${task_id} not found`);
    if (task.status === 'completed') {
      error_log(`[ReplenishmentWorker] Task #${task_id} already completed — skipping`);
      return { skipped: true };
    }

    const sourceRow = await dbExecFirst(
      'SELECT location_code FROM location_master WHERE id = ?',
      [source_bin_id],
    );
    const destRow = await dbExecFirst(
      'SELECT location_code FROM location_master WHERE id = ?',
      [destination_bin_id],
    );
    if (!sourceRow) throw new Error(`Source bin #${source_bin_id} not found in location_master`);
    if (!destRow) throw new Error(`Destination bin #${destination_bin_id} not found in location_master`);

    const sourceLoc: string = sourceRow.location_code;
    const destLoc: string = destRow.location_code;
    const moveQty: number = Number(qty);

    await job.updateProgress(20);

    // ── 2. Transition task → in_progress ─────────────────────────────────
    await dbExec(
      `UPDATE replen_task SET status = 'in_progress', updated_at = NOW() WHERE id = ? AND status != 'in_progress'`,
      [task_id],
    );

    // ── 3. FEFO allocation from the source bin ───────────────────────────
    const fefo = await allocateFEFO(sku_id, sourceLoc, moveQty);
    if (!fefo.sufficient) {
      // Revert status so it can be retried
      await dbExec(
        `UPDATE replen_task SET status = 'pending', updated_at = NOW() WHERE id = ?`,
        [task_id],
      );
      throw new Error(
        `Insufficient stock at ${sourceLoc} for SKU #${sku_id}. `
        + `Required: ${moveQty}, available: ${fefo.total_available}`,
      );
    }

    await job.updateProgress(40);

    // ── 4. Execute the stock transfer inside a transaction ───────────────
    await withTransaction(async () => {
      let remaining = moveQty;
      let usedBatch: string | null = null;
      let usedExpiry: string | null = null;

      for (const alloc of fefo.allocation) {
        if (remaining <= 1e-9) break;
        const deduct = Math.min(remaining, alloc.quantity);
        const newQty = round6(alloc.quantity - deduct);

        if (!usedBatch) usedBatch = alloc.batch_number;
        if (!usedExpiry) usedExpiry = alloc.expiry_date;

        // Deduct from source stock
        if (newQty <= 1e-9) {
          await dbExec('DELETE FROM stock WHERE id = ?', [alloc.stock_id]);
        } else {
          await dbExec(
            'UPDATE stock SET quantity = ?, updated_at = NOW() WHERE id = ?',
            [newQty, alloc.stock_id],
          );
        }

        // Update stock_locations for the source
        const sl = await dbExecFirst(
          `SELECT id, quantity FROM stock_locations
           WHERE stock_id = ? AND status = 'Available'
           ORDER BY pallet_seq ASC LIMIT 1`,
          [alloc.stock_id],
        );
        if (sl) {
          const slNew = Math.max(0, Number(sl.quantity) - deduct);
          await dbExec(
            'UPDATE stock_locations SET quantity = ?, status = ? WHERE id = ?',
            [slNew, slNew <= 0 ? 'Picked' : 'Available', sl.id],
          );
        }

        remaining = round6(remaining - deduct);
      }

      await job.updateProgress(60);

      // Add stock at destination (pickface)
      const destStock = await dbExecFirst(
        `SELECT id, quantity FROM stock
         WHERE product_id = ? AND location = ?
           AND batch_number <=> ?
           AND stock_status = 'Available'
         LIMIT 1`,
        [sku_id, destLoc, usedBatch],
      );

      if (destStock) {
        await dbExec(
          'UPDATE stock SET quantity = quantity + ?, updated_at = NOW() WHERE id = ?',
          [moveQty, destStock.id],
        );
      } else {
        // Get product info for pallet calc
        const productInfo = await dbExecFirst(
          'SELECT uom_type, uom_per_pallet FROM products WHERE id = ?',
          [sku_id],
        );
        const uom = productInfo?.uom_type ?? 'EA';
        const uomPerPallet = Math.max(1, Number(productInfo?.uom_per_pallet ?? 4));
        const pallet = Math.ceil(moveQty / uomPerPallet);

        await dbExec(
          `INSERT INTO stock
             (product_id, batch_number, location, quantity, uom,
              pallet, expiry_date, stock_status)
           VALUES (?, ?, ?, ?, ?, ?, ?, 'Available')`,
          [sku_id, usedBatch, destLoc, moveQty, uom, pallet, usedExpiry],
        );
      }

      await job.updateProgress(75);

      // ── 5. Stock ledger entries ───────────────────────────────────────
      const currentBalance = Number(
        await dbScalar(
          'SELECT balance FROM stock_ledger WHERE product_id = ? ORDER BY id DESC LIMIT 1',
          [sku_id],
        ) ?? 0,
      );

      const transferNumber = `RPL-${String(task_id).padStart(6, '0')}`;

      // Ledger: transfer out from source
      await dbExec(
        `INSERT INTO stock_ledger
           (transaction_date, product_id, transaction_type, reference_type,
            reference_id, reference_number, batch_number,
            quantity_in, quantity_out, uom, balance, location, notes)
         VALUES (CURDATE(), ?, 'TRANSFER_OUT', 'Replenishment', ?, ?, ?, 0, ?, 'EA', ?, ?, ?)`,
        [sku_id, task_id, transferNumber, usedBatch, moveQty, currentBalance - moveQty,
         sourceLoc, `Replenishment transfer to ${destLoc}`],
      );

      // Ledger: transfer in at destination
      await dbExec(
        `INSERT INTO stock_ledger
           (transaction_date, product_id, transaction_type, reference_type,
            reference_id, reference_number, batch_number,
            quantity_in, quantity_out, uom, balance, location, notes)
         VALUES (CURDATE(), ?, 'TRANSFER_IN', 'Replenishment', ?, ?, ?, ?, 0, 'EA', ?, ?, ?)`,
        [sku_id, task_id, transferNumber, usedBatch, moveQty, currentBalance - moveQty + moveQty,
         destLoc, `Replenishment transfer from ${sourceLoc}`],
      );

      await job.updateProgress(90);

      // ── 6. Mark task completed ────────────────────────────────────────
      await dbExec(
        `UPDATE replen_task
         SET status = 'completed', completed_at = NOW(), updated_at = NOW()
         WHERE id = ?`,
        [task_id],
      );

      // Unblock any outbound items waiting on this replenishment
      await dbExec(
        `UPDATE outbound_items
         SET blocked_on_replen_task_id = NULL
         WHERE blocked_on_replen_task_id = ?`,
        [task_id],
      );
    });

    await job.updateProgress(100);

    error_log(`[ReplenishmentWorker] Task #${task_id} completed successfully`);
    return { task_id, status: 'completed' };
  },
  {
    connection: redisConnection,
    concurrency: 1,
    limiter: {
      max: 10,
      duration: 1000,
    },
  },
);

// ── Worker event handlers ─────────────────────────────────────────────────

replenishmentWorker.on('completed', (job) => {
  error_log(`[ReplenishmentWorker] Job ${job.id} completed for task #${job.data.task_id}`);
});

replenishmentWorker.on('failed', (job, err) => {
  error_log(`[ReplenishmentWorker] Job ${job?.id} failed for task #${job?.data.task_id}: ${err.message}`);
});

replenishmentWorker.on('stalled', (jobId) => {
  error_log(`[ReplenishmentWorker] Job ${jobId} stalled`);
});

// ── Helpers ───────────────────────────────────────────────────────────────

interface FefoAllocationRow {
  stock_id: number;
  batch_number: string | null;
  location: string;
  expiry_date: string | null;
  quantity: number;
}

interface FefoResult {
  allocation: FefoAllocationRow[];
  sufficient: boolean;
  shortage: number;
  total_available: number;
}

/**
 * FEFO allocation scoped to a specific location (source bin).
 * Mirrors Outbound.getFEFOAllocation but filtered to the given location.
 */
async function allocateFEFO(
  productId: number,
  location: string,
  requiredQty: number,
): Promise<FefoResult> {
  const rows: FefoAllocationRow[] = await dbExec(
    `SELECT st.id AS stock_id, st.batch_number, st.location,
            st.quantity, st.expiry_date
     FROM stock st
     WHERE st.product_id = ?
       AND st.location = ?
       AND (st.stock_status IN ('Available','Dues In') OR st.stock_status IS NULL OR st.stock_status = '')
       AND st.quantity > 0
     ORDER BY
       CASE WHEN st.expiry_date IS NULL THEN 1 ELSE 0 END ASC,
       st.expiry_date ASC,
       st.id ASC`,
    [productId, location],
  );

  const allocation: FefoAllocationRow[] = [];
  let remaining = round6(requiredQty);

  for (const row of rows) {
    if (remaining <= 1e-9) break;
    const take = Math.min(remaining, Number(row.quantity));
    allocation.push({
      stock_id: row.stock_id,
      batch_number: row.batch_number,
      location: row.location,
      expiry_date: row.expiry_date,
      quantity: take,
    });
    remaining = round6(remaining - take);
  }

  let totalAvailable = 0;
  for (const row of rows) totalAvailable += Number(row.quantity);

  return {
    allocation,
    sufficient: remaining <= 1e-5,
    shortage: Math.max(0, remaining),
    total_available: totalAvailable,
  };
}

function round6(x: number): number {
  return Math.round(x * 1e6) / 1e6;
}

/**
 * Production-safe logging (mirrors PHP `error_log()`).
 */
function error_log(msg: string): void {
  // eslint-disable-next-line no-console
  console.error(msg);
}
