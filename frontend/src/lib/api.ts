export interface User {
  id: number;
  username: string;
  full_name: string;
  email: string;
  role: string;
  department?: string;
}

export type Department = 'inbound' | 'outbound' | 'inventory' | 'ops' | 'all';

// ─── Inbound ─────────────────────────────────────────────────────────────────

export interface InboundOrder {
  id: number;
  order_number: string;
  display_order_no?: string;
  order_date?: string;
  status?: string;
  shipment_no?: string;
  carrier_name?: string;
  line_count?: number;
  total_items?: number;
  total_qty?: number;
  total_pallet?: number;
  cross_dock_count?: number;
  received_date?: string;
  item_count?: number;
  notes?: string;
  created_by_name?: string;
}

export interface InboundStats {
  total?: number;
  this_month?: number;
  pending?: number;
  receiving?: number;
  dues_in?: number;
  by_status?: Array<{ status: string; count: number }>;
}

export interface AsnRow {
  id: number;
  asn_number: string;
  supplier_name?: string;
  status?: string;
  expected_arrival_date?: string;
  total_items?: number;
  notes?: string;
}

// ─── Outbound ────────────────────────────────────────────────────────────────

export interface OutboundOrder {
  id: number;
  order_number: string;
  display_order_no?: string;
  order_date?: string;
  customer_id?: number;
  customer_name?: string;
  so_number?: string;
  do_number?: string;
  shipment_number?: string;
  destination?: string;
  kota?: string;
  armada_no?: string;
  container_no?: string;
  jenis_armada?: string;
  expected_date?: string;
  status?: string;
  shipped_date?: string;
  created_by_name?: string;
  total_items?: number;
  total_qty?: number;
  total_pallet?: number;
  line_count?: number;
  cross_dock_count?: number;
  notes?: string;
  join?: { customer_name?: string };
}

export interface OutboundOrderDetail {
  id: number;
  order_number: string;
  display_order_no?: string;
  order_date?: string;
  customer_id?: number;
  customer_name?: string;
  so_number?: string;
  do_number?: string;
  shipment_number?: string;
  destination?: string;
  kota?: string;
  armada_no?: string;
  container_no?: string;
  jenis_armada?: string;
  expected_date?: string;
  status?: string;
  shipped_date?: string;
  created_by_name?: string;
  notes?: string;
}

export interface OutboundStats {
  total?: number;
  pending?: number;
  this_month?: number;
  by_status?: Array<{ status: string; count: number }>;
}

export interface PicklistRow {
  id: number;
  picklist_no?: string;
  outbound_number?: string;
  status?: string;
  created_date?: string;
  total_items?: number;
  total_qty?: number;
}

export interface PicklistStats {
  pending?: number;
  completed?: number;
}

export interface WaveRow {
  id: number;
  wave_number: string;
  status?: string;
  carrier?: string;
  cutoff_time?: string;
  order_count?: number;
}

// ─── Stock ───────────────────────────────────────────────────────────────────

export interface StockItem {
  id: number;
  product_code: string;
  product_name: string;
  batch_number?: string;
  location?: string;
  quantity?: number;
  uom?: string;
  pallet?: number;
  expiry_date?: string;
  hold_status?: string;
  manufacture_date?: string;
}

export interface StockSummaryRow {
  id: number;
  product_code: string;
  product_name: string;
  uom_type?: string;
  batches?: number;
  total_qty: number;
  total_pallet: number;
  nearest_expiry?: string;
  expiring_count?: number;
}

export interface StockTakeRow {
  id: number;
  take_number?: string;
  take_date?: string;
  status?: string;
  scope?: string;
}

export interface StockTakeStats {
  total?: number;
  this_month?: number;
  avg_accuracy?: number;
}

export interface StockTakeDetailData {
  id: number;
  take_number?: string;
  take_date?: string;
  status?: string;
  notes?: string;
  created_by_name?: string;
}

export interface StockTakeAccuracy {
  accuracy?: number;
  total_stock_take?: number;
  plus?: number;
  minus?: number;
  clear?: number;
}

