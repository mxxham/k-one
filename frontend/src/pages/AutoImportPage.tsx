import { DragEvent, useRef, useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import {
  Wand2, UploadCloud, FileCheck2, X, RefreshCw, CheckCircle2,
  AlertTriangle, Boxes, PackagePlus, Truck, PackageOpen, Layers,
} from 'lucide-react';
import { uploadApi } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { Card } from '@/components/Card';

interface AutoStats {
  products_created?: number;
  products_updated?: number;
  stock_imported?: number;
  stock_skipped?: number;
  stock_auto_created?: number;
  inbound_orders?: number;
  inbound_items?: number;
  outbound_orders?: number;
  outbound_items?: number;
  outbound_skipped?: number;
  skipped_sheets?: string[];
}

interface AutoResult {
  message?: string;
  stats?: AutoStats;
  log?: string[];
}

const SHEET_LEGEND: { label: string; skip?: boolean }[] = [
  { label: 'master data → Produk' },
  { label: 'WMS → Stock + Inbound (group by GR date)' },
  { label: 'data putaway → Stock' },
  { label: 'schedule of the day → Outbound (FEFO)' },
  { label: 'data level A / summary / SAP vs unrest / picking', skip: true },
];

const CHIP = 'inline-block bg-brand-50 border border-brand-200 text-brand-800 rounded-full px-3 py-1 text-[11px] font-semibold mr-1.5 mb-1.5';
const CHIP_SKIP = 'inline-block bg-gray-100 border border-gray-200 text-gray-500 rounded-full px-3 py-1 text-[11px] font-semibold mr-1.5 mb-1.5';

function StatTile({ label, value, icon: Icon, tone = 'brand' }: { label: string; value: number | string; icon: typeof Boxes; tone?: 'brand' | 'green' | 'warn' }) {
  const cls = {
    brand: 'bg-brand-50 text-brand-800',
    green: 'bg-emerald-50 text-emerald-700',
    warn: 'bg-amber-50 text-amber-700',
  }[tone];
  return (
    <div className={`rounded-xl ${cls} p-4 text-center`}>
      <Icon className="w-5 h-5 mx-auto mb-1 opacity-70" strokeWidth={1.6} />
      <div className="text-2xl font-extrabold">{value}</div>
      <div className="text-[11px] font-semibold uppercase tracking-wide opacity-70 mt-0.5">{label}</div>
    </div>
  );
}

export default function AutoImportPage() {
  const [file, setFile] = useState<File | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [result, setResult] = useState<AutoResult | null>(null);
  const [startTime, setStartTime] = useState<number | null>(null);
  const [elapsedTime, setElapsedTime] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null);
  const [drag, setDrag] = useState(false);

  useEffect(() => {
    if (busy && startTime) {
      intervalRef.current = setInterval(() => {
        setElapsedTime(Math.floor((Date.now() - startTime) / 1000));
      }, 1000);
    } else {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
        intervalRef.current = null;
      }
    }
    return () => {
      if (intervalRef.current) {
        clearInterval(intervalRef.current);
      }
    };
  }, [busy, startTime]);

  const drop = (e: DragEvent) => {
    e.preventDefault();
    setDrag(false);
    const f = e.dataTransfer.files?.[0];
    if (f && (f.name.endsWith('.xlsx') || f.name.endsWith('.xls'))) setFile(f);
    else setError('Pilih file .xlsx atau .xls');
  };

  const run = async () => {
    if (!file) return;
    const now = Date.now();
    setStartTime(now);
    setElapsedTime(0);
    setBusy(true);
    setError('');
    setResult(null);
    const fd = new FormData();
    fd.append('excel_file', file, file.name);
    try {
      const res = await uploadApi('import', 'auto', fd);
      setResult(res as AutoResult);
    } catch (e: any) {
      setError(e.message || 'Gagal memproses file');
    } finally {
      setBusy(false);
      setStartTime(null);
    }
  };

  const reset = () => {
    setFile(null);
    setResult(null);
    setError('');
    if (inputRef.current) inputRef.current.value = '';
  };

  const s = result?.stats || {};

  return (
    <div>
      <PageHeader
        title="Auto Import"
        subtitle="Satu file Excel → Master data, Stock, Inbound (GR), & Outbound otomatis"
        actions={
          <Link to="/import" className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25">
            Import Manual
          </Link>
        }
      />

      <Card title="Sheet yang diproses otomatis">
        <div>
          {SHEET_LEGEND.map((item) => (
            <span key={item.label} className={item.skip ? CHIP_SKIP : CHIP}>{item.label}</span>
          ))}
        </div>
      </Card>

      <Card title="Upload & Proses">
        <input ref={inputRef} type="file" accept=".xlsx,.xls" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) setFile(f); }} />
        {!file ? (
          <div
            onClick={() => inputRef.current?.click()}
            onDragOver={(e) => { e.preventDefault(); setDrag(true); }}
            onDragLeave={() => setDrag(false)}
            onDrop={drop}
            className={`cursor-pointer border-3 border-dashed rounded-2xl py-14 text-center transition-colors ${
              drag ? 'border-indigo-400 bg-indigo-50/60' : 'border-brand-300 bg-brand-50/40 hover:bg-brand-50'
            }`}
          >
            <Wand2 className="w-12 h-12 text-brand-600 mx-auto mb-3" strokeWidth={1.4} />
            <p className="text-sm font-semibold text-gray-600">Drag & drop file Excel (multi-sheet), atau klik untuk pilih</p>
            <p className="text-xs text-gray-400 mt-1">Format: .xlsx / .xls — semua sheet diproses dalam satu aksi</p>
          </div>
        ) : (
          <div className="flex items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3">
            <div className="flex items-center gap-3 min-w-0">
              <FileCheck2 className="w-6 h-6 text-emerald-600 flex-shrink-0" />
              <div className="min-w-0">
                <div className="text-sm font-semibold text-gray-800 truncate">{file.name}</div>
                <div className="text-xs text-gray-500">{(file.size / 1024).toFixed(1)} KB</div>
              </div>
            </div>
            <button onClick={reset} className="flex items-center gap-1 text-xs font-semibold text-brand-700 hover:text-brand-900 flex-shrink-0">
              <X className="w-3.5 h-3.5" /> Hapus
            </button>
          </div>
        )}

        <button
          onClick={run}
          disabled={!file || busy}
          className="w-full mt-4 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-lg bg-gradient-to-br from-brand-700 to-brand-600 text-white text-sm font-bold hover:from-brand-800 hover:to-brand-700 disabled:opacity-40 disabled:cursor-not-allowed transition"
        >
          {busy ? (
            <span className="inline-block w-4 h-4 border-2 border-white/40 border-t-white rounded-full animate-spin" />
          ) : (
            <UploadCloud className="w-4 h-4" />
          )}
          {busy ? 'Memproses semua sheet...' : 'Proses Auto Import Sekali Jalan'}
        </button>

        {busy && (
          <div className="mt-4 space-y-2">
            <div className="flex items-center justify-between text-xs text-gray-600">
              <span>Waktu proses</span>
              <span className="font-mono font-semibold text-brand-700">
                {Math.floor(elapsedTime / 60)}:{String(elapsedTime % 60).padStart(2, '0')}
              </span>
            </div>
            <div className="h-2 bg-gray-100 rounded-full overflow-hidden">
              <div
                className="h-full bg-gradient-to-r from-brand-500 via-brand-600 to-brand-500 rounded-full animate-pulse"
                style={{ width: `${Math.min(100, (elapsedTime / 120) * 100)}%` }}
              />
            </div>
            <p className="text-xs text-gray-500 text-center">
              Estimasi selesai ~2 menit (bergantung ukuran file)
            </p>
          </div>
        )}
      </Card>

      {error && (
        <div className="mb-5 px-4 py-3 rounded-lg bg-red-50 text-red-700 text-sm border border-red-200 flex items-start gap-2">
          <AlertTriangle className="w-4 h-4 mt-0.5 flex-shrink-0" /> {error}
        </div>
      )}

      {result && (
        <Card title="Hasil Auto Import" actions={
          <button onClick={reset} className="inline-flex items-center gap-1.5 text-xs font-semibold text-white bg-brand-600 hover:bg-brand-700 rounded-lg px-3 py-1.5">
            <RefreshCw className="w-3.5 h-3.5" /> Import Lagi
          </button>
        }>
          <div className="mb-4 px-4 py-3 rounded-lg bg-emerald-50 text-emerald-700 text-sm font-semibold border border-emerald-200 flex items-center gap-2">
            <CheckCircle2 className="w-4 h-4" /> {result.message}
          </div>

          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 mb-4">
            <StatTile label="Produk Baru" value={s.products_created ?? 0} icon={Boxes} />
            <StatTile label="Produk Update" value={s.products_updated ?? 0} icon={Boxes} tone="warn" />
            <StatTile label="Stok Diimport" value={s.stock_imported ?? 0} icon={Layers} tone="green" />
            <StatTile label="Inbound (GR)" value={s.inbound_orders ?? 0} icon={Truck} />
            <StatTile label="Outbound" value={s.outbound_orders ?? 0} icon={PackageOpen} tone="green" />
          </div>

          <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4 text-center">
            <div className="rounded-lg bg-gray-50 border border-gray-100 py-2 text-xs text-gray-600">
              Stok dilewati: <b>{s.stock_skipped ?? 0}</b>
            </div>
            <div className="rounded-lg bg-gray-50 border border-gray-100 py-2 text-xs text-gray-600">
              Stok auto-create: <b>{s.stock_auto_created ?? 0}</b>
            </div>
            <div className="rounded-lg bg-gray-50 border border-gray-100 py-2 text-xs text-gray-600">
              Inbound items: <b>{s.inbound_items ?? 0}</b>
            </div>
            <div className="rounded-lg bg-gray-50 border border-gray-100 py-2 text-xs text-gray-600">
              Outbound dilewati: <b>{s.outbound_skipped ?? 0}</b>
            </div>
          </div>

          {(s.skipped_sheets?.length ?? 0) > 0 && (
            <div className="mb-4 text-xs text-gray-600">
              <b>Sheet dilewati:</b> {(s.skipped_sheets ?? []).map((n) => <span key={n} className={CHIP_SKIP}>{n}</span>)}
            </div>
          )}

          {result.log?.length ? (
            <div className="rounded-xl bg-gray-50 border border-gray-200 overflow-hidden">
              <div className="px-4 py-2.5 bg-gray-100/80 border-b border-gray-200 text-[11px] font-bold uppercase tracking-wide text-gray-500">
                Log Import
              </div>
              <div className="max-h-72 overflow-y-auto p-3 font-mono text-[12px] leading-relaxed text-gray-700 divide-y divide-gray-100">
                {result.log.map((l, i) => (
                  <div key={i} className={`py-1 ${l.toLowerCase().includes('error') || l.toLowerCase().includes('skip') || l.toLowerCase().includes('tidak') ? 'text-red-600' : 'text-gray-700'}`}>
                    {l}
                  </div>
                ))}
              </div>
            </div>
          ) : null}
        </Card>
      )}
    </div>
  );
}
