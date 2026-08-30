import { FormEvent, useCallback, useEffect, useState } from 'react';
import {
  RefreshCw,
  AlertTriangle,
  CheckCircle2,
  Search,
  Filter,
  FileSpreadsheet,
  ArrowUpDown,
  ChevronDown,
  XCircle,
  History,
  BarChart3,
  ClipboardCheck,
} from 'lucide-react';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import Spinner from '@/components/Spinner';
import Modal from '@/components/Modal';
import { Field, TextInput, TextArea } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { fmtNum, fmtDate, fmtDateTime } from '@/lib/format';

// ─── Interfaces ──────────────────────────────────────────────────────────────

interface Discrepancy {
  stock_id: number;
  product_id: number;
  product_code: string;
  product_name: string;
  location: string;
  expected_qty: number;
  actual_qty: number;
  variance: number;
  variance_percent: number;
  batch_number: string;
  expiry_date: string;
}

interface VarianceReport {
  stock_id: number;
  product_code: string;
  product_name: string;
  location: string;
  current_qty: number;
  ledger_balance: number;
  total_in: number;
  total_out: number;
  transaction_count: number;
}

interface ReconciliationHistory {
  id: number;
  stock_id: number;
  product_code: string;
  location: string;
  old_qty: number;
  new_qty: number;
  variance: number;
  reason: string;
  adjusted_by_name: string;
  created_at: string;
}

type TabType = 'discrepancies' | 'variance' | 'history';

// ─── Component ───────────────────────────────────────────────────────────────

