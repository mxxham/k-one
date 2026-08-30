import { useState, useEffect, useCallback, useRef } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import {
  ArrowLeft,
  PlayCircle,
  CheckCircle,
  XCircle,
  RefreshCw,
  Truck,
  CalendarClock,
  Package,
} from 'lucide-react';
import { api } from '@/lib/api';
import { fmtDateTime, fmtNum } from '@/lib/format';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';
import Spinner from '@/components/Spinner';
import ConfirmButton from '@/components/ConfirmButton';

interface WaveOrder {
  id: number;
  order_number: string;
  order_date: string;
  so_number: string | null;
  do_number: string | null;
  destination: string | null;
  kota: string | null;
  armada_no: string | null;
  container_no: string | null;
  customer_name: string;
  customer_code: string;
  total_items: number;
  total_qty: number;
}

interface WavePicklist {
  id: number;
  picklist_number: string;
  status: string;
}

interface WaveDetailData {
  id: number;
  wave_number: string;
  status: string;
  carrier: string | null;
  cutoff_time: string | null;
  order_count: number;
  item_count: number;
  created_by_name: string | null;
  created_at: string;
  updated_at: string;
  orders: WaveOrder[];
  picklist: WavePicklist | null;
}

export default function WaveDetail() {
  const { id } = useParams<{ id: string }>();
  const { canWrite } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();

  const [wave, setWave] = useState<WaveDetailData | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const abortRef = useRef<AbortController | null>(null);

  const load = useCallback(async () => {
    abortRef.current?.abort();
    const ctrl = new AbortController();
    abortRef.current = ctrl;

    setLoading(true);
    try {
      const res = await api('waves', 'detail', { params: { id }, signal: ctrl.signal });
      if (!ctrl.signal.aborted) {
        setWave((res.wave || null) as WaveDetailData | null);
      }
    } catch (err: any) {
      if (err.name === 'AbortError') return;
      toast('error', err.message || 'Gagal memuat detail wave');
    } finally {
      if (!ctrl.signal.aborted) {
        setLoading(false);
      }
    }
  }, [id, toast]);

  useEffect(() => {
    load();
    return () => abortRef.current?.abort();
  }, [load]);

  const handleAction = async (action: string, confirmMsg: string, successMsg: string) => {
    if (!window.confirm(confirmMsg)) return;

    setBusy(true);
    try {
      await api('waves', action, { method: 'POST', body: { id } });
      toast('success', successMsg);
      load();
    } catch (err: any) {
      toast('error', err.message || 'Operasi gagal');
    } finally {
      setBusy(false);
    }
  };

  if (loading) {
    return (
      <div>
        <PageHeader title="Wave Detail" />
        <Spinner label="Memuat detail…" />
      </div>
    );
  }

  if (!wave) {
    return (
      <div>
        <PageHeader title="Wave Detail" />
        <Card>
          <EmptyState message="Wave tidak ditemukan" />
        </Card>
      </div>
    );
  }

  const status = wave.status || '';
  const isPlanning = status === 'Planning';
  const isActive = status === 'Active';

  const actions = (
    <>
      <button
        onClick={() => navigate('/waves')}
        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white/25 hover:bg-white/35 text-white text-sm font-semibold border border-white/40"
      >
        <ArrowLeft className="w-4 h-4" /> Kembali
      </button>

      {canWrite && isPlanning && (
        <button
          disabled={busy}
          onClick={() =>
            handleAction('release', `Release wave ${wave.wave_number}?`, 'Wave dirilis untuk picking')
          }
          className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold disabled:opacity-60"
        >
          <PlayCircle className="w-4 h-4" /> Release
        </button>
      )}

      {canWrite && isActive && (
        <>
          <button
            disabled={busy}
            onClick={() =>
              handleAction('complete', `Selesaikan wave ${wave.wave_number}?`, 'Wave diselesaikan')
            }
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white text-brand-700 hover:bg-brand-50 text-sm font-semibold disabled:opacity-60"
          >
            <CheckCircle className="w-4 h-4" /> Complete
          </button>
          <button
            disabled={busy}
            onClick={() =>
              handleAction('cancel', `Batalkan wave ${wave.wave_number}?`, 'Wave dibatalkan')
            }
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-semibold disabled:opacity-60"
          >
            <XCircle className="w-4 h-4" /> Cancel
          </button>
        </>
      )}

      <button
        onClick={load}
        className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-sm font-semibold border border-white/20"
      >
        <RefreshCw className="w-4 h-4" /> Refresh
      </button>
    </>
  );

  return (
    <div>
      <PageHeader
        title={wave.wave_number || `Wave #${wave.id}`}
        subtitle={`Status: ${status}`}
        actions={actions}
      />

      {/* KPI Cards */}
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-5">
        <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
          <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
            Status
          </div>
          <StatusBadge status={status} />
        </div>

        <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
          <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
            Orders
          </div>
          <div className="text-2xl font-extrabold text-brand-700">{wave.order_count}</div>
        </div>

        <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
          <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
            Items
          </div>
          <div className="text-2xl font-extrabold text-brand-700">{wave.item_count}</div>
        </div>

        <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
          <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
            Created At
          </div>
          <div className="text-sm font-medium text-gray-800">{fmtDateTime(wave.created_at)}</div>
        </div>
      </div>

      {/* Wave Information */}
      <Card title="Informasi Wave">
        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div>
            <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
              Wave Number
            </div>
            <div className="text-sm font-mono font-semibold text-brand-700">{wave.wave_number}</div>
          </div>

          {wave.carrier && (
            <div>
              <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                Carrier / Armada
              </div>
              <div className="flex items-center gap-2">
                <Truck className="w-4 h-4 text-brand-500" />
                <span className="text-sm font-medium text-gray-800">{wave.carrier}</span>
              </div>
            </div>
          )}

          {wave.cutoff_time && (
            <div>
              <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                Cutoff Time
              </div>
              <div className="flex items-center gap-2">
                <CalendarClock className="w-4 h-4 text-brand-500" />
                <span className="text-sm font-medium text-gray-800">
                  {fmtDateTime(wave.cutoff_time)}
                </span>
              </div>
            </div>
          )}

          <div>
            <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
              Created By
            </div>
            <div className="text-sm font-medium text-gray-800">{wave.created_by_name || '—'}</div>
          </div>

          {wave.picklist && (
            <div>
              <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">
                Picklist
              </div>
              <Link
                to={`/picklist/${wave.picklist.id}`}
                className="inline-flex items-center gap-2 text-sm font-medium text-brand-600 hover:underline"
              >
                <Package className="w-4 h-4" />
                {wave.picklist.picklist_number}
              </Link>
              <div className="mt-1">
                <StatusBadge status={wave.picklist.status} />
              </div>
            </div>
          )}
        </div>
      </Card>

      {/* Outbound Orders Table */}
      <Card title={`Outbound Orders (${wave.orders.length})`}>
        {wave.orders.length === 0 ? (
          <EmptyState message="Tidak ada outbound order dalam wave ini" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-[11px] uppercase tracking-wide text-gray-500">
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Order</th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Customer</th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Tujuan</th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Kota</th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold text-right">
                    Items
                  </th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold text-right">
                    Qty
                  </th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">SO</th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">DO</th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold">Armada</th>
                  <th className="px-3 py-2.5 bg-brand-50 text-brand-700 font-bold text-right">
                    Aksi
                  </th>
                </tr>
              </thead>
              <tbody>
                {wave.orders.map((order) => (
                  <tr key={order.id} className="border-t border-gray-100 hover:bg-brand-50 transition-colors">
                    <td className="px-3 py-2.5">
                      <Link
                        to={`/outbound/${order.id}`}
                        className="font-mono text-xs text-brand-600 hover:underline"
                      >
                        {order.order_number}
                      </Link>
                      <div className="text-[11px] text-gray-400">{fmtDateTime(order.order_date)}</div>
                    </td>
                    <td className="px-3 py-2.5">
                      <div className="text-sm font-semibold text-gray-800">{order.customer_name}</div>
                      <div className="text-[11px] text-gray-400">{order.customer_code}</div>
                    </td>
                    <td className="px-3 py-2.5 text-gray-700">{order.destination || '—'}</td>
                    <td className="px-3 py-2.5 text-gray-700">{order.kota || '—'}</td>
                    <td className="px-3 py-2.5 text-right text-gray-700">{order.total_items}</td>
                    <td className="px-3 py-2.5 text-right text-gray-700">{fmtNum(order.total_qty, 2)}</td>
                    <td className="px-3 py-2.5 text-gray-600 text-xs font-mono">{order.so_number || '—'}</td>
                    <td className="px-3 py-2.5 text-gray-600 text-xs font-mono">{order.do_number || '—'}</td>
                    <td className="px-3 py-2.5 text-gray-600 text-xs">{order.armada_no || '—'}</td>
                    <td className="px-3 py-2.5 text-right">
                      <Link
                        to={`/outbound/${order.id}`}
                        className="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-brand-50 text-brand-700 border border-brand-200 text-xs font-semibold hover:bg-brand-100"
                      >
                        Lihat
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
