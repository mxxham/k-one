import { Queue } from 'bullmq';
import { redisConnection } from './redisConnection';

/**
 * BullMQ Queue for replenishment tasks.
 * Producers add jobs here; the replenishmentWorker consumes them.
 */
export const replenishmentQueue = new Queue('replenishment', {
  connection: redisConnection,
  defaultJobOptions: {
    attempts: 3,
    backoff: {
      type: 'exponential',
      delay: 2000,
    },
    removeOnComplete: { count: 100 },
    removeOnFail: { count: 50 },
  },
});

/**
 * Shape of a replenishment job payload.
 */
export interface ReplenishmentJobData {
  /** FK replen_task.id */
  task_id: number;
  /** FK products.id */
  sku_id: number;
  /** FK location_master.id — bulk source bin */
  source_bin_id: number;
  /** FK location_master.id — pickface destination bin */
  destination_bin_id: number;
  /** Quantity to move */
  qty: number;
}
