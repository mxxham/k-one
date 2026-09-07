import { dbExec, dbExecFirst, dbScalar } from '../db';
import { redisLock } from '../redis/RedisLock';
import { replenishmentQueue, type ReplenishmentJobData } from '../queue/replenishmentQueue';

/**
 * PickfaceSplitter — Auto-replenishment split and trigger logic for outbound order lines.
 *
 * TypeScript port of classes/PickfaceSplitter.php.
 * Splits outbound order quantities into bulk picks and pickface replenishment needs,
 * then triggers replenishment tasks when pickface stock is projected to fall below minimum.
 */

export interface PickfaceConfig {
  id: number;
  sku_id: number;
  pickface_bin_id: number;
  pickface_max: number;
  pickface_min: number;
  pickface_location_code: string;
  row_name: string | null;
  aisle: string | null;
  zone: string | null;
}

export interface SplitResult {
  bulk_qty: number;
  pickface_qty: number;
}

export interface ReplenishmentCheckResult {
  needs_replenishment: boolean;
  projected_on_hand: number;
  pickface_min: number;
  config: PickfaceConfig | null;
}

/**
 * Split an outbound order line quantity into bulk and pickface components.
 *
 * Spec §3:
 *   bulk_qty    = floor(orderQty / pickfaceMax) * pickfaceMax
 *   pickfaceQty = orderQty % pickfaceMax
 */
export function splitOrderLine(orderQty: number, pickfaceMax: number): SplitResult {
  if (pickfaceMax <= 0) {
    throw new Error(`pickfaceMax must be greater than zero, got ${pickfaceMax}`);
  }
  if (orderQty < 0) {
    throw new Error(`orderQty must not be negative, got ${orderQty}`);
  }

  const bulkQty = Math.floor(orderQty / pickfaceMax) * pickfaceMax;
  const pickfaceQty = orderQty % pickfaceMax;

  return {
    bulk_qty: Math.round(bulkQty * 1e6) / 1e6,
    pickface_qty: Math.round(pickfaceQty * 1e6) / 1e6,
  };
}

/**
 * Get pickface configuration for a SKU from sku_pickface_config table.
 */
export async function getPickfaceConfig(skuId: number): Promise<PickfaceConfig | null> {
  const row = await dbExecFirst(
    `SELECT c.id, c.sku_id, c.pickface_bin_id, c.pickface_max, c.pickface_min,
            lm.location_code AS pickface_location_code,
            lm.row_name, lm.aisle, lm.zone
     FROM sku_pickface_config c
     JOIN location_master lm ON lm.id = c.pickface_bin_id
     WHERE c.sku_id = ?
     LIMIT 1`,
    [skuId],
  );

  if (!row) return null;

  return {
    id: Number(row.id),
    sku_id: Number(row.sku_id),
    pickface_bin_id: Number(row.pickface_bin_id),
    pickface_max: Number(row.pickface_max),
    pickface_min: Number(row.pickface_min),
    pickface_location_code: String(row.pickface_location_code),
    row_name: row.row_name ?? null,
    aisle: row.aisle ?? null,
    zone: row.zone ?? null,
  };
}

/**
 * Check if replenishment is needed for a SKU's pickface bin.
 *
 * Spec §4:
 *   projected_on_hand = current_on_hand + in_transit - reserved
 *   Trigger replenishment when projected_on_hand <= pickface_min
 *
 * In-flight dedup: open/incomplete replen_task records for the same SKU
 * and pickface bin are treated as "already received" when computing the trigger.
 */
export async function checkReplenishment(
  skuId: number,
  pickfaceQty: number,
): Promise<ReplenishmentCheckResult> {
  // 1. Get pickface configuration
  const config = await getPickfaceConfig(skuId);

  if (!config) {
    return {
      needs_replenishment: false,
      projected_on_hand: 0,
      pickface_min: 0,
      config: null,
    };
  }

  const pickfaceMin = Number(config.pickface_min);
  const pickfaceBinId = config.pickface_bin_id;

  // 2. Get pickface bin location code
  const locRow = await dbExecFirst(
    'SELECT location_code FROM location_master WHERE id = ? LIMIT 1',
    [pickfaceBinId],
  );
  const pickfaceLocation = locRow?.location_code ?? null;

  if (!pickfaceLocation) {
    return {
      needs_replenishment: false,
      projected_on_hand: 0,
      pickface_min: pickfaceMin,
      config,
    };
  }

  // 3. Compute current_on_hand at pickface bin
  const onHandVal = await dbScalar(
    `SELECT COALESCE(SUM(s.quantity), 0) FROM stock s
     WHERE s.product_id = ?
       AND s.location = ?
       AND s.stock_status = 'Available'
       AND (s.hold_status = 'available' OR s.hold_status IS NULL)
       AND s.quantity > 0`,
    [skuId, pickfaceLocation],
  );
  const currentOnHand = Number(onHandVal ?? 0);

  // 4. Compute in_transit: pending replen_task moving TO this pickface bin
  const inTransitVal = await dbScalar(
    `SELECT COALESCE(SUM(r.qty), 0) FROM replen_task r
     WHERE r.sku_id = ?
       AND r.destination_bin_id = ?
       AND r.status IN ('pending', 'printed', 'in_progress')`,
    [skuId, pickfaceBinId],
  );
  const inTransit = Number(inTransitVal ?? 0);

  // 5. Compute reserved: stock reserved for outbound orders at pickface bin
  const reservedVal = await dbScalar(
    `SELECT COALESCE(SUM(oil.quantity), 0) FROM outbound_item_locations oil
     JOIN outbound_items oi ON oi.id = oil.outbound_item_id
     JOIN stock_locations sl ON sl.id = oil.stock_location_id
     WHERE oi.product_id = ?
       AND sl.location_code = ?
       AND oi.outbound_order_id IS NOT NULL`,
    [skuId, pickfaceLocation],
  );
  const reserved = Number(reservedVal ?? 0);

  // 6. Projected on-hand
  const projectedOnHand = currentOnHand + inTransit - reserved;

  // 7. In-flight dedup: don't trigger a duplicate task if one already exists
  const existingTaskVal = await dbScalar(
    `SELECT COUNT(*) FROM replen_task
     WHERE sku_id = ?
       AND destination_bin_id = ?
       AND status IN ('pending', 'printed', 'in_progress')`,
    [skuId, pickfaceBinId],
  );
  const hasInFlightTask = Number(existingTaskVal ?? 0) > 0;

  // 8. Trigger decision
  const needsReplenishment = projectedOnHand <= pickfaceMin && !hasInFlightTask;

  return {
    needs_replenishment: needsReplenishment,
    projected_on_hand: Math.round(projectedOnHand * 1e6) / 1e6,
    pickface_min: pickfaceMin,
    config,
  };
}

