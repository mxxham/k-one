import { useCallback, useEffect, useState } from 'react';
import {
  RefreshCw,
  Activity,
  Server,
  Database,
  Cpu,
  MemoryStick,
  Users,
  Package,
  ShoppingCart,
  AlertTriangle,
  AlertCircle,
  Info,
  Clock,
  Zap,
  Timer,
  BarChart3,
  Shield,
} from 'lucide-react';
import { api } from '@/lib/api';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import Spinner from '@/components/Spinner';
import { useToast } from '@/components/Toast';
import { fmtNum, fmtDate, fmtDateTime } from '@/lib/format';

// ─── Types ──────────────────────────────────────────────────────────────────

interface HealthStatus {
  status: 'healthy' | 'degraded' | 'unhealthy';
  database: 'connected' | 'disconnected';
  uptime_seconds: number;
  memory_usage_mb: number;
  cpu_usage_percent: number;
  timestamp: string;
}

interface Metrics {
  total_inbound: number;
  total_outbound: number;
  total_stock: number;
  active_users: number;
  orders_today: number;
  picks_today: number;
}

interface Alert {
  id: number;
  level: 'info' | 'warning' | 'critical';
  message: string;
  source: string;
  created_at: string;
}

interface PerformanceStats {
  avg_query_time_ms: number;
  slow_queries: number;
  total_queries: number;
  cache_hit_rate: number;
}

interface UptimeStats {
  uptime_percent: number;
  downtime_minutes: number;
  last_incident: string | null;
}

// ─── Helpers ────────────────────────────────────────────────────────────────

