import { AsyncLocalStorage } from 'node:async_hooks';
import type { Response } from 'express';
import { dbExec, dbExecFirst } from './db';

export interface AppUser {
  id: number;
  username: string;
  full_name: string;
  email: string;
  role: string;
}

export interface ReqContext {
  user: AppUser | null;
  ip: string | null;
  body: any;
  queryParams: Record<string, any>;
  res: Response;
  files?: any[];
}

export const reqStore = new AsyncLocalStorage<ReqContext>();

/** Thrown by `jsonErr`; the router turns it into an error JSON response. */
export class ApiError extends Error {
  status: number;
  constructor(status: number, message: string) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

/** Thrown by `jsonOut` after the response is sent; aborts the request flow. */
export class JsonOutSent extends Error {
  constructor() {
    super('json_out');
    this.name = 'JsonOutSent';
  }
}

export function ctx(): ReqContext {
  const s = reqStore.getStore();
  if (!s) throw new Error('No request context');
  return s;
}

/** PHP `json_out()` — send a success payload and stop (throws JsonOutSent). */
export function jsonOut(payload: Record<string, any>): never {
  const c = ctx();
  if (!c.res.headersSent) c.res.json({ success: true, ...payload });
  throw new JsonOutSent();
}

/** PHP `json_err()` — throw an ApiError to be rendered by the router. */
export function jsonErr(message: string, code = 400): never {
  throw new ApiError(code, message);
}

/** PHP `body()`. */
export function body(): any {
  return ctx().body;
}

/** PHP `query($key, $default)`. */
export function query(key: string, def: any = null): any {
  const v = ctx().queryParams[key];
  return v === undefined || v === null ? def : v;
}

/** First uploaded file (multer), or null. */
export function uploadedFile(): any | null {
  const files = ctx().files ?? [];
  return files[0] ?? null;
}

/** Send a raw buffer/stream response (e.g. Excel download) and stop the flow. */
export function sendBuffer(buffer: Buffer, contentType: string, filename: string): never {
  const c = ctx();
  c.res.setHeader('Content-Type', contentType);
  c.res.setHeader('Content-Disposition', `attachment; filename="${filename}"`);
  c.res.setHeader('Cache-Control', 'max-age=0');
  c.res.end(buffer);
  throw new JsonOutSent();
}

/** PHP `page_params()`. */
export function pageParams(defaultPerPage = 50): [number, number, number] {
  const q = ctx().queryParams;
  const page = Math.max(1, parseInt(q.page ?? '1', 10) || 1);
  let perPage = Math.max(1, parseInt(q.per_page ?? String(defaultPerPage), 10) || defaultPerPage);
  if (perPage > 500) perPage = 500;
  return [page, perPage, (page - 1) * perPage];
}

export function apiRequireAuth(): AppUser {
  const u = ctx().user;
  if (!u) jsonErr('Unauthorized', 401);
  return u as AppUser;
}

const WRITE_ROLES = ['admin', 'operator', 'warehouse', 'supervisor', 'staff'];

export function apiRequireWrite(): AppUser {
  const user = apiRequireAuth();
  if (!WRITE_ROLES.includes(user.role)) {
    jsonErr('Akses ditolak. Role Anda tidak memiliki izin untuk mengubah data.', 403);
  }
  return user;
}

export function apiRequireAdmin(): AppUser {
  const user = apiRequireAuth();
  if (user.role !== 'admin') jsonErr('Akses ditolak. Khusus admin.', 403);
  return user;
}

export function statusesFor(module: string): string[] {
  const map: Record<string, string[]> = {
    inbound: ['Draft', 'Dues In', 'Receiving', 'Good Received', 'Goods Received', 'Unserviceable', 'Picked', 'ATP', 'Completed', 'Cancelled'],
    outbound: ['Open', 'Picking', 'Picked', 'Shipped', 'Delivered', 'Completed', 'Cancelled'],
    picklist: ['Draft', 'Confirmed', 'Picking', 'Picked', 'Completed', 'Cancelled'],
    stocktake: ['Draft', 'In Progress', 'Completed', 'Cancelled'],
    bintransfer: ['Pending', 'Completed', 'Cancelled'],
  };
  return map[module] ?? [];
}

/** Local (Asia/Jakarta) date as YYYY-MM-DD, matching PHP `date('Y-m-d')`. */
export function todayYmd(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/* ------------------------------------------------------------------ */
/* Shared helper queries (ported from api/helpers.php)                 */
/* ------------------------------------------------------------------ */

export async function searchProductsJson(q: string | null): Promise<any[]> {
  q = (q ?? '').trim();
  const like = '%' + q + '%';
  const rows = await dbExec(
    `SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet,
            p.max_sku_qty, p.max_trans_qty, p.liters_per_unit,
            COALESCE(SUM(s.quantity),0) as stock_qty
     FROM products p
     LEFT JOIN stock s ON s.product_id = p.id AND s.stock_status = 'Available'
     WHERE p.is_active = 1
       AND (p.product_code LIKE ? OR p.product_name LIKE ?)
     GROUP BY p.id
     ORDER BY p.product_name
     LIMIT 30`,
    [like, like],
  );
  return rows.map((r: any) => ({
    id: Number(r.id),
    text: r.product_code + ' — ' + r.product_name,
    product_code: r.product_code,
    product_name: r.product_name,
    uom: r.uom_type,
    uom_per_pallet: r.uom_per_pallet,
    liters_per_unit: r.liters_per_unit,
    stock_qty: r.stock_qty,
    max_sku_qty: r.max_sku_qty,
    max_trans_qty: r.max_trans_qty,
  }));
}

export async function activeUsersList(): Promise<any[]> {
  const rows = await dbExec('SELECT id, username, full_name, role FROM users WHERE is_active = 1 ORDER BY full_name');
  return rows.map((u: any) => ({
    id: Number(u.id),
    username: u.username,
    full_name: u.full_name,
    role: u.role,
  }));
}

/** Port of `Product::getAll(2000)` used by products_options(). */
export async function productsOptions(): Promise<any[]> {
  const rows = await dbExec(
    `SELECT p.id, p.product_code, p.product_name, p.uom_type, p.uom_per_pallet
     FROM products p
     GROUP BY p.id
     ORDER BY p.product_name
     LIMIT 2000`,
  );
  return rows.map((p: any) => ({
    id: Number(p.id),
    product_code: p.product_code,
    product_name: p.product_name,
    uom: p.uom_type,
    uom_per_pallet: p.uom_per_pallet,
  }));
}

export async function customersOptions(): Promise<any[]> {
  const rows = await dbExec('SELECT * FROM customers ORDER BY customer_name');
  return rows.map((c: any) => ({
    id: Number(c.id),
    customer_code: c.customer_code,
    customer_name: c.customer_name,
  }));
}

export async function locationOptions(): Promise<any[]> {
  const rows = await dbExec('SELECT location_code, aisle, zone, is_active FROM location_master ORDER BY location_code');
  return rows.map((l: any) => ({
    location_code: l.location_code,
    aisle: l.aisle,
    zone: l.zone,
    is_active: Number(l.is_active),
  }));
}