export interface StockTakeItem {
  id: number;
  product_code?: string;
  product_name?: string;
  batch_number?: string;
  uom?: string;
  location?: string;
  qty_system?: number | null;
  counter_1?: number | null;
  counter_2?: number | null;
  counter_3?: number | null;
  qty_physical?: number | null;
  difference?: number | null;
  status?: string;
  notes?: string;
  counter_by?: string;
}

// ─── Bin Transfer ────────────────────────────────────────────────────────────

export interface BinTransferRow {
  id: number;
  transfer_number: string;
  transfer_date: string;
  product_id: number;
  product_code: string;
  product_name: string;
  batch_number: string;
  from_location: string;
  to_location: string;
  quantity: number;
  uom: string;
  reason: string;
  status: string;
  created_by_name: string;
  completed_by_name: string;
  created_date?: string;
  created_at?: string;
}

// ─── Location ────────────────────────────────────────────────────────────────

export interface Location {
  id: number;
  code: string;
  aisle?: string;
  rack?: string;
  row_name?: string;
  level?: string;
  bin?: string;
  zone?: string;
}

// ─── Product ─────────────────────────────────────────────────────────────────

export interface Product {
  id: number;
  product_code: string;
  product_name: string;
  uom?: string;
  uom_per_pallet?: number;
}

// ─── Supplier ────────────────────────────────────────────────────────────────

export interface Supplier {
  id: number;
  supplier_code?: string;
  supplier_name: string;
}

// ─── Customer ────────────────────────────────────────────────────────────────

export interface Customer {
  id: number;
  customer_code?: string;
  customer_name: string;
}

// ─── Dashboard ───────────────────────────────────────────────────────────────

export interface DashboardStats {
  kpi?: {
    total_qty?: number;
    total_drums?: number;
    total_drums_trend?: number;
    total_pallets?: number;
    total_pallets_utilization?: number;
    total_locations?: number;
    occupied_locations?: number;
    aging_batch_count?: number;
    aging_quantity?: number;
    dues_in?: number;
    receiving_now?: number;
    pending_outbound?: number;
    shipped_today_orders?: number;
    shipped_today_quantity?: number;
    pick_accuracy_percent?: number;
    pick_accurate_lines?: number;
    pick_total_lines?: number;
  };
  stock_summary?: StockSummaryRow[];
  monthly_activity?: MonthlyActivity[];
  stock_by_location?: StockByLocationRow[];
  pending_inbound?: InboundOrder[];
  pending_outbound?: OutboundOrder[];
}

export interface MonthlyActivity {
  month?: string;
  inbound_qty?: number;
  outbound_qty?: number;
}

export interface StockByLocationRow {
  aisle: string;
  total_locs?: number;
  occupied_locs?: number;
  total_qty?: number;
  total_pallet?: number;
}

export interface AisleDetail {
  error?: string;
  stats?: {
    total?: number;
    occupied?: number;
    total_qty?: number;
    total_pallet?: number;
  } | null;
  locations?: AisleLocation[];
}

export interface AisleLocation {
  id?: number;
  code?: string;
  rack?: string;
  row_name?: string;
  zone?: string;
  product?: string;
  product_code?: string;
  qty?: number;
  pallet?: number;
  batch?: string;
  expiry?: string;
  is_partial?: boolean;
  is_eceran?: boolean;
}

export interface AbcStatus {
  classified?: number;
  total?: number;
  last_computed_at?: string;
}

// ─── Activity Log ────────────────────────────────────────────────────────────

export interface ActivityLogRow {
  id: number;
  module?: string;
  module_icon?: string;
  action?: string;
  record_id?: number;
  full_name?: string;
  username?: string;
  old_value?: string;
  new_value?: string;
  created_at?: string;
}

// ─── Replenishment ───────────────────────────────────────────────────────────

export interface ReplSuggestion {
  target_id?: number;
  product_id?: number;
  product_code?: string;
  product_name?: string;
  location_id?: number;
  pick_face_location?: string;
  current_qty?: number;
  min_qty?: number;
  shortage?: number;
}

