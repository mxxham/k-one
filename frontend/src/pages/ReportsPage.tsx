import { ReactNode, useEffect, useRef, useState } from 'react';
import {
  RefreshCw, FileText, CalendarDays, Box, Truck, PackageOpen, Boxes, BookOpen,
  ArrowDownToLine, ArrowUpFromLine, PackagePlus, PackageMinus, Printer, FileSpreadsheet,
} from 'lucide-react';
import { api, apiHref, webBase, ReportData, ReportRow } from '@/lib/api';
import { WebBtn } from '@/components/WebBtn';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import Spinner from '@/components/Spinner';
import StatusBadge from '@/components/StatusBadge';
import { Field, TextInput } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { fmtNum, fmtDate, todayISO, expiryInfo } from '@/lib/format';

interface Col {
  key: string;
  label: string;
  render?: (row: any) => ReactNode;
}

function expiryCell(v?: string | null) {
  const info = expiryInfo(v);
  const cls: Record<string, string> = {
    ok: 'text-emerald-600',
    warning: 'text-orange-600',
    critical: 'text-amber-600 font-semibold',
    expired: 'text-red-600 font-semibold',
    none: 'text-gray-400',
  };
  return <span className={cls[info.level]}>{info.text}</span>;
}

function MiniTable({ cols, rows, empty = 'Tidak ada data' }: { cols: Col[]; rows: any[]; empty?: string }) {
  if (!rows || rows.length === 0) return <EmptyState message={empty} />;
  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="bg-brand-50">
          <tr className="text-left text-[11px] uppercase tracking-wide text-brand-700">
            {cols.map((c) => (
              <th key={c.key} className="px-3 py-2.5 font-bold whitespace-nowrap">
                {c.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100">
          {rows.map((r: any, i: number) => (
            <tr key={r.id ?? i} className="hover:bg-brand-50 transition-colors">
              {cols.map((c) => (
                <td key={c.key} className="px-3 py-2.5 whitespace-nowrap">
                  {c.render ? c.render(r) : r[c.key] != null ? String(r[c.key]) : '—'}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

const TABS = [
  { key: 'daily', label: 'Daily Report', icon: CalendarDays, action: 'daily', needsRange: true, daily: true },
  { key: 'products', label: 'Products', icon: Box, action: 'products', needsRange: false },
  { key: 'inbound', label: 'Inbound', icon: Truck, action: 'inbound', needsRange: true },
  { key: 'outbound', label: 'Outbound', icon: PackageOpen, action: 'outbound', needsRange: true },
  { key: 'stock', label: 'Stock', icon: Boxes, action: 'stock', needsRange: false },
  { key: 'ledger', label: 'Ledger', icon: BookOpen, action: 'ledger', needsRange: true },
  { key: 'inbound_summary', label: 'Inbound Summary', icon: Truck, action: 'inbound_summary', needsRange: true, isReport: true },
  { key: 'outbound_summary', label: 'Outbound Summary', icon: PackageOpen, action: 'outbound_summary', needsRange: true, isReport: true },
  { key: 'turnover', label: 'Turnover', icon: RefreshCw, action: 'turnover', needsRange: true, isReport: true },
];

const DAILY_STOCK_COLS: Col[] = [
  { key: 'product_code', label: 'Kode' },
  { key: 'product_name', label: 'Produk' },
  { key: 'uom_type', label: 'UOM' },
  { key: 'batches', label: 'Batch' },
  { key: 'total_qty', label: 'Total Qty', render: (r) => fmtNum(r.total_qty, 0) },
  { key: 'total_pallet', label: 'Total Pallet', render: (r) => fmtNum(r.total_pallet, 0) },
  { key: 'nearest_expiry', label: 'Exp Terdekat', render: (r) => expiryCell(r.nearest_expiry) },
  {
    key: 'expiring_count',
    label: 'Segera Exp',
    render: (r) =>
      Number(r.expiring_count) > 0 ? (
        <span className="inline-flex px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700 text-[11px] font-bold">
          {r.expiring_count}
        </span>
      ) : (
        '—'
      ),
  },
];

const INBOUND_ACTIVITY_COLS: Col[] = [
  { key: 'order_number', label: 'Nomor Order' },
  { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  { key: 'order_date', label: 'Tanggal Order', render: (r) => fmtDate(r.order_date) },
  { key: 'received_date', label: 'Tanggal Terima', render: (r) => fmtDate(r.received_date) },
  { key: 'carrier_name', label: 'Carrier' },
  { key: 'item_count', label: 'Items', render: (r) => fmtNum(r.item_count ?? r.total_items ?? r.line_count, 0) },
  { key: 'total_drums', label: 'Total Qty', render: (r) => fmtNum(r.total_drums ?? r.total_qty, 0) },
];

const OUTBOUND_ACTIVITY_COLS: Col[] = [
  { key: 'order_number', label: 'Nomor Order' },
  { key: 'status', label: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  { key: 'order_date', label: 'Tanggal Order', render: (r) => fmtDate(r.order_date) },
  { key: 'shipment_number', label: 'Shipment' },
  { key: 'customer_name', label: 'Customer' },
  { key: 'item_count', label: 'Items', render: (r) => fmtNum(r.item_count ?? r.total_items ?? r.line_count, 0) },
  { key: 'total_drums', label: 'Total Qty', render: (r) => fmtNum(r.total_drums ?? r.total_qty, 0) },
];

const EXPIRING_COLS: Col[] = [
  { key: 'product_code', label: 'Kode' },
  { key: 'product_name', label: 'Produk' },
  { key: 'batch_number', label: 'Batch' },
  { key: 'location', label: 'Lokasi' },
  { key: 'qty', label: 'Qty', render: (r) => fmtNum(r.qty ?? r.quantity, 0) },
  { key: 'pallet', label: 'Pallet', render: (r) => fmtNum(r.pallet, 0) },
  { key: 'expiry_date', label: 'Expiry', render: (r) => expiryCell(r.expiry_date ?? r.exp_date) },
  {
    key: 'days_until_expiry',
    label: 'Sisa Hari',
    render: (r) =>
      r.days_until_expiry != null ? (
        <span className={Number(r.days_until_expiry) <= 120 ? 'text-amber-600 font-semibold' : 'text-gray-600'}>
          {Number(r.days_until_expiry)} hari
        </span>
      ) : (
        '—'
      ),
  },
];

const LOW_STOCK_COLS: Col[] = [
  { key: 'product_code', label: 'Kode' },
  { key: 'product_name', label: 'Produk' },
  { key: 'location', label: 'Lokasi' },
  { key: 'quantity', label: 'Qty', render: (r) => fmtNum(r.quantity ?? r.qty, 0) },
  { key: 'uom', label: 'UOM' },
  { key: 'pallet', label: 'Pallet', render: (r) => fmtNum(r.pallet, 0) },
  { key: 'reorder_level', label: 'Min. Stok', render: (r) => (r.reorder_level != null ? fmtNum(r.reorder_level, 0) : '—') },
];

const INBOUND_SUMMARY_COLS: Col[] = [
  { key: 'product_code', label: 'Kode' },
  { key: 'product_name', label: 'Produk' },
  { key: 'uom_type', label: 'UOM' },
  { key: 'order_count', label: 'Orders', render: (r) => fmtNum(r.order_count, 0) },
  { key: 'total_qty', label: 'Total Qty', render: (r) => fmtNum(r.total_qty, 0) },
  { key: 'total_pallets', label: 'Pallets', render: (r) => fmtNum(r.total_pallets, 0) },
  { key: 'receipt_days', label: 'Receipt Days', render: (r) => fmtNum(r.receipt_days, 0) },
];

const OUTBOUND_SUMMARY_COLS: Col[] = [
  { key: 'product_code', label: 'Kode' },
  { key: 'product_name', label: 'Produk' },
  { key: 'uom_type', label: 'UOM' },
  { key: 'order_count', label: 'Orders', render: (r) => fmtNum(r.order_count, 0) },
  { key: 'total_qty', label: 'Total Qty', render: (r) => fmtNum(r.total_qty, 0) },
  { key: 'total_pallets', label: 'Pallets', render: (r) => fmtNum(r.total_pallets, 0) },
  { key: 'customer_count', label: 'Customers', render: (r) => fmtNum(r.customer_count, 0) },
];

const TURNOVER_COLS: Col[] = [
  { key: 'product_code', label: 'Kode' },
  { key: 'product_name', label: 'Produk' },
  { key: 'uom_type', label: 'UOM' },
  { key: 'total_outbound', label: 'Outbound', render: (r) => fmtNum(r.total_outbound, 0) },
  { key: 'total_inbound', label: 'Inbound', render: (r) => fmtNum(r.total_inbound, 0) },
  { key: 'current_stock', label: 'Current Stock', render: (r) => fmtNum(r.current_stock, 0) },
  {
    key: 'turnover_rate', label: 'Turnover Rate', render: (r) => {
      const rate = Number(r.turnover_rate);
      const cls = rate > 2 ? 'text-emerald-600 font-bold' : rate >= 1 ? 'text-amber-600 font-semibold' : 'text-red-500 font-semibold';
      return <span className={cls}>{rate.toFixed(2)}</span>;
    },
  },
  {
    key: 'days_of_stock', label: 'Days of Stock', render: (r) => {
      if (r.days_of_stock == null) return '—';
      const days = Number(r.days_of_stock);
      const cls = days > 90 ? 'text-emerald-600' : days >= 30 ? 'text-amber-600' : 'text-red-500 font-semibold';
      return <span className={cls}>{days.toFixed(1)}</span>;
    },
  },
  {
    key: 'net_movement', label: 'Net', render: (r) => {
      const net = Number(r.net_movement);
      return <span className={net >= 0 ? 'text-emerald-600 font-semibold' : 'text-red-500 font-semibold'}>{net >= 0 ? '+' : ''}{fmtNum(net, 0)}</span>;
    },
  },
];

const TAB_COLS: Record<string, Col[]> = {
  products: [
    { key: 'product_code', label: 'Kode' },
    { key: 'product_name', label: 'Produk' },
    { key: 'category', label: 'Kategori' },
    { key: 'uom_type', label: 'UOM' },
    { key: 'uom_per_pallet', label: 'UOM/Pallet', render: (r) => fmtNum(r.uom_per_pallet) },
    { key: 'drums_per_pallet', label: 'Drums/Pallet', render: (r) => fmtNum(r.drums_per_pallet) },
    { key: 'total_qty', label: 'Total Qty', render: (r) => fmtNum(r.total_qty, 0) },
    { key: 'total_pallets', label: 'Total Pallet', render: (r) => fmtNum(r.total_pallets, 0) },
  ],
  inbound: INBOUND_ACTIVITY_COLS,
  outbound: OUTBOUND_ACTIVITY_COLS,
  stock: [
    { key: 'product_code', label: 'Kode' },
    { key: 'product_name', label: 'Produk' },
    { key: 'batch_number', label: 'Batch' },
    { key: 'location', label: 'Lokasi' },
    { key: 'quantity', label: 'Qty', render: (r) => fmtNum(r.quantity ?? r.qty, 0) },
    { key: 'uom', label: 'UOM' },
    { key: 'pallet', label: 'Pallet', render: (r) => fmtNum(r.pallet, 0) },
    { key: 'expiry_date', label: 'Expiry', render: (r) => expiryCell(r.expiry_date ?? r.exp_date) },
    { key: 'stock_status', label: 'Status', render: (r) => <StatusBadge status={r.stock_status} /> },
  ],
  ledger: [
    { key: 'transaction_date', label: 'Tanggal', render: (r) => fmtDate(r.transaction_date) },
    { key: 'product_code', label: 'Kode' },
    { key: 'product_name', label: 'Produk' },
    { key: 'transaction_type', label: 'Tipe' },
    { key: 'reference_number', label: 'Referensi' },
    { key: 'batch_number', label: 'Batch' },
    {
      key: 'quantity_in',
      label: 'Masuk',
      render: (r) =>
        Number(r.quantity_in) > 0 ? <span className="text-emerald-600 font-semibold">{fmtNum(r.quantity_in, 0)}</span> : '—',
    },
    {
      key: 'quantity_out',
      label: 'Keluar',
      render: (r) =>
        Number(r.quantity_out) > 0 ? <span className="text-red-500 font-semibold">{fmtNum(r.quantity_out, 0)}</span> : '—',
    },
    { key: 'balance', label: 'Saldo', render: (r) => fmtNum(r.balance, 0) },
    { key: 'uom', label: 'UOM' },
    { key: 'location', label: 'Lokasi' },
  ],
  inbound_summary: INBOUND_SUMMARY_COLS,
  outbound_summary: OUTBOUND_SUMMARY_COLS,
  turnover: TURNOVER_COLS,
};

function ReportSummaryView({ activeTab, reportData, fromDate, toDate }: { activeTab: string; reportData: ReportData; fromDate: string; toDate: string }) {
  const summary = reportData.summary as any || {};

  const kpiCards = activeTab === 'inbound_summary' ? [
    { label: 'Total Orders', value: summary.total_orders, grad: 'from-brand-600 to-brand-400' },
    { label: 'Products', value: summary.total_products, grad: 'from-emerald-600 to-emerald-400' },
    { label: 'Qty Received', value: summary.total_qty_received, grad: 'from-blue-600 to-blue-400' },
    { label: 'Pallets', value: summary.total_pallets, grad: 'from-orange-500 to-amber-400' },
  ] : activeTab === 'outbound_summary' ? [
    { label: 'Total Orders', value: summary.total_orders, grad: 'from-brand-600 to-brand-400' },
    { label: 'Products', value: summary.total_products, grad: 'from-emerald-600 to-emerald-400' },
    { label: 'Qty Shipped', value: summary.total_qty_shipped, grad: 'from-blue-600 to-blue-400' },
    { label: 'Customers', value: summary.total_customers, grad: 'from-purple-600 to-purple-400' },
  ] : [
    { label: 'Products', value: summary.products_analyzed, grad: 'from-brand-600 to-brand-400' },
    { label: 'Total Outbound', value: summary.total_outbound, grad: 'from-red-500 to-red-400' },
    { label: 'Total Inbound', value: summary.total_inbound, grad: 'from-emerald-600 to-emerald-400' },
    { label: 'Avg Turnover', value: summary.avg_turnover_rate?.toFixed?.(2) ?? summary.avg_turnover_rate, grad: 'from-orange-500 to-amber-400' },
  ];

  const tableData = activeTab === 'turnover'
    ? (reportData.items as any[] || [])
    : (reportData.product_breakdown as any[] || []);

  const tabLabel = TABS.find((t) => t.key === activeTab)?.label;

  return (
    <>
      <div className="text-xs text-gray-500 font-medium mb-4">
        Periode: {fmtDate(fromDate)} — {fmtDate(toDate)}
      </div>
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
        {kpiCards.map((k) => (
          <div key={k.label} className={`rounded-xl bg-gradient-to-br ${k.grad} p-4 text-white shadow-sm`}>
            <div className="text-2xl font-extrabold mt-2">{k.value != null ? fmtNum(k.value, 0) : '—'}</div>
            <div className="text-[11px] font-semibold uppercase tracking-wide opacity-85 mt-0.5">{k.label}</div>
          </div>
        ))}
      </div>
      <Card title={`${tabLabel} — By Product`}>
        <MiniTable cols={TAB_COLS[activeTab] || []} rows={tableData} empty="Tidak ada data untuk periode ini" />
      </Card>
    </>
  );
}

export default function ReportsPage() {
  const [activeTab, setActiveTab] = useState('daily');
  const [fromDate, setFromDate] = useState(todayISO());
  const [toDate, setToDate] = useState(todayISO());
  const [reportData, setReportData] = useState<ReportData | null>(null);
  const [tabData, setTabData] = useState<ReportRow[]>([]);
  const [loading, setLoading] = useState(false);
  const reqId = useRef(0);
  const toast = useToast();

  const load = async (tab?: string) => {
    const key = tab ?? activeTab;
    const cfg = TABS.find((t) => t.key === key)!;
    const id = ++reqId.current;
    setLoading(true);
    try {
      const params: Record<string, string> = {};
      if (cfg.needsRange) {
        if (cfg.daily) {
          params.date = fromDate;
          params.date_to = toDate;
        } else if ((cfg as any).isReport) {
          params.date_from = fromDate;
          params.date_to = toDate;
        } else {
          params.start_date = fromDate;
          params.end_date = toDate;
        }
      }
      const res = await api('report', cfg.action, { params });
      if (reqId.current !== id) return;
      if (cfg.daily || (cfg as any).isReport) setReportData(res.report);
      else setTabData((Array.isArray(res.rows) ? res.rows : res) as any[]);
      toast('success', 'Data berhasil dimuat');
    } catch (e: any) {
      if (reqId.current !== id) return;
      toast('error', e.message || 'Gagal memuat data');
    } finally {
      if (reqId.current === id) setLoading(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab]);

  const ls = reportData?.ledger_summary || {};
  const ledgerCards = [
    { label: 'Transaksi Masuk', value: ls.transactions_in, icon: ArrowDownToLine, grad: 'from-brand-600 to-brand-400' },
    { label: 'Transaksi Keluar', value: ls.transactions_out, icon: ArrowUpFromLine, grad: 'from-orange-500 to-amber-400' },
    { label: 'Qty Masuk', value: ls.qty_in, icon: PackagePlus, grad: 'from-emerald-600 to-emerald-400' },
    { label: 'Qty Keluar', value: ls.qty_out, icon: PackageMinus, grad: 'from-red-500 to-red-400' },
  ];

  const legacyType = ({
    daily: 'daily', stock: 'stock', expiring: 'expiring',
    inbound_summary: 'inbound_summary', outbound_summary: 'outbound_summary', turnover: 'turnover',
  } as Record<string, string | undefined>)[activeTab];

  return (
    <div>
      <PageHeader
        title="Reports"
        subtitle="Laporan harian & ringkasan data warehouse"
        actions={
          <>
            {legacyType && (
              <>
                <WebBtn
                  href={`${webBase()}/print_report.php?type=${legacyType}${['daily', 'inbound_summary', 'outbound_summary', 'turnover'].includes(activeTab) ? `&date=${fromDate}&date_to=${toDate}` : ''}`}
                  label="Print / PDF"
                  icon={<Printer className="w-4 h-4" />}
                />
                <WebBtn
                  href={apiHref('export', 'report', {
                    type: legacyType,
                    date_from: ['inbound_summary', 'outbound_summary', 'turnover'].includes(activeTab) ? fromDate : undefined,
                    date_to: ['daily', 'inbound_summary', 'outbound_summary', 'turnover'].includes(activeTab) ? toDate : undefined,
                    date: activeTab === 'daily' ? fromDate : undefined,
                  })}
                  label="Export Excel"
                  icon={<FileSpreadsheet className="w-4 h-4" />}
                />
              </>
            )}
            <button
              onClick={() => load()}
              disabled={loading}
              className="inline-flex items-center gap-1.5 bg-white/10 border border-white/20 text-white rounded-lg px-3 py-1.5 text-sm font-semibold hover:bg-white/20 disabled:opacity-60"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} /> Refresh
            </button>
          </>
        }
      />

      <Card>
        <div className="flex items-end gap-3 flex-wrap">
          <Field label="Dari Tanggal" className="w-44">
            <TextInput type="date" value={fromDate} onChange={(e) => setFromDate(e.target.value)} />
          </Field>
          <Field label="Sampai Tanggal" className="w-44">
            <TextInput type="date" value={toDate} onChange={(e) => setToDate(e.target.value)} />
          </Field>
          <button
            onClick={() => load()}
            disabled={loading}
            className="inline-flex items-center gap-1.5 bg-brand-600 text-white rounded-lg px-3 py-1.5 text-sm font-semibold hover:bg-brand-700 disabled:opacity-60"
          >
            <FileText className="w-4 h-4" /> {activeTab === 'daily' ? 'Daily Report' : 'Muat Data'}
          </button>
        </div>
      </Card>

      <div className="flex items-center gap-1 border-b border-gray-200 mb-5 overflow-x-auto">
        {TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setActiveTab(t.key)}
            className={`flex items-center gap-1.5 px-3 py-2 text-sm font-semibold whitespace-nowrap border-b-2 -mb-px transition-colors ${
              activeTab === t.key ? 'border-brand-600 text-brand-700' : 'border-transparent text-gray-500 hover:text-brand-600'
            }`}
          >
            <t.icon className="w-4 h-4" /> {t.label}
          </button>
        ))}
      </div>

      {loading ? (
        <Spinner label="Memuat data..." />
      ) : activeTab === 'daily' ? (
        reportData ? (
          <>
            <div className="text-xs text-gray-500 font-medium mb-4">
              Periode: {fmtDate(fromDate)} — {fmtDate(toDate)}
            </div>

            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-5">
              {ledgerCards.map((k) => (
                <div key={k.label} className={`rounded-xl bg-gradient-to-br ${k.grad} p-4 text-white shadow-sm`}>
                  <k.icon className="w-4 h-4 opacity-80" />
                  <div className="text-2xl font-extrabold mt-2">{fmtNum(k.value, 0)}</div>
                  <div className="text-[11px] font-semibold uppercase tracking-wide opacity-85 mt-0.5">{k.label}</div>
                </div>
              ))}
            </div>

            <Card title="Stock Summary">
              <MiniTable cols={DAILY_STOCK_COLS} rows={reportData.stock_summary || []} />
            </Card>

            <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
              <Card title="Inbound Activity">
                <MiniTable cols={INBOUND_ACTIVITY_COLS} rows={reportData.inbound_activity || []} />
              </Card>
              <Card title="Outbound Activity">
                <MiniTable cols={OUTBOUND_ACTIVITY_COLS} rows={reportData.outbound_activity || []} />
              </Card>
            </div>

            <div className="grid grid-cols-1 xl:grid-cols-2 gap-5">
              <Card title="Expiring Items">
                <MiniTable cols={EXPIRING_COLS} rows={reportData.expiring_items || []} />
              </Card>
              <Card title="Low Stock">
                <MiniTable cols={LOW_STOCK_COLS} rows={reportData.low_stock || []} />
              </Card>
            </div>
          </>
      ) : ['inbound_summary', 'outbound_summary', 'turnover'].includes(activeTab) && reportData ? (
        <ReportSummaryView activeTab={activeTab} reportData={reportData} fromDate={fromDate} toDate={toDate} />
      ) : (
          <EmptyState message="Klik tombol Daily Report untuk membuat laporan" />
        )
      ) : (
        <Card title={TABS.find((t) => t.key === activeTab)?.label}>
          <MiniTable cols={TAB_COLS[activeTab] || []} rows={tabData} />
        </Card>
      )}
    </div>
  );
}
