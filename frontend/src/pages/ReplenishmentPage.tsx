import React, { useState, useEffect, useCallback } from 'react';
import {
  AlertCircle,
  RefreshCw,
  TrendingUp,
  Zap,
  CheckCircle2,
  XCircle,
  ChevronDown,
  ArrowRight,
  Clock,
  SkipForward,
  Play,
  History,
} from 'lucide-react';
import { api } from '@/lib/api';
import { fmtDateTime } from '@/lib/format';
import { useToast } from '@/components/Toast';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import { PageState } from '@/components/PageState';

interface Shortage {
  product_id: string;
  product_code: string;
  product_name: string;
  location_id: string;
  pick_face_location: string;
  available_qty: number;
  min_qty: number;
  uom_per_pallet: number;
  shortage: number;
}

interface SuggestedTransfer {
  id: string;
  from_location_id: string;
  from_location_code: string;
  from_location_name: string;
  to_location_id: string;
  to_location_code: string;
  to_location_name: string;
  product_id: string;
  product_code: string;
  product_name: string;
  transfer_qty: number;
  priority: string;
  reason: string;
  suggested_at: string;
}

interface ReplenishmentConfig {
  enabled: string;
  schedule_minutes: string;
  batch_size: string;
  cooldown_minutes: string;
  require_approval: string;
  notify_on_generate: string;
  notify_on_execute: string;
  notify_on_failure: string;
}

interface ReplenishmentActivity {
  id: number;
  product_id: number;
  location_code: string;
  trigger_type: string;
  shortage_qty: number | null;
  transfer_id: number | null;
  status: 'pending' | 'generated' | 'executed' | 'failed' | 'skipped';
  skip_reason: string | null;
  created_at: string;
}

interface ReplenishmentStatus {
  enabled: boolean;
  total_shortages: number;
  pending_transfers: number;
  recent_activity: ReplenishmentActivity[];
}

type TabType = 'shortages' | 'suggestions' | 'auto-replenish' | 'history';

