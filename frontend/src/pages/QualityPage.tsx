import { FormEvent, useCallback, useEffect, useState } from 'react';
import {
  Search,
  RefreshCw,
  ShieldCheck,
  ShieldAlert,
  ClipboardCheck,
  AlertTriangle,
  CheckCircle2,
  XCircle,
  Eye,
  Plus,
} from 'lucide-react';
import { api } from '@/lib/api';
import { WebBtn } from '@/components/WebBtn';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import Modal from '@/components/Modal';
import Spinner from '@/components/Spinner';
import { Field, TextInput, Select, TextArea } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { fmtDate } from '@/lib/format';

const STATUS_OPTIONS = ['Pending', 'In Progress', 'Passed', 'Failed', 'Quarantine'];

const STATUS_CONFIG: Record<string, { color: string; icon: typeof ShieldCheck }> = {
  Pending: { color: 'bg-yellow-50 text-yellow-600', icon: ClipboardCheck },
  'In Progress': { color: 'bg-blue-50 text-blue-600', icon: Eye },
  Passed: { color: 'bg-green-50 text-green-600', icon: CheckCircle2 },
  Failed: { color: 'bg-red-50 text-red-600', icon: XCircle },
  Quarantine: { color: 'bg-orange-50 text-orange-600', icon: ShieldAlert },
};

const SOURCE_OPTIONS = ['Inbound', 'Outbound', 'Stock'];

interface QualityRecord {
  id: number;
  record_number: string;
  source_type: 'Inbound' | 'Outbound' | 'Stock';
  source_id: number;
  product_id: number;
  product_code: string;
  product_name: string;
  batch_number: string;
  location: string;
  quantity: number;
  status: 'Pending' | 'In Progress' | 'Passed' | 'Failed' | 'Quarantine';
  inspector_id: number | null;
  inspector_name: string | null;
  inspection_date: string | null;
  result: string | null;
  defect_type: string | null;
  defect_notes: string | null;
  action_taken: string | null;
  created_at: string;
}

interface QualitySummary {
  total: number;
  pending: number;
  in_progress: number;
  passed: number;
  failed: number;
  quarantine: number;
}

