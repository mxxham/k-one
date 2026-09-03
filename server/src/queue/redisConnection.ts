import type { RedisOptions } from 'ioredis';

/**
 * Shared Redis connection options for BullMQ producers and consumers.
 * Reads from environment variables with sensible defaults.
 */
export const redisConnection: RedisOptions = {
  host: process.env.REDIS_HOST || '127.0.0.1',
  port: Number(process.env.REDIS_PORT) || 6379,
  password: process.env.REDIS_PASSWORD || undefined,
  maxRetriesPerRequest: null,
};
