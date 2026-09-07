import { FormEvent, useCallback, useEffect, useState } from 'react';
import {
  Search,
  Plus,
  PackageX,
  ClipboardCheck,
  Clock,
  CheckCircle2,
  XCircle,
  Eye,
} from 'lucide-react';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { EmptyState } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import Modal from '@/components/Modal';
import Spinner from '@/components/Spinner';
import { Field, TextInput, Select, TextArea } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { useConfirmDialog } from '@/hooks/useConfirmDialog';
import { fmtDateTime, fmtNum } from '@/lib/format';

interface RmaRow {
  id: number;
  rma_number: string;
  customer_name: string;
  product_code: string;
  product_name: string;
  batch_number: string;
  quantity: number;
  uom: string;
  reason: string;
  status: string;
  notes: string;
  created_by_name: string;
  created_at: string;
  updated_at: string;
  approved_by_name: string | null;
  approved_at: string | null;
  received_by_name: string | null;
  received_at: string | null;
  completed_by_name: string | null;
  completed_at: string | null;
  rejected_by_name: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
}

interface RmaStats {
  total: number;
  pending: number;
  approved: number;
  received: number;
  completed: number;
  rejected: number;
}

const STATUS_OPTIONS = ['Pending', 'Approved', 'Received', 'Completed', 'Rejected'];
const PAGE_SIZE = 25;

