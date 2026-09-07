import { useState, useEffect, useCallback } from 'react';
import { Search, Plus, Pencil, RefreshCw, Warehouse, AlertTriangle } from 'lucide-react';
import { usePickfaceConfigs, useUpdatePickfaceConfig, useCreatePickfaceConfig, useDeletePickfaceConfig } from '@/hooks/usePickfaceConfigs';
import { useProducts, useLocationBins } from '@/hooks/useProducts';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import Spinner from '@/components/Spinner';
import Modal from '@/components/Modal';
import ConfirmButton from '@/components/ConfirmButton';
import { Field, TextInput, Select } from '@/components/Field';

interface PickfaceConfig {
  id: number;
  sku_id: number;
  product_code: string;
  product_name: string;
  uom_type: string;
  uom_per_pallet: number;
  pickface_bin_id: number | null;
  pickface_location_code: string | null;
  pickface_min: number;
  pickface_max: number;
  assigned: boolean;
  created_at: string;
  updated_at: string;
}

const STATUS_STYLES: Record<string, string> = {
  assigned: 'bg-emerald-50 text-emerald-700 border-emerald-300',
  unassigned: 'bg-amber-50 text-amber-700 border-amber-300',
};

const STATUS_LABELS: Record<string, string> = {
  assigned: 'Assigned',
  unassigned: 'Unassigned',
};

