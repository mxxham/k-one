import { useEffect, useRef, useState } from 'react';
import {
  Shield, RefreshCw, Download, ChevronDown, ChevronRight,
  AlertTriangle, Settings, FileDown, ShieldAlert,
} from 'lucide-react';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import Spinner from '@/components/Spinner';
import { Field, Select, TextInput } from '@/components/Field';
import { fmtDateTime } from '@/lib/format';

// ─── Interfaces ──────────────────────────────────────────────────────────────

interface AuditLogEntry {
  id: number;
  event_type: string;
  user_id: number | null;
  username: string | null;
  target_user_id: number | null;
  success: number | null;
  ip_address: string | null;
  user_agent: string | null;
  config_key: string | null;
  old_value: string | null;
  new_value: string | null;
  export_type: string | null;
  module: string | null;
  record_count: number | null;
  format: string | null;
  data_type: string | null;
  action: string | null;
  details: string | null;
  old_role: string | null;
  new_role: string | null;
  created_at: string;
}

interface AuditSummary {
  failed_logins_24h: number;
  config_changes_24h: number;
  exports_24h: number;
  privilege_changes_24h: number;
}

// ─── Constants ───────────────────────────────────────────────────────────────

const EVENT_TYPES = [
  { value: '', label: 'All Events' },
  { value: 'LOGIN_FAILED', label: 'Login Failed' },
  { value: 'LOGIN_SUCCESS', label: 'Login Success' },
  { value: 'CONFIG_CHANGE', label: 'Config Change' },
  { value: 'DATA_EXPORT', label: 'Data Export' },
  { value: 'PRIVILEGE_CHANGE', label: 'Privilege Change' },
  { value: 'PASSWORD_RESET', label: 'Password Reset' },
  { value: 'USER_CREATED', label: 'User Created' },
  { value: 'USER_DELETED', label: 'User Deleted' },
];

const EVENT_COLORS: Record<string, string> = {
  LOGIN_FAILED: 'bg-red-100 text-red-700',
  LOGIN_SUCCESS: 'bg-green-100 text-green-700',
  CONFIG_CHANGE: 'bg-amber-100 text-amber-700',
  DATA_EXPORT: 'bg-blue-100 text-blue-700',
  PRIVILEGE_CHANGE: 'bg-purple-100 text-purple-700',
  PASSWORD_RESET: 'bg-orange-100 text-orange-700',
  USER_CREATED: 'bg-teal-100 text-teal-700',
  USER_DELETED: 'bg-red-100 text-red-700',
};

const TH = 'px-3 py-2.5 font-bold whitespace-nowrap';
const TD = 'px-3 py-2.5 whitespace-nowrap';

// ─── Summary Card ────────────────────────────────────────────────────────────

function SummaryCard({ label, value, icon: Icon, color }: {
  label: string;
  value: number;
  icon: any;
  color: string;
}) {
  return (
    <div className="bg-white rounded-xl border border-gray-200 shadow-sm p-4 flex items-center gap-3">
      <div className={`w-10 h-10 rounded-lg flex items-center justify-center ${color}`}>
        <Icon className="w-5 h-5" />
      </div>
      <div>
        <div className="text-2xl font-bold text-gray-900">{value}</div>
        <div className="text-xs text-gray-500">{label}</div>
      </div>
    </div>
  );
}

// ─── Expandable Row Detail ───────────────────────────────────────────────────

function RowDetail({ row }: { row: AuditLogEntry }) {
  const details: Array<{ label: string; value: string | null }> = [
    { label: 'Event Type', value: row.event_type },
    { label: 'IP Address', value: row.ip_address },
    { label: 'User Agent', value: row.user_agent },
    { label: 'Config Key', value: row.config_key },
    { label: 'Old Value', value: row.old_value },
    { label: 'New Value', value: row.new_value },
    { label: 'Export Type', value: row.export_type },
    { label: 'Module', value: row.module },
    { label: 'Record Count', value: row.record_count != null ? String(row.record_count) : null },
    { label: 'Format', value: row.format },
    { label: 'Data Type', value: row.data_type },
    { label: 'Action', value: row.action },
    { label: 'Details', value: row.details },
    { label: 'Old Role', value: row.old_role },
    { label: 'New Role', value: row.new_role },
    { label: 'Success', value: row.success != null ? (row.success ? 'Yes' : 'No') : null },
  ];

  return (
    <tr>
      <td colSpan={6} className="bg-gray-50 px-5 py-3">
        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 text-xs">
          {details.filter((d) => d.value).map((d) => (
            <div key={d.label}>
              <span className="font-semibold text-gray-600">{d.label}:</span>{' '}
              <span className="text-gray-800">{d.value}</span>
            </div>
          ))}
        </div>
      </td>
    </tr>
  );
}

