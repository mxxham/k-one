import mysql from 'mysql2/promise';
import type { Connection, Pool } from 'mysql2/promise';
import { AsyncLocalStorage } from 'node:async_hooks';

/**
 * Mirrors the PHP global `db()` PDO singleton.
 *
 * `withTransaction` runs `fn` with a dedicated connection bound to the
 * AsyncLocalStorage store, so every nested `dbExec` inside the transaction
 * uses that same connection — exactly like PHP's single PDO connection.
 */
export const txStore = new AsyncLocalStorage<Connection>();

let pool: Pool | null = null;

export function db(): Pool {
  if (!pool) {
    pool = mysql.createPool({
      host: process.env.DB_HOST || 'localhost',
      port: Number(process.env.DB_PORT) || 3306,
      user: process.env.DB_USER || 'root',
      password: process.env.DB_PASS || '',
      database: process.env.DB_NAME || 'sanchaya',
      charset: 'utf8mb4',
      waitForConnections: true,
      connectionLimit: 10,
      dateStrings: true,
    });
  }
  return pool;
}

/**
 * Run a prepared statement. Inside a transaction, the statement runs on the
 * transaction connection; otherwise it uses the pool. Returns the raw result
 * (RowDataPacket[] for SELECT, OkPacket for INSERT/UPDATE/DELETE).
 */
export async function dbExec(sql: string, params: any[] = []): Promise<any> {
  const conn = txStore.getStore();
  const [rows] = conn
    ? await conn.execute(sql, params)
    : await db().execute(sql, params);
  return rows;
}

export async function dbExecFirst(sql: string, params: any[] = []): Promise<any> {
  const rows = await dbExec(sql, params);
  if (Array.isArray(rows) && rows.length) return rows[0];
  return null;
}

/** Returns the first column of the first row, or null. */
export async function dbScalar(sql: string, params: any[] = []): Promise<any> {
  const row = await dbExecFirst(sql, params);
  if (!row) return null;
  return row[Object.keys(row)[0]];
}

export async function withTransaction<T>(fn: () => Promise<T>): Promise<T> {
  const conn = await db().getConnection();
  try {
    await conn.beginTransaction();
    const result = await txStore.run(conn, fn);
    await conn.commit();
    return result;
  } catch (e) {
    await conn.rollback();
    throw e;
  } finally {
    conn.release();
  }
}

/** True when the current async context is inside a transaction. */
export function inTx(): boolean {
  return !!txStore.getStore();
}

/**
 * Run `fn` in a transaction only when one isn't already open on this context
 * (mirrors PHP's `$ownsTransaction` pattern). Nested calls reuse the outer tx.
 */
export async function withTransactionOwned<T>(fn: () => Promise<T>): Promise<T> {
  if (inTx()) return fn();
  return withTransaction(fn);
}
