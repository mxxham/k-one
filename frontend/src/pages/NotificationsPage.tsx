import { FormEvent, useCallback, useEffect, useState } from 'react';
import {
  Search,
  Bell,
  Send,
  CheckCircle2,
  Trash2,
  Mail,
  Monitor,
  AlertTriangle,
  MailOpen,
} from 'lucide-react';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import Modal from '@/components/Modal';
import Spinner from '@/components/Spinner';
import { Field, TextInput, Select, TextArea } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { useConfirmDialog } from '@/hooks/useConfirmDialog';
import { fmtDate } from '@/lib/format';

// ─── Types ──────────────────────────────────────────────────────────────────

interface Notification {
  id: number;
  type: 'email' | 'system' | 'alert';
  title: string;
  message: string;
  recipient: string;
  status: 'Pending' | 'Sent' | 'Failed' | 'Read';
  module: string | null;
  reference_id: number | null;
  created_at: string;
  sent_at: string | null;
  read_at: string | null;
}

const STATUS_OPTIONS = ['Pending', 'Sent', 'Failed', 'Read'] as const;
const TYPE_OPTIONS = ['email', 'system', 'alert'] as const;

// ─── Helpers ────────────────────────────────────────────────────────────────

const TYPE_ICONS: Record<string, typeof Mail> = {
  email: Mail,
  system: Monitor,
  alert: AlertTriangle,
};

const TYPE_COLORS: Record<string, string> = {
  email: 'bg-brand-50 text-brand-600',
  system: 'bg-green-50 text-green-600',
  alert: 'bg-orange-50 text-orange-600',
};

const STATUS_DOT_COLORS: Record<string, string> = {
  Pending: 'bg-yellow-400',
  Sent: 'bg-green-500',
  Failed: 'bg-red-500',
  Read: 'bg-gray-400',
};

// ─── Component ──────────────────────────────────────────────────────────────

