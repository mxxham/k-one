import Redis from 'ioredis';
import { redisConnection } from '../queue/redisConnection';

/**
 * RedisLock — distributed lock using Redis SET NX EX pattern.
 * Prevents concurrent operations on shared resources (e.g. pickface bins).
 *
 * Pattern: SET lock:{key} 1 NX EX {ttl}
 *   - NX: only set if not exists (atomic acquire)
 *   - EX: auto-expire after ttl seconds (safety net against stale locks)
 *
 * Atomic release uses a Lua script to verify the caller owns the lock
 * before deleting it, preventing accidental release of someone else's lock.
 */
class RedisLock {
  private client: Redis;

  /** Lua script: check value matches, then delete — atomic */
  private static readonly RELEASE_SCRIPT = `
    if redis.call("GET", KEYS[1]) == ARGV[1] then
      return redis.call("DEL", KEYS[1])
    else
      return 0
    end
  `;

  /** Unique owner token to prevent releasing someone else's lock */
  private readonly ownerId: string;

  constructor() {
    this.client = new Redis(redisConnection);
    this.ownerId = `lock-owner-${process.pid}-${Date.now()}`;
  }

  /**
   * Acquire a distributed lock for the given key.
   *
   * @param key       Resource identifier (e.g. pickface bin ID)
   * @param ttlSeconds Lock duration in seconds (default 30)
   * @returns true if lock acquired, false if already held
   */
  async acquire(key: string, ttlSeconds: number = 30): Promise<boolean> {
    const lockKey = `lock:${key}`;
    const result = await this.client.set(lockKey, this.ownerId, 'EX', ttlSeconds, 'NX');
    return result === 'OK';
  }

  /**
   * Release a distributed lock. Only succeeds if the current instance owns it.
   *
   * @param key Resource identifier (e.g. pickface bin ID)
   */
  async release(key: string): Promise<void> {
    const lockKey = `lock:${key}`;
    await this.client.eval(RedisLock.RELEASE_SCRIPT, 1, lockKey, this.ownerId);
  }

  /**
   * Check if a lock exists for the given key.
   *
   * @param key Resource identifier (e.g. pickface bin ID)
   * @returns true if lock exists, false otherwise
   */
  async isLocked(key: string): Promise<boolean> {
    const lockKey = `lock:${key}`;
    const result = await this.client.exists(lockKey);
    return result === 1;
  }

  /**
   * Close the Redis connection. Call on shutdown.
   */
  async disconnect(): Promise<void> {
    await this.client.quit();
  }
}

/** Singleton — shared across the process */
export const redisLock = new RedisLock();
