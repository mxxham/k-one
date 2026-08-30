<?php
declare(strict_types=1);

/**
 * StockLock — pessimistic locking for concurrent stock operations.
 * Prevents double-pick, overselling, and race conditions on stock records.
 *
 * Uses SELECT ... FOR UPDATE within transactions to acquire row-level locks.
 * Lock ordering by stock ID prevents deadlocks in batch operations.
 */
class StockLock
{
    /**
     * Acquire a lock on a specific stock record.
     * Uses SELECT ... FOR UPDATE within a transaction.
     *
     * @param int    $stockId  The stock ID to lock
     * @param string $operation What operation needs the lock (for debugging)
     * @param int    $timeout  Seconds to wait for lock (MySQL innodb_lock_wait_timeout hint)
     * @return array The locked stock record with stock_locations joined
     * @throws StockException if stock record not found
     */
    public static function acquireLock(int $stockId, string $operation = 'unknown', int $timeout = 5): array
    {
        $db = db();

        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }

        try {
            $stmt = $db->prepare("
                SELECT s.*, sl.lpn_code, sl.status AS location_status
                FROM stock s
                LEFT JOIN stock_locations sl ON sl.stock_id = s.id
                WHERE s.id = ?
                FOR UPDATE
            ");
            $stmt->execute([$stockId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new StockException("Stock #{$stockId} not found", [
                    'stock_id'   => $stockId,
                    'operation'  => $operation,
                ]);
            }

            return $row;
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Acquire locks on multiple stock records (for batch operations).
     * Locks in ascending ID order to prevent deadlocks.
     *
     * @param int[]  $stockIds Array of stock IDs to lock
     * @param string $operation What operation needs the lock
     * @return array[] Locked stock records in the same order as input IDs
     */
    public static function acquireMultipleLocks(array $stockIds, string $operation = 'unknown'): array
    {
        if (empty($stockIds)) {
            return [];
        }

        // Sort IDs ascending to prevent deadlocks
        $sorted = $stockIds;
        sort($sorted, SORT_NUMERIC);

        $db = db();
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }

        try {
            $locked = [];
            $stmt = $db->prepare("
                SELECT s.*, sl.lpn_code, sl.status AS location_status
                FROM stock s
                LEFT JOIN stock_locations sl ON sl.stock_id = s.id
                WHERE s.id = ?
                FOR UPDATE
            ");

            foreach ($sorted as $stockId) {
                $stmt->execute([$stockId]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $locked[$stockId] = $row;
                }
            }

            return $locked;
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Execute a callback within a locked context.
     * Automatically handles transaction and lock lifecycle.
     *
     * The callback receives the locked rows (keyed by stock ID) and its return
     * value is passed through. The transaction commits when the callback returns
     * successfully, or rolls back on any exception.
     *
     * @param int[]    $stockIds Array of stock IDs to lock
     * @param callable $callback fn(array<int, array> $lockedRows): mixed
     * @param string   $operation Operation name for debugging
     * @return mixed The callback's return value
     */
    public static function withLock(array $stockIds, callable $callback, string $operation = 'unknown'): mixed
    {
        $db = db();
        $ownTx = !$db->inTransaction();
        if ($ownTx) {
            $db->beginTransaction();
        }

        try {
            $locked = self::acquireMultipleLocks($stockIds, $operation);

            $result = $callback($locked);

            if ($ownTx) {
                $db->commit();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($ownTx && $db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Release all locks by committing the current transaction.
     * Only commits if we are in a transaction; no-op otherwise.
     */
    public static function releaseLocks(): void
    {
        $db = db();
        if ($db->inTransaction()) {
            $db->commit();
        }
    }
}