// ─── CSV Export ──────────────────────────────────────────────────────────────

function exportToCsv(rows: AuditLogEntry[]) {
  const headers = [
    'Timestamp', 'Event Type', 'Username', 'IP Address', 'Config Key',
    'Old Value', 'New Value', 'Export Type', 'Module', 'Record Count',
    'Details', 'Old Role', 'New Role',
  ];
  const csvRows = [headers.join(',')];
  for (const r of rows) {
    csvRows.push([
      r.created_at,
      r.event_type,
      r.username ?? '',
      r.ip_address ?? '',
      r.config_key ?? '',
      r.old_value ?? '',
      r.new_value ?? '',
      r.export_type ?? '',
      r.module ?? '',
      r.record_count != null ? String(r.record_count) : '',
      r.details ?? '',
      r.old_role ?? '',
      r.new_role ?? '',
    ].map((v) => `"${v.replace(/"/g, '""')}"`).join(','));
  }
  const blob = new Blob([csvRows.join('\n')], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `security_audit_log_${new Date().toISOString().slice(0, 10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// ─── Main Component ──────────────────────────────────────────────────────────

export default function SecurityAuditPage() {
  const [logs, setLogs] = useState<AuditLogEntry[]>([]);
  const [summary, setSummary] = useState<AuditSummary>({
    failed_logins_24h: 0,
    config_changes_24h: 0,
    exports_24h: 0,
    privilege_changes_24h: 0,
  });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [expandedRow, setExpandedRow] = useState<number | null>(null);

  // Filters
  const [eventType, setEventType] = useState('');
  const [userId, setUserId] = useState('');
  const [dateFrom, setDateFrom] = useState('');
  const [dateTo, setDateTo] = useState('');
  const [search, setSearch] = useState('');

  const reqId = useRef(0);

  const load = async () => {
    const id = ++reqId.current;
    setLoading(true);
    setError('');
    try {
      const params: Record<string, any> = { limit: 100 };
      if (eventType) params.event_type = eventType;
      if (userId) params.user_id = userId;
      if (dateFrom) params.date_from = dateFrom;
      if (dateTo) params.date_to = dateTo;
      if (search) params.search = search;

      const res = await api('system', 'security_audit', { params });
      if (reqId.current !== id) return;
      setLogs(res.logs ?? []);
      if (res.summary) setSummary(res.summary);
    } catch (e: any) {
      if (reqId.current !== id) return;
      setError(e.message || 'Failed to load security audit log');
    } finally {
      if (reqId.current === id) setLoading(false);
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [eventType, userId, dateFrom, dateTo, search]);

  const toggleRow = (id: number) => {
    setExpandedRow((prev) => (prev === id ? null : id));
  };

  return (
    <div>
      <PageHeader
        title="Security Audit Log"
        subtitle="Monitor security-sensitive operations for compliance and incident response"
        actions={
          <div className="flex items-center gap-2">
            <button
              onClick={() => exportToCsv(logs)}
              disabled={loading || logs.length === 0}
              className="inline-flex items-center gap-1.5 bg-white/10 border border-white/20 text-white rounded-lg px-3 py-1.5 text-sm font-semibold hover:bg-white/20 disabled:opacity-60"
            >
              <Download className="w-4 h-4" /> Export CSV
            </button>
            <button
              onClick={load}
              disabled={loading}
              className="inline-flex items-center gap-1.5 bg-white/10 border border-white/20 text-white rounded-lg px-3 py-1.5 text-sm font-semibold hover:bg-white/20 disabled:opacity-60"
            >
              <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} /> Refresh
            </button>
          </div>
        }
      />

      {/* Summary Cards */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
        <SummaryCard
          label="Failed Logins (24h)"
          value={summary.failed_logins_24h}
          icon={ShieldAlert}
          color="bg-red-50 text-red-600"
        />
        <SummaryCard
          label="Config Changes (24h)"
          value={summary.config_changes_24h}
          icon={Settings}
          color="bg-amber-50 text-amber-600"
        />
        <SummaryCard
          label="Exports (24h)"
          value={summary.exports_24h}
          icon={FileDown}
          color="bg-blue-50 text-blue-600"
        />
        <SummaryCard
          label="Privilege Changes (24h)"
          value={summary.privilege_changes_24h}
          icon={AlertTriangle}
          color="bg-purple-50 text-purple-600"
        />
      </div>

      {/* Filters */}
      <Card title="Filters">
        <div className="grid grid-cols-2 md:grid-cols-5 gap-3">
          <Field label="Event Type">
            <Select value={eventType} onChange={(e) => setEventType(e.target.value)}>
              {EVENT_TYPES.map((et) => (
                <option key={et.value} value={et.value}>
                  {et.label}
                </option>
              ))}
            </Select>
          </Field>
          <Field label="User ID">
            <TextInput
              type="number"
              placeholder="User ID"
              value={userId}
              onChange={(e) => setUserId(e.target.value)}
            />
          </Field>
          <Field label="Date From">
            <TextInput
              type="date"
              value={dateFrom}
              onChange={(e) => setDateFrom(e.target.value)}
            />
          </Field>
          <Field label="Date To">
            <TextInput
              type="date"
              value={dateTo}
              onChange={(e) => setDateTo(e.target.value)}
            />
          </Field>
          <Field label="Search">
            <TextInput
              type="text"
              placeholder="IP, details..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </Field>
        </div>
      </Card>

      {/* Audit Log Table */}
      <Card
        title="Audit Log"
        actions={<span className="text-xs text-gray-500">{logs.length} records</span>}
      >
        {error && (
          <div className="mb-4 px-3 py-2 rounded-lg bg-red-50 text-red-700 text-sm border border-red-200">
            {error}
          </div>
        )}

        {loading ? (
          <Spinner label="Loading..." />
        ) : logs.length === 0 ? (
          <EmptyState message="No audit log entries found" />
        ) : (
          <div className="overflow-x-auto -mx-5 px-5">
            <table className="w-full text-sm">
              <thead className="bg-brand-50">
                <tr className="text-left text-[11px] uppercase tracking-wide text-brand-700">
                  <th className={TH}></th>
                  <th className={TH}>Timestamp</th>
                  <th className={TH}>Event</th>
                  <th className={TH}>User</th>
                  <th className={TH}>IP Address</th>
                  <th className={TH}>Details</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {logs.map((row) => (
                  <AuditRow
                    key={row.id}
                    row={row}
                    expanded={expandedRow === row.id}
                    onToggle={() => toggleRow(row.id)}
                  />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}

// ─── Audit Row ───────────────────────────────────────────────────────────────

function AuditRow({ row, expanded, onToggle }: {
  row: AuditLogEntry;
  expanded: boolean;
  onToggle: () => void;
}) {
  const colorClass = EVENT_COLORS[row.event_type] || 'bg-gray-100 text-gray-700';
  const detailText = row.details || row.config_key || row.export_type || row.action || '—';

  return (
    <>
      <tr
        className="hover:bg-brand-50 transition-colors align-top cursor-pointer"
        onClick={onToggle}
      >
        <td className={`${TD} w-8`}>
          {expanded ? (
            <ChevronDown className="w-4 h-4 text-gray-400" />
          ) : (
            <ChevronRight className="w-4 h-4 text-gray-400" />
          )}
        </td>
        <td className={`${TD} text-gray-600`}>{fmtDateTime(row.created_at)}</td>
        <td className={TD}>
          <span className={`inline-flex items-center px-2 py-0.5 rounded-md text-[11px] font-semibold ${colorClass}`}>
            {row.event_type}
          </span>
        </td>
        <td className={`${TD} font-semibold`}>{row.username || '—'}</td>
        <td className={`${TD} text-xs text-gray-400`}>{row.ip_address || '—'}</td>
        <td className={`${TD} text-gray-600 min-w-[220px] whitespace-normal`}>{detailText}</td>
      </tr>
      {expanded && <RowDetail row={row} />}
    </>
  );
}
