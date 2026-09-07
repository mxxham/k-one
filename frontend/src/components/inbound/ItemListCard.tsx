import { Plus, Edit3, CalendarDays, Boxes, MapPin, Layers, ClipboardList, AlertTriangle } from 'lucide-react';
import { Card, EmptyState } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import { TextInput, Select } from '@/components/Field';
import ConfirmButton from '@/components/ConfirmButton';
import ScanInput from '@/components/ScanInput';
import { fmtNum, fmtDate } from '@/lib/format';
import type { ItemDetail, PalletLocation } from '@/pages/InboundDetail';

/* ------------------------------------------------------------------ */
/*  Local helpers (moved from InboundDetail — only used in this table) */
/* ------------------------------------------------------------------ */

const ITEM_STATUSES = ['Dues In', 'Goods Received', 'Unserviceable', 'ATP'];

const ITEM_STATUS_PILL: Record<string, string> = {
  'Dues In': 'bg-amber-50 text-amber-700 border-amber-300',
  'Goods Received': 'bg-emerald-50 text-emerald-700 border-emerald-300',
  ATP: 'bg-emerald-50 text-emerald-700 border-emerald-300',
  Unserviceable: 'bg-red-50 text-red-700 border-red-300',
};

function ItemStatusPill({ status }: { status?: string | null }) {
  const key = status || '—';
  const cls = ITEM_STATUS_PILL[key] || 'bg-gray-100 text-gray-600 border-gray-300';
  return (
    <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${cls}`}>{key}</span>
  );
}

function ActionBtn({ onClick, title, disabled, children }: { onClick: () => void; title: string; disabled?: boolean; children: React.ReactNode }) {
  return (
    <button
      onClick={onClick}
      disabled={disabled}
      title={title}
      className="p-1.5 rounded-lg text-gray-500 hover:text-brand-700 hover:bg-brand-50 border border-transparent hover:border-brand-100 disabled:opacity-40 disabled:pointer-events-none"
    >
      {children}
    </button>
  );
}

/* ------------------------------------------------------------------ */
/*  Types                                                             */
/* ------------------------------------------------------------------ */

export interface DisplayRow {
  item: ItemDetail;
  pallet: PalletLocation | null;
}

interface ItemListCardProps {
  /** All inbound items (used for the empty-state check) */
  items: ItemDetail[];
  /** Flattened display rows (one row per pallet location) */
  displayRows: DisplayRow[];
  /** Whether the current user can edit */
  editable: boolean;
  /** Whether a mutation is in progress */
  busy: boolean;

  /* Scan state */
  scanErr: string | null;
  putawayTarget: ItemDetail | null;
  scanNextStatus: string;
  overrideReason: string;
  scanMatchId: number | null;

  /* Scan callbacks */
  handleScan: (code: string) => void;
  handleOverride: () => void;
  setOverrideReason: (v: string) => void;
  setScanErr: (v: string | null) => void;

  /* Action callbacks */
  openAddItem: () => void;
  openEditQty: (item: ItemDetail) => void;
  openEditDates: (item: ItemDetail) => void;
  openPalletNo: (item: ItemDetail) => void;
  openLoc: (item: ItemDetail) => void;
  openPalletModal: (item: ItemDetail) => void;
  updateItemStatus: (item: ItemDetail, status: string) => void;
  deleteItem: (item: ItemDetail) => void;
  palletCount: (item: ItemDetail) => number;
}

/* ------------------------------------------------------------------ */
/*  Component                                                         */
/* ------------------------------------------------------------------ */

export function ItemListCard({
  items,
  displayRows,
  editable,
  busy,
  scanErr,
  putawayTarget,
  scanNextStatus,
  overrideReason,
  scanMatchId,
  handleScan,
  handleOverride,
  setOverrideReason,
  setScanErr,
  openAddItem,
  openEditQty,
  openEditDates,
  openPalletNo,
  openLoc,
  openPalletModal,
  updateItemStatus,
  deleteItem,
  palletCount,
}: ItemListCardProps) {
  return (
    <Card
      title={`Items (${displayRows.length})`}
      actions={
        editable ? (
          <button
            onClick={openAddItem}
            className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-600 text-white hover:bg-brand-700"
          >
            <Plus className="w-3.5 h-3.5" /> Add Item
          </button>
        ) : undefined
      }
    >
      {editable && (
        <div className="px-3 pt-3">
          <ScanInput onScan={handleScan} placeholder={`Scan SKU → ${putawayTarget ? `${putawayTarget.product_code || ''} → ${scanNextStatus}` : 'semua item selesai'}`} disabled={busy} className="max-w-md" />
          {putawayTarget && (
            <div className="text-[11px] text-gray-400 mt-1">
              Diproses: {putawayTarget.product_code || '—'} · {putawayTarget.product_name || ''} · {putawayTarget.batch_number || '—'}
            </div>
          )}
          {scanErr && (
            <div className="mt-2 rounded-lg border-[1.5px] border-red-200 bg-red-50 p-3">
              <div className="flex items-start gap-2">
                <AlertTriangle className="w-4 h-4 text-red-600 mt-0.5 shrink-0" />
                <div className="text-sm text-red-700">{scanErr}</div>
              </div>
              <div className="flex items-center gap-2 mt-2">
                <TextInput
                  placeholder="Alasan override (wajib)"
                  value={overrideReason}
                  onChange={(ev) => setOverrideReason(ev.target.value)}
                  className="max-w-sm"
                />
                <button
                  type="button"
                  onClick={handleOverride}
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700"
                >
                  Override & Lanjut
                </button>
                <button
                  type="button"
                  onClick={() => { setScanErr(null); setOverrideReason(''); }}
                  className="px-3 py-1.5 text-xs font-semibold rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200"
                >
                  Batal
                </button>
              </div>
            </div>
          )}
        </div>
      )}
      {items.length === 0 ? (
        <EmptyState message="Belum ada item" />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-[11px] uppercase tracking-wide text-gray-500">
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Product</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">OD No</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">SO No</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Batch</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Pallet No</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold text-right">Qty</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">UOM</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold text-right">Actual</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold text-right">Pallet</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Location</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Mfg Date</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Exp Date</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Process</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Stock</th>
                <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold"></th>
              </tr>
            </thead>
            <tbody>
              {displayRows.map((row) => {
                const item = row.item;
                const pallet = row.pallet;
                const rowQty = pallet ? Number(pallet.quantity ?? 0) : Number(item.quantity ?? 0);
                const rowLoc = pallet ? pallet.location_code : (item.location || '—');
                return (
                  <tr key={`${item.id}-${pallet ? pallet.pallet_seq : 0}`} className={`border-t border-gray-100 transition-colors ${scanMatchId === item.id ? 'bg-emerald-50 ring-2 ring-inset ring-emerald-300' : 'hover:bg-brand-50'}`}>
                    <td className="px-3 py-2.5">
                      <div className="font-semibold text-gray-800">{item.product_code}</div>
                      <div className="text-[11px] text-gray-500 truncate max-w-[180px]">{item.product_name || ''}</div>
                    </td>
                    <td className="px-3 py-2.5 text-gray-600">{item.od_number || '—'}</td>
                    <td className="px-3 py-2.5 text-gray-600">{item.so_number || '—'}</td>
                    <td className="px-3 py-2.5 text-gray-600">{item.batch_number || '—'}</td>
                    <td className="px-3 py-2.5 text-gray-600">{item.pallet_no || (pallet ? `Pallet ${pallet.pallet_seq}` : '—')}</td>
                    <td className="px-3 py-2.5 text-gray-700 font-medium text-right">{fmtNum(rowQty, 0)}</td>
                    <td className="px-3 py-2.5 text-gray-600">{item.uom || '—'}</td>
                    <td className="px-3 py-2.5 text-gray-700 font-medium text-right">{fmtNum(rowQty, 0)}</td>
                    <td className="px-3 py-2.5 text-gray-700 font-medium text-right">{pallet ? 1 : fmtNum(palletCount(item), 0)}</td>
                    <td className="px-3 py-2.5 font-mono text-xs text-gray-700">
                      {item.cross_dock_outbound_order_id ? (
                        <div className="flex flex-col gap-0.5">
                          <span className="inline-flex items-center gap-1 text-[11px] font-bold text-violet-700 bg-violet-50 border border-violet-200 rounded-full px-2 py-0.5">
                            <ClipboardList className="w-3 h-3" /> CROSS-DOCK
                          </span>
                          <span className="text-[11px] text-violet-700">→ {item.cross_dock_order_number || item.cross_dock_outbound_order_id} @ STAGING</span>
                        </div>
                      ) : (
                        rowLoc
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-gray-600">{fmtDate(item.manufacture_date)}</td>
                    <td className="px-3 py-2.5 text-gray-600">{fmtDate(item.exp_date)}</td>
                    <td className="px-3 py-2.5">
                      <ItemStatusPill status={item.in_process_status} />
                    </td>
                    <td className="px-3 py-2.5">
                      <StatusBadge status={item.stock_status} />
                    </td>
                    <td className="px-3 py-2.5">
                      {editable ? (
                        <div className="flex items-center gap-1 flex-wrap justify-end">
                          <ActionBtn title="Edit Qty" onClick={() => openEditQty(item)}>
                            <Edit3 className="w-3.5 h-3.5" />
                          </ActionBtn>
                          <ActionBtn title="Edit Dates" onClick={() => openEditDates(item)}>
                            <CalendarDays className="w-3.5 h-3.5" />
                          </ActionBtn>
                          <ActionBtn title="Update Pallet No" onClick={() => openPalletNo(item)}>
                            <Boxes className="w-3.5 h-3.5" />
                          </ActionBtn>
                          <ActionBtn title="Assign Location" onClick={() => openLoc(item)}>
                            <MapPin className="w-3.5 h-3.5" />
                          </ActionBtn>
                          <ActionBtn title="Manage Pallet Locations" onClick={() => openPalletModal(item)}>
                            <Layers className="w-3.5 h-3.5" />
                          </ActionBtn>
                          <Select
                            value={item.in_process_status || ''}
                            onChange={(e) => updateItemStatus(item, e.target.value)}
                            className="!w-32 !py-1 !px-2 text-xs"
                          >
                            {ITEM_STATUSES.map((s) => (
                              <option key={s} value={s}>
                                {s}
                              </option>
                            ))}
                          </Select>
                          <ConfirmButton label="Delete" confirmText="Hapus item ini?" onConfirm={() => deleteItem(item)} />
                        </div>
                      ) : (
                        <span className="text-[11px] text-gray-400">View only</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