// ─── Cycle Count ─────────────────────────────────────────────────────────────

export interface CycleCountSchedule {
  id: number;
  schedule_name?: string;
  frequency?: string;
  next_run_date?: string;
  is_due?: boolean | string;
}

// ─── Ledger ──────────────────────────────────────────────────────────────────

export interface LedgerProduct {
  id: number;
  product_code?: string;
  product_name?: string;
}

// ─── Report ──────────────────────────────────────────────────────────────────

export interface ReportData {
  ledger_summary?: {
    transactions_in?: number;
    transactions_out?: number;
    qty_in?: number;
    qty_out?: number;
  };
  stock_summary?: ReportRow[];
  inbound_activity?: ReportRow[];
  outbound_activity?: ReportRow[];
  expiring_items?: ReportRow[];
  low_stock?: ReportRow[];
  [key: string]: unknown;
}

export interface ReportRow {
  id?: number;
  [key: string]: unknown;
}

// ─── Import ──────────────────────────────────────────────────────────────────

export interface PreviewStats {
  total_rows?: number;
  valid_rows?: number;
  invalid_rows?: number;
  [key: string]: unknown;
}

export const DEPARTMENTS: Array<{ key: Department; label: string }> = [
  { key: 'inbound', label: 'Inbound' },
  { key: 'outbound', label: 'Outbound' },
  { key: 'inventory', label: 'Inventory' },
  { key: 'ops', label: 'Operations' },
  { key: 'all', label: 'Semua Departemen (Supervisor)' },
];

/** Home route per department; 'all' (supervisor/admin) gets the combined dashboard. */
export function departmentHome(department?: string): string {
  switch (department) {
    case 'inbound':
      return '/dashboard/inbound';
    case 'outbound':
      return '/dashboard/outbound';
    case 'inventory':
      return '/dashboard/inventory';
    case 'ops':
      return '/putaway-tasks';
    default:
      return '/dashboard';
  }
}

export interface ApiResult<T = any> {
  success: boolean;
  message?: string;
  [key: string]: any;
}

// ─── Pagination ─────────────────────────────────────────────────────────────

/** Standard pagination metadata returned by all paginated API endpoints. */
export interface PaginationMeta {
  page: number;
  per_page: number;
  total: number;
  total_pages: number;
  has_next: boolean;
  has_prev: boolean;
}

/** API result that includes pagination metadata. */
export type PaginatedResult<T = any> = ApiResult<T> & PaginationMeta;

/** Pagination parameters accepted by paginatedApi(). */
export interface PaginationParams {
  page?: number;
  per_page?: number;
  [key: string]: any;
}

/**
 * Call a paginated API endpoint.
 *
 * Automatically injects `page` and `per_page` into query params and returns a
 * strongly-typed `PaginatedResult<T>` that includes both the data and the
 * pagination metadata (`page`, `total`, `total_pages`, `has_next`, etc.).
 *
 * @example
 * ```ts
 * const res = await paginatedApi<StockItem[]>('stock', 'list', { page: 2, per_page: 25 });
 * console.log(res.rows, res.total_pages, res.has_next);
 * ```
 */
export async function paginatedApi<T = any>(
  module: string,
  action: string,
  params: PaginationParams = {},
): Promise<PaginatedResult<T>> {
  return api<T>(module, action, { params }) as Promise<PaginatedResult<T>>;
}

const TOKEN_KEY = 'kone_token';
const USER_KEY = 'kone_user';

export function getToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function setSession(token: string, user: User) {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(USER_KEY, JSON.stringify(user));
}

export function getStoredUser(): User | null {
  try {
    const raw = localStorage.getItem(USER_KEY);
    return raw ? (JSON.parse(raw) as User) : null;
  } catch {
    return null;
  }
}

export function clearSession() {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(USER_KEY);
}