export default function QualityPage() {
  const toast = useToast();
  const { canWrite } = useAuth();

  const [rows, setRows] = useState<QualityRecord[]>([]);
  const [summary, setSummary] = useState<QualitySummary | null>(null);
  const [loading, setLoading] = useState(true);

  const [q, setQ] = useState('');
  const [qApplied, setQApplied] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [totalPages, setTotalPages] = useState(1);

  const [createOpen, setCreateOpen] = useState(false);
  const [detailRow, setDetailRow] = useState<QualityRecord | null>(null);
  const [resultOpen, setResultOpen] = useState(false);

  const [submitting, setSubmitting] = useState(false);

  // Create form state
  const [createForm, setCreateForm] = useState({
    source_type: 'Inbound',
    product_code: '',
    product_name: '',
    batch_number: '',
    location: '',
    quantity: '',
    notes: '',
  });

  // Status update state
  const [newStatus, setNewStatus] = useState('');
  const [statusNote, setStatusNote] = useState('');

  // Add result state
  const [resultForm, setResultForm] = useState({
    result: 'Passed',
    defect_type: '',
    defect_notes: '',
    action_taken: '',
    inspector_name: '',
  });

  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api('quality', 'list', {
        params: {
          q: qApplied,
          status,
          page: String(page),
          per_page: '25',
        },
      });
      setRows((res.data || []) as QualityRecord[]);
      setTotalPages(res.total_pages || 1);
      if (res.summary) setSummary(res.summary as QualitySummary);
    } catch (e: any) {
      toast('error', e.message || 'Gagal memuat data quality');
    } finally {
      setLoading(false);
    }
  }, [qApplied, status, page, toast]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  const reload = () => {
    loadList();
  };

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    setPage(1);
    setQApplied(q);
  };

  const handleStatusFilter = (e: React.ChangeEvent<HTMLSelectElement>) => {
    setStatus(e.target.value);
    setPage(1);
  };

  const updateCreateField = (field: string, value: string) => {
    setCreateForm((prev) => ({ ...prev, [field]: value }));
  };

  const submitCreate = async (e: FormEvent) => {
    e.preventDefault();
    if (!createForm.product_code.trim() || !createForm.product_name.trim()) {
      toast('error', 'Kode dan nama produk wajib diisi');
      return;
    }
    if (!createForm.batch_number.trim()) {
      toast('error', 'Nomor batch wajib diisi');
      return;
    }
    if (!createForm.location.trim()) {
      toast('error', 'Lokasi wajib diisi');
      return;
    }
    const qty = Number(createForm.quantity);
    if (!createForm.quantity || isNaN(qty) || qty <= 0) {
      toast('error', 'Jumlah harus lebih dari 0');
      return;
    }
    setSubmitting(true);
    try {
      await api('quality', 'create', {
        method: 'POST',
        body: {
          source_type: createForm.source_type,
          product_code: createForm.product_code.trim(),
          product_name: createForm.product_name.trim(),
          batch_number: createForm.batch_number.trim(),
          location: createForm.location.trim(),
          quantity: qty,
          notes: createForm.notes.trim(),
        },
      });
      toast('success', 'Record quality berhasil dibuat');
      setCreateOpen(false);
      setCreateForm({
        source_type: 'Inbound',
        product_code: '',
        product_name: '',
        batch_number: '',
        location: '',
        quantity: '',
        notes: '',
      });
      reload();
    } catch (err: any) {
      toast('error', err.message || 'Gagal membuat record quality');
    } finally {
      setSubmitting(false);
    }
  };

  const openDetail = (row: QualityRecord) => {
    setDetailRow(row);
    setNewStatus(row.status);
    setStatusNote('');
  };

  const submitStatusUpdate = async (e: FormEvent) => {
    e.preventDefault();
    if (!detailRow) return;
    if (newStatus === detailRow.status) {
      toast('error', 'Status tidak berubah');
      return;
    }
    setSubmitting(true);
    try {
      await api('quality', 'update_status', {
        method: 'POST',
        body: {
          id: detailRow.id,
          status: newStatus,
          note: statusNote.trim(),
        },
      });
      toast('success', 'Status berhasil diperbarui');
      setDetailRow(null);
      reload();
    } catch (err: any) {
      toast('error', err.message || 'Gagal memperbarui status');
    } finally {
      setSubmitting(false);
    }
  };

  const openResult = (row: QualityRecord) => {
    setDetailRow(null);
    setResultForm({
      result: 'Passed',
      defect_type: '',
      defect_notes: '',
      action_taken: '',
      inspector_name: row.inspector_name || '',
    });
    setResultOpen(true);
  };

  const submitResult = async (e: FormEvent) => {
    e.preventDefault();
    if (!detailRow) return;
    if (!resultForm.inspector_name.trim()) {
      toast('error', 'Nama inspector wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      await api('quality', 'add_result', {
        method: 'POST',
        body: {
          id: detailRow.id,
          result: resultForm.result,
          defect_type: resultForm.defect_type.trim(),
          defect_notes: resultForm.defect_notes.trim(),
          action_taken: resultForm.action_taken.trim(),
          inspector_name: resultForm.inspector_name.trim(),
        },
      });
      toast('success', 'Hasil inspeksi berhasil disimpan');
      setResultOpen(false);
      setDetailRow(null);
      reload();
    } catch (err: any) {
      toast('error', err.message || 'Gagal menyimpan hasil inspeksi');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div>
      <PageHeader
        title="Quality Management"
        subtitle="Inspeksi kualitas produk masuk, keluar, dan stok gudang"
        actions={
          <>
            {canWrite && (
              <button
                onClick={() => setCreateOpen(true)}
                className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25"
              >
                <Plus className="w-4 h-4" /> Record Baru
              </button>
            )}
            <button
              onClick={reload}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25"
            >
              <RefreshCw className="w-4 h-4" /> Refresh
            </button>
          </>
        }
      />

      {/* Summary Cards */}
      {summary && (
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-4 mb-5">
          {[
            { label: 'Total', value: summary.total, icon: <ClipboardCheck className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
            { label: 'Pending', value: summary.pending, icon: <AlertTriangle className="w-4 h-4" />, cls: 'bg-yellow-50 text-yellow-600' },
            { label: 'In Progress', value: summary.in_progress, icon: <Eye className="w-4 h-4" />, cls: 'bg-blue-50 text-blue-600' },
            { label: 'Passed', value: summary.passed, icon: <CheckCircle2 className="w-4 h-4" />, cls: 'bg-green-50 text-green-600' },
            { label: 'Failed', value: summary.failed, icon: <XCircle className="w-4 h-4" />, cls: 'bg-red-50 text-red-600' },
          ].map((c) => (
            <div key={c.label} className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
              <div className={`w-8 h-8 rounded-lg ${c.cls} flex items-center justify-center mb-2`}>{c.icon}</div>
              <div className="text-xl font-bold text-brand-900">{c.value}</div>
              <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">{c.label}</div>
            </div>
          ))}
        </div>
      )}

      {/* Search & Filter */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-5">
        <form onSubmit={handleSearch} className="flex flex-wrap items-end gap-3">
          <div className="w-full md:w-72">
            <Field label="Cari">
              <div className="relative">
                <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="Record number, kode/nama produk, batch…"
                  className="w-full pl-9 pr-3 py-2 border-[1.5px] border-gray-300 rounded-lg text-sm focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 outline-none"
                />
              </div>
            </Field>
          </div>
          <div className="w-44">
            <Field label="Status">
              <Select value={status} onChange={handleStatusFilter}>
                <option value="">Semua</option>
                {STATUS_OPTIONS.map((s) => (
                  <option key={s} value={s}>
                    {s}
                  </option>
                ))}
              </Select>
            </Field>
          </div>
          <button
            type="submit"
            className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 inline-flex items-center gap-2"
          >
            <Search className="w-4 h-4" /> Cari
          </button>
        </form>
      </div>

      {/* Table */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
        <div className="px-5 py-3.5 border-b border-gray-100 bg-brand-50/50 flex items-center justify-between">
          <h3 className="font-bold text-sm text-brand-700">Daftar Inspeksi Quality</h3>
        </div>
        {loading ? (
          <Spinner label="Memuat data quality…" />
        ) : rows.length === 0 ? (
          <EmptyState message="Tidak ada data inspeksi quality" />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full text-sm min-w-[1100px]">
                <thead>
                  <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                    <th className="px-3 py-2.5 text-left font-bold">Record No.</th>
                    <th className="px-3 py-2.5 text-left font-bold">Produk</th>
                    <th className="px-3 py-2.5 text-left font-bold">Batch</th>
                    <th className="px-3 py-2.5 text-left font-bold">Source</th>
                    <th className="px-3 py-2.5 text-right font-bold">Qty</th>
                    <th className="px-3 py-2.5 text-left font-bold">Status</th>
                    <th className="px-3 py-2.5 text-left font-bold">Inspector</th>
                    <th className="px-3 py-2.5 text-left font-bold">Tanggal</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100">
                  {rows.map((r) => (
                    <tr
                      key={r.id}
                      className="hover:bg-brand-50/50 cursor-pointer"
                      onClick={() => openDetail(r)}
                    >
                      <td className="px-3 py-2.5">
                        <div className="font-semibold text-brand-800 font-mono text-xs">{r.record_number}</div>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="font-semibold text-brand-800">{r.product_code}</div>
                        <div className="text-xs text-gray-500">{r.product_name}</div>
                      </td>
                      <td className="px-3 py-2.5 text-gray-600">{r.batch_number || '—'}</td>
                      <td className="px-3 py-2.5">
                        <span className="inline-flex px-2 py-0.5 rounded bg-gray-100 text-gray-700 text-[11px] font-semibold">
                          {r.source_type}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-right font-semibold">{r.quantity}</td>
                      <td className="px-3 py-2.5">
                        <StatusBadge status={r.status} />
                      </td>
                      <td className="px-3 py-2.5 text-gray-600 text-xs">{r.inspector_name || '—'}</td>
                      <td className="px-3 py-2.5 text-gray-600 text-xs">{fmtDate(r.inspection_date || r.created_at)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-between text-sm">
              <div className="text-gray-500">
                Halaman {page} dari {totalPages}
              </div>
              <div className="flex gap-2">
                <button
                  disabled={page <= 1}
                  onClick={() => setPage((p) => p - 1)}
                  className="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Prev
                </button>
                <button
                  disabled={page >= totalPages}
                  onClick={() => setPage((p) => p + 1)}
                  className="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed"
                >
                  Next
                </button>
              </div>
            </div>
          </>
        )}
      </div>

      {/* Create Modal */}
      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="Record Quality Baru" size="md">
        <form onSubmit={submitCreate} className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Field label="Source Type" required>
              <Select
                value={createForm.source_type}
                onChange={(e) => updateCreateField('source_type', e.target.value)}
              >
                {SOURCE_OPTIONS.map((s) => (
                  <option key={s} value={s}>{s}</option>
                ))}
              </Select>
            </Field>
            <Field label="Kode Produk" required>
              <TextInput
                value={createForm.product_code}
                onChange={(e) => updateCreateField('product_code', e.target.value)}
                placeholder="Contoh: PROD-001"
                autoFocus
              />
            </Field>
            <Field label="Nama Produk" required>
              <TextInput
                value={createForm.product_name}
                onChange={(e) => updateCreateField('product_name', e.target.value)}
                placeholder="Nama produk"
              />
            </Field>
            <Field label="Nomor Batch" required>
              <TextInput
                value={createForm.batch_number}
                onChange={(e) => updateCreateField('batch_number', e.target.value)}
                placeholder="Contoh: B20260830"
              />
            </Field>
            <Field label="Lokasi" required>
              <TextInput
                value={createForm.location}
                onChange={(e) => updateCreateField('location', e.target.value)}
                placeholder="Contoh: A-01-01"
              />
            </Field>
            <Field label="Jumlah" required>
              <TextInput
                type="number"
                min={1}
                value={createForm.quantity}
                onChange={(e) => updateCreateField('quantity', e.target.value)}
                placeholder="0"
              />
            </Field>
          </div>
          <Field label="Catatan">
            <TextArea
              value={createForm.notes}
              onChange={(e) => updateCreateField('notes', e.target.value)}
              rows={3}
              placeholder="Catatan tambahan…"
            />
          </Field>
          <div className="flex justify-end gap-2 pt-1">
            <button
              type="button"
              onClick={() => setCreateOpen(false)}
              className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={submitting}
              className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50"
            >
              {submitting ? 'Menyimpan…' : 'Buat Record'}
            </button>
          </div>
        </form>
      </Modal>

      {/* Detail Modal */}
      <Modal open={!!detailRow} onClose={() => setDetailRow(null)} title="Detail Inspeksi Quality" size="lg">
        {detailRow && (
          <div className="space-y-5">
            {/* Record Info */}
            <div className="rounded-lg bg-brand-50 p-4">
              <h4 className="text-[11px] uppercase tracking-wide text-brand-600 font-bold mb-3">Informasi Record</h4>
              <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                <div>
                  <span className="text-gray-500 text-xs">Record Number</span>
                  <div className="font-semibold font-mono">{detailRow.record_number}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Status</span>
                  <div><StatusBadge status={detailRow.status} /></div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Source</span>
                  <div className="font-semibold">{detailRow.source_type} #{detailRow.source_id}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Produk</span>
                  <div className="font-semibold">{detailRow.product_code} — {detailRow.product_name}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Batch</span>
                  <div className="font-semibold">{detailRow.batch_number || '—'}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Lokasi</span>
                  <div className="font-semibold">{detailRow.location}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Jumlah</span>
                  <div className="font-semibold">{detailRow.quantity}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Inspector</span>
                  <div className="font-semibold">{detailRow.inspector_name || '—'}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Tanggal Inspeksi</span>
                  <div className="font-semibold">{fmtDate(detailRow.inspection_date)}</div>
                </div>
              </div>
            </div>

            {/* Result Info */}
            {detailRow.result && (
              <div className={`rounded-lg p-4 ${detailRow.result === 'Passed' ? 'bg-green-50' : 'bg-red-50'}`}>
                <h4 className="text-[11px] uppercase tracking-wide text-gray-600 font-bold mb-3">Hasil Inspeksi</h4>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-3 text-sm">
                  <div>
                    <span className="text-gray-500 text-xs">Hasil</span>
                    <div className="font-semibold">
                      <StatusBadge status={detailRow.result === 'Passed' ? 'Passed' : 'Failed'} />
                    </div>
                  </div>
                  {detailRow.defect_type && (
                    <div>
                      <span className="text-gray-500 text-xs">Jenis Defect</span>
                      <div className="font-semibold">{detailRow.defect_type}</div>
                    </div>
                  )}
                  {detailRow.action_taken && (
                    <div>
                      <span className="text-gray-500 text-xs">Tindakan</span>
                      <div className="font-semibold">{detailRow.action_taken}</div>
                    </div>
                  )}
                </div>
                {detailRow.defect_notes && (
                  <div className="mt-3 text-sm">
                    <span className="text-gray-500 text-xs">Catatan Defect</span>
                    <div className="mt-1 text-gray-700">{detailRow.defect_notes}</div>
                  </div>
                )}
              </div>
            )}

            {/* Action Buttons */}
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                onClick={() => setDetailRow(null)}
                className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
              >
                Tutup
              </button>
              {canWrite && detailRow.status !== 'Passed' && detailRow.status !== 'Failed' && (
                <>
                  <button
                    type="button"
                    onClick={() => openResult(detailRow)}
                    className="px-4 py-2 rounded-lg bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-700"
                  >
                    Tambah Hasil
                  </button>
                </>
              )}
            </div>
          </div>
        )}
      </Modal>

      {/* Result Modal */}
      <Modal open={resultOpen} onClose={() => setResultOpen(false)} title="Tambah Hasil Inspeksi" size="md">
        <form onSubmit={submitResult} className="space-y-4">
          <Field label="Nama Inspector" required>
            <TextInput
              value={resultForm.inspector_name}
              onChange={(e) => setResultForm((prev) => ({ ...prev, inspector_name: e.target.value }))}
              placeholder="Nama inspector"
              autoFocus
            />
          </Field>
          <Field label="Hasil Inspeksi" required>
            <Select
              value={resultForm.result}
              onChange={(e) => setResultForm((prev) => ({ ...prev, result: e.target.value }))}
            >
              <option value="Passed">Passed</option>
              <option value="Failed">Failed</option>
            </Select>
          </Field>
          {resultForm.result === 'Failed' && (
            <>
              <Field label="Jenis Defect">
                <Select
                  value={resultForm.defect_type}
                  onChange={(e) => setResultForm((prev) => ({ ...prev, defect_type: e.target.value }))}
                >
                  <option value="">Pilih jenis defect…</option>
                  <option value="Packaging">Packaging Damage</option>
                  <option value="Quality">Quality Degradation</option>
                  <option value="Labeling">Labeling Issue</option>
                  <option value="Contamination">Contamination</option>
                  <option value="Temperature">Temperature Deviation</option>
                  <option value="Other">Lainnya</option>
                </Select>
              </Field>
              <Field label="Catatan Defect">
                <TextArea
                  value={resultForm.defect_notes}
                  onChange={(e) => setResultForm((prev) => ({ ...prev, defect_notes: e.target.value }))}
                  rows={3}
                  placeholder="Deskripsi detail defect…"
                />
              </Field>
            </>
          )}
          <Field label="Tindakan yang Diambil">
            <TextArea
              value={resultForm.action_taken}
              onChange={(e) => setResultForm((prev) => ({ ...prev, action_taken: e.target.value }))}
              rows={2}
              placeholder="Tindakan yang diambil…"
            />
          </Field>
          <div className="flex justify-end gap-2 pt-1">
            <button
              type="button"
              onClick={() => setResultOpen(false)}
              className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={submitting}
              className={`px-4 py-2 rounded-lg text-white text-sm font-semibold disabled:opacity-50 ${
                resultForm.result === 'Passed'
                  ? 'bg-emerald-600 hover:bg-emerald-700'
                  : 'bg-red-600 hover:bg-red-700'
              }`}
            >
              {submitting ? 'Menyimpan…' : 'Simpan Hasil'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
