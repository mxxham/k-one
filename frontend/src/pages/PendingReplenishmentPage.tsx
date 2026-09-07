import { useState, useEffect, useCallback } from 'react';
import { api } from '@/lib/api';
import { useToast } from '@/components/Toast';
import { PageHeader } from '@/components/PageHeader';
import StatusBadge from '@/components/StatusBadge';
import Spinner from '@/components/Spinner';
import { Card } from '@/components/Card';
import { RefreshCw, AlertTriangle, CheckCircle, XCircle, Filter } from 'lucide-react';

interface PendingTask {
  id: number;
  sku_id: number;
  product_code: string;
  product_name: string;
  source_location_code: string;
  destination_location_code: string;
  qty: number;
  status: string;
  triggering_order_id: number | null;
  created_at: string;
  age_hours: number;
  task_type: 'replenishment' | 'bin_to_bin';
}

type TaskTypeFilter = 'all' | 'replenishment' | 'bin_to_bin';
type AgeFilter = 'all' | '24h' | '72h';

const PendingReplenishmentPage: React.FC = () => {
  const toast = useToast();
  const [tasks, setTasks] = useState<PendingTask[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [taskTypeFilter, setTaskTypeFilter] = useState<TaskTypeFilter>('all');
  const [ageFilter, setAgeFilter] = useState<AgeFilter>('all');
  const [processingId, setProcessingId] = useState<number | null>(null);

  const fetchTasks = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await api('replenishment', 'list_pending');
      setTasks(data.tasks || []);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      setError(msg);
      toast('error', `Failed to load pending tasks: ${msg}`);
    } finally {
      setLoading(false);
    }
  }, [toast]);

  useEffect(() => {
    fetchTasks();
  }, [fetchTasks]);

  const handleConfirm = async (task: PendingTask) => {
    setProcessingId(task.id);
    try {
      await api('replenishment', 'confirm_task', {
        method: 'POST',
        body: { id: task.id, task_type: task.task_type },
      });
      toast('success', `Task confirmed: ${task.product_code}`);
      fetchTasks();
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      toast('error', `Failed to confirm task: ${msg}`);
    } finally {
      setProcessingId(null);
    }
  };

  const handleCancel = async (task: PendingTask) => {
    setProcessingId(task.id);
    try {
      await api('replenishment', 'cancel_task', {
        method: 'POST',
        body: { id: task.id, task_type: task.task_type },
      });
      toast('success', `Task cancelled: ${task.product_code}`);
      fetchTasks();
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Unknown error';
      toast('error', `Failed to cancel task: ${msg}`);
    } finally {
      setProcessingId(null);
    }
  };

  const filteredTasks = tasks.filter((task) => {
    if (taskTypeFilter !== 'all' && task.task_type !== taskTypeFilter) return false;
    if (ageFilter === '24h' && task.age_hours <= 24) return false;
    if (ageFilter === '72h' && task.age_hours <= 72) return false;
    return true;
  });

  const getRowBg = (task: PendingTask) => {
    if (task.age_hours > 72) return 'bg-red-50';
    if (task.age_hours > 24) return 'bg-amber-50';
    return '';
  };

  return (
    <div>
      <PageHeader
        title="Pending Replenishment Tasks"
        subtitle="Review and confirm replenishment and bin-to-bin consolidation tasks"
        actions={
          <button
            onClick={fetchTasks}
            disabled={loading}
            className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-semibold border border-white/20 disabled:opacity-50"
          >
            {loading ? (
              <RefreshCw className="w-4 h-4 animate-spin" />
            ) : (
              <RefreshCw className="w-4 h-4" />
            )}
            Refresh
          </button>
        }
      />

      <div className="flex items-center gap-3 mb-5 flex-wrap">
        <div className="flex items-center gap-2">
          <Filter className="w-4 h-4 text-gray-500" />
          <span className="text-sm font-medium text-gray-600">Type:</span>
          {(['all', 'replenishment', 'bin_to_bin'] as TaskTypeFilter[]).map((t) => (
            <button
              key={t}
              onClick={() => setTaskTypeFilter(t)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition ${
                taskTypeFilter === t
                  ? 'bg-brand-600 text-white border-brand-600'
                  : 'bg-white text-gray-600 border-gray-200 hover:bg-brand-50'
              }`}
            >
              {t === 'all' ? 'All' : t === 'replenishment' ? 'Replenishment' : 'Bin-to-Bin'}
            </button>
          ))}
        </div>
        <div className="flex items-center gap-2">
          <span className="text-sm font-medium text-gray-600">Age:</span>
          {(['all', '24h', '72h'] as AgeFilter[]).map((a) => (
            <button
              key={a}
              onClick={() => setAgeFilter(a)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold border transition ${
                ageFilter === a
                  ? 'bg-brand-600 text-white border-brand-600'
                  : 'bg-white text-gray-600 border-gray-200 hover:bg-brand-50'
              }`}
            >
              {a === 'all' ? 'All' : a === '24h' ? '> 24h' : '> 72h'}
            </button>
          ))}
        </div>
        <div className="ml-auto text-sm text-gray-500">
          {filteredTasks.length} task{filteredTasks.length !== 1 ? 's' : ''}
        </div>
      </div>

      {loading && tasks.length === 0 ? (
        <Spinner label="Loading pending tasks..." />
      ) : error ? (
        <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-center">
          <AlertTriangle className="mx-auto h-8 w-8 text-red-500 mb-3" />
          <p className="text-sm font-medium text-red-800 mb-1">Failed to load tasks</p>
          <p className="text-sm text-red-600 mb-4">{error}</p>
          <button
            onClick={fetchTasks}
            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors"
          >
            <RefreshCw className="w-4 h-4" />
            Retry
          </button>
        </div>
      ) : filteredTasks.length === 0 ? (
        <Card>
          <div className="py-14 text-center text-gray-400">
            <div className="text-3xl mb-3 opacity-40">◇</div>
            <div className="text-sm">No pending tasks</div>
          </div>
        </Card>
      ) : (
        <Card>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-200">
                  <th className="text-left py-3 px-3 font-semibold text-gray-600">Type</th>
                  <th className="text-left py-3 px-3 font-semibold text-gray-600">SKU</th>
                  <th className="text-left py-3 px-3 font-semibold text-gray-600">Product</th>
                  <th className="text-left py-3 px-3 font-semibold text-gray-600">Source</th>
                  <th className="text-left py-3 px-3 font-semibold text-gray-600">Destination</th>
                  <th className="text-right py-3 px-3 font-semibold text-gray-600">Qty</th>
                  <th className="text-left py-3 px-3 font-semibold text-gray-600">Linked Order</th>
                  <th className="text-right py-3 px-3 font-semibold text-gray-600">Age</th>
                  <th className="text-left py-3 px-3 font-semibold text-gray-600">Status</th>
                  <th className="text-right py-3 px-3 font-semibold text-gray-600">Actions</th>
                </tr>
              </thead>
              <tbody>
                {filteredTasks.map((task) => (
                  <tr
                    key={`${task.task_type}-${task.id}`}
                    className={`border-b border-gray-100 last:border-b-0 ${getRowBg(task)}`}
                  >
                    <td className="py-3 px-3">
                      <span
                        className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${
                          task.task_type === 'replenishment'
                            ? 'bg-amber-50 text-amber-700 border-amber-300'
                            : 'bg-blue-50 text-blue-700 border-blue-300'
                        }`}
                      >
                        {task.task_type === 'replenishment' ? 'Replenishment' : 'Bin-to-Bin'}
                      </span>
                    </td>
                    <td className="py-3 px-3 font-medium text-gray-900">{task.product_code}</td>
                    <td className="py-3 px-3 text-gray-600">{task.product_name}</td>
                    <td className="py-3 px-3 font-mono text-xs text-gray-700">{task.source_location_code}</td>
                    <td className="py-3 px-3 font-mono text-xs text-gray-700">{task.destination_location_code}</td>
                    <td className="py-3 px-3 text-right font-semibold text-gray-900">{task.qty}</td>
                    <td className="py-3 px-3 text-gray-600">
                      {task.triggering_order_id ? `#${task.triggering_order_id}` : '—'}
                    </td>
                    <td className="py-3 px-3 text-right">
                      <span
                        className={`font-semibold ${
                          task.age_hours > 72
                            ? 'text-red-600'
                            : task.age_hours > 24
                              ? 'text-amber-600'
                              : 'text-gray-600'
                        }`}
                      >
                        {task.age_hours}h
                      </span>
                    </td>
                    <td className="py-3 px-3">
                      <StatusBadge status={task.status} />
                    </td>
                    <td className="py-3 px-3 text-right">
                      <div className="flex items-center justify-end gap-1.5">
                        <button
                          onClick={() => handleConfirm(task)}
                          disabled={processingId === task.id}
                          className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white text-xs font-semibold transition-colors"
                        >
                          <CheckCircle className="w-3.5 h-3.5" />
                          Confirm
                        </button>
                        <button
                          onClick={() => handleCancel(task)}
                          disabled={processingId === task.id}
                          className="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-white hover:bg-red-50 disabled:opacity-50 text-red-600 text-xs font-semibold border border-red-200 transition-colors"
                        >
                          <XCircle className="w-3.5 h-3.5" />
                          Cancel
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>
      )}
    </div>
  );
};

export default PendingReplenishmentPage;
