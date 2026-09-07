import { FormEvent, useCallback, useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Search,
  RefreshCw,
  ArrowRightLeft,
  SlidersHorizontal,
  Boxes,
  Layers,
  PackageOpen,
  CalendarClock,
  PackageX,
  Lock,
  Clock,
  FileSpreadsheet,
  ShieldAlert,
  ShieldCheck,
  Eye,
} from 'lucide-react';
import { api, apiHref } from '@/lib/api';
import { WebBtn } from '@/components/WebBtn';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import Modal from '@/components/Modal';
import Spinner from '@/components/Spinner';
import { PageState } from '@/components/PageState';
import { Field, TextInput, Select, TextArea } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { fmtNum, fmtDate, expiryInfo } from '@/lib/format';

const STATUS_OPTIONS = ['Available', 'Reserved', 'Expired', 'Dues In', 'Rejected'];

interface StockRow {
  id: number;
  product_id: number;
  product_code: string;
  product_name: string;
  category: string;
  uom_type: string;
  uom_per_pallet: number;
  velocity_class: string | null;
  batch_number: string;
  location: string;
  quantity: number;
  uom: string;
  pallet: number;
  manufacture_date: string;
  expiry_date: string;
  stock_status: string;
  hold_status: string;
  hold_reason: string;
  hold_by: number | null;
  hold_at: string | null;
}

interface GroupedRow {
  product_id: number;
  product_code: string;
  product_name: string;
  category: string | null;
  uom_type: string | null;
  uom_per_pallet: number | null;
  velocity_class: string | null;
  total_qty: number;
  total_pallet: number;
  location_count: number;
  batch_count: number;
  batches: string[];
  earliest_expiry: string | null;
  latest_expiry: string | null;
  statuses: string[];
  has_hold: boolean;
}

interface StockSummary {
  total_products: number;
  total_drums: number;
  total_pallets: number;
  available_items: number;
  reserved_items: number;
  expired_items: number;
  dues_in_items: number;
  expiring_soon: number;
  critical: number;
  expired: number;
  total_qty: number;
}

interface ByLocationRow {
  area: string;
  products: number;
  total_qty: number;
  total_pallet: number;
}