export default function PickfaceManagementPage() {
  const toast = useToast();
  const { canAdmin } = useAuth();

  const { data: configs, loading, error, refetch } = usePickfaceConfigs();
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<PickfaceConfig | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const updateMut = useUpdatePickfaceConfig();
  const createMut = useCreatePickfaceConfig();
  const deleteMut = useDeletePickfaceConfig();

  const { products, loading: productsLoading } = useProducts();
  const { bins, loading: binsLoading } = useLocationBins();

  const [formSkuId, setFormSkuId] = useState('');
  const [formBinId, setFormBinId] = useState('');
  const [formMin, setFormMin] = useState('1');
  const [formMax, setFormMax] = useState('0');

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    try {
      const payload: { sku_id: number; pickface_bin_id: number | null; pickface_min: number; pickface_max: number; id?: number | undefined } = {
        sku_id: parseInt(formSkuId, 10),
        pickface_bin_id: null,
        pickface_min: parseInt(formMin, 10),
        pickface_max: parseInt(formMax, 10),
      };
      if (formBinId !== '') {
        payload.pickface_bin_id = parseInt(formBinId, 10);
      }

      if (editing) {
        const updatePayload = { ...payload, id: editing.id };
        await updateMut.mutate(updatePayload);
        toast('success', 'Pickface config updated');
      } else {
        await createMut.mutate(payload);
        toast('success', 'Pickface config created');
      }
      setModalOpen(false);
      refetch();
    } catch (err: any) {
      toast('error', err.message || 'Failed to save');
    }
  };

  const handleDelete = async () => {
    if (!deletingId) return;
    try {
      await deleteMut.mutate(deletingId);
      toast('success', 'Pickface bin unassigned');
      setDeletingId(null);
      refetch();
    } catch (err: any) {
      toast('error', err.message || 'Failed to delete');
    }
  };

  const filteredConfigs = (configs || []).filter((c) => {
    if (statusFilter === 'assigned' && !c.assigned) return false;
    if (statusFilter === 'unassigned' && c.assigned) return false;
    if (search) {
        const q = search.toLowerCase();
        const haystack = `${c.product_code} ${c.product_name} ${c.pickface_location_code ?? ''}`.toLowerCase();
        if (!haystack.includes(q)) return false;
    }
    return true;
  });

  const filteredCount = filteredConfigs.length;

  return (
    <div>
      <PageHeader
        title="Pickface Management"
        subtitle="Manage SKU pickface bin assignments and thresholds"
        actions={
          canAdmin ? (
            <button onClick={() => { setEditing(null); setFormSkuId(''); setFormBinId(''); setFormMin('1'); setFormMax('0'); setModalOpen(true); }} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-semibold border border-white/20">
              <Plus className="w-4 h-4" /> Add Config
            </button>
          ) : undefined
        }
      />

      <Card>
        <div className="mb-4 flex items-end gap-3 flex-wrap">
          <div className="w-72">
            <Field label="Search">
              <div className="relative">
                <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                <input
                  type="text"
                  placeholder="SKU code, product name, or bin..."
                  value={search}
                  onChange={(e) => setSearch(e.target.value)}
                  className="w-full pl-8 pr-3 py-2 border-[1.5px] border-gray-300 rounded-lg text-sm text-brand-900 bg-white focus:border-brand-500 focus:ring-[3px] focus:ring-brand-500/15 outline-none transition"
                />
              </div>
            </Field>
          </div>
          <Field label="Status">
            <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="all">All</option>
              <option value="assigned">Assigned</option>
              <option value="unassigned">Unassigned</option>
            </Select>
          </Field>
          <button onClick={refetch} className="px-3 py-2 rounded-lg bg-white text-gray-600 text-sm font-semibold border border-gray-300 hover:bg-gray-50 inline-flex items-center gap-1.5">
            <RefreshCw className={`w-4 h-4 ${loading ? 'animate-spin' : ''}`} /> Refresh
          </button>
        </div>

        {loading ? (
          <Spinner label="Loading pickface configs..." />
        ) : error ? (
          <EmptyState message={error} />
        ) : filteredConfigs.length === 0 ? (
          <EmptyState message={search || statusFilter !== 'all' ? 'No configs match the current filters.' : 'No pickface configs found. Click "Add Config" to create one.'} />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[900px]">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-3 text-left font-bold">SKU Code</th>
                  <th className="px-3 py-3 text-left font-bold">Product Name</th>
                  <th className="px-3 py-3 text-left font-bold">Pickface Bin</th>
                  <th className="px-3 py-3 text-center font-bold">Min Qty</th>
                  <th className="px-3 py-3 text-center font-bold">Max Qty</th>
                  <th className="px-3 py-3 text-center font-bold">Status</th>
                  <th className="px-3 py-3 text-right font-bold">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {filteredConfigs.map((c: PickfaceConfig) => (
                  <tr key={c.id} className="hover:bg-brand-50/50">
                    <td className="px-3 py-3 font-semibold text-brand-700 font-mono">{c.product_code}</td>
                    <td className="px-3 py-3 text-gray-600">{c.product_name}</td>
                    <td className="px-3 py-3 text-gray-600 font-mono">{c.pickface_location_code || '—'}</td>
                    <td className="px-3 py-3 text-center font-semibold">{c.pickface_min}</td>
                    <td className="px-3 py-3 text-center font-semibold">{c.pickface_max || '—'}</td>
                    <td className="px-3 py-3 text-center">
                      <span className={`inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border ${STATUS_STYLES[c.assigned ? 'assigned' : 'unassigned']}`}>
                        {STATUS_LABELS[c.assigned ? 'assigned' : 'unassigned']}
                      </span>
                    </td>
                    <td className="px-3 py-3 text-right">
                      <div className="inline-flex items-center gap-1">
                        {canAdmin && (
                          <>
                            <button onClick={() => { setEditing(c); setFormSkuId(String(c.sku_id)); setFormBinId(c.pickface_bin_id != null ? String(c.pickface_bin_id) : ''); setFormMin(String(c.pickface_min)); setFormMax(String(c.pickface_max)); setModalOpen(true); }} className="p-1.5 rounded hover:bg-brand-100 text-brand-600" title="Edit">
                              <Pencil className="w-4 h-4" />
                            </button>
                            <ConfirmButton
                              label="Delete"
                              confirmText="Unassign this pickface bin?"
                              onConfirm={() => setDeletingId(c.id)}
                              variant="danger"
                            />
                          </>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        <div className="text-xs text-gray-400 mt-3">
          Showing {filteredCount} config{filteredCount !== 1 ? 's' : ''}
        </div>
      </Card>

      <Modal
        open={modalOpen}
        onClose={() => setModalOpen(false)}
        title={editing ? 'Edit Pickface Config' : 'Add Pickface Config'}
        size="md"
      >
        <form onSubmit={handleSave} className="space-y-4">
          <Field label="SKU / Product" required>
            <Select value={formSkuId} onChange={(e) => setFormSkuId(e.target.value)} required>
              <option value="">Select a product...</option>
              {products.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.product_code} — {p.product_name}
                </option>
              ))}
            </Select>
            {productsLoading && <span className="text-xs text-gray-400">Loading products...</span>}
            {!productsLoading && products.length === 0 && (
              <span className="text-xs text-gray-400">No products found</span>
            )}
          </Field>
          <Field label="Pickface Bin" required>
            <Select value={formBinId} onChange={(e) => setFormBinId(e.target.value)} required>
              <option value="">Select a bin...</option>
              {bins.map((b) => (
                <option key={b.id} value={b.id}>
                  {b.location_code}
                </option>
              ))}
            </Select>
            {binsLoading && <span className="text-xs text-gray-400">Loading bins...</span>}
            {!binsLoading && bins.length === 0 && (
              <span className="text-xs text-gray-400">No Level A bins found</span>
            )}
          </Field>
          <div className="grid grid-cols-2 gap-4">
            <Field label="Pickface Min">
              <TextInput type="number" min="0" value={formMin} onChange={(e) => setFormMin(e.target.value)} required />
            </Field>
            <Field label="Pickface Max">
              <TextInput type="number" min="0" value={formMax} onChange={(e) => setFormMax(e.target.value)} />
            </Field>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <button type="button" onClick={() => setModalOpen(false)} className="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">Cancel</button>
            <button type="submit" disabled={createMut.loading || updateMut.loading || !formSkuId || !formBinId} className="px-4 py-2 rounded-lg text-sm font-bold bg-brand-600 text-white hover:bg-brand-700 disabled:opacity-50">
              {(createMut.loading || updateMut.loading) ? 'Saving...' : (editing ? 'Update' : 'Create')}
            </button>
          </div>
        </form>
      </Modal>

      <Modal open={deletingId !== null} onClose={() => setDeletingId(null)} title="Unassign Pickface Bin" size="sm">
        <div className="flex items-center gap-3 py-2">
          <AlertTriangle className="w-6 h-6 text-amber-500 flex-shrink-0" />
          <p className="text-sm text-gray-600">Are you sure you want to unassign this pickface bin? The SKU will fall back to auto-detection.</p>
        </div>
        <div className="flex justify-end gap-2 mt-4">
          <button onClick={() => setDeletingId(null)} className="px-4 py-2 rounded-lg text-sm font-semibold border border-gray-300 text-gray-600 hover:bg-gray-50">Cancel</button>
          <ConfirmButton label="Unassign" confirmText="This will unassign the pickface bin." onConfirm={handleDelete} variant="danger" />
        </div>
      </Modal>
    </div>
  );
}
