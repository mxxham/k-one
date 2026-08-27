import { db, dbExec } from './db';
import { ctx } from './helpers';

let tableEnsured = false;

async function ensureTable(): Promise<void> {
  if (tableEnsured) return;
  await db().query(`CREATE TABLE IF NOT EXISTS activity_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT          DEFAULT NULL,
    username       VARCHAR(100) DEFAULT NULL,
    full_name      VARCHAR(100) DEFAULT NULL,
    action         VARCHAR(100) NOT NULL,
    module         VARCHAR(50)  NOT NULL,
    reference_type VARCHAR(50)  DEFAULT NULL,
    reference_id   INT          DEFAULT NULL,
    reference_no   VARCHAR(100) DEFAULT NULL,
    description    TEXT         DEFAULT NULL,
    old_value      TEXT         DEFAULT NULL,
    new_value      TEXT         DEFAULT NULL,
    ip_address     VARCHAR(45)  DEFAULT NULL,
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_module  (module),
    INDEX idx_ref     (reference_type, reference_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`);
  tableEnsured = true;
}

/**
 * Port of `ActivityLogger::log()`. Uses the current request's user from the
 * context (PHP used $_SESSION, which api_current_user populated from the token).
 */
export async function activityLog(
  action: string,
  module: string,
  referenceType?: string | null,
  referenceId?: number | null,
  referenceNo?: string | null,
  description?: string | null,
  oldValue?: any,
  newValue?: any,
): Promise<void> {
  try {
    await ensureTable();
    const c = ctx();
    const user = c.user;
    await dbExec(
      `INSERT INTO activity_log
         (user_id, username, full_name, action, module,
          reference_type, reference_id, reference_no,
          description, old_value, new_value, ip_address)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
      [
        user?.id ?? null,
        user?.username ?? null,
        user?.full_name ?? null,
        String(action).toUpperCase(),
        String(module).toLowerCase(),
        referenceType ?? null,
        referenceId ?? null,
        referenceNo ?? null,
        description ?? null,
        oldValue !== undefined && oldValue !== null ? JSON.stringify(oldValue) : null,
        newValue !== undefined && newValue !== null ? JSON.stringify(newValue) : null,
        c.ip ?? null,
      ],
    );
  } catch (e) {
    console.error('[ActivityLogger::log]', e);
  }
}

/** Port of `ActivityLogger::actionLabel()` — $labels map + ucwords fallback. */
export function actionLabel(action: string): string {
  const labels: Record<string, string> = {
    CREATE_INBOUND: 'Buat Inbound',
    UPDATE_INBOUND: 'Edit Inbound',
    DELETE_INBOUND: 'Hapus Inbound',
    ADD_INBOUND_ITEM: 'Tambah Item Inbound',
    DELETE_INBOUND_ITEM: 'Hapus Item Inbound',
    UPDATE_ITEM_STATUS: 'Update Status Item',
    RECEIVE_INBOUND: 'Terima Inbound',
    CREATE_OUTBOUND: 'Buat Outbound',
    UPDATE_OUTBOUND: 'Edit Outbound',
    DELETE_OUTBOUND: 'Hapus Outbound',
    ADD_OUTBOUND_ITEM: 'Tambah Item Outbound',
    DELETE_OUTBOUND_ITEM: 'Hapus Item Outbound',
    PICK_OUTBOUND: 'Pick Outbound',
    SHIP_OUTBOUND: 'Kirim Outbound',
    COMPLETE_OUTBOUND: 'Selesai Outbound',
    BIN_TRANSFER: 'Transfer Bin-to-Bin',
    COMPLETE_BIN_TRANSFER: 'Selesai Bin Transfer',
    CANCEL_BIN_TRANSFER: 'Batal Bin Transfer',
  };
  return (
    labels[action] ??
    action
      .replace(/_/g, ' ')
      .toLowerCase()
      .split(' ')
      .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
      .join(' ')
  );
}

/** Port of `ActivityLogger::moduleIcon()` — $icons map + fallback. */
export function moduleIcon(module: string): string {
  const icons: Record<string, string> = {
    inbound: 'fas fa-arrow-down',
    outbound: 'fas fa-arrow-up',
    bin_transfer: 'fas fa-exchange-alt',
    stock: 'fas fa-boxes',
    user: 'fas fa-user',
  };
  return icons[module] ?? 'fas fa-circle';
}

/** Port of `ActivityLogger::moduleColor()` — $colors map + fallback. */
export function moduleColor(module: string): string {
  const colors: Record<string, string> = {
    inbound: '#014f4e',
    outbound: '#026766',
    bin_transfer: '#026766',
    stock: '#e65100',
    user: '#37474f',
  };
  return colors[module] ?? '#607d8b';
}