const ReplenishmentPage: React.FC = () => {
  const toast = useToast();
  const [activeTab, setActiveTab] = useState<TabType>('shortages');
  const [shortages, setShortages] = useState<Shortage[]>([]);
  const [suggestions, setSuggestions] = useState<SuggestedTransfer[]>([]);
  const [loading, setLoading] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [expandedShortageId, setExpandedShortageId] = useState<string | null>(null);
  const [expandedSuggestionId, setExpandedSuggestionId] = useState<string | null>(null);

  const [autoConfig, setAutoConfig] = useState<ReplenishmentConfig>({
    enabled: '0',
    schedule_minutes: '15',
    batch_size: '10',
    cooldown_minutes: '30',
    require_approval: '0',
    notify_on_generate: '1',
    notify_on_execute: '1',
    notify_on_failure: '1',
  });
  const [autoStatus, setAutoStatus] = useState<ReplenishmentStatus | null>(null);
  const [autoActivity, setAutoActivity] = useState<ReplenishmentActivity[]>([]);
  const [autoLoading, setAutoLoading] = useState(false);
  const [autoConfigLoading, setAutoConfigLoading] = useState(false);
  const [autoRunning, setAutoRunning] = useState(false);
  const [autoSaveSuccess, setAutoSaveSuccess] = useState(false);

  useEffect(() => {
    detectShortages();
  }, []);

  const detectShortages = async () => {
    setLoading(true);

    try {
      const data = await api('replenishment', 'detect');
      const rows = data.shortages || [];
      setShortages(rows);
      toast('success', `Detected ${rows.length} shortage(s) across the warehouse`);
    } catch (err) {
      const errorMsg =
        err instanceof Error ? err.message : 'Unknown error occurred';
      toast('error', `Error detecting shortages: ${errorMsg}`);
      setShortages([]);
    } finally {
      setLoading(false);
    }
  };

  const suggestTransfers = async () => {
    setLoading(true);

    try {
      const data = await api('replenishment', 'suggest');
      const rows = data.suggestions || [];
      setSuggestions(rows);
      setActiveTab('suggestions');
      toast('success', `Generated ${rows.length} transfer suggestion(s)`);
    } catch (err) {
      const errorMsg =
        err instanceof Error ? err.message : 'Unknown error occurred';
      toast('error', `Error suggesting transfers: ${errorMsg}`);
      setSuggestions([]);
    } finally {
      setLoading(false);
    }
  };

  const generateTransfers = async () => {
    if (suggestions.length === 0) {
      toast('error', 'No suggestions available. Please suggest transfers first.');
      return;
    }

    setGenerating(true);

    try {
      const data = await api('replenishment', 'generate', {
        method: 'POST',
        body: {
          suggestions: suggestions.map((s) => ({
            from_location_id: s.from_location_id,
            to_location_id: s.to_location_id,
            product_id: s.product_id,
            qty: s.transfer_qty,
          })),
        },
      });

      const generated = data.generated || [];
      const insufficient = data.insufficient || [];
      const skipped = data.skipped || [];
      const parts = [];
      if (generated.length) parts.push(`${generated.length} generated`);
      if (insufficient.length) parts.push(`${insufficient.length} insufficient stock`);
      if (skipped.length) parts.push(`${skipped.length} skipped`);
      toast(
        'success',
        parts.length ? `Replenishment complete: ${parts.join(', ')}` : 'No transfers generated'
      );
      setSuggestions([]);
      setTimeout(() => detectShortages(), 1000);
    } catch (err) {
      const errorMsg =
        err instanceof Error ? err.message : 'Unknown error occurred';
      toast('error', `Error generating transfers: ${errorMsg}`);
    } finally {
      setGenerating(false);
    }
  };

  const loadAutoStatus = useCallback(async () => {
    setAutoLoading(true);
    try {
      const data = await api('replenishment', 'auto_status');
      if (data.success !== false) {
        setAutoStatus({
          enabled: data.enabled ?? false,
          total_shortages: data.total_shortages ?? 0,
          pending_transfers: data.pending_transfers ?? 0,
          recent_activity: data.recent_activity ?? [],
        });
        setAutoActivity(data.recent_activity ?? []);
      }
    } catch {
      setAutoStatus(null);
      setAutoActivity([]);
    } finally {
      setAutoLoading(false);
    }
  }, []);

  const loadAutoConfig = useCallback(async () => {
    setAutoConfigLoading(true);
    try {
      const data = await api('replenishment', 'auto_config');
      if (data.success !== false && data.config) {
        setAutoConfig(data.config);
      }
    } catch {
      // keep defaults
    } finally {
      setAutoConfigLoading(false);
    }
  }, []);

  const saveAutoConfig = async () => {
    setAutoConfigLoading(true);
    setAutoSaveSuccess(false);
    try {
      await api('replenishment', 'update_auto_config', {
        method: 'POST',
        body: autoConfig,
      });
      setAutoSaveSuccess(true);
      setTimeout(() => setAutoSaveSuccess(false), 3000);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      toast('error', `Failed to save config: ${msg}`);
    } finally {
      setAutoConfigLoading(false);
    }
  };

  const toggleAutoEnabled = async () => {
    const newEnabled = autoConfig.enabled === '1' ? '0' : '1';
    setAutoConfig((prev) => ({ ...prev, enabled: newEnabled }));
    try {
      await api('replenishment', 'auto_toggle', {
        method: 'POST',
        body: { enabled: newEnabled },
      });
      loadAutoStatus();
    } catch (err) {
      setAutoConfig((prev) => ({ ...prev, enabled: newEnabled === '1' ? '0' : '1' }));
      const msg = err instanceof Error ? err.message : 'Unknown error';
      toast('error', `Failed to toggle: ${msg}`);
    }
  };

  const runAutoNow = async () => {
    setAutoRunning(true);
    try {
      const data = await api('replenishment', 'run_cycle', {
        method: 'POST',
        body: { trigger: 'manual' },
      });
      const count = data.transfers_created ?? 0;
      toast('success', `Auto-replenish cycle complete: ${count} transfer(s) created`);
      loadAutoStatus();
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      toast('error', `Auto-replenish failed: ${msg}`);
    } finally {
      setAutoRunning(false);
    }
  };

  useEffect(() => {
    if (activeTab === 'auto-replenish') {
      loadAutoStatus();
      loadAutoConfig();
    }
  }, [activeTab, loadAutoStatus, loadAutoConfig]);

  const TABS = [
    { key: 'shortages' as const, label: 'Shortages', icon: AlertCircle },
    { key: 'suggestions' as const, label: 'Suggestions', icon: TrendingUp },
    { key: 'auto-replenish' as const, label: 'Auto-Replenish', icon: Zap },
    { key: 'history' as const, label: 'History', icon: RefreshCw },
  ];

  return (
    <div>
      <PageHeader
        title="Replenishment"
        subtitle="Detect shortages, suggest transfers, and manage warehouse stock levels"
        actions={
          <>
            <button
              onClick={detectShortages}
              disabled={loading || generating}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-semibold border border-white/20 disabled:opacity-50"
            >
              {loading ? (
                <RefreshCw className="w-4 h-4 animate-spin" />
              ) : (
                <AlertCircle className="w-4 h-4" />
              )}
              Detect Shortages
            </button>
            <button
              onClick={suggestTransfers}
              disabled={loading || generating}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-semibold border border-white/20 disabled:opacity-50"
            >
              {loading ? (
                <RefreshCw className="w-4 h-4 animate-spin" />
              ) : (
                <TrendingUp className="w-4 h-4" />
              )}
              Suggest Transfers
            </button>
            <button
              onClick={generateTransfers}
              disabled={loading || generating || suggestions.length === 0}
              className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-semibold border border-white/20 disabled:opacity-50"
            >
              {generating ? (
                <RefreshCw className="w-4 h-4 animate-spin" />
              ) : (
                <Zap className="w-4 h-4" />
              )}
              Generate Transfers
            </button>
          </>
        }
      />

      <div className="flex gap-2 mb-5 flex-wrap">
        {TABS.map((t) => (
          <button
            key={t.key}
            onClick={() => setActiveTab(t.key)}
            className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold border transition ${
              activeTab === t.key
                ? 'bg-brand-600 text-white border-brand-600 shadow-sm'
                : 'bg-white text-gray-600 border-gray-200 hover:bg-brand-50'
            }`}
          >
            <t.icon className="w-4 h-4" />
            {t.label}
            {t.key === 'shortages' && shortages.length > 0 && (
              <span className="ml-1 px-2 py-0.5 bg-red-50 text-red-700 border border-red-200 rounded-full text-xs font-semibold">
                {shortages.length}
              </span>
            )}
            {t.key === 'suggestions' && suggestions.length > 0 && (
              <span className="ml-1 px-2 py-0.5 bg-amber-50 text-amber-700 border border-amber-200 rounded-full text-xs font-semibold">
                {suggestions.length}
              </span>
            )}
            {t.key === 'auto-replenish' && autoStatus && (
              <span className={`ml-1 px-2 py-0.5 rounded-full text-xs font-semibold ${
                autoStatus.enabled
                  ? 'bg-emerald-50 text-emerald-700 border border-emerald-300'
                  : 'bg-gray-100 text-gray-500 border border-gray-200'
              }`}>
                {autoStatus.enabled ? 'ON' : 'OFF'}
              </span>
            )}
          </button>
        ))}
      </div>

      {/* Shortages Tab */}
      {activeTab === 'shortages' && (
        <PageState loading={loading} onRetry={detectShortages} empty={shortages.length === 0} emptyMessage="No shortages detected">
          <div className="space-y-3">
              {shortages.map((shortage) => (
                <div
                  key={`${shortage.product_id}-${shortage.pick_face_location}`}
                  className="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow"
                >
                  <button
                    onClick={() =>
                      setExpandedShortageId(
                        expandedShortageId ===
                          `${shortage.product_id}-${shortage.pick_face_location}`
                          ? null
                          : `${shortage.product_id}-${shortage.pick_face_location}`
                      )
                    }
                    className="w-full p-4 flex items-center justify-between hover:bg-brand-50/50 transition-colors"
                  >
                    <div className="flex-1 text-left">
                      <div className="flex items-center gap-3">
                        <div className="flex-1">
                          <h4 className="font-semibold text-gray-900">
                            {shortage.product_name}
                          </h4>
                          <p className="text-sm text-gray-600">
                            Product: {shortage.product_code} | Location: {shortage.pick_face_location}
                          </p>
                        </div>
                        <div className="text-right">
                          <div className="text-lg font-bold text-red-600">
                            {shortage.shortage} units
                          </div>
                          <p className="text-xs text-gray-500">
                            Current: {shortage.available_qty} / Min: {shortage.min_qty}
                          </p>
                        </div>
                      </div>
                    </div>
                    <ChevronDown
                      className={`w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-2 ${
                        expandedShortageId ===
                        `${shortage.product_id}-${shortage.pick_face_location}`
                          ? 'rotate-180'
                          : ''
                      }`}
                    />
                  </button>

                  {expandedShortageId ===
                    `${shortage.product_id}-${shortage.pick_face_location}` && (
                    <div className="border-t border-gray-100 bg-gray-50 p-4">
                      <dl className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                          <dt className="font-semibold text-gray-600">
                            Current Quantity
                          </dt>
                          <dd className="text-gray-900">
                            {shortage.available_qty} units
                          </dd>
                        </div>
                        <div>
                          <dt className="font-semibold text-gray-600">
                            Minimum Quantity
                          </dt>
                          <dd className="text-gray-900">
                            {shortage.min_qty} units
                          </dd>
                        </div>
                        <div>
                          <dt className="font-semibold text-gray-600">
                            Replenishment Target
                          </dt>
                          <dd className="text-brand-600 font-semibold">
                            {shortage.uom_per_pallet} units (uom_per_pallet)
                          </dd>
                        </div>
                        <div>
                          <dt className="font-semibold text-gray-600">
                            Shortage Amount
                          </dt>
                          <dd className="text-red-600 font-semibold">
                            {shortage.shortage} units
                          </dd>
                        </div>
                      </dl>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </PageState>
      )}

      {/* Suggestions Tab */}
      {activeTab === 'suggestions' && (
        <PageState loading={loading} onRetry={suggestTransfers} empty={suggestions.length === 0} emptyMessage="No transfer suggestions">
          <div className="space-y-3">
              {suggestions.map((suggestion) => (
                <div
                  key={suggestion.id}
                  className="bg-white rounded-xl border border-gray-200 shadow-sm hover:shadow-md transition-shadow"
                >
                  <button
                    onClick={() =>
                      setExpandedSuggestionId(
                        expandedSuggestionId === suggestion.id
                          ? null
                          : suggestion.id
                      )
                    }
                    className="w-full p-4 flex items-center justify-between hover:bg-brand-50/50 transition-colors"
                  >
                    <div className="flex-1 text-left">
                      <div className="flex items-center gap-3">
                        <div className="flex-1">
                          <h4 className="font-semibold text-gray-900">
                            {suggestion.product_name}
                          </h4>
                          <p className="text-sm text-gray-600">
                            From: <span className="font-medium">{suggestion.from_location_code}</span> <ArrowRight className="w-4 h-4 inline" /> To: <span className="font-medium">{suggestion.to_location_code}</span>
                          </p>
                        </div>
                        <div className="text-right">
                          <div className="text-lg font-bold text-amber-600">
                            {suggestion.transfer_qty} units
                          </div>
                          <p className="text-xs text-gray-500 capitalize">
                            {suggestion.priority} Priority
                          </p>
                        </div>
                      </div>
                    </div>
                    <ChevronDown
                      className={`w-5 h-5 text-gray-400 transition-transform flex-shrink-0 ml-2 ${
                        expandedSuggestionId === suggestion.id
                          ? 'rotate-180'
                          : ''
                      }`}
                    />
                  </button>

                  {expandedSuggestionId === suggestion.id && (
                    <div className="border-t border-gray-100 bg-gray-50 p-4">
                      <dl className="grid grid-cols-2 gap-4 text-sm">
                        <div>
                          <dt className="font-semibold text-gray-600">
                            From Location
                          </dt>
                          <dd className="text-gray-900">
                            {suggestion.from_location_code} - {suggestion.from_location_name}
                          </dd>
                        </div>
                        <div>
                          <dt className="font-semibold text-gray-600">
                            To Location
                          </dt>
                          <dd className="text-gray-900">
                            {suggestion.to_location_code} - {suggestion.to_location_name}
                          </dd>
                        </div>
                        <div>
                          <dt className="font-semibold text-gray-600">
                            Product Code
                          </dt>
                          <dd className="text-gray-900">
                            {suggestion.product_code}
                          </dd>
                        </div>
                        <div>
                          <dt className="font-semibold text-gray-600">
                            Transfer Quantity
                          </dt>
                          <dd className="text-amber-600 font-semibold">
                            {suggestion.transfer_qty} units
                          </dd>
                        </div>
                        <div className="col-span-2">
                          <dt className="font-semibold text-gray-600">
                            Reason
                          </dt>
                          <dd className="text-gray-900">
                            {suggestion.reason}
                          </dd>
                        </div>
                        <div className="col-span-2">
                          <dt className="font-semibold text-gray-600">
                            Suggested At
                          </dt>
                          <dd className="text-gray-900">
                            {new Date(suggestion.suggested_at).toLocaleString()}
                          </dd>
                        </div>
                      </dl>
                    </div>
                  )}
                </div>
              ))}
            </div>
          </PageState>
      )}

      {/* Auto-Replenish Tab */}
      {activeTab === 'auto-replenish' && (
        <PageState loading={autoLoading} onRetry={loadAutoStatus} emptyMessage="Loading auto-replenish status...">
          <div className="space-y-5">
              <Card title="Auto-Replenishment System">
                <div className="flex items-center justify-between mb-5">
                  <p className="text-sm text-gray-500">
                    Automatically detect shortages and generate bin transfers on a schedule
                  </p>
                  <div className="flex items-center gap-3">
                    <span className={`text-sm font-medium ${
                      autoConfig.enabled === '1' ? 'text-emerald-700' : 'text-gray-500'
                    }`}>
                      {autoConfig.enabled === '1' ? 'Enabled' : 'Disabled'}
                    </span>
                    <button
                      onClick={toggleAutoEnabled}
                      className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors ${
                        autoConfig.enabled === '1' ? 'bg-emerald-600' : 'bg-gray-300'
                      }`}
                    >
                      <span
                        className={`inline-block h-5 w-5 transform rounded-full bg-white transition-transform ${
                          autoConfig.enabled === '1' ? 'translate-x-6' : 'translate-x-1'
                        }`}
                      />
                    </button>
                  </div>
                </div>

                {/* Status Cards */}
                {autoStatus && (
                  <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-3 flex items-center gap-3">
                      <span className="w-9 h-9 rounded-lg bg-red-50 text-red-600 flex items-center justify-center">
                        <AlertCircle className="w-4 h-4" />
                      </span>
                      <div>
                        <div className="text-lg font-bold text-gray-900">{autoStatus.total_shortages}</div>
                        <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">Total Shortages</div>
                      </div>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-3 flex items-center gap-3">
                      <span className="w-9 h-9 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center">
                        <Clock className="w-4 h-4" />
                      </span>
                      <div>
                        <div className="text-lg font-bold text-gray-900">{autoStatus.pending_transfers}</div>
                        <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">Pending Transfers</div>
                      </div>
                    </div>
                    <div className="bg-white rounded-xl border border-gray-200 shadow-sm px-5 py-3 flex items-center gap-3">
                      <span className={`w-9 h-9 rounded-lg flex items-center justify-center ${
                        autoStatus.enabled ? 'bg-emerald-50 text-emerald-600' : 'bg-gray-50 text-gray-400'
                      }`}>
                        <Zap className="w-4 h-4" />
                      </span>
                      <div>
                        <div className={`text-lg font-bold ${
                          autoStatus.enabled ? 'text-emerald-700' : 'text-gray-500'
                        }`}>
                          {autoStatus.enabled ? 'Active' : 'Inactive'}
                        </div>
                        <div className="text-[11px] text-gray-500 font-semibold uppercase tracking-wide">System Status</div>
                      </div>
                    </div>
                  </div>
                )}
              </Card>

              {/* Configuration */}
              <Card title="Configuration">
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      Schedule (minutes)
                    </label>
                    <select
                      value={autoConfig.schedule_minutes}
                      onChange={(e) => setAutoConfig({ ...autoConfig, schedule_minutes: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm"
                    >
                      <option value="5">Every 5 minutes</option>
                      <option value="10">Every 10 minutes</option>
                      <option value="15">Every 15 minutes</option>
                      <option value="30">Every 30 minutes</option>
                      <option value="60">Every hour</option>
                    </select>
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      Batch Size (transfers per cycle)
                    </label>
                    <input
                      type="number"
                      min="1"
                      max="100"
                      value={autoConfig.batch_size}
                      onChange={(e) => setAutoConfig({ ...autoConfig, batch_size: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium text-gray-700 mb-1">
                      Cooldown (minutes between runs)
                    </label>
                    <input
                      type="number"
                      min="0"
                      max="1440"
                      value={autoConfig.cooldown_minutes}
                      onChange={(e) => setAutoConfig({ ...autoConfig, cooldown_minutes: e.target.value })}
                      className="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-brand-500 focus:border-brand-500 text-sm"
                    />
                  </div>
                  <div className="flex items-center gap-2 pt-6">
                    <input
                      type="checkbox"
                      id="require_approval"
                      checked={autoConfig.require_approval === '1'}
                      onChange={(e) => setAutoConfig({
                        ...autoConfig,
                        require_approval: e.target.checked ? '1' : '0',
                      })}
                      className="w-4 h-4 text-brand-600 rounded border-gray-300 focus:ring-brand-500"
                    />
                    <label htmlFor="require_approval" className="text-sm font-medium text-gray-700">
                      Require supervisor approval
                    </label>
                  </div>
                </div>

                <div className="mt-4">
                  <p className="text-sm font-medium text-gray-700 mb-2">Notifications</p>
                  <div className="flex flex-wrap gap-4">
                    {[
                      { key: 'notify_on_generate', label: 'On generate' },
                      { key: 'notify_on_execute', label: 'On execute' },
                      { key: 'notify_on_failure', label: 'On failure' },
                    ].map(({ key, label }) => (
                      <label key={key} className="flex items-center gap-2">
                        <input
                          type="checkbox"
                          checked={autoConfig[key as keyof ReplenishmentConfig] === '1'}
                          onChange={(e) => setAutoConfig({
                            ...autoConfig,
                            [key]: e.target.checked ? '1' : '0',
                          })}
                          className="w-4 h-4 text-brand-600 rounded border-gray-300 focus:ring-brand-500"
                        />
                        <span className="text-sm text-gray-700">{label}</span>
                      </label>
                    ))}
                  </div>
                </div>

                <div className="mt-5 flex items-center gap-3">
                  <button
                    onClick={saveAutoConfig}
                    disabled={autoConfigLoading}
                    className="px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 disabled:opacity-50 text-white text-sm font-semibold transition-colors"
                  >
                    {autoConfigLoading ? 'Saving...' : 'Save Configuration'}
                  </button>
                  {autoSaveSuccess && (
                    <span className="text-emerald-600 text-sm font-medium flex items-center gap-1">
                      <CheckCircle2 className="w-4 h-4" />
                      Saved
                    </span>
                  )}
                </div>
              </Card>

              {/* Quick Actions */}
              <Card title="Quick Actions">
                <div className="flex items-center gap-3">
                  <button
                    onClick={runAutoNow}
                    disabled={autoRunning}
                    className="px-5 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-sm font-semibold flex items-center gap-2 transition-colors"
                  >
                    {autoRunning ? (
                      <>
                        <RefreshCw className="w-4 h-4 animate-spin" />
                        Running...
                      </>
                    ) : (
                      <>
                        <Play className="w-4 h-4" />
                        Run Now
                      </>
                    )}
                  </button>
                  <button
                    onClick={loadAutoStatus}
                    disabled={autoLoading}
                    className="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700 text-sm font-medium flex items-center gap-2 transition-colors"
                  >
                    <History className="w-4 h-4" />
                    Refresh Activity
                  </button>
                </div>
              </Card>

              {/* Recent Activity */}
              <Card title="Recent Activity (Last 24 Hours)">
                {autoActivity.length === 0 ? (
                  <EmptyState message="No recent auto-replenishment activity" />
                ) : (
                  <div className="space-y-2">
                    {autoActivity.map((item) => (
                      <div
                        key={item.id}
                        className={`flex items-center gap-3 p-3 rounded-lg ${
                          item.status === 'generated' || item.status === 'executed'
                            ? 'bg-emerald-50'
                            : item.status === 'skipped'
                              ? 'bg-amber-50'
                              : item.status === 'failed'
                                ? 'bg-red-50'
                                : 'bg-gray-50'
                        }`}
                      >
                        <div className="flex-shrink-0">
                          {item.status === 'generated' || item.status === 'executed' ? (
                            <CheckCircle2 className="w-5 h-5 text-emerald-600" />
                          ) : item.status === 'skipped' ? (
                            <SkipForward className="w-5 h-5 text-amber-600" />
                          ) : item.status === 'failed' ? (
                            <XCircle className="w-5 h-5 text-red-600" />
                          ) : (
                            <Clock className="w-5 h-5 text-gray-400" />
                          )}
                        </div>
                        <div className="flex-1 min-w-0">
                          <p className="text-sm text-gray-900">
                            {item.status === 'generated' && (
                              <>Generated transfer for <span className="font-medium">{item.location_code}</span></>
                            )}
                            {item.status === 'executed' && (
                              <>Executed transfer for <span className="font-medium">{item.location_code}</span></>
                            )}
                            {item.status === 'skipped' && (
                              <>Skipped <span className="font-medium">{item.location_code}</span>
                                {item.skip_reason && (
                                  <span className="text-amber-700"> ({item.skip_reason})</span>
                                )}
                              </>
                            )}
                            {item.status === 'failed' && (
                              <>Failed <span className="font-medium">{item.location_code}</span>
                                {item.skip_reason && (
                                  <span className="text-red-700"> ({item.skip_reason})</span>
                                )}
                              </>
                            )}
                            {item.status === 'pending' && (
                              <>Pending transfer for <span className="font-medium">{item.location_code}</span></>
                            )}
                          </p>
                          <p className="text-xs text-gray-500 mt-0.5">
                            {item.trigger_type} &middot; {fmtDateTime(item.created_at)}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </Card>
            </div>
          </PageState>
      )}

      {/* History Tab */}
      {activeTab === 'history' && (
        <Card>
          <EmptyState message="Replenishment history will be displayed here" />
        </Card>
      )}
    </div>
  );
};

export default ReplenishmentPage;