function formatUptime(seconds: number): string {
  const days = Math.floor(seconds / 86400);
  const hrs = Math.floor((seconds % 86400) / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  if (days > 0) return `${days}d ${hrs}h ${mins}m`;
  if (hrs > 0) return `${hrs}h ${mins}m`;
  return `${mins}m`;
}

const HEALTH_COLORS: Record<string, string> = {
  healthy: 'bg-emerald-500',
  degraded: 'bg-amber-500',
  unhealthy: 'bg-red-500',
};

const HEALTH_TEXT_COLORS: Record<string, string> = {
  healthy: 'text-emerald-600',
  degraded: 'text-amber-600',
  unhealthy: 'text-red-600',
};

const HEALTH_BG_COLORS: Record<string, string> = {
  healthy: 'bg-emerald-50 border-emerald-200',
  degraded: 'bg-amber-50 border-amber-200',
  unhealthy: 'bg-red-50 border-red-200',
};

const ALERT_LEVEL_CONFIG: Record<string, { icon: typeof Info; color: string; bg: string }> = {
  info: { icon: Info, color: 'text-sky-600', bg: 'bg-sky-50 border-sky-200' },
  warning: { icon: AlertTriangle, color: 'text-amber-600', bg: 'bg-amber-50 border-amber-200' },
  critical: { icon: AlertCircle, color: 'text-red-600', bg: 'bg-red-50 border-red-200' },
};

// ─── Component ──────────────────────────────────────────────────────────────

export default function MonitoringPage() {
  const toast = useToast();
  const [health, setHealth] = useState<HealthStatus | null>(null);
  const [metrics, setMetrics] = useState<Metrics | null>(null);
  const [alerts, setAlerts] = useState<Alert[]>([]);
  const [performance, setPerformance] = useState<PerformanceStats | null>(null);
  const [uptime, setUptime] = useState<UptimeStats | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  const loadData = useCallback(async (isRefresh = false) => {
    if (isRefresh) setRefreshing(true);
    else setLoading(true);

    try {
      const [healthRes, metricsRes, alertsRes, perfRes, uptimeRes] = await Promise.allSettled([
        api('monitoring', 'health'),
        api('monitoring', 'metrics'),
        api('monitoring', 'alerts'),
        api('monitoring', 'performance'),
        api('monitoring', 'uptime'),
      ]);

      if (healthRes.status === 'fulfilled') setHealth(healthRes.value.data as HealthStatus);
      if (metricsRes.status === 'fulfilled') setMetrics(metricsRes.value.data as Metrics);
      if (alertsRes.status === 'fulfilled') setAlerts((alertsRes.value.data as Alert[]) || []);
      if (perfRes.status === 'fulfilled') setPerformance(perfRes.value.data as PerformanceStats);
      if (uptimeRes.status === 'fulfilled') setUptime(uptimeRes.value.data as UptimeStats);
    } catch (err: any) {
      console.error('Failed to load monitoring data:', err);
      toast('error', 'Gagal memuat data monitoring');
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, []);

  // Initial load
  useEffect(() => {
    loadData();
  }, [loadData]);

  // Auto-refresh every 30 seconds
  useEffect(() => {
    const interval = setInterval(() => {
      if (!document.hidden) loadData(true);
    }, 30000);
    return () => clearInterval(interval);
  }, [loadData]);

  const handleRefresh = () => loadData(true);

  if (loading) {
    return (
      <div>
        <PageHeader
          title="System Monitoring"
          subtitle="Pemantauan status dan performa sistem WMS"
          actions={
            <button
              onClick={handleRefresh}
              disabled={refreshing}
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25 disabled:opacity-50"
            >
              <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} /> Refresh
            </button>
          }
        />
        <Spinner label="Memuat data monitoring…" />
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="System Monitoring"
        subtitle="Pemantauan status dan performa sistem WMS"
        actions={
          <button
            onClick={handleRefresh}
            disabled={refreshing}
            className="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-white/15 border border-white/30 text-white text-sm font-semibold hover:bg-white/25 disabled:opacity-50"
          >
            <RefreshCw className={`w-4 h-4 ${refreshing ? 'animate-spin' : ''}`} /> Refresh
          </button>
        }
      />

      {/* ─── Health Status Card ─────────────────────────────────────────── */}
      {health && (
        <Card title="System Health">
          <div className={`rounded-xl border p-6 ${HEALTH_BG_COLORS[health.status] || HEALTH_BG_COLORS.healthy}`}>
            <div className="flex items-start gap-6">
              {/* Status Indicator */}
              <div className="flex flex-col items-center gap-2">
                <div className={`w-16 h-16 rounded-full ${HEALTH_COLORS[health.status] || HEALTH_COLORS.healthy} flex items-center justify-center shadow-lg`}>
                  {health.status === 'healthy' && <Shield className="w-8 h-8 text-white" />}
                  {health.status === 'degraded' && <AlertTriangle className="w-8 h-8 text-white" />}
                  {health.status === 'unhealthy' && <AlertCircle className="w-8 h-8 text-white" />}
                </div>
                <span className={`text-sm font-bold uppercase tracking-wide ${HEALTH_TEXT_COLORS[health.status] || HEALTH_TEXT_COLORS.healthy}`}>
                  {health.status}
                </span>
              </div>

              {/* Details Grid */}
              <div className="flex-1 grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div>
                  <div className="text-[11px] uppercase tracking-wide text-gray-500 font-bold mb-1">Database</div>
                  <div className="flex items-center gap-2">
                    <Database className="w-4 h-4" />
                    <span className={`text-sm font-semibold ${health.database === 'connected' ? 'text-emerald-600' : 'text-red-600'}`}>
                      {health.database === 'connected' ? 'Connected' : 'Disconnected'}
                    </span>
                  </div>
                </div>
                <div>
                  <div className="text-[11px] uppercase tracking-wide text-gray-500 font-bold mb-1">Uptime</div>
                  <div className="flex items-center gap-2">
                    <Clock className="w-4 h-4" />
                    <span className="text-sm font-semibold text-gray-800">{formatUptime(health.uptime_seconds)}</span>
                  </div>
                </div>
                <div>
                  <div className="text-[11px] uppercase tracking-wide text-gray-500 font-bold mb-1">Memory</div>
                  <div className="flex items-center gap-2">
                    <MemoryStick className="w-4 h-4" />
                    <span className="text-sm font-semibold text-gray-800">{fmtNum(health.memory_usage_mb, 1)} MB</span>
                  </div>
                </div>
                <div>
                  <div className="text-[11px] uppercase tracking-wide text-gray-500 font-bold mb-1">CPU</div>
                  <div className="flex items-center gap-2">
                    <Cpu className="w-4 h-4" />
                    <span className={`text-sm font-semibold ${health.cpu_usage_percent > 80 ? 'text-red-600' : health.cpu_usage_percent > 60 ? 'text-amber-600' : 'text-gray-800'}`}>
                      {fmtNum(health.cpu_usage_percent, 1)}%
                    </span>
                  </div>
                </div>
              </div>
            </div>
            <div className="mt-4 pt-3 border-t border-gray-200/50 text-xs text-gray-500">
              Last checked: {fmtDateTime(health.timestamp)}
            </div>
          </div>
        </Card>
      )}

      {/* ─── Metrics Cards ──────────────────────────────────────────────── */}
      {metrics && (
        <div className="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-4 mb-5">
          {[
            { label: 'Total Inbound', value: metrics.total_inbound, icon: <Package className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
            { label: 'Total Outbound', value: metrics.total_outbound, icon: <Package className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
            { label: 'Total Stock', value: metrics.total_stock, icon: <BarChart3 className="w-4 h-4" />, cls: 'bg-brand-50 text-brand-600' },
            { label: 'Active Users', value: metrics.active_users, icon: <Users className="w-4 h-4" />, cls: 'bg-sky-50 text-sky-600' },
            { label: 'Orders Today', value: metrics.orders_today, icon: <ShoppingCart className="w-4 h-4" />, cls: 'bg-emerald-50 text-emerald-600' },
            { label: 'Picks Today', value: metrics.picks_today, icon: <Zap className="w-4 h-4" />, cls: 'bg-purple-50 text-purple-600' },
          ].map((c) => (
            <div key={c.label} className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
              <div className={`w-8 h-8 rounded-lg ${c.cls} flex items-center justify-center mb-2`}>{c.icon}</div>
              <div className="text-xl font-bold text-brand-900">{fmtNum(c.value, 0)}</div>
              <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">{c.label}</div>
            </div>
          ))}
        </div>
      )}

      {/* ─── Alerts Table ───────────────────────────────────────────────── */}
      <Card
        title="Recent Alerts"
        actions={
          <span className="text-xs text-gray-500 font-semibold">
            {alerts.length} alert{alerts.length !== 1 ? 's' : ''}
          </span>
        }
      >
        {alerts.length === 0 ? (
          <EmptyState message="No alerts — system is running smoothly" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold">Level</th>
                  <th className="px-3 py-2.5 text-left font-bold">Message</th>
                  <th className="px-3 py-2.5 text-left font-bold">Source</th>
                  <th className="px-3 py-2.5 text-left font-bold">Time</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {alerts.map((alert) => {
                  const cfg = ALERT_LEVEL_CONFIG[alert.level] || ALERT_LEVEL_CONFIG.info;
                  const Icon = cfg.icon;
                  return (
                    <tr key={alert.id} className="hover:bg-gray-50">
                      <td className="px-3 py-2.5">
                        <span className={`inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${cfg.bg} ${cfg.color}`}>
                          <Icon className="w-3 h-3" />
                          {alert.level}
                        </span>
                      </td>
                      <td className="px-3 py-2.5 text-gray-700">{alert.message}</td>
                      <td className="px-3 py-2.5">
                        <span className="text-xs font-semibold text-gray-600 bg-gray-100 px-2 py-0.5 rounded">{alert.source}</span>
                      </td>
                      <td className="px-3 py-2.5 text-xs text-gray-500">{fmtDateTime(alert.created_at)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Card>

      {/* ─── Performance & Uptime Row ──────────────────────────────────── */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-5">
        {/* Performance Card */}
        {performance && (
          <Card title="Database Performance">
            <div className="grid grid-cols-2 gap-4">
              <div className="rounded-lg bg-brand-50 p-4">
                <div className="flex items-center gap-2 mb-2">
                  <Timer className="w-4 h-4 text-brand-600" />
                  <span className="text-[11px] uppercase tracking-wide text-brand-600 font-bold">Avg Query Time</span>
                </div>
                <div className="text-2xl font-bold text-brand-800">{fmtNum(performance.avg_query_time_ms, 1)} ms</div>
              </div>
              <div className="rounded-lg bg-brand-50 p-4">
                <div className="flex items-center gap-2 mb-2">
                  <Zap className="w-4 h-4 text-brand-600" />
                  <span className="text-[11px] uppercase tracking-wide text-brand-600 font-bold">Cache Hit Rate</span>
                </div>
                <div className="text-2xl font-bold text-brand-800">{fmtNum(performance.cache_hit_rate, 1)}%</div>
              </div>
              <div className="rounded-lg bg-gray-50 p-4">
                <div className="flex items-center gap-2 mb-2">
                  <BarChart3 className="w-4 h-4 text-gray-600" />
                  <span className="text-[11px] uppercase tracking-wide text-gray-600 font-bold">Total Queries</span>
                </div>
                <div className="text-2xl font-bold text-gray-800">{fmtNum(performance.total_queries, 0)}</div>
              </div>
              <div className="rounded-lg bg-gray-50 p-4">
                <div className="flex items-center gap-2 mb-2">
                  <AlertTriangle className="w-4 h-4 text-gray-600" />
                  <span className="text-[11px] uppercase tracking-wide text-gray-600 font-bold">Slow Queries</span>
                </div>
                <div className={`text-2xl font-bold ${performance.slow_queries > 0 ? 'text-amber-600' : 'text-gray-800'}`}>
                  {fmtNum(performance.slow_queries, 0)}
                </div>
              </div>
            </div>
          </Card>
        )}

        {/* Uptime Card */}
        {uptime && (
          <Card title="Uptime & Availability">
            <div className="space-y-4">
              {/* Uptime Percentage Bar */}
              <div>
                <div className="flex items-center justify-between mb-2">
                  <span className="text-[11px] uppercase tracking-wide text-gray-500 font-bold">Uptime</span>
                  <span className={`text-lg font-bold ${uptime.uptime_percent >= 99.9 ? 'text-emerald-600' : uptime.uptime_percent >= 99 ? 'text-amber-600' : 'text-red-600'}`}>
                    {fmtNum(uptime.uptime_percent, 2)}%
                  </span>
                </div>
                <div className="w-full bg-gray-100 rounded-full h-3 overflow-hidden">
                  <div
                    className={`h-full rounded-full transition-all duration-500 ${
                      uptime.uptime_percent >= 99.9 ? 'bg-emerald-500' : uptime.uptime_percent >= 99 ? 'bg-amber-500' : 'bg-red-500'
                    }`}
                    style={{ width: `${Math.min(uptime.uptime_percent, 100)}%` }}
                  />
                </div>
              </div>

              {/* Stats */}
              <div className="grid grid-cols-2 gap-4">
                <div className="rounded-lg bg-gray-50 p-4">
                  <div className="flex items-center gap-2 mb-2">
                    <Activity className="w-4 h-4 text-gray-600" />
                    <span className="text-[11px] uppercase tracking-wide text-gray-600 font-bold">Downtime</span>
                  </div>
                  <div className="text-2xl font-bold text-gray-800">{fmtNum(uptime.downtime_minutes, 0)} min</div>
                </div>
                <div className="rounded-lg bg-gray-50 p-4">
                  <div className="flex items-center gap-2 mb-2">
                    <Server className="w-4 h-4 text-gray-600" />
                    <span className="text-[11px] uppercase tracking-wide text-gray-600 font-bold">Last Incident</span>
                  </div>
                  <div className="text-sm font-semibold text-gray-800">
                    {uptime.last_incident ? fmtDateTime(uptime.last_incident) : 'None'}
                  </div>
                </div>
              </div>
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
