import { useEffect, useState } from 'react';
import { AlertTriangle, AlertCircle, Info, X } from 'lucide-react';
import { api } from '@/lib/api';
import { Link } from 'react-router-dom';

interface Alert {
  level: 'critical' | 'warning' | 'info';
  type: string;
  message: string;
  count: number;
  action_link?: string;
}

interface AlertsData {
  alerts: Alert[];
}

function alertIcon(level: string) {
  switch (level) {
    case 'critical': return AlertTriangle;
    case 'warning': return AlertCircle;
    case 'info': return Info;
    default: return Info;
  }
}

function alertStyle(level: string) {
  switch (level) {
    case 'critical': return 'bg-red-50/50 border-red-200 text-red-900';
    case 'warning': return 'bg-amber-50/50 border-amber-200 text-amber-900';
    case 'info': return 'bg-sky-50/50 border-sky-200 text-sky-900';
    default: return 'bg-gray-50/50 border-gray-200 text-gray-900';
  }
}

function alertBadgeStyle(level: string) {
  switch (level) {
    case 'critical': return 'bg-red-100 text-red-800';
    case 'warning': return 'bg-amber-100 text-amber-800';
    case 'info': return 'bg-sky-100 text-sky-800';
    default: return 'bg-gray-100 text-gray-800';
  }
}

export default function DashboardAlerts() {
  const [data, setData] = useState<AlertsData | null>(null);
  const [loading, setLoading] = useState(true);
  const [dismissed, setDismissed] = useState<Set<string>>(new Set());

  useEffect(() => {
    loadAlerts();
  }, []);

  const loadAlerts = async () => {
    setLoading(true);
    try {
      const res = await api('dashboard', 'alerts');
      setData({ alerts: res.alerts || [] });
    } catch (e: any) {
      console.error('Failed to load alerts:', e);
    } finally {
      setLoading(false);
    }
  };

  const dismissAlert = (type: string) => {
    setDismissed(new Set([...dismissed, type]));
  };

  if (loading || !data) return null;

  const visibleAlerts = data.alerts.filter(a => !dismissed.has(a.type));

  if (visibleAlerts.length === 0) return null;

  return (
    <div className="space-y-2.5 mb-5">
      {visibleAlerts.map((alert) => {
        const Icon = alertIcon(alert.level);
        const style = alertStyle(alert.level);
        const badgeStyle = alertBadgeStyle(alert.level);

        return (
          <div
            key={alert.type}
            className={`rounded-xl border px-5 py-3.5 flex items-center justify-between gap-4 ${style} shadow-sm`}
          >
            <div className="flex items-center gap-3.5 flex-1 min-w-0">
              <Icon className="w-[18px] h-[18px] flex-shrink-0 opacity-80" />
              <div className="flex items-center gap-2.5 flex-wrap min-w-0">
                <span className={`inline-flex items-center justify-center px-2.5 py-0.5 rounded-full text-xs font-bold tabular-nums ${badgeStyle}`}>
                  {alert.count}
                </span>
                <span className="font-medium text-sm leading-snug">{alert.message}</span>
              </div>
            </div>
            <div className="flex items-center gap-2 flex-shrink-0">
              {alert.action_link && (
                <Link
                  to={alert.action_link}
                  className="px-3.5 py-1.5 text-xs font-semibold rounded-lg bg-white/60 hover:bg-white/90 border border-current/15 transition-all duration-150"
                >
                  View
                </Link>
              )}
              <button
                onClick={() => dismissAlert(alert.type)}
                className="p-1 rounded-lg hover:bg-black/5 text-current/40 hover:text-current/70 transition-all duration-150"
                title="Dismiss"
              >
                <X className="w-4 h-4" />
              </button>
            </div>
          </div>
        );
      })}
    </div>
  );
}