export default function NotificationsPage() {
  const toast = useToast();
  const { canWrite } = useAuth();
  const { confirm, ConfirmDialog } = useConfirmDialog();

  // ── List state ──
  const [rows, setRows] = useState<Notification[]>([]);
  const [loading, setLoading] = useState(true);
  const [totalPages, setTotalPages] = useState(1);
  const [page, setPage] = useState(1);
  const PER_PAGE = 25;

  // ── Filters ──
  const [q, setQ] = useState('');
  const [qApplied, setQApplied] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');

  // ── Send modal state ──
  const [sendOpen, setSendOpen] = useState(false);
  const [sendType, setSendType] = useState('email');
  const [sendTitle, setSendTitle] = useState('');
  const [sendMessage, setSendMessage] = useState('');
  const [sendRecipient, setSendRecipient] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // ── Detail modal state ──
  const [detailRow, setDetailRow] = useState<Notification | null>(null);

  // ── Data fetching ──
  const loadList = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api('notification', 'list', {
        params: {
          q: qApplied,
          status: statusFilter,
          type: typeFilter,
          page: String(page),
          per_page: String(PER_PAGE),
        },
      });
      setRows((res.data || []) as Notification[]);
      setTotalPages(res.total_pages || 1);
    } catch (e: any) {
      toast('error', e.message || 'Gagal memuat notifikasi');
    } finally {
      setLoading(false);
    }
  }, [qApplied, statusFilter, typeFilter, page, toast]);

  useEffect(() => {
    loadList();
  }, [loadList]);

  // Reset to page 1 when filters change
  useEffect(() => {
    setPage(1);
  }, [qApplied, statusFilter, typeFilter]);

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    setQApplied(q);
  };

  // ── Send notification ──
  const openSendModal = () => {
    setSendType('email');
    setSendTitle('');
    setSendMessage('');
    setSendRecipient('');
    setSendOpen(true);
  };

  const submitSend = async (e: FormEvent) => {
    e.preventDefault();
    if (!sendTitle.trim() || !sendMessage.trim() || !sendRecipient.trim()) {
      toast('error', 'Semua field wajib diisi');
      return;
    }
    setSubmitting(true);
    try {
      await api('notification', 'send', {
        method: 'POST',
        body: {
          type: sendType,
          title: sendTitle.trim(),
          message: sendMessage.trim(),
          recipient: sendRecipient.trim(),
        },
      });
      toast('success', 'Notifikasi berhasil dikirim');
      setSendOpen(false);
      loadList();
    } catch (err: any) {
      toast('error', err.message || 'Gagal mengirim notifikasi');
    } finally {
      setSubmitting(false);
    }
  };

  // ── Mark as read ──
  const markAsRead = async (id: number) => {
    try {
      await api('notification', 'mark_read', {
        method: 'POST',
        body: { id },
      });
      toast('success', 'Ditandai sudah dibaca');
      loadList();
    } catch (err: any) {
      toast('error', err.message || 'Gagal menandai notifikasi');
    }
  };

  // ── Delete ──
  const deleteNotification = async (id: number) => {
    if (!(await confirm('Hapus notifikasi ini?'))) return;
    try {
      await api('notification', 'delete', {
        method: 'POST',
        body: { id },
      });
      toast('success', 'Notifikasi berhasil dihapus');
      setDetailRow(null);
      loadList();
    } catch (err: any) {
      toast('error', err.message || 'Gagal menghapus notifikasi');
    }
  };

  // ── Detail modal ──
  const openDetail = (row: Notification) => {
    setDetailRow(row);
  };

  // ── Render ──
  return (
    <div>
      <PageHeader
        title="Notifications"
        subtitle="Kelola notifikasi sistem, email, dan alert"
        actions={
          canWrite ? (
            <button
              onClick={openSendModal}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25"
            >
              <Send className="w-4 h-4" /> Kirim Notifikasi
            </button>
          ) : undefined
        }
      />

      {/* ── Summary Cards ── */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
        {[
          { label: 'Total', value: rows.length, icon: <Bell className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
          { label: 'Pending', value: rows.filter((r) => r.status === 'Pending').length, icon: <Send className="w-4 h-4" />, cls: 'bg-yellow-50 text-yellow-600' },
          { label: 'Terkirim', value: rows.filter((r) => r.status === 'Sent').length, icon: <CheckCircle2 className="w-4 h-4" />, cls: 'bg-green-50 text-green-600' },
          { label: 'Gagal', value: rows.filter((r) => r.status === 'Failed').length, icon: <AlertTriangle className="w-4 h-4" />, cls: 'bg-red-50 text-red-600' },
        ].map((c) => (
          <div key={c.label} className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
            <div className={`w-8 h-8 rounded-lg ${c.cls} flex items-center justify-center mb-2`}>{c.icon}</div>
            <div className="text-xl font-bold text-brand-900">{c.value}</div>
            <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">{c.label}</div>
          </div>
        ))}
      </div>

      {/* ── Filters ── */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4 mb-5">
        <form onSubmit={handleSearch} className="flex flex-wrap items-end gap-3">
          <div className="w-full md:w-72">
            <Field label="Cari">
              <div className="relative">
                <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
                <input
                  value={q}
                  onChange={(e) => setQ(e.target.value)}
                  placeholder="Judul, pesan, penerima…"
                  className="w-full pl-9 pr-3 py-2 border-[1.5px] border-gray-300 rounded-lg text-sm focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 outline-none"
                />
              </div>
            </Field>
          </div>
          <div className="w-44">
            <Field label="Status">
              <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
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
            <Field label="Tipe">
              <Select value={typeFilter} onChange={(e) => setTypeFilter(e.target.value)}>
                <option value="">Semua</option>
                {TYPE_OPTIONS.map((t) => (
                  <option key={t} value={t}>
                    {t.charAt(0).toUpperCase() + t.slice(1)}
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

      {/* ── Table ── */}
      <div className="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-5">
        <div className="px-5 py-3.5 border-b border-gray-100 bg-brand-50/50 flex items-center justify-between">
          <h3 className="font-bold text-sm text-brand-700">Daftar Notifikasi</h3>
          <span className="text-xs text-gray-500">
            Halaman {page} dari {totalPages}
          </span>
        </div>
        {loading ? (
          <Spinner label="Memuat notifikasi…" />
        ) : rows.length === 0 ? (
          <EmptyState message="Tidak ada data notifikasi" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[900px]">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold w-10">Tipe</th>
                  <th className="px-3 py-2.5 text-left font-bold">Judul</th>
                  <th className="px-3 py-2.5 text-left font-bold">Penerima</th>
                  <th className="px-3 py-2.5 text-left font-bold">Status</th>
                  <th className="px-3 py-2.5 text-left font-bold">Modul</th>
                  <th className="px-3 py-2.5 text-left font-bold">Dibuat</th>
                  <th className="px-3 py-2.5 text-right font-bold">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {rows.map((r) => {
                  const TypeIcon = TYPE_ICONS[r.type] || Bell;
                  const typeColor = TYPE_COLORS[r.type] || 'bg-gray-50 text-gray-600';
                  return (
                    <tr
                      key={r.id}
                      className="hover:bg-brand-50/50 cursor-pointer"
                      onClick={() => openDetail(r)}
                    >
                      <td className="px-3 py-2.5">
                        <div className={`w-8 h-8 rounded-lg ${typeColor} flex items-center justify-center`}>
                          <TypeIcon className="w-4 h-4" />
                        </div>
                      </td>
                      <td className="px-3 py-2.5">
                        <div className="font-semibold text-brand-800">{r.title}</div>
                        <div className="text-xs text-gray-500 truncate max-w-xs">{r.message}</div>
                      </td>
                      <td className="px-3 py-2.5 text-gray-600">{r.recipient}</td>
                      <td className="px-3 py-2.5">
                        <div className="flex items-center gap-2">
                          <span className={`w-2 h-2 rounded-full ${STATUS_DOT_COLORS[r.status]}`} />
                          <StatusBadge status={r.status} />
                        </div>
                      </td>
                      <td className="px-3 py-2.5 text-gray-600">{r.module || '—'}</td>
                      <td className="px-3 py-2.5 text-gray-600">{fmtDate(r.created_at)}</td>
                      <td className="px-3 py-2.5 text-right" onClick={(e) => e.stopPropagation()}>
                        <div className="inline-flex items-center gap-1">
                          {r.status !== 'Read' && canWrite && (
                            <button
                              onClick={() => markAsRead(r.id)}
                              className="p-1.5 rounded-lg text-gray-400 hover:text-brand-600 hover:bg-brand-50"
                              title="Tandai sudah dibaca"
                            >
                              <MailOpen className="w-4 h-4" />
                            </button>
                          )}
                          {canWrite && (
                            <button
                              onClick={() => deleteNotification(r.id)}
                              className="p-1.5 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50"
                              title="Hapus"
                            >
                              <Trash2 className="w-4 h-4" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}

        {/* ── Pagination ── */}
        {!loading && totalPages > 1 && (
          <div className="px-5 py-3 border-t border-gray-100 flex items-center justify-between">
            <span className="text-xs text-gray-500">
              {rows.length} notifikasi ditampilkan
            </span>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                Sebelumnya
              </button>
              {Array.from({ length: totalPages }, (_, i) => i + 1)
                .filter((p) => p === 1 || p === totalPages || Math.abs(p - page) <= 2)
                .reduce<(number | 'dots')[]>((acc, p, idx, arr) => {
                  if (idx > 0 && p - (arr[idx - 1] as number) > 1) acc.push('dots');
                  acc.push(p);
                  return acc;
                }, [])
                .map((p, idx) =>
                  p === 'dots' ? (
                    <span key={`dots-${idx}`} className="text-gray-400 text-xs">…</span>
                  ) : (
                    <button
                      key={p}
                      onClick={() => setPage(p as number)}
                      className={`px-3 py-1.5 rounded-lg text-xs font-semibold ${
                        page === p
                          ? 'bg-brand-600 text-white'
                          : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                      }`}
                    >
                      {p}
                    </button>
                  ),
                )}
              <button
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                className="px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 text-xs font-semibold hover:bg-gray-200 disabled:opacity-40 disabled:cursor-not-allowed"
              >
                Berikutnya
              </button>
            </div>
          </div>
        )}
      </div>

      {/* ── Send Modal ── */}
      <Modal open={sendOpen} onClose={() => setSendOpen(false)} title="Kirim Notifikasi" size="sm">
        <form onSubmit={submitSend} className="space-y-4">
          <Field label="Tipe" required>
            <Select value={sendType} onChange={(e) => setSendType(e.target.value)}>
              <option value="email">Email</option>
              <option value="system">System</option>
              <option value="alert">Alert</option>
            </Select>
          </Field>
          <Field label="Judul" required>
            <TextInput
              value={sendTitle}
              onChange={(e) => setSendTitle(e.target.value)}
              placeholder="Judul notifikasi…"
              autoFocus
            />
          </Field>
          <Field label="Pesan" required>
            <TextArea
              value={sendMessage}
              onChange={(e) => setSendMessage(e.target.value)}
              rows={4}
              placeholder="Isi pesan notifikasi…"
            />
          </Field>
          <Field label="Penerima" required>
            <TextInput
              value={sendRecipient}
              onChange={(e) => setSendRecipient(e.target.value)}
              placeholder="Email atau nama penerima…"
            />
          </Field>
          <div className="flex justify-end gap-2 pt-1">
            <button
              type="button"
              onClick={() => setSendOpen(false)}
              className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
            >
              Batal
            </button>
            <button
              type="submit"
              disabled={submitting || !sendTitle.trim() || !sendMessage.trim() || !sendRecipient.trim()}
              className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 disabled:opacity-50 inline-flex items-center gap-2"
            >
              {submitting ? 'Mengirim…' : <><Send className="w-4 h-4" /> Kirim</>}
            </button>
          </div>
        </form>
      </Modal>

      {/* ── Detail Modal ── */}
      <Modal open={!!detailRow} onClose={() => setDetailRow(null)} title="Detail Notifikasi" size="md">
        {detailRow && (
          <div className="space-y-5">
            {/* Header info */}
            <div className="rounded-lg bg-brand-50 p-4">
              <h4 className="text-[11px] uppercase tracking-wide text-brand-600 font-bold mb-3">Informasi Notifikasi</h4>
              <div className="grid grid-cols-2 gap-3 text-sm">
                <div>
                  <span className="text-gray-500 text-xs">ID</span>
                  <div className="font-semibold">#{detailRow.id}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Tipe</span>
                  <div className="font-semibold capitalize">{detailRow.type}</div>
                </div>
                <div className="col-span-2">
                  <span className="text-gray-500 text-xs">Judul</span>
                  <div className="font-semibold">{detailRow.title}</div>
                </div>
                <div className="col-span-2">
                  <span className="text-gray-500 text-xs">Pesan</span>
                  <div className="font-semibold whitespace-pre-wrap">{detailRow.message}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Penerima</span>
                  <div className="font-semibold">{detailRow.recipient}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Status</span>
                  <div className="font-semibold">
                    <StatusBadge status={detailRow.status} />
                  </div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Modul</span>
                  <div className="font-semibold">{detailRow.module || '—'}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Reference ID</span>
                  <div className="font-semibold">{detailRow.reference_id ?? '—'}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Dibuat</span>
                  <div className="font-semibold">{fmtDate(detailRow.created_at)}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Dikirim</span>
                  <div className="font-semibold">{fmtDate(detailRow.sent_at)}</div>
                </div>
                <div>
                  <span className="text-gray-500 text-xs">Dibaca</span>
                  <div className="font-semibold">{fmtDate(detailRow.read_at)}</div>
                </div>
              </div>
            </div>

            {/* Actions */}
            <div className="flex justify-end gap-2 pt-1">
              {detailRow.status !== 'Read' && canWrite && (
                <button
                  onClick={() => {
                    markAsRead(detailRow.id);
                    setDetailRow(null);
                  }}
                  className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 inline-flex items-center gap-2"
                >
                  <MailOpen className="w-4 h-4" /> Tandai Dibaca
                </button>
              )}
              {canWrite && (
                <button
                  onClick={() => deleteNotification(detailRow.id)}
                  className="px-4 py-2 rounded-lg bg-red-600 text-white text-sm font-semibold hover:bg-red-700 inline-flex items-center gap-2"
                >
                  <Trash2 className="w-4 h-4" /> Hapus
                </button>
              )}
              <button
                onClick={() => setDetailRow(null)}
                className="px-4 py-2 rounded-lg bg-gray-100 text-gray-700 text-sm font-semibold hover:bg-gray-200"
              >
                Tutup
              </button>
            </div>
          </div>
        )}
      </Modal>
      <ConfirmDialog />
    </div>
  );
}
