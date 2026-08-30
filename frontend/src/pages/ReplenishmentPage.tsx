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
  MapPin,
  Clock,
  SkipForward,
  Play,
  Settings,
  Bell,
  History,
} from 'lucide-react';
import { api } from '@/lib/api';
import { fmtDateTime } from '@/lib/format';

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
  const [activeTab, setActiveTab] = useState<TabType>('shortages');
  const [shortages, setShortages] = useState<Shortage[]>([]);
  const [suggestions, setSuggestions] = useState<SuggestedTransfer[]>([]);
  const [loading, setLoading] = useState(false);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [successMessage, setSuccessMessage] = useState<string | null>(null);
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
    setError(null);
    setSuccessMessage(null);

    try {
      const data = await api('replenishment', 'detect');
      const rows = data.shortages || [];
      setShortages(rows);
      setSuccessMessage(`Detected ${rows.length} shortage(s) across the warehouse`);
    } catch (err) {
      const errorMsg =
        err instanceof Error ? err.message : 'Unknown error occurred';
      setError(`Error detecting shortages: ${errorMsg}`);
      setShortages([]);
    } finally {
      setLoading(false);
    }
  };

  const suggestTransfers = async () => {
    setLoading(true);
    setError(null);
    setSuccessMessage(null);

    try {
      const data = await api('replenishment', 'suggest');
      const rows = data.suggestions || [];
      setSuggestions(rows);
      setActiveTab('suggestions');
      setSuccessMessage(`Generated ${rows.length} transfer suggestion(s)`);
    } catch (err) {
      const errorMsg =
        err instanceof Error ? err.message : 'Unknown error occurred';
      setError(`Error suggesting transfers: ${errorMsg}`);
      setSuggestions([]);
    } finally {
      setLoading(false);
    }
  };

  const generateTransfers = async () => {
    if (suggestions.length === 0) {
      setError('No suggestions available. Please suggest transfers first.');
      return;
    }

    setGenerating(true);
    setError(null);
    setSuccessMessage(null);

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
      setSuccessMessage(
        parts.length ? `Replenishment complete: ${parts.join(', ')}` : 'No transfers generated'
      );
      setSuggestions([]);
      setTimeout(() => detectShortages(), 1000);
    } catch (err) {
      const errorMsg =
        err instanceof Error ? err.message : 'Unknown error occurred';
      setError(`Error generating transfers: ${errorMsg}`);
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
    setError(null);
    try {
      await api('replenishment', 'update_auto_config', {
        method: 'POST',
        body: autoConfig,
      });
      setAutoSaveSuccess(true);
      setTimeout(() => setAutoSaveSuccess(false), 3000);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      setError(`Failed to save config: ${msg}`);
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
      setError(`Failed to toggle: ${msg}`);
    }
  };

  const runAutoNow = async () => {
    setAutoRunning(true);
    setError(null);
    try {
      const data = await api('replenishment', 'run_cycle', {
        method: 'POST',
        body: { trigger: 'manual' },
      });
      const count = data.transfers_created ?? 0;
      setSuccessMessage(`Auto-replenish cycle complete: ${count} transfer(s) created`);
      loadAutoStatus();
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      setError(`Auto-replenish failed: ${msg}`);
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

  return (
    <div className="min-h-screen bg-gradient-to-br from-slate-50 to-slate-100 p-6">
      <div className="max-w-7xl mx-auto">
        {/* Header */}
        <div className="mb-8">
          <div className="flex items-center justify-between">
            <div>
              <h1 className="text-4xl font-bold text-slate-900 flex items-center gap-3">
                <TrendingUp className="w-10 h-10 text-blue-600" />
                Replenishment Management
              </h1>
              <p className="text-slate-600 mt-2">
                Detect shortages, suggest transfers, and manage warehouse stock levels
              </p>
            </div>
          </div>
        </div>

        {/* Alert Messages */}
        {error && (
          <div className="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start gap-3">
            <XCircle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" />
            <div className="flex-1">
              <h3 className="font-semibold text-red-900">Error</h3>
              <p className="text-red-800 text-sm mt-1">{error}</p>
            </div>
            <button
              onClick={() => setError(null)}
              className="text-red-600 hover:text-red-900 font-medium text-sm"
            >
              Dismiss
            </button>
          </div>
        )}

        {successMessage && (
          <div className="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg flex items-start gap-3">
            <CheckCircle2 className="w-5 h-5 text-green-600 flex-shrink-0 mt-0.5" />
            <div className="flex-1">
              <h3 className="font-semibold text-green-900">Success</h3>
              <p className="text-green-800 text-sm mt-1">{successMessage}</p>
            </div>
            <button
              onClick={() => setSuccessMessage(null)}
              className="text-green-600 hover:text-green-900 font-medium text-sm"
            >
              Dismiss
            </button>
          </div>
        )}

        {/* Action Buttons */}
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
          <button
            onClick={detectShortages}
            disabled={loading || generating}
            className="px-6 py-3 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-semibold rounded-lg flex items-center justify-center gap-2 transition-colors shadow-lg"
          >
            {loading ? (
              <>
                <RefreshCw className="w-5 h-5 animate-spin" />
                Detecting...
              </>
            ) : (
              <>
                <AlertCircle className="w-5 h-5" />
                Detect Shortages
              </>
            )}
          </button>

          <button
            onClick={suggestTransfers}
            disabled={loading || generating}
            className="px-6 py-3 bg-amber-600 hover:bg-amber-700 disabled:bg-amber-400 text-white font-semibold rounded-lg flex items-center justify-center gap-2 transition-colors shadow-lg"
          >
            {loading ? (
              <>
                <RefreshCw className="w-5 h-5 animate-spin" />
                Suggesting...
              </>
            ) : (
              <>
                <TrendingUp className="w-5 h-5" />
                Suggest Transfers
              </>
            )}
          </button>

          <button
            onClick={generateTransfers}
            disabled={loading || generating || suggestions.length === 0}
            className="px-6 py-3 bg-green-600 hover:bg-green-700 disabled:bg-green-400 text-white font-semibold rounded-lg flex items-center justify-center gap-2 transition-colors shadow-lg"
          >
            {generating ? (
              <>
                <RefreshCw className="w-5 h-5 animate-spin" />
                Generating...
              </>
            ) : (
              <>
                <Zap className="w-5 h-5" />
                Generate Transfers
              </>
            )}
          </button>
        </div>

        {/* Tabs */}
        <div className="bg-white rounded-lg shadow-lg overflow-hidden">
          <div className="border-b border-slate-200">
            <div className="flex">
              {[
                { id: 'shortages' as const, label: 'Shortages', icon: AlertCircle },
                { id: 'suggestions' as const, label: 'Suggestions', icon: TrendingUp },
                { id: 'auto-replenish' as const, label: 'Auto-Replenish', icon: Zap },
                { id: 'history' as const, label: 'History', icon: RefreshCw },
              ].map(({ id, label, icon: Icon }) => (
                <button
                  key={id}
                  onClick={() => setActiveTab(id)}
                  className={`flex items-center gap-2 px-6 py-4 font-medium transition-colors border-b-2 ${
                    activeTab === id
                      ? 'border-blue-600 text-blue-600 bg-blue-50'
                      : 'border-transparent text-slate-600 hover:text-slate-900'
                  }`}
                >
                  <Icon className="w-5 h-5" />
                  {label}
                  {id === 'shortages' && shortages.length > 0 && (
                    <span className="ml-1 px-2 py-0.5 bg-red-100 text-red-700 rounded-full text-xs font-semibold">
                      {shortages.length}
                    </span>
                  )}
                  {id === 'suggestions' && suggestions.length > 0 && (
                    <span className="ml-1 px-2 py-0.5 bg-amber-100 text-amber-700 rounded-full text-xs font-semibold">
                      {suggestions.length}
                    </span>
                  )}
                  {id === 'auto-replenish' && autoStatus && (
                    <span className={`ml-1 px-2 py-0.5 rounded-full text-xs font-semibold ${
                      autoStatus.enabled ? 'bg-green-100 text-green-700' : 'bg-slate-100 text-slate-500'
                    }`}>
                      {autoStatus.enabled ? 'ON' : 'OFF'}
                    </span>
                  )}
                </button>
              ))}
            </div>
          </div>

          <div className="p-6">
            {/* Shortages Tab */}
            {activeTab === 'shortages' && (
              <div>
                {shortages.length === 0 ? (
                  <div className="text-center py-12">
                    <AlertCircle className="w-12 h-12 text-slate-300 mx-auto mb-4" />
                    <p className="text-slate-600 text-lg">
                      {loading ? 'Loading shortages...' : 'No shortages detected'}
                    </p>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {shortages.map((shortage) => (
                      <div
                        key={`${shortage.product_id}-${shortage.pick_face_location}`}
                        className="border border-slate-200 rounded-lg hover:shadow-md transition-shadow"
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
                          className="w-full p-4 flex items-center justify-between hover:bg-slate-50 transition-colors"
                        >
                          <div className="flex-1 text-left">
                            <div className="flex items-center gap-3">
                              <div className="flex-1">
                                <h4 className="font-semibold text-slate-900">
                                  {shortage.product_name}
                                </h4>
                                <p className="text-sm text-slate-600">
                                  Product: {shortage.product_code} | Location: {shortage.pick_face_location}
                                </p>
                              </div>
                              <div className="text-right">
                                <div className="text-lg font-bold text-red-600">
                                  {shortage.shortage} units
                                </div>
                                <p className="text-xs text-slate-500">
                                  Current: {shortage.available_qty} / Min: {shortage.min_qty}
                                </p>
                              </div>
                            </div>
                          </div>
                          <ChevronDown
                            className={`w-5 h-5 text-slate-400 transition-transform flex-shrink-0 ml-2 ${
                              expandedShortageId ===
                              `${shortage.product_id}-${shortage.pick_face_location}`
                                ? 'rotate-180'
                                : ''
                            }`}
                          />
                        </button>

                        {expandedShortageId ===
                          `${shortage.product_id}-${shortage.pick_face_location}` && (
                          <div className="border-t border-slate-200 bg-slate-50 p-4">
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                              <div>
                                <dt className="font-semibold text-slate-700">
                                  Current Quantity
                                </dt>
                                <dd className="text-slate-900">
                                  {shortage.available_qty} units
                                </dd>
                              </div>
                              <div>
                                <dt className="font-semibold text-slate-700">
                                  Minimum Quantity
                                </dt>
                                <dd className="text-slate-900">
                                  {shortage.min_qty} units
                                </dd>
                              </div>
                              <div>
                                <dt className="font-semibold text-slate-700">
                                  Replenishment Target
                                </dt>
                                <dd className="text-blue-600 font-semibold">
                                  {shortage.uom_per_pallet} units (uom_per_pallet)
                                </dd>
                              </div>
                              <div>
                                <dt className="font-semibold text-slate-700">
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
                )}
              </div>
            )}

            {/* Suggestions Tab */}
            {activeTab === 'suggestions' && (
              <div>
                {suggestions.length === 0 ? (
                  <div className="text-center py-12">
                    <TrendingUp className="w-12 h-12 text-slate-300 mx-auto mb-4" />
                    <p className="text-slate-600 text-lg">
                      {loading ? 'Loading suggestions...' : 'No transfer suggestions'}
                    </p>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {suggestions.map((suggestion) => (
                      <div
                        key={suggestion.id}
                        className="border border-slate-200 rounded-lg hover:shadow-md transition-shadow bg-gradient-to-r from-amber-50 to-transparent"
                      >
                        <button
                          onClick={() =>
                            setExpandedSuggestionId(
                              expandedSuggestionId === suggestion.id
                                ? null
                                : suggestion.id
                            )
                          }
                          className="w-full p-4 flex items-center justify-between hover:bg-amber-50 transition-colors"
                        >
                          <div className="flex-1 text-left">
                            <div className="flex items-center gap-3">
                              <div className="flex-1">
                                <h4 className="font-semibold text-slate-900">
                                  {suggestion.product_name}
                                </h4>
                                <p className="text-sm text-slate-600">
                                  From: <span className="font-medium">{suggestion.from_location_code}</span> →
                                  To: <span className="font-medium">{suggestion.to_location_code}</span>
                                </p>
                              </div>
                              <div className="text-right">
                                <div className="text-lg font-bold text-amber-600">
                                  {suggestion.transfer_qty} units
                                </div>
                                <p className="text-xs text-slate-500 capitalize">
                                  {suggestion.priority} Priority
                                </p>
                              </div>
                            </div>
                          </div>
                          <ChevronDown
                            className={`w-5 h-5 text-slate-400 transition-transform flex-shrink-0 ml-2 ${
                              expandedSuggestionId === suggestion.id
                                ? 'rotate-180'
                                : ''
                            }`}
                          />
                        </button>

                        {expandedSuggestionId === suggestion.id && (
                          <div className="border-t border-amber-200 bg-amber-50 p-4">
                            <dl className="grid grid-cols-2 gap-4 text-sm">
                              <div>
                                <dt className="font-semibold text-slate-700">
                                  From Location
                                </dt>
                                <dd className="text-slate-900">
                                  {suggestion.from_location_code} - {suggestion.from_location_name}
                                </dd>
                              </div>
                              <div>
                                <dt className="font-semibold text-slate-700">
                                  To Location
                                </dt>
                                <dd className="text-slate-900">
                                  {suggestion.to_location_code} - {suggestion.to_location_name}
                                </dd>
                              </div>
                              <div>
                                <dt className="font-semibold text-slate-700">
                                  Product Code
                                </dt>
                                <dd className="text-slate-900">
                                  {suggestion.product_code}
                                </dd>
                              </div>
                              <div>
                                <dt className="font-semibold text-slate-700">
                                  Transfer Quantity
                                </dt>
                                <dd className="text-amber-600 font-semibold">
                                  {suggestion.transfer_qty} units
                                </dd>
                              </div>
                              <div className="col-span-2">
                                <dt className="font-semibold text-slate-700">
                                  Reason
                                </dt>
                                <dd className="text-slate-900">
                                  {suggestion.reason}
                                </dd>
                              </div>
                              <div className="col-span-2">
                                <dt className="font-semibold text-slate-700">
                                  Suggested At
                                </dt>
                                <dd className="text-slate-900">
                                  {new Date(suggestion.suggested_at).toLocaleString()}
                                </dd>
                              </div>
                            </dl>
                          </div>
                        )}
                      </div>
                    ))}
                  </div>
                )}
              </div>
            )}

            {/* Auto-Replenish Tab */}
            {activeTab === 'auto-replenish' && (
              <div className="space-y-6">
                {autoLoading ? (
                  <div className="text-center py-12">
                    <RefreshCw className="w-12 h-12 text-slate-300 mx-auto mb-4 animate-spin" />
                    <p className="text-slate-600 text-lg">Loading auto-replenish status...</p>
                  </div>
                ) : (
                  <>
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="text-xl font-bold text-slate-900 flex items-center gap-2">
                          <Zap className="w-6 h-6 text-blue-600" />
                          Auto-Replenishment System
                        </h3>
                        <p className="text-slate-600 mt-1">
                          Automatically detect shortages and generate bin transfers on a schedule
                        </p>
                      </div>
                      <div className="flex items-center gap-3">
                        <span className={`text-sm font-medium ${
                          autoConfig.enabled === '1' ? 'text-green-700' : 'text-slate-500'
                        }`}>
                          {autoConfig.enabled === '1' ? 'Enabled' : 'Disabled'}
                        </span>
                        <button
                          onClick={toggleAutoEnabled}
                          className={`relative inline-flex h-7 w-12 items-center rounded-full transition-colors ${
                            autoConfig.enabled === '1' ? 'bg-green-600' : 'bg-slate-300'
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
                        <div className="bg-white border border-slate-200 rounded-lg p-4">
                          <div className="flex items-center gap-3">
                            <div className="p-2 bg-red-50 rounded-lg">
                              <AlertCircle className="w-5 h-5 text-red-600" />
                            </div>
                            <div>
                              <p className="text-sm text-slate-600">Total Shortages</p>
                              <p className="text-2xl font-bold text-slate-900">{autoStatus.total_shortages}</p>
                            </div>
                          </div>
                        </div>
                        <div className="bg-white border border-slate-200 rounded-lg p-4">
                          <div className="flex items-center gap-3">
                            <div className="p-2 bg-amber-50 rounded-lg">
                              <Clock className="w-5 h-5 text-amber-600" />
                            </div>
                            <div>
                              <p className="text-sm text-slate-600">Pending Transfers</p>
                              <p className="text-2xl font-bold text-slate-900">{autoStatus.pending_transfers}</p>
                            </div>
                          </div>
                        </div>
                        <div className="bg-white border border-slate-200 rounded-lg p-4">
                          <div className="flex items-center gap-3">
                            <div className={`p-2 rounded-lg ${
                              autoStatus.enabled ? 'bg-green-50' : 'bg-slate-50'
                            }`}>
                              <Zap className={`w-5 h-5 ${
                                autoStatus.enabled ? 'text-green-600' : 'text-slate-400'
                              }`} />
                            </div>
                            <div>
                              <p className="text-sm text-slate-600">System Status</p>
                              <p className={`text-lg font-bold ${
                                autoStatus.enabled ? 'text-green-600' : 'text-slate-500'
                              }`}>
                                {autoStatus.enabled ? 'Active' : 'Inactive'}
                              </p>
                            </div>
                          </div>
                        </div>
                      </div>
                    )}

                    {/* Configuration */}
                    <div className="bg-white border border-slate-200 rounded-lg p-6">
                      <div className="flex items-center gap-2 mb-4">
                        <Settings className="w-5 h-5 text-slate-600" />
                        <h4 className="font-semibold text-slate-900">Configuration</h4>
                      </div>
                      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                          <label className="block text-sm font-medium text-slate-700 mb-1">
                            Schedule (minutes)
                          </label>
                          <select
                            value={autoConfig.schedule_minutes}
                            onChange={(e) => setAutoConfig({ ...autoConfig, schedule_minutes: e.target.value })}
                            className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                          >
                            <option value="5">Every 5 minutes</option>
                            <option value="10">Every 10 minutes</option>
                            <option value="15">Every 15 minutes</option>
                            <option value="30">Every 30 minutes</option>
                            <option value="60">Every hour</option>
                          </select>
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-slate-700 mb-1">
                            Batch Size (transfers per cycle)
                          </label>
                          <input
                            type="number"
                            min="1"
                            max="100"
                            value={autoConfig.batch_size}
                            onChange={(e) => setAutoConfig({ ...autoConfig, batch_size: e.target.value })}
                            className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                          />
                        </div>
                        <div>
                          <label className="block text-sm font-medium text-slate-700 mb-1">
                            Cooldown (minutes between runs)
                          </label>
                          <input
                            type="number"
                            min="0"
                            max="1440"
                            value={autoConfig.cooldown_minutes}
                            onChange={(e) => setAutoConfig({ ...autoConfig, cooldown_minutes: e.target.value })}
                            className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
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
                            className="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                          />
                          <label htmlFor="require_approval" className="text-sm font-medium text-slate-700">
                            Require supervisor approval
                          </label>
                        </div>
                      </div>

                      <div className="mt-4">
                        <p className="text-sm font-medium text-slate-700 mb-2">Notifications</p>
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
                                className="w-4 h-4 text-blue-600 rounded border-slate-300 focus:ring-blue-500"
                              />
                              <span className="text-sm text-slate-700">{label}</span>
                            </label>
                          ))}
                        </div>
                      </div>

                      <div className="mt-6 flex items-center gap-3">
                        <button
                          onClick={saveAutoConfig}
                          disabled={autoConfigLoading}
                          className="px-4 py-2 bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white font-medium rounded-lg transition-colors"
                        >
                          {autoConfigLoading ? 'Saving...' : 'Save Configuration'}
                        </button>
                        {autoSaveSuccess && (
                          <span className="text-green-600 text-sm font-medium flex items-center gap-1">
                            <CheckCircle2 className="w-4 h-4" />
                            Saved
                          </span>
                        )}
                      </div>
                    </div>

                    {/* Quick Actions */}
                    <div className="bg-white border border-slate-200 rounded-lg p-6">
                      <div className="flex items-center gap-2 mb-4">
                        <Bell className="w-5 h-5 text-slate-600" />
                        <h4 className="font-semibold text-slate-900">Quick Actions</h4>
                      </div>
                      <div className="flex items-center gap-3">
                        <button
                          onClick={runAutoNow}
                          disabled={autoRunning}
                          className="px-6 py-2.5 bg-green-600 hover:bg-green-700 disabled:bg-green-400 text-white font-semibold rounded-lg flex items-center gap-2 transition-colors shadow-lg"
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
                          className="px-4 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-medium rounded-lg flex items-center gap-2 transition-colors"
                        >
                          <History className="w-4 h-4" />
                          Refresh Activity
                        </button>
                      </div>
                    </div>

                    {/* Recent Activity */}
                    <div className="bg-white border border-slate-200 rounded-lg p-6">
                      <div className="flex items-center gap-2 mb-4">
                        <History className="w-5 h-5 text-slate-600" />
                        <h4 className="font-semibold text-slate-900">Recent Activity (Last 24 Hours)</h4>
                      </div>
                      {autoActivity.length === 0 ? (
                        <div className="text-center py-8">
                          <Clock className="w-10 h-10 text-slate-300 mx-auto mb-3" />
                          <p className="text-slate-500">No recent auto-replenishment activity</p>
                        </div>
                      ) : (
                        <div className="space-y-2">
                          {autoActivity.map((item) => (
                            <div
                              key={item.id}
                              className={`flex items-center gap-3 p-3 rounded-lg ${
                                item.status === 'generated' || item.status === 'executed'
                                  ? 'bg-green-50'
                                  : item.status === 'skipped'
                                    ? 'bg-amber-50'
                                    : item.status === 'failed'
                                      ? 'bg-red-50'
                                      : 'bg-slate-50'
                              }`}
                            >
                              <div className="flex-shrink-0">
                                {item.status === 'generated' || item.status === 'executed' ? (
                                  <CheckCircle2 className="w-5 h-5 text-green-600" />
                                ) : item.status === 'skipped' ? (
                                  <SkipForward className="w-5 h-5 text-amber-600" />
                                ) : item.status === 'failed' ? (
                                  <XCircle className="w-5 h-5 text-red-600" />
                                ) : (
                                  <Clock className="w-5 h-5 text-slate-400" />
                                )}
                              </div>
                              <div className="flex-1 min-w-0">
                                <p className="text-sm text-slate-900">
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
                                <p className="text-xs text-slate-500 mt-0.5">
                                  {item.trigger_type} &middot; {fmtDateTime(item.created_at)}
                                </p>
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  </>
                )}
              </div>
            )}

            {/* History Tab */}
            {activeTab === 'history' && (
              <div>
                <div className="text-center py-12">
                  <RefreshCw className="w-12 h-12 text-slate-300 mx-auto mb-4" />
                  <p className="text-slate-600 text-lg">
                    Replenishment history will be displayed here
                  </p>
                  <p className="text-slate-500 text-sm mt-2">
                    Shows all executed transfers and their status
                  </p>
                </div>
              </div>
            )}
          </div>
        </div>
      </div>
    </div>
  );
};

export default ReplenishmentPage;