/**
 * Find a suitable source bin for replenishment.
 * Selects from bulk/reserve locations (levels B–E) in FEFO order.
 *
 * @returns Source location_master.id or null if none found
 */
async function findSourceBin(
  skuId: number,
  pickfaceBinId: number,
  neededQty: number,
): Promise<number | null> {
  // Get pickface location code to exclude it
  const pfLocRow = await dbExecFirst(
    'SELECT location_code FROM location_master WHERE id = ? LIMIT 1',
    [pickfaceBinId],
  );
  const pickfaceLocation = pfLocRow?.location_code ?? null;

  if (!pickfaceLocation) return null;

  // Find bulk bins (levels B–E) with sufficient stock, FEFO order
  const row = await dbExecFirst(
    `SELECT s.location, lm.id AS location_id, lm.row_name,
            COALESCE(SUM(s.quantity), 0) AS available_qty
     FROM stock s
     JOIN location_master lm ON lm.location_code = s.location
     WHERE s.product_id = ?
       AND s.stock_status = 'Available'
       AND (s.hold_status = 'available' OR s.hold_status IS NULL)
       AND s.quantity > 0
       AND s.location != ?
       AND s.location NOT IN ('QUA_SHELL', 'STAGING', 'UNALLOCATED')
       AND lm.row_name IN ('B', 'C', 'D', 'E')
       AND lm.is_active = 1
     GROUP BY s.location, lm.id, lm.row_name
     HAVING available_qty >= ?
     ORDER BY
        CASE WHEN s.expiry_date IS NULL THEN 1 ELSE 0 END,
        s.expiry_date ASC,
        s.location ASC,
        s.id ASC
     LIMIT 1`,
    [skuId, pickfaceLocation, neededQty],
  );

  return row ? Number(row.location_id) : null;
}

/**
 * Create a replenishment task to move stock from a bulk bin to the pickface bin.
 *
 * - Inserts a replen_task record with status 'pending'
 * - Enqueues a BullMQ job on the replenishment queue
 * - Acquires and releases a Redis lock on the pickface_bin_id
 *
 * @returns The ID of the created replen_task
 * @throws if no suitable source bin is found or pickface config missing
 */
export async function createReplenTask(
  skuId: number,
  pickfaceQty: number,
  orderId: number | null,
  overrideSourceBinId?: number,
): Promise<number> {
  if (pickfaceQty <= 0) {
    throw new Error(`pickfaceQty must be greater than zero, got ${pickfaceQty}`);
  }

  const config = await getPickfaceConfig(skuId);
  if (!config) {
    throw new Error(`No pickface config found for SKU #${skuId}`);
  }

  const pickfaceBinId = config.pickface_bin_id;

  const existingTaskVal = await dbScalar(
    `SELECT id FROM replen_task
     WHERE sku_id = ?
       AND destination_bin_id = ?
       AND status IN ('pending', 'printed', 'in_progress')
     LIMIT 1`,
    [skuId, pickfaceBinId],
  );
  if (existingTaskVal) {
    const existingId = Number(existingTaskVal);
    console.log(`[PickfaceSplitter] Dedup: SKU #${skuId} already has pending task #${existingId} for bin #${pickfaceBinId}, skipping creation`);
    return existingId;
  }

  const sourceBinId = overrideSourceBinId ?? await findSourceBin(skuId, pickfaceBinId, Math.ceil(pickfaceQty));

  if (!sourceBinId) {
    throw new Error(
      `No suitable source bin found for replenishment of SKU #${skuId}, qty ${pickfaceQty}`,
    );
  }

  // 4. Acquire Redis lock on pickface_bin_id
  const lockKey = `pickface:${pickfaceBinId}:${skuId}`;
  await redisLock.acquire(lockKey, 30);

  try {
    // 5. Insert replen_task record (no nested transaction — caller already owns one)
    const ins = await dbExec(
      `INSERT INTO replen_task
         (sku_id, source_bin_id, destination_bin_id, qty, triggering_order_id, status, created_at)
       VALUES (?, ?, ?, ?, ?, 'pending', NOW())`,
      [skuId, sourceBinId, pickfaceBinId, Math.ceil(pickfaceQty), orderId],
    );
    const taskId = Number((ins as any).insertId);

    // 6. Enqueue BullMQ job
    const jobData: ReplenishmentJobData = {
      task_id: taskId,
      sku_id: skuId,
      source_bin_id: sourceBinId,
      destination_bin_id: pickfaceBinId,
      qty: Math.ceil(pickfaceQty),
    };
    await replenishmentQueue.add('replenish', jobData, {
      jobId: `replen-task-${taskId}`,
    });

    return taskId;
  } finally {
    // 7. Release Redis lock
    await redisLock.release(lockKey);
  }
}