export default function StockPage() {
  const toast = useToast();
  const { canWrite, canAdmin } = useAuth();
  const [searchParams] = useSearchParams();

  const [rows, setRows] = useState<GroupedRow[]>([]);
  const [summary, setSummary] = useState<StockSummary | null>(null);
  const [byLocation, setByLocation] = useState<ByLocationRow[]>([]);
  const [locations, setLocations] = useState<string[]>([]);

  const [q, setQ] = useState(() => searchParams.get('q') || '');
  const [qApplied, setQApplied] = useState(() => searchParams.get('q') || '');
  const [status, setStatus] = useState('');
  const [location, setLocation] = useState('');
  const [year, setYear] = useState(() => searchParams.get('year') || '');
  const [expiring, setExpiring] = useState(false);
  const [loading, setLoading] = useState(true);

  const [transferRow, setTransferRow] = useState<StockRow | null>(null);
  const [toLoc, setToLoc] = useState('');
  const [tQty, setTQty] = useState('');
  const [adjustRow, setAdjustRow] = useState<StockRow | null>(null);
  const [adjQty, setAdjQty] = useState('');
  const [adjReason, setAdjReason] = useState('');
  const [holdRow, setHoldRow] = useState<StockRow | null>(null);
  const [holdStatus, setHoldStatus] = useState('on_hold');
  const [holdReason, setHoldReason] = useState('');
  const [releaseRow, setReleaseRow] = useState<StockRow | null>(null);
  const [releaseReason, setReleaseReason] = useState('');
  const [detailRow, setDetailRow] = useState<GroupedRow | null>(null);
  const [detailLocations, setDetailLocations] = useState<StockRow[]>([]);
  const [detailLoading, setDetailLoading] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api('stock', 'list_grouped', {
        params: { status, q: qApplied, location, year: year || undefined, expiring: expiring ? '1' : undefined },
      });
      setRows((res.rows || []) as GroupedRow[]);
      if (res.summary) setSummary(res.summary as StockSummary);
    } catch (e: any) {
      toast('error', e.message || 'Gagal memuat data stok');
    } finally {
      setLoading(false);
    }
  }, [status, qApplied, location, year, expiring, toast]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  useEffect(() => {
    const pollInterval = setInterval(async () => {
      if (document.hidden) return;

      try {
        const lastSync = localStorage.getItem('stock_last_sync');
        const params: Record<string, string> = { limit: '100' };
        if (lastSync) params.last_sync = lastSync;

        const res = await api('stock', 'sync', { params });

        if (res.success && res.stocks?.length > 0) {
          loadList();
          localStorage.setItem('stock_last_sync', res.last_sync);
        }
      } catch (err) {
        console.error('Stock sync failed:', err);
      }
    }, 15000);

    return () => clearInterval(pollInterval);
  }, [loadList]);

  const loadSummary = async () => {
    try {
      const res = await api('stock', 'summary');
      setSummary(res.summary as StockSummary);
    } catch {
      // non-fatal
    }
  };

  const loadByLocation = async () => {
    try {
      const res = await api('stock', 'by_location');
      setByLocation((res.rows || []) as ByLocationRow[]);
    } catch {
      // non-fatal
    }
  };

  useEffect(() => {
    api('stock', 'locations')
      .then((res) => setLocations((res.rows || []) as string[]))
      .catch(() => {});
    loadSummary();
    loadByLocation();
  }, []);

  const reload = () => {
    loadList();
    loadSummary();
    loadByLocation();
  };

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    setQApplied(q);
  };

  const openTransfer = (r: GroupedRow) => {
    api('stock', 'list', { params: { q: r.product_code, status: 'Available' } })
      .then((res) => {
        const allRows = (res.rows || []) as StockRow[];
        if (allRows.length > 0) {
          setTransferRow(allRows[0]);
          setDetailLocations(allRows);
        } else {
          toast('error', 'Tidak ada stok tersedia untuk produk ini');
        }
      })
      .catch(() => toast('error', 'Gagal memuat data stok'));
    setToLoc('');
    setTQty('');
  };

  const openAdjust = (r: GroupedRow) => {
    api('stock', 'list', { params: { q: r.product_code } })
      .then((res) => {
        const allRows = (res.rows || []) as StockRow[];
        if (allRows.length > 0) {
          setAdjustRow(allRows[0]);
          setAdjQty(String(allRows[0].quantity ?? ''));
        } else {
          toast('error', 'Tidak ada data stok untuk produk ini');
        }
      })
      .catch(() => toast('error', 'Gagal memuat data stok'));
    setAdjReason('');
  };

  const openHold = (r: GroupedRow) => {
    api('stock', 'list', { params: { q: r.product_code, status: 'Available' } })
      .then((res) => {
        const allRows = (res.rows || []) as StockRow[];
        if (allRows.length > 0) {
          setHoldRow(allRows[0]);
        } else {
          toast('error', 'Tidak ada stok tersedia untuk produk ini');
        }
      })
      .catch(() => toast('error', 'Gagal memuat data stok'));
    setHoldStatus('on_hold');
    setHoldReason('');
  };

  const openRelease = (r: GroupedRow) => {
    api('stock', 'list', { params: { q: r.product_code } })
      .then((res) => {
        const allRows = (res.rows || []) as StockRow[];
        const heldRow = allRows.find((row) => row.hold_status && row.hold_status !== 'available');
        if (heldRow) {
          setReleaseRow(heldRow);
        } else {
          toast('error', 'Tidak ada stok yang di-hold untuk produk ini');
        }
      })
      .catch(() => toast('error', 'Gagal memuat data stok'));
    setReleaseReason('');
  };

  const openDetail = async (r: GroupedRow) => {
    setDetailRow(r);
    setDetailLocations([]);
    setDetailLoading(true);
    try {
      const res = await api('stock', 'list', { params: { q: r.product_code } });
      setDetailLocations((res.rows || []) as StockRow[]);
    } catch {
      setDetailLocations([]);
    } finally {
      setDetailLoading(false);
    }
  };

  const submitTransfer = async (e: FormEvent) => {
    e.preventDefault();
    if (!transferRow) return;
    setSubmitting(true);
    try {
      const body: Record<string, any> = { stock_id: transferRow.id, to_location: toLoc.trim() };
      if (tQty !== '') body.quantity = Number(tQty);
      await api('stock', 'transfer', { method: 'POST', body });
      toast('success', 'Transfer stok berhasil');
      setTransferRow(null);
      reload();
    } catch (err: any) {
      toast('error', err.message || 'Transfer stok gagal');
    } finally {
      setSubmitting(false);
    }
  };

  const submitAdjust = async (e: FormEvent) => {
    e.preventDefault();
    if (!adjustRow) return;
    const qtyNum = Number(adjQty);
    if (adjQty === '' || isNaN(qtyNum)) {
      toast('error', 'Isi jumlah dengan benar');
      return;
    }
    setSubmitting(true);
    try {
      await api('stock', 'adjust', {
        method: 'POST',
        body: { stock_id: adjustRow.id, quantity: qtyNum, reason: adjReason.trim() },
      });
      toast('success', 'Adjustment stok berhasil');
      setAdjustRow(null);
      reload();
    } catch (err: any) {
      toast('error', err.message || 'Adjustment stok gagal');
    } finally {
      setSubmitting(false);
    }
  };

  const submitHold = async (e: FormEvent) => {
    e.preventDefault();
    if (!holdRow) return;
    if (!holdReason.trim()) {
      toast('error', 'Alasan hold wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      await api('stock', 'hold', {
        method: 'POST',
        body: { stock_id: holdRow.id, status: holdStatus, reason: holdReason.trim() },
      });
      toast('success', 'Stok di-hold');
      setHoldRow(null);
      reload();
    } catch (err: any) {
      toast('error', err.message || 'Hold stok gagal');
    } finally {
      setSubmitting(false);
    }
  };

  const submitRelease = async (e: FormEvent) => {
    e.preventDefault();
    if (!releaseRow) return;
    setSubmitting(true);
    try {
      await api('stock', 'release', {
        method: 'POST',
        body: { stock_id: releaseRow.id, reason: releaseReason.trim() },
      });
      toast('success', 'Stok di-release');
      setReleaseRow(null);
      reload();
    } catch (err: any) {
      toast('error', err.message || 'Release stok gagal');
    } finally {
      setSubmitting(false);
    }
  };

  const expiryCls = (level: string): string => {
    switch (level) {
      case 'expired':
        return 'text-red-600 font-semibold';
      case 'critical':
        return 'text-red-600 font-medium';
      case 'warning':
        return 'text-orange-600 font-medium';
      default:
        return 'text-gray-700';
    }
  };

  return (
    <PageState loading={loading} onRetry={loadList} empty={rows.length === 0} emptyMessage="Tidak ada data stok">
    <div>
      <PageHeader
        title="Stock"
        subtitle="Ketersediaan stok, batch, dan lokasi penyimpanan"
        actions={
          <>
            <WebBtn
              href={apiHref('export', 'stock')}
              label="Export Excel"
              tone="dark"
              icon={<FileSpreadsheet className="w-4 h-4" />}
            />
            <button
              onClick={reload}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25"
            >
              <RefreshCw className="w-4 h-4" /> Refresh
            </button>
          </>
        }
      />

      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4 mb-5">
          {[
            { label: 'Total Produk', value: summary.total_products, icon: <Boxes className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
            { label: 'Total Qty', value: summary.total_drums, icon: <Layers className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
            { label: 'Total Pallet', value: summary.total_pallets, icon: <PackageOpen className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
            { label: 'Segera Expire', value: summary.expiring_soon, icon: <CalendarClock className="w-4 h-4" />, cls: 'bg-orange-50 text-orange-600' },
            { label: 'Expired', value: summary.expired, icon: <PackageX className="w-4 h-4" />, cls: 'bg-red-50 text-red-600' },
            { label: 'Reserved', value: summary.reserved_items, icon: <Lock className="w-4 h-4" />, cls: 'bg-indigo-50 text-indigo-600' },
            { label: 'Dues In', value: summary.dues_in_items, icon: <Clock className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
          ].map((c) => (
            <div key={c.label} className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
              <div className={`w-8 h-8 rounded-lg ${c.cls} flex items-center justify-center mb-2`}>{c.icon}</div>
              <div className="text-xl font-bold text-brand-900">{fmtNum(c.value, 0)}</div>
              <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">{c.label}</div>
            </div>
          ))}
        </div>
      )}

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-5">
        <form onSubmit={handleSearch} className="flex flex-wrap items-end gap-3">
          <div className="w-full md:w-72">
            <Field label="Cari">
              <div className="relative">
                <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="Kode / nama produk, batch…"
                  className="w-full pl-9 pr-3 py-2 border-[1.5px] border-gray-300 rounded-lg text-sm focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 outline-none"
                />
              </div>
            </Field>
          </div>
          <div className="w-44">
            <Field label="Status">
              <Select value={status} onChange={(e) => setStatus(e.target.value)}>
                <option value="">Semua</option>
                {STATUS_OPTIONS.map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
          <div className="w-44">
            <Field label="Lokasi">
              <Select value={location} onChange={(e) => setLocation(e.target.value)}>
                <option value="">Semua</option>
                {locations.map((l) => (
                  <option key={l} value={l}>
                    {l}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
          <label className="flex items-center gap-2 pb-2 text-sm text-gray-600 cursor-pointer">
            <input
              type="checkbox"
              checked={expiring}
              onChange={(e) => setExpiring(e.target.checked)}
              className="accent-brand-600 w-4 h-4"
            />
            Hanya segera expire
          </label>
          {year && (
            <div className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-red-50 border border-red-200 text-sm font-semibold text-red-700">
              Expire {year}
              <button
                type="button"
                onClick={() => setYear('')}
                className="w-4 h-4 rounded-full hover:bg-red-200 flex items-center justify-center text-xs"
                title="Hapus filter tahun"
              >
                ✕
              </button>
            </div>
          )}
          <button
            type="submit"
            className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 inline-flex items-center gap-2"
          >
            <Search className="w-4 h-4" /> Cari
          </button>
        </form>
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
        <div className="px-5 py-3.5 border-b border-gray-100 bg-brand-50/50 flex items-center justify-between">
          <h3 className="font-bold text-sm text-brand-700">Daftar Stok</h3>
        </div>
        <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[1200px]">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold">Produk</th>
                  <th className="px-3 py-2.5 text-left font-bold">Kategori</th>
                  <th className="px-3 py-2.5 text-left font-bold">UOM Type</th>
                  <th className="px-3 py-2.5 text-center font-bold">Velocity</th>
                  <th className="px-3 py-2.5 text-left font-bold">Batch</th>
                  <th className="px-3 py-2.5 text-right font-bold">Lokasi</th>
                  <th className="px-3 py-2.5 text-right font-bold">Total Qty</th>
                  <th className="px-3 py-2.5 text-right font-bold">Total Pallet</th>
                  <th className="px-3 py-2.5 text-left font-bold">Expire Terdekat</th>
                  <th className="px-3 py-2.5 text-left font-bold">Status</th>
                  <th className="px-3 py-2.5 text-left font-bold">Hold</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {rows.map((r) => {
                  const exp = expiryInfo(r.earliest_expiry);
                  return (
                    <tr key={r.product_code} className="hover:bg-brand-50/50 cursor-pointer" onClick={() => openDetail(r)}>
                      <td className="px-3 py-2.5">
                        <div className="font-semibold text-brand-800">{r.product_code}</div>
                        <div className="text-xs text-gray-500">{r.product_name}</div>
                      </td>
                      <td className="px-3 py-2.5 text-gray-600">{r.category || '—'}</td>
                      <td className="px-3 py-2.5 text-gray-600">{r.uom_type || '—'}</td>
                      <td className="px-3 py-2.5 text-center">
                        {r.velocity_class ? (
                          <StatusBadge status={r.velocity_class} />
                        ) : (
                          <span className="text-[11px] text-gray-400">—</span>
                        )}
                      </td>
                      <td className="px-3 py-2.5 text-gray-600">
                        {r.batch_count > 2 ? (
                          <span title={r.batches.join(', ')}>{r.batches[0]} +{r.batch_count - 1}</span>
                        ) : r.batches.length > 0 ? (
                          r.batches.join(', ')
                        ) : '—'}
                      </td>
                      <td className="px-3 py-2.5 text-right text-gray-700">{r.location_count}</td>
                      <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(r.total_qty, 0)}</td>
                      <td className="px-3 py-2.5 text-right">{fmtNum(r.total_pallet, 0)}</td>
                      <td className={`px-3 py-2.5 ${expiryCls(exp.level)}`}>
                        <div>{fmtDate(r.earliest_expiry)}</div>
                        {exp.level !== 'none' && <div className="text-[10px] opacity-80">{exp.text}</div>}
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="flex gap-1 flex-wrap">
                          {r.statuses.map((s) => (
                            <StatusBadge key={s} status={s} />
                          ))}
                        </div>
                      </td>
                      <td className="px-3 py-2.5">
                        {r.has_hold ? (
                          <span className="inline-flex px-1.5 py-0.5 rounded-full bg-orange-100 text-orange-700 text-[11px] font-bold">Hold</span>
                        ) : (
                          <span className="text-gray-400">—</span>
                        )}
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
      </div>

      <Card title="Stok per Area">
        {byLocation.length === 0 ? (
          <EmptyState message="Tidak ada data per area" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold">Area</th>
                  <th className="px-3 py-2.5 text-right font-bold">Produk</th>
                  <th className="px-3 py-2.5 text-right font-bold">Total Qty</th>
                  <th className="px-3 py-2.5 text-right font-bold">Total Pallet</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {byLocation.map((b) => (
                  <tr
                    key={b.area}
                    className="hover:bg-brand-50/50 cursor-pointer"
                    onClick={() => { setLocation(b.area); setQApplied(''); }}
                  >
                    <td className="px-3 py-2.5 font-semibold text-brand-800">{b.area || '—'}</td>
                    <td className="px-3 py-2.5 text-right">{fmtNum(b.products, 0)}</td>
                    <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(b.total_qty, 0)}</td>
                    <td className="px-3 py-2.5 text-right">{fmtNum(b.total_pallet, 0)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      <datalist id="stock-locations">
        {locations.map((l) => (
          <option key={l} value={l} />
        ))}
      </datalist>

      <Modal open={!!transferRow} onClose={() => setTransferRow(null)} title="Transfer Stok" size="sm">
        {transferRow && (
          <form onSubmit={submitTransfer} className="space-y-4">
            <div className="bg-brand-50 rounded-lg p-3 text-xs text-brand-800">
              <div className="flex justify-between">
                <span className="text-gray-500">Produk</span>
                <span className="font-semibold">
                  {transferRow.product_code} — {transferRow.product_name}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Batch · Lokasi</span>
                <span className="font-semibold">
                  {transferRow.batch_number || '—'} · {transferRow.location}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Qty tersedia</span>
                <span className="font-semibold">
                  {fmtNum(transferRow.quantity, 0)} {transferRow.uom}
                </span>
              </div>
            </div>
            <Field label="Lokasi Tujuan" required>
              <TextInput
                list="stock-locations"
                value={toLoc}
                onChange={(e) => setToLoc(e.target.value)}
                placeholder="Contoh: A-01-01"
                autoFocus
              />
            </Field>
            <Field label="Jumlah" hint="Kosongkan untuk memindahkan seluruh qty">
              <TextInput type="number" min={0} value={tQty} onChange={(e) => setTQty(e.target.value)} placeholder="Semua" />
            </Field>
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                onClick={() => setTransferRow(null)}
                className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
              >
                Batal
              </button>
              <button
                type="submit"
                disabled={submitting || !toLoc.trim()}
                className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50"
              >
                {submitting ? 'Memproses…' : 'Transfer'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      <Modal open={!!adjustRow} onClose={() => setAdjustRow(null)} title="Adjust Stok" size="sm">
        {adjustRow && (
          <form onSubmit={submitAdjust} className="space-y-4">
            <div className="bg-brand-50 rounded-lg p-3 text-xs text-brand-800">
              <div className="flex justify-between">
                <span className="text-gray-500">Produk</span>
                <span className="font-semibold">
                  {adjustRow.product_code} — {adjustRow.product_name}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Batch · Lokasi</span>
                <span className="font-semibold">
                  {adjustRow.batch_number || '—'} · {adjustRow.location}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Qty saat ini</span>
                <span className="font-semibold">
                  {fmtNum(adjustRow.quantity, 0)} {adjustRow.uom}
                </span>
              </div>
            </div>
            <Field label="Jumlah Baru" required>
              <TextInput type="number" value={adjQty} onChange={(e) => setAdjQty(e.target.value)} placeholder="Qty baru" autoFocus />
            </Field>
            <Field label="Alasan" required>
              <TextArea value={adjReason} onChange={(e) => setAdjReason(e.target.value)} rows={3} placeholder="Alasan adjustment…" />
            </Field>
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                onClick={() => setAdjustRow(null)}
                className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
              >
                Batal
              </button>
              <button
                type="submit"
                disabled={submitting || adjQty === '' || !adjReason.trim()}
                className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50"
              >
                {submitting ? 'Menyimpan…' : 'Simpan'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      <Modal open={!!holdRow} onClose={() => setHoldRow(null)} title="Hold / Quarantine Stok" size="sm">
        {holdRow && (
          <form onSubmit={submitHold} className="space-y-4">
            <div className="bg-orange-50 rounded-lg p-3 text-xs text-orange-800">
              <div className="flex justify-between">
                <span className="text-gray-500">Produk</span>
                <span className="font-semibold">
                  {holdRow.product_code} — {holdRow.product_name}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Batch · Lokasi</span>
                <span className="font-semibold">
                  {holdRow.batch_number || '—'} · {holdRow.location}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Qty</span>
                <span className="font-semibold">
                  {fmtNum(holdRow.quantity, 0)} {holdRow.uom}
                </span>
              </div>
            </div>
            <Field label="Status Hold" required>
              <Select value={holdStatus} onChange={(e) => setHoldStatus(e.target.value)}>
                <option value="on_hold">On Hold</option>
                <option value="quarantine">Quarantine</option>
                <option value="damaged">Damaged</option>
              </Select>
            </Field>
            <Field label="Alasan" required>
              <TextArea value={holdReason} onChange={(e) => setHoldReason(e.target.value)} rows={3} placeholder="Alasan hold / quarantine…" autoFocus />
            </Field>
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                onClick={() => setHoldRow(null)}
                className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
              >
                Batal
              </button>
              <button
                type="submit"
                disabled={submitting || !holdReason.trim()}
                className="px-4 py-2 rounded-lg bg-orange-600 text-white text-sm font-semibold hover:bg-orange-700 disabled:opacity-50"
              >
                {submitting ? 'Menyimpan…' : 'Hold'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      <Modal open={!!releaseRow} onClose={() => setReleaseRow(null)} title="Release Hold Stok" size="sm">
        {releaseRow && (
          <form onSubmit={submitRelease} className="space-y-4">
            <div className="bg-emerald-50 rounded-lg p-3 text-xs text-emerald-800">
              <div className="flex justify-between">
                <span className="text-gray-500">Produk</span>
                <span className="font-semibold">
                  {releaseRow.product_code} — {releaseRow.product_name}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Batch · Lokasi</span>
                <span className="font-semibold">
                  {releaseRow.batch_number || '—'} · {releaseRow.location}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Status hold</span>
                <span className="font-semibold">
                  <StatusBadge status={releaseRow.hold_status} />
                </span>
              </div>
              {releaseRow.hold_reason && (
                <div className="mt-1">
                  <span className="text-gray-500">Alasan hold: </span>
                  <span className="font-semibold">{releaseRow.hold_reason}</span>
                </div>
              )}
            </div>
            <Field label="Alasan Release" hint="Opsional">
              <TextArea value={releaseReason} onChange={(e) => setReleaseReason(e.target.value)} rows={3} placeholder="Alasan release…" autoFocus />
            </Field>
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                onClick={() => setReleaseRow(null)}
                className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
              >
                Batal
              </button>
              <button
                type="submit"
                disabled={submitting}
                className="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50"
              >
                {submitting ? 'Menyimpan…' : 'Release'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      {/* Detail modal */}
      <Modal open={!!detailRow} onClose={() => setDetailRow(null)} title="Detail Stok" size="lg">
        {detailRow && (
          <div className="space-y-5">
            {/* Product Info */}
            <div className="rounded-lg bg-brand-50 p-4">
              <h4 className="text-[11px] uppercase tracking-wide text-brand-600 font-bold mb-3">Informasi Produk</h4>
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                <div>
                  <span className="text-gray-500 text-xs">Kode Produk</span>
                  <div className="font-semibold">{detailRow.product_code}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Nama Produk</span>
                  <div className="font-semibold">{detailRow.product_name}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Kategori</span>
                  <div className="font-semibold">{detailRow.category || '—'}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">UOM Type</span>
                  <div className="font-semibold">{detailRow.uom_type || '—'}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">UOM per Pallet</span>
                  <div className="font-semibold">{fmtNum(detailRow.uom_per_pallet, 0)}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Velocity</span>
                  <div className="font-semibold">
                    {detailRow.velocity_class ? <StatusBadge status={detailRow.velocity_class} /> : '—'}
                  </div>
                </div>
              </div>
            </div>

            {/* Stock Summary */}
            <div className="rounded-lg bg-gray-50 p-4">
              <h4 className="text-[11px] uppercase tracking-wide text-gray-500 font-bold mb-3">Ringkasan Stok</h4>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                <div>
                  <span className="text-gray-500 text-xs">Total Qty</span>
                  <div className="font-semibold">{fmtNum(detailRow.total_qty ?? 0, 0)}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Total Pallet</span>
                  <div className="font-semibold">{fmtNum(detailRow.total_pallet ?? 0, 0)}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Jumlah Lokasi</span>
                  <div className="font-semibold">{detailRow.location_count ?? 0}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Jumlah Batch</span>
                  <div className="font-semibold">{detailRow.batch_count ?? 0}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Expire Terdekat</span>
                  <div className="font-semibold">
                    {(() => { const e = expiryInfo(detailRow.earliest_expiry); return <span className={e.level === 'expired' ? 'text-red-600' : e.level === 'critical' ? 'text-red-500' : e.level === 'warning' ? 'text-orange-500' : ''}>{fmtDate(detailRow.earliest_expiry)} {e.level !== 'none' && <span className="text-xs opacity-70">({e.text})</span>}</span>; })()}
                  </div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Expire Terakhir</span>
                  <div className="font-semibold">{fmtDate(detailRow.latest_expiry)}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Batch</span>
                  <div className="font-semibold text-xs">{(detailRow.batches || []).join(', ') || '—'}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Status</span>
                  <div className="flex gap-1">
                    {(detailRow.statuses || []).map((s: string) => <StatusBadge key={s} status={s} />)}
                  </div>
                </div>
              </div>
            </div>

            {/* All Locations for same product */}
            <div>
              <h4 className="text-[11px] uppercase tracking-wide text-gray-500 font-bold mb-3">
                Semua Lokasi — {detailRow.product_code}
                {!detailLoading && <span className="text-gray-400 font-normal ml-2">({detailLocations.length} baris)</span>}
              </h4>
              {detailLoading ? (
                <Spinner label="Memuat lokasi…" />
              ) : detailLocations.length === 0 ? (
                <EmptyState message="Tidak ada data lokasi" />
              ) : (
                <>
                  {/* Summary */}
                  <div className="flex gap-4 mb-3 text-sm">
                    <div className="rounded-lg bg-brand-50 px-3 py-2">
                      <span className="text-[10px] uppercase text-brand-600 font-bold">Total Qty</span>
                      <div className="font-bold text-brand-800">{fmtNum(detailLocations.reduce((a, r) => a + Number(r.quantity || 0), 0), 0)}</div>
                    </div>
                    <div className="rounded-lg bg-brand-50 px-3 py-2">
                      <span className="text-[10px] uppercase text-brand-600 font-bold">Total Pallet</span>
                      <div className="font-bold text-brand-800">{fmtNum(detailLocations.reduce((a, r) => a + Number(r.pallet || 0), 0), 0)}</div>
                    </div>
                    <div className="rounded-lg bg-brand-50 px-3 py-2">
                      <span className="text-[10px] uppercase text-brand-600 font-bold">Lokasi</span>
                      <div className="font-bold text-brand-800">{detailLocations.length}</div>
                    </div>
                  </div>
                  <div className="overflow-x-auto rounded-lg border border-gray-200">
                    <table className="w-full text-sm">
                      <thead className="bg-brand-50">
                        <tr className="text-left text-[11px] uppercase tracking-wide text-brand-700">
                          <th className="px-3 py-2 font-bold">Lokasi</th>
                          <th className="px-3 py-2 font-bold">Batch</th>
                          <th className="px-3 py-2 text-right font-bold">Qty</th>
                          <th className="px-3 py-2 text-right font-bold">Pallet</th>
                          <th className="px-3 py-2 font-bold">Expire</th>
                          <th className="px-3 py-2 font-bold">Status</th>
                          <th className="px-3 py-2 font-bold">Hold</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100">
                        {detailLocations.map((loc) => {
                          const exp = expiryInfo(loc.expiry_date);
                          return (
                            <tr key={loc.id} className="hover:bg-gray-50">
                              <td className="px-3 py-2 font-mono text-xs font-semibold">{loc.location || '—'}</td>
                              <td className="px-3 py-2">{loc.batch_number || '—'}</td>
                              <td className="px-3 py-2 text-right font-semibold">{fmtNum(loc.quantity, 0)}</td>
                              <td className="px-3 py-2 text-right">{fmtNum(loc.pallet, 0)}</td>
                              <td className={`px-3 py-2 ${exp.level === 'expired' ? 'text-red-600' : exp.level === 'critical' ? 'text-red-500' : exp.level === 'warning' ? 'text-orange-500' : ''}`}>
                                {fmtDate(loc.expiry_date)}
                              </td>
                              <td className="px-3 py-2"><StatusBadge status={loc.stock_status} /></td>
                              <td className="px-3 py-2"><StatusBadge status={loc.hold_status || 'available'} /></td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                </>
              )}
            </div>
          </div>
        )}
      </Modal>
    </div>
    </PageState>
  );
}
