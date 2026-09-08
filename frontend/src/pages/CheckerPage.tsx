import { useCallback, useEffect, useState } from 'react';
import { ArrowLeft, CheckCircle2, XCircle, ClipboardCheck } from 'lucide-react';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import Spinner from '@/components/Spinner';
import ScanInput from '@/components/ScanInput';
import { TextInput } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { api } from '@/lib/api';
import { fmtNum } from '@/lib/format';

interface PendingLine {
  id: number;
  outbound_item_id: number;
  outbound_id: number;
  order_number: string;
  product_id: number;
  product_code: string;
  product_name: string;
  lpn_code: string | null;
  location: string | null;
  qty: number;
  check_status: string;
}

type Step = 'lpn' | 'sku' | 'override';
interface Mismatch {
  step: 'lpn' | 'sku';
  code: string;
}

/**
 * Checker page — dual-scan (LPN + SKU) verification for outbound picking.
 * Mirrors PutawayScanPage's step-based wizard pattern exactly.
 */
export default function CheckerPage() {
  const toast = useToast();
  const { user } = useAuth();
  const role = user?.role;

  const [lines, setLines] = useState<PendingLine[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshKey, setRefreshKey] = useState(0);

  const [selected, setSelected] = useState<PendingLine | null>(null);
  const [step, setStep] = useState<Step>('lpn');
  const [busy, setBusy] = useState(false);
  const [mismatch, setMismatch] = useState<Mismatch | null>(null);
  const [overrideReason, setOverrideReason] = useState('');
  const [lastMatched, setLastMatched] = useState<string | null>(null);

  const loadLines = useCallback(async () => {
    try {
      setLoading(true);
      const res = await api('checker', 'pending_lines');
      setLines((res.lines || []) as PendingLine[]);
    } catch (err: any) {
      toast('error', err.message || 'Gagal memuat item');
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    loadLines();
  }, [loadLines, refreshKey]);

  const openLine = (line: PendingLine) => {
    setSelected(line);
    setStep('lpn');
    setMismatch(null);
    setOverrideReason('');
    setLastMatched(null);
  };

  const backToList = () => {
    setSelected(null);
    setStep('lpn');
    setMismatch(null);
    setOverrideReason('');
    setLastMatched(null);
    setRefreshKey((k) => k + 1);
  };

  const confirmLine = async (scannedLpn: string, scannedSku: string, reason?: string) => {
    if (!selected) return;
    try {
      setBusy(true);
      await api('checker', 'confirm_line', {
        body: {
          id: selected.id,
          scanned_lpn: scannedLpn,
          scanned_sku: scannedSku,
          override_reason: reason || null,
        },
      });
      toast('success', 'Item berhasil dicek.');
      backToList();
    } catch (err: any) {
      toast('error', err.message || 'Gagal konfirmasi item');
    } finally {
      setBusy(false);
    }
  };

  const handleLpnScan = async (raw: string) => {
    if (!selected || busy) return;
    const code = raw.trim().toUpperCase();
    const expected = (selected.lpn_code || '').trim().toUpperCase();
    if (expected && code === expected) {
      setLastMatched('LPN cocok ✓');
      setStep('sku');
      return;
    }
    setMismatch({ step: 'lpn', code: raw });
    setStep('override');
  };

  const handleSkuScan = async (raw: string) => {
    if (!selected || busy) return;
    const code = raw.trim().toUpperCase();
    const expected = (selected.product_code || '').trim().toUpperCase();
    if (expected && code === expected) {
      await confirmLine(selected.lpn_code || '', code);
      return;
    }
    setMismatch({ step: 'sku', code: raw });
    setStep('override');
  };

  const handleOverrideContinue = async () => {
    if (!mismatch || !selected) return;
    if (!overrideReason.trim()) return toast('error', 'Alasan override wajib diisi.');
    const lpn = mismatch.step === 'lpn' ? mismatch.code : selected.lpn_code || '';
    const sku = mismatch.step === 'sku' ? mismatch.code : selected.product_code || '';
    await confirmLine(lpn, sku, overrideReason.trim());
  };

  return (
    <div className="max-w-md mx-auto">
      <PageHeader title="Checker" subtitle="Verifikasi item dengan scan LPN lalu scan SKU." />

      {!selected ? (
        <Card title="Item perlu dicek">
          {loading ? (
            <Spinner label="Memuat item…" />
          ) : lines.length === 0 ? (
            <EmptyState message="Tidak ada item yang perlu dicek." />
          ) : (
            <ul className="divide-y divide-gray-100">
              {lines.map((line) => (
                <li key={line.id}>
                  <button onClick={() => openLine(line)} className="w-full text-left px-1 py-3 hover:bg-gray-50 rounded-lg">
                    <div className="flex items-center justify-between">
                      <span className="font-mono text-xs font-bold text-brand-700">{line.order_number}</span>
                      <span className="text-[10px] font-bold text-amber-600 bg-amber-50 border border-amber-200 px-2 py-0.5 rounded-full">
                        {line.check_status}
                      </span>
                    </div>
                    <div className="font-semibold text-sm mt-1">{line.product_name || line.product_code}</div>
                    <div className="text-xs text-gray-500">{line.product_code}</div>
                    <div className="flex justify-between text-xs mt-1">
                      <span>
                        Qty: <span className="font-semibold">{fmtNum(line.qty)}</span>
                      </span>
                      <span>
                        LPN: <span className="font-semibold font-mono">{line.lpn_code || '—'}</span>
                      </span>
                    </div>
                    <div className="text-xs text-gray-500 mt-0.5">
                      Lokasi: <span className="font-semibold">{line.location || '—'}</span>
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>
      ) : (
        <Card title={`${selected.order_number} — ${selected.product_code}`}>
          <div className="mb-3 flex items-center justify-between gap-2">
            <button onClick={backToList} className="inline-flex items-center gap-1 text-sm font-semibold text-brand-700 hover:text-brand-900">
              <ArrowLeft className="w-4 h-4" /> Daftar Item
            </button>
          </div>

          <div className="space-y-4">
            <div className="rounded-xl border border-gray-200 bg-gray-50 p-3">
              <div className="flex items-center justify-between">
                <span className="text-[10px] font-bold text-gray-500 uppercase tracking-wide">Order</span>
                <span className="font-mono text-xs font-semibold">{selected.order_number}</span>
              </div>
              <div className="font-semibold text-sm mt-1">{selected.product_name || selected.product_code}</div>
              <div className="text-xs text-gray-500">{selected.product_code}</div>
              <div className="flex justify-between text-xs mt-1">
                <span>
                  Qty: <span className="font-semibold">{fmtNum(selected.qty)}</span>
                </span>
                <span>
                  LPN: <span className="font-semibold font-mono">{selected.lpn_code || '—'}</span>
                </span>
              </div>
              <div className="text-xs text-gray-500 mt-0.5">
                Lokasi: <span className="font-semibold">{selected.location || '—'}</span>
              </div>
            </div>

            {lastMatched && (
              <div className="flex items-center gap-1.5 text-emerald-700 text-xs font-semibold bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-2">
                <CheckCircle2 className="w-4 h-4" /> {lastMatched}
              </div>
            )}

            {step === 'lpn' && (
              <ScanInput onScan={handleLpnScan} placeholder="Scan LPN pallet…" className="mt-1" disabled={busy} />
            )}

            {step === 'sku' && (
              <ScanInput onScan={handleSkuScan} placeholder="Scan barcode SKU…" className="mt-1" disabled={busy} />
            )}

            {step === 'override' && mismatch && (
              <div className="rounded-xl border border-red-300 bg-red-50 p-3 space-y-2">
                <div className="flex items-start gap-1.5 text-red-700 text-xs font-semibold">
                  <XCircle className="w-4 h-4 mt-0.5" />
                  <span>
                    Scan{' '}
                    <span className="font-mono bg-white px-1 rounded">'{mismatch.code}'</span> tidak cocok dengan{' '}
                    {mismatch.step === 'lpn' ? `LPN '${selected.lpn_code}'` : `SKU '${selected.product_code}'`}.
                  </span>
                </div>
                <TextInput
                  value={overrideReason}
                  onChange={(e) => setOverrideReason(e.target.value)}
                  placeholder="Alasan override…"
                />
                <div className="flex items-center gap-2">
                  {role === 'supervisor' || role === 'admin' ? (
                    <button
                      onClick={handleOverrideContinue}
                      disabled={busy}
                      className="px-3 py-1.5 rounded-lg bg-red-600 text-white text-xs font-semibold hover:bg-red-700 disabled:opacity-50"
                    >
                      Override & Lanjut
                    </button>
                  ) : (
                    <span className="text-xs text-red-600 font-semibold">Override memerlukan izin supervisor.</span>
                  )}
                  <button
                    onClick={() => setMismatch(null)}
                    className="px-3 py-1.5 rounded-lg bg-gray-200 text-gray-700 text-xs font-semibold hover:bg-gray-300"
                  >
                    Batal
                  </button>
                </div>
              </div>
            )}
          </div>
        </Card>
      )}
    </div>
  );
}