export default function RmaPage() {
  const toast = useToast();
  const { canWrite } = useAuth();
  const { confirm, ConfirmDialog } = useConfirmDialog();

  const [rows, setRows] = useState<RmaRow[]>([]);
  const [stats, setStats] = useState<RmaStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);
  const [total, setTotal] = useState(0);

  const [q, setQ] = useState('');
  const [qApplied, setQApplied] = useState('');
  const [status, setStatus] = useState('');

  const [showCreate, setShowCreate] = useState(false);
  const [createForm, setCreateForm] = useState({
    customer_name: '',
    product_code: '',
    batch_number: '',
    quantity: '',
    uom: '',
    reason: '',
    notes: '',
  });

  const [detailRow, setDetailRow] = useState<RmaRow | null>(null);
  const [detailLoading, setDetailLoading] = useState(false);

  const [rejectRow, setRejectRow] = useState<RmaRow | null>(null);
  const [rejectReason, setRejectReason] = useState('');
  const [receiveRow, setReceiveRow] = useState<RmaRow | null>(null);
  const [receiveQty, setReceiveQty] = useState('');
  const [receiveNotes, setReceiveNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api('rma', 'list', {
        params: { q: qApplied, status, page, per_page: PAGE_SIZE },
      });
      setRows((res.rows || []) as RmaRow[]);
      setTotalPages(res.total_pages || 1);
      setTotal(res.total || 0);
      if (res.stats) setStats(res.stats as RmaStats);
    } catch (e: any) {
      toast('error', e.message || 'Gagal memuat data RMA');
    } finally {
      setLoading(false);
    }
  }, [qApplied, status, page, toast]);

  useEffect(() => { loadList(); }, [loadList]);

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    setPage(1);
    setQApplied(q);
  };

  const handleFilterStatus = (val: string) => {
    setStatus(val);
    setPage(1);
  };

  const handleCreateChange = (field: string, value: string) => {
    setCreateForm((prev) => ({ ...prev, [field]: value }));
  };

  const submitCreate = async (e: FormEvent) => {
    e.preventDefault();
    if (!createForm.customer_name.trim() || !createForm.product_code.trim() || !createForm.reason.trim()) {
      toast('error', 'Customer, Product Code, dan Reason wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      await api('rma', 'create', {
        method: 'POST',
        body: {
          customer_name: createForm.customer_name.trim(),
          product_code: createForm.product_code.trim(),
          batch_number: createForm.batch_number.trim(),
          quantity: createForm.quantity ? Number(createForm.quantity) : 0,
          uom: createForm.uom.trim(),
          reason: createForm.reason.trim(),
          notes: createForm.notes.trim(),
        },
      });
      toast('success', 'RMA berhasil dibuat');
      setShowCreate(false);
      setCreateForm({ customer_name: '', product_code: '', batch_number: '', quantity: '', uom: '', reason: '', notes: '' });
      loadList();
    } catch (err: any) {
      toast('error', err.message || 'Gagal membuat RMA');
    } finally {
      setSubmitting(false);
    }
  };

  const openDetail = async (row: RmaRow) => {
    setDetailRow(row);
    setDetailLoading(true);
    try {
      const res = await api('rma', 'detail', { params: { id: row.id } });
      if (res.row) setDetailRow(res.row as RmaRow);
    } catch { /* keep basic row */ } finally {
      setDetailLoading(false);
    }
  };

  const handleApprove = async (row: RmaRow) => {
    if (!(await confirm('Approve RMA ' + row.rma_number + '?'))) return;
    setSubmitting(true);
    try {
      await api('rma', 'approve', { method: 'POST', body: { id: row.id } });
      toast('success', 'RMA disetujui');
      loadList();
      setDetailRow(null);
    } catch (err: any) {
      toast('error', err.message || 'Gagal approve RMA');
    } finally {
      setSubmitting(false);
    }
  };

  const openReject = (row: RmaRow) => {
    setRejectRow(row);
    setRejectReason('');
  };

  const submitReject = async (e: FormEvent) => {
    e.preventDefault();
    if (!rejectRow) return;
    if (!rejectReason.trim()) {
      toast('error', 'Alasan reject wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      await api('rma', 'reject', {
        method: 'POST',
        body: { id: rejectRow.id, rejection_reason: rejectReason.trim() },
      });
      toast('success', 'RMA ditolak');
      setRejectRow(null);
      loadList();
      setDetailRow(null);
    } catch (err: any) {
      toast('error', err.message || 'Gagal reject RMA');
    } finally {
      setSubmitting(false);
    }
  };

  const openReceive = (row: RmaRow) => {
    setReceiveRow(row);
    setReceiveQty(String(row.quantity || ''));
    setReceiveNotes('');
  };

  const submitReceive = async (e: FormEvent) => {
    e.preventDefault();
    if (!receiveRow) return;
    const qty = Number(receiveQty);
    if (!receiveQty || isNaN(qty) || qty <= 0) {
      toast('error', 'Jumlah yang diterima harus lebih dari 0');
      return;
    }
    setSubmitting(true);
    try {
      await api('rma', 'receive', {
        method: 'POST',
        body: { id: receiveRow.id, quantity: qty, notes: receiveNotes.trim() },
      });
      toast('success', 'RMA berhasil diterima');
      setReceiveRow(null);
      loadList();
      setDetailRow(null);
    } catch (err: any) {
      toast('error', err.message || 'Gagal menerima RMA');
    } finally {
      setSubmitting(false);
    }
  };

  const handleComplete = async (row: RmaRow) => {
    if (!(await confirm('Selesaikan RMA ' + row.rma_number + '?'))) return;
    setSubmitting(true);
    try {
      await api('rma', 'complete', { method: 'POST', body: { id: row.id } });
      toast('success', 'RMA selesai');
      loadList();
      setDetailRow(null);
    } catch (err: any) {
      toast('error', err.message || 'Gagal menyelesaikan RMA');
    } finally {
      setSubmitting(false);
    }
  };

  const canApprove = (row: RmaRow) => canWrite && row.status === 'Pending';
  const canReject = (row: RmaRow) => canWrite && (row.status === 'Pending' || row.status === 'Approved');
  const canReceive = (row: RmaRow) => canWrite && row.status === 'Approved';
  const canComplete = (row: RmaRow) => canWrite && row.status === 'Received';

  const statsCards = stats
    ? [
        { label: 'Total', value: stats.total, icon: <ClipboardCheck className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
        { label: 'Pending', value: stats.pending, icon: <Clock className="w-4 h-4" />, cls: 'bg-yellow-50 text-yellow-600' },
        { label: 'Approved', value: stats.approved, icon: <CheckCircle2 className="w-4 h-4" />, cls: 'bg-sky-50 text-sky-600' },
        { label: 'Received', value: stats.received, icon: <PackageX className="w-4 h-4" />, cls: 'bg-emerald-50 text-emerald-600' },
        { label: 'Completed', value: stats.completed, icon: <CheckCircle2 className="w-4 h-4" />, cls: 'bg-green-50 text-green-600' },
        { label: 'Rejected', value: stats.rejected, icon: <XCircle className="w-4 h-4" />, cls: 'bg-red-50 text-red-600' },
      ]
    : [];

  return (
    <div>
      <PageHeader
        title="RMA (Return Merchandise Authorization)"
        subtitle="Kelola pengembalian barang dari customer"
        actions={
          canWrite ? (
            <button
              onClick={() => setShowCreate(true)}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25"
            >
              <Plus className="w-4 h-4" /> Buat RMA
            </button>
          ) : undefined
        }
      />

      {statsCards.length > 0 && (
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-5">
          {statsCards.map((c) => (
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
                  placeholder="Nomor RMA, customer, produk..."
                  className="w-full pl-9 pr-3 py-2 border-[1.5px] border-gray-300 rounded-lg text-sm focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 outline-none"
                />
              </div>
            </Field>
          </div>
          <div className="w-44">
            <Field label="Status">
              <Select value={status} onChange={(e) => handleFilterStatus(e.target.value)}>
                <option value="">Semua</option>
                {STATUS_OPTIONS.map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </Select>
            </Field>
          </div>
          <button type="submit" className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 inline-flex items-center gap-2">
            <Search className="w-4 h-4" /> Cari
          </button>
          <button type="button" onClick={() => { setQ(''); setQApplied(''); setStatus(''); setPage(1); }} className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200">
            Reset
          </button>
        </form>
      </div>

      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
        <div className="px-5 py-3.5 border-b border-gray-100 bg-brand-50/50 flex items-center justify-between">
          <h3 className="font-bold text-sm text-brand-700">Daftar RMA</h3>
          <span className="text-xs text-gray-500">{total} data</span>
        </div>
        {loading ? (
          <Spinner label="Memuat data RMA..." />
        ) : rows.length === 0 ? (
          <EmptyState message="Tidak ada data RMA" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[1100px]">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold">Nomor RMA</th>
                  <th className="px-3 py-2.5 text-left font-bold">Customer</th>
                  <th className="px-3 py-2.5 text-left font-bold">Produk</th>
                  <th className="px-3 py-2.5 text-left font-bold">Batch</th>
                  <th className="px-3 py-2.5 text-right font-bold">Qty</th>
                  <th className="px-3 py-2.5 text-left font-bold">Alasan</th>
                  <th className="px-3 py-2.5 text-left font-bold">Status</th>
                  <th className="px-3 py-2.5 text-left font-bold">Dibuat</th>
                  <th className="px-3 py-2.5 text-center font-bold">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {rows.map((r) => (
                  <tr key={r.id} className="hover:bg-brand-50/50">
                    <td className="px-3 py-2.5 font-semibold text-brand-800">{r.rma_number}</td>
                    <td className="px-3 py-2.5 text-gray-700">{r.customer_name}</td>
                    <td className="px-3 py-2.5">
                      <div className="font-semibold text-brand-800">{r.product_code}</div>
                      <div className="text-xs text-gray-500">{r.product_name}</div>
                    </td>
                    <td className="px-3 py-2.5 text-gray-600">{r.batch_number || '—'}</td>
                    <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(r.quantity, 0)} {r.uom}</td>
                    <td className="px-3 py-2.5 text-gray-600 max-w-[200px] truncate" title={r.reason}>{r.reason}</td>
                    <td className="px-3 py-2.5"><StatusBadge status={r.status} /></td>
                    <td className="px-3 py-2.5 text-gray-500 text-xs">{fmtDateTime(r.created_at)}</td>
                    <td className="px-3 py-2.5">
                      <div className="flex items-center justify-center gap-1">
                        <button onClick={() => openDetail(r)} className="p-1.5 rounded-lg hover:bg-brand-100 text-brand-600" title="Detail">
                          <Eye className="w-4 h-4" />
                        </button>
                        {canApprove(r) && (
                          <button onClick={() => handleApprove(r)} className="p-1.5 rounded-lg hover:bg-green-100 text-green-600" title="Approve">
                            <CheckCircle2 className="w-4 h-4" />
                          </button>
                        )}
                        {canReject(r) && (
                          <button onClick={() => openReject(r)} className="p-1.5 rounded-lg hover:bg-red-100 text-red-600" title="Reject">
                            <XCircle className="w-4 h-4" />
                          </button>
                        )}
                        {canReceive(r) && (
                          <button onClick={() => openReceive(r)} className="p-1.5 rounded-lg hover:bg-brand-100 text-brand-600" title="Receive">
                            <PackageX className="w-4 h-4" />
                          </button>
                        )}
                        {canComplete(r) && (
                          <button onClick={() => handleComplete(r)} className="p-1.5 rounded-lg hover:bg-emerald-100 text-emerald-600" title="Complete">
                            <CheckCircle2 className="w-4 h-4" />
                          </button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {totalPages > 1 && (
          <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
            <span className="text-xs text-gray-500">Halaman {page} dari {totalPages}</span>
            <div className="flex gap-1">
              <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page <= 1} className="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200 disabled:opacity-40">Prev</button>
              {Array.from({ length: Math.min(5, totalPages) }, (_, i) => {
                const start = Math.max(1, Math.min(page - 2, totalPages - 4));
                const p = start + i;
                if (p > totalPages) return null;
                return (
                  <button key={p} onClick={() => setPage(p)} className={`px-3 py-1.5 rounded-lg text-xs font-semibold ${p === page ? 'bg-brand-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'}`}>{p}</button>
                );
              })}
              <button onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page >= totalPages} className="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200 disabled:opacity-40">Next</button>
            </div>
          </div>
        )}
      </div>

      {/* Create Modal */}
      <Modal open={showCreate} onClose={() => setShowCreate(false)} title="Buat RMA Baru" size="md">
        <form onSubmit={submitCreate} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Field label="Customer" required>
              <TextInput value={createForm.customer_name} onChange={(e) => handleCreateChange('customer_name', e.target.value)} placeholder="Nama customer" autoFocus />
            </Field>
            <Field label="Product Code" required>
              <TextInput value={createForm.product_code} onChange={(e) => handleCreateChange('product_code', e.target.value)} placeholder="Kode produk" />
            </Field>
            <Field label="Batch Number">
              <TextInput value={createForm.batch_number} onChange={(e) => handleCreateChange('batch_number', e.target.value)} placeholder="Nomor batch" />
            </Field>
            <Field label="Quantity">
              <TextInput type="number" min={0} value={createForm.quantity} onChange={(e) => handleCreateChange('quantity', e.target.value)} placeholder="Jumlah" />
            </Field>
            <Field label="UOM">
              <TextInput value={createForm.uom} onChange={(e) => handleCreateChange('uom', e.target.value)} placeholder="Unit of Measure" />
            </Field>
          </div>
          <Field label="Alasan Return" required>
            <TextArea value={createForm.reason} onChange={(e) => handleCreateChange('reason', e.target.value)} rows={3} placeholder="Alasan pengembalian barang..." />
          </Field>
          <Field label="Catatan">
            <TextArea value={createForm.notes} onChange={(e) => handleCreateChange('notes', e.target.value)} rows={2} placeholder="Catatan tambahan (opsional)" />
          </Field>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setShowCreate(false)} className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200">Batal</button>
            <button type="submit" disabled={submitting} className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50">
              {submitting ? 'Menyimpan...' : 'Buat RMA'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Detail Modal */}
      <Modal open={!!detailRow} onClose={() => setDetailRow(null)} title="Detail RMA" size="lg">
        {detailRow && (
          <div className="space-y-5">
            {detailLoading ? (
              <Spinner label="Memuat detail..." />
            ) : (
              <>
                <div className="rounded-lg bg-brand-50 p-4">
                  <div className="flex items-center justify-between mb-3">
                    <h4 className="text-[11px] uppercase tracking-wide text-brand-600 font-bold">Informasi RMA</h4>
                    <StatusBadge status={detailRow.status} />
                  </div>
                  <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                    <div>
                      <span className="text-gray-500 text-xs">Nomor RMA</span>
                      <div className="font-semibold">{detailRow.rma_number}</div>
                    </div>
                    <div>
                      <span className="text-gray-500 text-xs">Customer</span>
                      <div className="font-semibold">{detailRow.customer_name}</div>
                    </div>
                    <div>
                      <span className="text-gray-500 text-xs">Dibuat Oleh</span>
                      <div className="font-semibold">{detailRow.created_by_name || '—'}</div>
                    </div>
                    <div>
                      <span className="text-gray-500 text-xs">Tanggal Dibuat</span>
                      <div className="font-semibold">{fmtDateTime(detailRow.created_at)}</div>
                    </div>
                    <div>
                      <span className="text-gray-500 text-xs">Terakhir Diupdate</span>
                      <div className="font-semibold">{fmtDateTime(detailRow.updated_at)}</div>
                    </div>
                  </div>
                </div>

                <div className="rounded-lg bg-gray-50 p-4">
                  <h4 className="text-[11px] uppercase tracking-wide text-gray-500 font-bold mb-3">Informasi Produk</h4>
                  <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
                    <div>
                      <span className="text-gray-500 text-xs">Kode Produk</span>
                      <div className="font-semibold">{detailRow.product_code}</div>
                    </div>
                    <div>
                      <span className="text-gray-500 text-xs">Nama Produk</span>
                      <div className="font-semibold">{detailRow.product_name}</div>
                    </div>
                    <div>
                      <span className="text-gray-500 text-xs">Batch</span>
                      <div className="font-semibold">{detailRow.batch_number || '—'}</div>
                    </div>
                    <div>
                      <span className="text-gray-500 text-xs">Jumlah</span>
                      <div className="font-semibold">{fmtNum(detailRow.quantity, 0)} {detailRow.uom}</div>
                    </div>
                  </div>
                </div>

                <div className="rounded-lg bg-orange-50 p-4">
                  <h4 className="text-[11px] uppercase tracking-wide text-orange-600 font-bold mb-2">Alasan Return</h4>
                  <p className="text-sm text-orange-800">{detailRow.reason}</p>
                  {detailRow.notes && (
                    <>
                      <h4 className="text-[11px] uppercase tracking-wide text-orange-600 font-bold mb-2 mt-3">Catatan</h4>
                      <p className="text-sm text-orange-800">{detailRow.notes}</p>
                    </>
                  )}
                </div>

                {(detailRow.approved_by_name || detailRow.rejected_by_name || detailRow.received_by_name || detailRow.completed_by_name) && (
                  <div className="rounded-lg bg-emerald-50 p-4">
                    <h4 className="text-[11px] uppercase tracking-wide text-emerald-600 font-bold mb-3">Riwayat</h4>
                    <div className="grid grid-cols-2 sm:grid-cols-2 gap-3 text-sm">
                      {detailRow.approved_by_name && (
                        <div>
                          <span className="text-gray-500 text-xs">Disetujui Oleh</span>
                          <div className="font-semibold">{detailRow.approved_by_name}</div>
                          <div className="text-xs text-gray-400">{fmtDateTime(detailRow.approved_at)}</div>
                        </div>
                      )}
                      {detailRow.rejected_by_name && (
                        <div>
                          <span className="text-gray-500 text-xs">Ditolak Oleh</span>
                          <div className="font-semibold">{detailRow.rejected_by_name}</div>
                          <div className="text-xs text-gray-400">{fmtDateTime(detailRow.rejected_at)}</div>
                          {detailRow.rejection_reason && (
                            <div className="text-xs text-red-600 mt-1">Alasan: {detailRow.rejection_reason}</div>
                          )}
                        </div>
                      )}
                      {detailRow.received_by_name && (
                        <div>
                          <span className="text-gray-500 text-xs">Diterima Oleh</span>
                          <div className="font-semibold">{detailRow.received_by_name}</div>
                          <div className="text-xs text-gray-400">{fmtDateTime(detailRow.received_at)}</div>
                        </div>
                      )}
                      {detailRow.completed_by_name && (
                        <div>
                          <span className="text-gray-500 text-xs">Selesai Oleh</span>
                          <div className="font-semibold">{detailRow.completed_by_name}</div>
                          <div className="text-xs text-gray-400">{fmtDateTime(detailRow.completed_at)}</div>
                        </div>
                      )}
                    </div>
                  </div>
                )}

                {canWrite && (
                  <div className="flex justify-end gap-2 pt-2 border-t border-gray-200">
                    {canApprove(detailRow) && (
                      <button onClick={() => handleApprove(detailRow)} disabled={submitting} className="px-4 py-2 rounded-lg bg-green-600 text-white text-sm font-semibold hover:bg-green-700 disabled:opacity-50">Approve</button>
                    )}
                    {canReject(detailRow) && (
                      <button onClick={() => openReject(detailRow)} disabled={submitting} className="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50">Reject</button>
                    )}
                    {canReceive(detailRow) && (
                      <button onClick={() => openReceive(detailRow)} disabled={submitting} className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50">Receive</button>
                    )}
                    {canComplete(detailRow) && (
                      <button onClick={() => handleComplete(detailRow)} disabled={submitting} className="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700 disabled:opacity-50">Complete</button>
                    )}
                  </div>
                )}
              </>
            )}
          </div>
        )}
      </Modal>

      {/* Reject Modal */}
      <Modal open={!!rejectRow} onClose={() => setRejectRow(null)} title="Tolak RMA" size="sm">
        {rejectRow && (
          <form onSubmit={submitReject} className="space-y-4">
            <div className="bg-red-50 rounded-lg p-3 text-xs text-red-800">
              <div className="flex justify-between">
                <span className="text-gray-500">Nomor RMA</span>
                <span className="font-semibold">{rejectRow.rma_number}</span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Customer</span>
                <span className="font-semibold">{rejectRow.customer_name}</span>
              </div>
            </div>
            <Field label="Alasan Reject" required>
              <TextArea value={rejectReason} onChange={(e) => setRejectReason(e.target.value)} rows={3} placeholder="Alasan penolakan..." autoFocus />
            </Field>
            <div className="flex justify-end gap-2 pt-1">
              <button type="button" onClick={() => setRejectRow(null)} className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200">Batal</button>
              <button type="submit" disabled={submitting || !rejectReason.trim()} className="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 disabled:opacity-50">
                {submitting ? 'Menolak...' : 'Tolak'}
              </button>
            </div>
          </form>
        )}
      </Modal>

      {/* Receive Modal */}
      <Modal open={!!receiveRow} onClose={() => setReceiveRow(null)} title="Terima RMA" size="sm">
        {receiveRow && (
          <form onSubmit={submitReceive} className="space-y-4">
            <div className="bg-sky-50 rounded-lg p-3 text-xs text-sky-800">
              <div className="flex justify-between">
                <span className="text-gray-500">Nomor RMA</span>
                <span className="font-semibold">{receiveRow.rma_number}</span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Customer</span>
                <span className="font-semibold">{receiveRow.customer_name}</span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Produk</span>
                <span className="font-semibold">{receiveRow.product_code} — {receiveRow.product_name}</span>
              </div>
              <div className="flex justify-between mt-1">
                <span className="text-gray-500">Jumlah Direturn</span>
                <span className="font-semibold">{fmtNum(receiveRow.quantity, 0)} {receiveRow.uom}</span>
              </div>
            </div>
            <Field label="Jumlah Diterima" required>
              <TextInput type="number" min={1} value={receiveQty} onChange={(e) => setReceiveQty(e.target.value)} placeholder="Jumlah yang diterima" autoFocus />
            </Field>
            <Field label="Catatan Penerimaan">
              <TextArea value={receiveNotes} onChange={(e) => setReceiveNotes(e.target.value)} rows={2} placeholder="Catatan penerimaan (opsional)" />
            </Field>
            <div className="flex justify-end gap-2 pt-1">
              <button type="button" onClick={() => setReceiveRow(null)} className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200">Batal</button>
              <button type="submit" disabled={submitting || !receiveQty || Number(receiveQty) <= 0} className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50">
                {submitting ? 'Menerima...' : 'Terima'}
              </button>
            </div>
          </form>
        )}
      </Modal>
      <ConfirmDialog />
    </div>
  );
}