const StockReconciliationPage: React.FC = () => {
  const toast = useToast();
  const { canWrite } = useAuth();
  const [activeTab, setActiveTab] = useState<TabType>('discrepancies');

  // ── Discrepancies ──
  const [discrepancies, setDiscrepancies] = useState<Discrepancy[]>([]);
  const [discrepancyLoading, setDiscrepancyLoading] = useState(false);

  // ── Variance Report ──
  const [varianceRows, setVarianceRows] = useState<VarianceReport[]>([]);
  const [varianceLoading, setVarianceLoading] = useState(false);
  const [varianceFilter, setVarianceFilter] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');

  // ── History ──
  const [historyRows, setHistoryRows] = useState<ReconciliationHistory[]>([]);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [historyFrom, setHistoryFrom] = useState('');
  const [historyTo, setHistoryTo] = useState('');

  // ── Reconcile Modal ──
  const [reconcileRow, setReconcileRow] = useState<Discrepancy | null>(null);
  const [actualQty, setActualQty] = useState('');
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // ── Sort state ──
  const [sortKey, setSortKey] = useState<keyof Discrepancy | null>(null);
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('asc');

  // ── Load Discrepancies ──
  const loadDiscrepancies = useCallback(async () => {
    setDiscrepancyLoading(true);
    try {
      const res = await api('stock', 'discrepancies');
      setDiscrepancies((res.rows || []) as Discrepancy[]);
    } catch (err: any) {
      toast('error', err.message || 'Gagal memuat data discrepancy');
    } finally {
      setDiscrepancyLoading(false);
    }
  }, [toast]);

  // ── Load Variance Report ──
  const loadVariance = useCallback(async () => {
    setVarianceLoading(true);
    try {
      const params: Record<string, any> = {};
      if (varianceFilter) params.product_id = varianceFilter;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;
      const res = await api('stock', 'reconcile_report', { params });
      setVarianceRows((res.rows || []) as VarianceReport[]);
    } catch (err: any) {
      toast('error', err.message || 'Gagal memuat variance report');
    } finally {
      setVarianceLoading(false);
    }
  }, [toast, varianceFilter, dateFrom, dateTo]);

  // ── Load History ──
  const loadHistory = useCallback(async () => {
    setHistoryLoading(true);
    try {
      const params: Record<string, any> = {};
      if (historyFrom) params.date_from = historyFrom;
      if (historyTo) params.date_to = historyTo;
      const res = await api('stock', 'reconcile_report', { params });
      setHistoryRows((res.rows || []) as ReconciliationHistory[]);
    } catch (err: any) {
      toast('error', err.message || 'Gagal memuat riwayat');
    } finally {
      setHistoryLoading(false);
    }
  }, [toast, historyFrom, historyTo]);

  // ── Initial load ──
  useEffect(() => {
    loadDiscrepancies();
  }, [loadDiscrepancies]);

  useEffect(() => {
    if (activeTab === 'variance') loadVariance();
    if (activeTab === 'history') loadHistory();
  }, [activeTab, loadVariance, loadHistory]);

  // ── Reconcile submit ──
  const submitReconcile = async (e: FormEvent) => {
    e.preventDefault();
    if (!reconcileRow) return;
    const qty = Number(actualQty);
    if (isNaN(qty) || qty < 0) {
      toast('error', 'Jumlah aktual harus angka >= 0');
      return;
    }
    if (!reason.trim()) {
      toast('error', 'Alasan wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      await api('stock', 'reconcile', {
        method: 'POST',
        body: { stock_id: reconcileRow.stock_id, actual_qty: qty, reason: reason.trim() },
      });
      toast('success', 'Reconcile berhasil');
      setReconcileRow(null);
      setActualQty('');
      setReason('');
      loadDiscrepancies();
    } catch (err: any) {
      toast('error', err.message || 'Reconcile gagal');
    } finally {
      setSubmitting(false);
    }
  };

  // ── Sort handler ──
  const handleSort = (key: keyof Discrepancy) => {
    if (sortKey === key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortKey(key);
      setSortDir('asc');
    }
  };

  const sortedDiscrepancies = [...discrepancies].sort((a, b) => {
    if (!sortKey) return 0;
    const aVal = a[sortKey];
    const bVal = b[sortKey];
    if (typeof aVal === 'number' && typeof bVal === 'number') {
      return sortDir === 'asc' ? aVal - bVal : bVal - aVal;
    }
    const aStr = String(aVal ?? '');
    const bStr = String(bVal ?? '');
    return sortDir === 'asc' ? aStr.localeCompare(bStr) : bStr.localeCompare(aStr);
  });

  // ── Summary counts ──
  const totalDiscrepancies = discrepancies.length;
  const autoFixable = discrepancies.filter((d) => Math.abs(d.variance) <= 5).length;
  const needsReview = discrepancies.filter((d) => Math.abs(d.variance) > 5).length;

  // ── Sort icon ──
  const SortIcon = ({ col }: { col: keyof Discrepancy }) => (
    <ArrowUpDown className={`w-3 h-3 inline-block ml-1 ${sortKey === col ? 'text-brand-600' : 'text-gray-400'}`} />
  );

  return (
    <div>
      <PageHeader
        title="Stock Reconciliation"
        subtitle="Deteksi dan perbaiki selisih inventaris"
        actions={
          <button
            onClick={() => {
              loadDiscrepancies();
              if (activeTab === 'variance') loadVariance();
              if (activeTab === 'history') loadHistory();
            }}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25"
          >
            <RefreshCw className="w-4 h-4" /> Refresh
          </button>
        }
      />

      {/* ── Tabs ── */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
        <div className="border-b border-gray-100">
          <div className="flex">
            {[
              { id: 'discrepancies' as const, label: 'Discrepancies', icon: AlertTriangle, count: totalDiscrepancies },
              { id: 'variance' as const, label: 'Variance Report', icon: BarChart3, count: varianceRows.length },
              { id: 'history' as const, label: 'History', icon: History, count: historyRows.length },
            ].map(({ id, label, icon: Icon, count }) => (
              <button
                key={id}
                onClick={() => setActiveTab(id)}
                className={`flex items-center gap-2 px-6 py-4 font-medium transition-colors border-b-2 ${
                  activeTab === id
                    ? 'border-brand-600 text-brand-600 bg-brand-50/50'
                    : 'border-transparent text-gray-600 hover:text-gray-900'
                }`}
              >
                <Icon className="w-4 h-4" />
                {label}
                {count > 0 && (
                  <span className="ml-1 px-2 py-0.5 bg-gray-100 text-gray-600 rounded-full text-xs font-semibold">
                    {count}
                  </span>
                )}
              </button>
            ))}
          </div>
        </div>

        <div className="p-5">
          {/* ── Discrepancies Tab ── */}
          {activeTab === 'discrepancies' && (
            <div>
              {/* Summary Cards */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-5">
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-lg bg-brand-50 flex items-center justify-center">
                      <AlertTriangle className="w-5 h-5 text-brand-600" />
                    </div>
                    <div>
                      <div className="text-2xl font-bold text-brand-900">{fmtNum(totalDiscrepancies, 0)}</div>
                      <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">Total Discrepancies</div>
                    </div>
                  </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-lg bg-emerald-50 flex items-center justify-center">
                      <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                    </div>
                    <div>
                      <div className="text-2xl font-bold text-emerald-900">{fmtNum(autoFixable, 0)}</div>
                      <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">Auto-fixable</div>
                    </div>
                  </div>
                </div>
                <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
                  <div className="flex items-center gap-3">
                    <div className="w-10 h-10 rounded-lg bg-orange-50 flex items-center justify-center">
                      <XCircle className="w-5 h-5 text-orange-600" />
                    </div>
                    <div>
                      <div className="text-2xl font-bold text-orange-900">{fmtNum(needsReview, 0)}</div>
                      <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">Needs Review</div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Table */}
              <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div className="px-5 py-3.5 border-b border-gray-100 bg-brand-50/50 flex items-center justify-between">
                  <h3 className="font-bold text-sm text-brand-700">Daftar Discrepancies</h3>
                </div>
                {discrepancyLoading ? (
                  <Spinner label="Memuat data…" />
                ) : discrepancies.length === 0 ? (
                  <EmptyState message="Tidak ada discrepancy ditemukan" />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[900px]">
                      <thead>
                        <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                          <th className="px-3 py-2.5 text-left font-bold cursor-pointer select-none" onClick={() => handleSort('product_code')}>
                            Product <SortIcon col="product_code" />
                          </th>
                          <th className="px-3 py-2.5 text-left font-bold cursor-pointer select-none" onClick={() => handleSort('location')}>
                            Location <SortIcon col="location" />
                          </th>
                          <th className="px-3 py-2.5 text-right font-bold cursor-pointer select-none" onClick={() => handleSort('expected_qty')}>
                            Expected <SortIcon col="expected_qty" />
                          </th>
                          <th className="px-3 py-2.5 text-right font-bold cursor-pointer select-none" onClick={() => handleSort('actual_qty')}>
                            Actual <SortIcon col="actual_qty" />
                          </th>
                          <th className="px-3 py-2.5 text-right font-bold cursor-pointer select-none" onClick={() => handleSort('variance')}>
                            Variance <SortIcon col="variance" />
                          </th>
                          <th className="px-3 py-2.5 text-right font-bold">%</th>
                          <th className="px-3 py-2.5 text-left font-bold">Batch</th>
                          <th className="px-3 py-2.5 text-center font-bold">Action</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100">
                        {sortedDiscrepancies.map((d) => (
                          <tr key={d.stock_id} className="hover:bg-brand-50/50">
                            <td className="px-3 py-2.5">
                              <div className="font-semibold text-brand-800">{d.product_code}</div>
                              <div className="text-xs text-gray-500">{d.product_name}</div>
                            </td>
                            <td className="px-3 py-2.5 font-mono text-xs">{d.location}</td>
                            <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(d.expected_qty, 0)}</td>
                            <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(d.actual_qty, 0)}</td>
                            <td className="px-3 py-2.5 text-right">
                              <span className={`font-bold ${d.variance < 0 ? 'text-red-600' : d.variance > 0 ? 'text-emerald-600' : 'text-gray-700'}`}>
                                {d.variance > 0 ? '+' : ''}{fmtNum(d.variance, 0)}
                              </span>
                            </td>
                            <td className="px-3 py-2.5 text-right">
                              <span className={`text-xs font-medium ${d.variance_percent < 0 ? 'text-red-600' : d.variance_percent > 0 ? 'text-emerald-600' : 'text-gray-700'}`}>
                                {d.variance_percent > 0 ? '+' : ''}{fmtNum(d.variance_percent, 1)}%
                              </span>
                            </td>
                            <td className="px-3 py-2.5 text-gray-600 text-xs">{d.batch_number || '—'}</td>
                            <td className="px-3 py-2.5 text-center">
                              {canWrite && (
                                <button
                                  onClick={() => {
                                    setReconcileRow(d);
                                    setActualQty(String(d.expected_qty));
                                    setReason('');
                                  }}
                                  className="px-3 py-1.5 rounded-lg bg-brand-600 text-white text-xs font-semibold hover:bg-brand-700 inline-flex items-center gap-1"
                                >
                                  <ClipboardCheck className="w-3.5 h-3.5" /> Reconcile
                                </button>
                              )}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* ── Variance Report Tab ── */}
          {activeTab === 'variance' && (
            <div>
              {/* Filters */}
              <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-5">
                <div className="flex flex-wrap items-end gap-3">
                  <div className="w-44">
                    <Field label="Dari Tanggal">
                      <TextInput type="date" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
                    </Field>
                  </div>
                  <div className="w-44">
                    <Field label="Sampai Tanggal">
                      <TextInput type="date" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
                    </Field>
                  </div>
                  <div className="w-48">
                    <Field label="Filter Produk">
                      <TextInput value={varianceFilter} onChange={(e) => setVarianceFilter(e.target.value)} placeholder="Product ID…" />
                    </Field>
                  </div>
                  <button
                    onClick={loadVariance}
                    className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 inline-flex items-center gap-2"
                  >
                    <Search className="w-4 h-4" /> Filter
                  </button>
                </div>
              </div>

              {/* Variance Table */}
              <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div className="px-5 py-3.5 border-b border-gray-100 bg-brand-50/50 flex items-center justify-between">
                  <h3 className="font-bold text-sm text-brand-700">Variance Report</h3>
                </div>
                {varianceLoading ? (
                  <Spinner label="Memuat data…" />
                ) : varianceRows.length === 0 ? (
                  <EmptyState message="Tidak ada data variance" />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[900px]">
                      <thead>
                        <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                          <th className="px-3 py-2.5 text-left font-bold">Product</th>
                          <th className="px-3 py-2.5 text-left font-bold">Location</th>
                          <th className="px-3 py-2.5 text-right font-bold">Current Qty</th>
                          <th className="px-3 py-2.5 text-right font-bold">Ledger Balance</th>
                          <th className="px-3 py-2.5 text-right font-bold">Total In</th>
                          <th className="px-3 py-2.5 text-right font-bold">Total Out</th>
                          <th className="px-3 py-2.5 text-right font-bold">Transactions</th>
                          <th className="px-3 py-2.5 text-right font-bold">Difference</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100">
                        {varianceRows.map((r) => {
                          const diff = r.current_qty - r.ledger_balance;
                          return (
                            <tr key={r.stock_id} className="hover:bg-brand-50/50">
                              <td className="px-3 py-2.5">
                                <div className="font-semibold text-brand-800">{r.product_code}</div>
                                <div className="text-xs text-gray-500">{r.product_name}</div>
                              </td>
                              <td className="px-3 py-2.5 font-mono text-xs">{r.location}</td>
                              <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(r.current_qty, 0)}</td>
                              <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(r.ledger_balance, 0)}</td>
                              <td className="px-3 py-2.5 text-right text-emerald-600 font-medium">+{fmtNum(r.total_in, 0)}</td>
                              <td className="px-3 py-2.5 text-right text-red-600 font-medium">-{fmtNum(r.total_out, 0)}</td>
                              <td className="px-3 py-2.5 text-right">{fmtNum(r.transaction_count, 0)}</td>
                              <td className="px-3 py-2.5 text-right">
                                <span className={`font-bold ${diff < 0 ? 'text-red-600' : diff > 0 ? 'text-emerald-600' : 'text-gray-700'}`}>
                                  {diff > 0 ? '+' : ''}{fmtNum(diff, 0)}
                                </span>
                              </td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}

          {/* ── History Tab ── */}
          {activeTab === 'history' && (
            <div>
              {/* Filters */}
              <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-5">
                <div className="flex flex-wrap items-end gap-3">
                  <div className="w-44">
                    <Field label="Dari Tanggal">
                      <TextInput type="date" value={historyFrom} onChange={(e) => setHistoryFrom(e.target.value)} />
                    </Field>
                  </div>
                  <div className="w-44">
                    <Field label="Sampai Tanggal">
                      <TextInput type="date" value={historyTo} onChange={(e) => setHistoryTo(e.target.value)} />
                    </Field>
                  </div>
                  <button
                    onClick={loadHistory}
                    className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 inline-flex items-center gap-2"
                  >
                    <Search className="w-4 h-4" /> Filter
                  </button>
                </div>
              </div>

              {/* History Table */}
              <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
                <div className="px-5 py-3.5 border-b border-gray-100 bg-brand-50/50 flex items-center justify-between">
                  <h3 className="font-bold text-sm text-brand-700">Riwayat Reconcile</h3>
                </div>
                {historyLoading ? (
                  <Spinner label="Memuat data…" />
                ) : historyRows.length === 0 ? (
                  <EmptyState message="Tidak ada riwayat reconcile" />
                ) : (
                  <div className="overflow-x-auto">
                    <table className="w-full text-sm min-w-[800px]">
                      <thead>
                        <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                          <th className="px-3 py-2.5 text-left font-bold">Tanggal</th>
                          <th className="px-3 py-2.5 text-left font-bold">Product</th>
                          <th className="px-3 py-2.5 text-left font-bold">Location</th>
                          <th className="px-3 py-2.5 text-right font-bold">Qty Lama</th>
                          <th className="px-3 py-2.5 text-right font-bold">Qty Baru</th>
                          <th className="px-3 py-2.5 text-right font-bold">Variance</th>
                          <th className="px-3 py-2.5 text-left font-bold">Alasan</th>
                          <th className="px-3 py-2.5 text-left font-bold">Oleh</th>
                        </tr>
                      </thead>
                      <tbody className="divide-y divide-gray-100">
                        {historyRows.map((h) => (
                          <tr key={h.id} className="hover:bg-brand-50/50">
                            <td className="px-3 py-2.5 text-gray-600">{fmtDateTime(h.created_at)}</td>
                            <td className="px-3 py-2.5 font-semibold text-brand-800">{h.product_code}</td>
                            <td className="px-3 py-2.5 font-mono text-xs">{h.location}</td>
                            <td className="px-3 py-2.5 text-right">{fmtNum(h.old_qty, 0)}</td>
                            <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(h.new_qty, 0)}</td>
                            <td className="px-3 py-2.5 text-right">
                              <span className={`font-bold ${h.variance < 0 ? 'text-red-600' : h.variance > 0 ? 'text-emerald-600' : 'text-gray-700'}`}>
                                {h.variance > 0 ? '+' : ''}{fmtNum(h.variance, 0)}
                              </span>
                            </td>
                            <td className="px-3 py-2.5 text-gray-600 text-xs max-w-[200px] truncate" title={h.reason}>
                              {h.reason || '—'}
                            </td>
                            <td className="px-3 py-2.5 text-gray-700 font-medium">{h.adjusted_by_name}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>
            </div>
          )}
        </div>
      </div>

      {/* ── Reconcile Modal ── */}
      <Modal open={!!reconcileRow} onClose={() => setReconcileRow(null)} title="Reconcile Stok" size="sm">
        {reconcileRow && (
          <form onSubmit={submitReconcile} className="space-y-4">
            <div className="bg-brand-50 rounded-lg p-3 text-xs text-brand-800">
              <div className="flex justify-between">
                <span className="text-gray-500">Produk</span>
                <span className="font-semibold">
                  {reconcileRow.product_code} — {reconcileRow.product_name}
                </span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Lokasi</span>
                <span className="font-semibold">{reconcileRow.location}</span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Batch</span>
                <span className="font-semibold">{reconcileRow.batch_number || '—'}</span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Qty Sistem</span>
                <span className="font-semibold">{fmtNum(reconcileRow.expected_qty, 0)}</span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Selisih Saat Ini</span>
                <span className={`font-bold ${reconcileRow.variance < 0 ? 'text-red-600' : reconcileRow.variance > 0 ? 'text-emerald-600' : ''}`}>
                  {reconcileRow.variance > 0 ? '+' : ''}{fmtNum(reconcileRow.variance, 0)}
                </span>
              </div>
            </div>
            <Field label="Qty Aktual" required>
              <TextInput
                type="number"
                min={0}
                value={actualQty}
                onChange={(e) => setActualQty(e.target.value)}
                placeholder="Qty aktual fisik"
                autoFocus
              />
            </Field>
            <Field label="Alasan Reconcile" required>
              <TextArea
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                rows={3}
                placeholder="Alasan perbedaan stok…"
              />
            </Field>
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                onClick={() => setReconcileRow(null)}
                className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
              >
                Batal
              </button>
              <button
                type="submit"
                disabled={submitting || !reason.trim() || actualQty === ''}
                className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50"
              >
                {submitting ? 'Memproses…' : 'Reconcile'}
              </button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  );
};

export default StockReconciliationPage;