// New K-one v2 backend (NestJS). Dev: proxied via Vite. Prod: served by nginx as /k-one/api.
const DEFAULT_BASE = '';
const BASE = (import.meta.env.VITE_API_BASE as string | undefined) ?? DEFAULT_BASE;

export interface RequestOptions {
  method?: 'GET' | 'POST';
  params?: Record<string, any>;
  body?: any;
  signal?: AbortSignal;
}

async function handleResponse(res: Response): Promise<any> {
  if (res.status === 401) {
    clearSession();
    window.location.href = '/login';
    throw new Error('Session expired');
  }
  let data: any = null;
  try {
    data = await res.json();
  } catch {
    throw new Error(`Server returned invalid JSON (HTTP ${res.status})`);
  }
  if (!res.ok || (data && data.success === false)) {
    const msg = data?.message || `Request failed (HTTP ${res.status})`;
    const err: any = new Error(msg);
    err.status = res.status;
    throw err;
  }
  return data;
}

export async function api<T = any>(module: string, action: string, opts: RequestOptions = {}): Promise<ApiResult<T>> {
  if (opts.body instanceof FormData) {
    const token = getToken();
    const headers: Record<string, string> = {};
    if (token) headers['Authorization'] = `Bearer ${token}`;
    const res = await fetch(`${BASE}/index.php?module=${module}&action=${action}`, {
      method: 'POST',
      headers,
      body: opts.body,
    });
    return handleResponse(res);
  }
  const { method = 'GET', params = {}, body } = opts;
  const token = getToken();

  const query = new URLSearchParams();
  query.set('module', module);
  query.set('action', action);
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') query.set(k, String(v));
  });

  const headers: Record<string, string> = {};
  if (token) headers['Authorization'] = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  // A body implies a mutation — fetch rejects GET/HEAD with a body, so any
  // call site that passes body without an explicit method is treated as POST.
  const useMethod = body !== undefined && method === 'GET' ? 'POST' : method;

  const res = await fetch(`${BASE}/index.php?${query.toString()}`, {
    method: useMethod,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
    signal: opts.signal,
  });

  return handleResponse(res);
}

/** Upload a file (multipart FormData) to a JSON API endpoint. */
export async function uploadApi<T = any>(module: string, action: string, formData: FormData): Promise<ApiResult<T>> {
  return api<T>(module, action, { method: 'POST', body: formData });
}

/**
 * Build an absolute URL to a JSON API endpoint (downloads / print).
 *
 * SECURITY: Tokens must never appear in URL query strings — they are logged
 * by proxies, browsers, and leaked via Referer headers.  For authenticated
 * downloads, use a POST request with the `Authorization` header or set up
 * the server to accept session cookies instead of bearer tokens in URLs.
 */
export function apiHref(module: string, action: string, params?: Record<string, any>): string {
  const q = new URLSearchParams({ module, action });
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v !== undefined && v !== null && v !== '') q.set(k, String(v));
  });
  return `${BASE}/index.php?${q.toString()}`;
}

/** Web root of the PHP server (base URL without the `/api` suffix). */
export function webBase(): string {
  // In dev, Vite runs on :5173 but PHP pages are on :80 — use explicit origin.
  const phpBase = (import.meta.env.VITE_PHP_BASE as string | undefined) ?? '';
  if (phpBase) return phpBase;
  return BASE.replace(/\/api\/?$/, '');
}

/** Open a legacy server-rendered PHP page (print/export) in a new tab. */
export function openWebPage(url: string) {
  window.open(url, '_blank', 'noopener,noreferrer');
}

/** Login special-cased so it works before a token exists. */
export async function loginApi(username: string, password: string) {
  const res = await fetch(`${BASE}/index.php?module=auth&action=login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password }),
  });
  const data = await handleResponse(res);
  if (!data.token) throw new Error('Login gagal');
  setSession(data.token, data.user);
  return data.user as User;
}

export async function logoutApi() {
  const token = getToken();
  if (!token) return;
  try {
    await fetch(`${BASE}/index.php?module=auth&action=logout`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
    });
  } catch {
    // ignore network errors on logout
  }
  clearSession();
}
