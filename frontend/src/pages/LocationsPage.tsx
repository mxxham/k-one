import { FormEvent, useCallback, useEffect, useState } from 'react';
import { Plus, RefreshCw, Pencil, MapPin, Search, Map, Boxes, List, Printer, SlidersHorizontal, Package } from 'lucide-react';
import { api } from '@/lib/api';
import { fmtNum } from '@/lib/format';
import { useToast } from '@/components/Toast';
import { useAuth } from '@/context/AuthContext';
import { PageHeader } from '@/components/PageHeader';
import { Card, EmptyState } from '@/components/Card';
import Spinner from '@/components/Spinner';
import Modal from '@/components/Modal';
import Pagination from '@/components/Pagination';
import ConfirmButton from '@/components/ConfirmButton';
import { Field, TextInput, Select, Grid } from '@/components/Field';
import RackViews from '@/components/RackViews';
import LocationLabels, { LocationLabelRow } from '@/components/LocationLabels';

const PER_PAGE = 25;

interface LocationRow {
  id: number;
  location_code: string;
  aisle: string | null;
  rack: string | null;
  row_name: string | null;
  position: string | null;
  zone: string | null;
  is_active: number;
  availability: string;
  occupied_pallets: number;
  current_qty: number;
  current_batch: string | null;
}

interface ZoneSummary {
  zone: string | null;
  total: number;
  active: number;
}

const ZONE_OPTIONS = ['Bulk', 'Carton', 'Pallet', 'Rack', 'Pail', 'Special', 'Quarantine', 'General'];
const LEVEL_OPTIONS = ['A', 'B', 'C', 'D', 'E'];
const UOM_OPTIONS = ['Drum', 'Carton', 'Pail', 'EA', 'Bags', 'CAR', 'Fluidbag', 'IBC'];
const ZONE_CODE_OPTIONS = ['BULK', 'RESERVE', 'PICKFACE', 'QUARANTINE', 'STAGING'];

interface UomLimitRow {
  uom_type: string;
  min_level: string;
  max_level: string;
  allow_pick_face: number;
  max_weight_kg: number | null;
  max_height_cm: number | null;
  requires_equipment: number;
  product_count: number;
}

interface ProductRuleRow {
  id: number;
  product_code: string;
  product_name: string;
  uom_type: string;
  drums_per_pallet: number | null;
  uom_per_pallet: number | null;
  preferred_zone_code: string | null;
  rule_max_level: string | null;
  allow_pick_face: number | null;
  full_pallet_to_pick: number | null;
  consolidate: number | null;
  uom_max_level: string | null;
  uom_min_level: string | null;
}

const emptyForm = {
  location_code: '',
  aisle: '',
  rack: '',
  row_name: '',
  position: '',
  zone: 'Bulk',
};

export default function LocationsPage() {
  const toast = useToast();
  const { canWrite, canAdmin } = useAuth();

  const [rows, setRows] = useState<LocationRow[]>([]);
  const [zones, setZones] = useState<ZoneSummary[]>([]);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [zoneFilter, setZoneFilter] = useState('');
  const [availableOnly, setAvailableOnly] = useState(false);
  const [loading, setLoading] = useState(true);

  const [modalOpen, setModalOpen] = useState(false);
  const [editing, setEditing] = useState<LocationRow | null>(null);
  const [form, setForm] = useState(emptyForm);
  const [saving, setSaving] = useState(false);

  const [labelModalOpen, setLabelModalOpen] = useState(false);
  const [binLabels, setBinLabels] = useState<LocationLabelRow[]>([]);
  const [labelsBusy, setLabelsBusy] = useState(false);

  const [tab, setTab] = useState<'list' | 'rackmap' | 'rack3d' | 'uom' | 'prules'>('list');

  const TABS = [
    { key: 'list' as const, label: 'Locations List', icon: List },
    { key: 'rackmap' as const, label: 'Rack Map (2D)', icon: Map },
    { key: 'rack3d' as const, label: 'Rack View (3D)', icon: Boxes },
    { key: 'uom' as const, label: 'UOM Limits', icon: SlidersHorizontal },
    { key: 'prules' as const, label: 'Product Rules', icon: Package },
  ];

  const [uomRows, setUomRows] = useState<UomLimitRow[]>([]);
  const [uomLoading, setUomLoading] = useState(false);
  const [uomEditModal, setUomEditModal] = useState(false);
  const [uomEditRow, setUomEditRow] = useState<UomLimitRow | null>(null);
  const [uomForm, setUomForm] = useState({ min_level: 'A', max_level: 'E', allow_pick_face: 1, max_weight_kg: '', max_height_cm: '', requires_equipment: 0 });
  const [uomSaving, setUomSaving] = useState(false);

  const [prRows, setPrRows] = useState<ProductRuleRow[]>([]);
  const [prTotal, setPrTotal] = useState(0);
  const [prPage, setPrPage] = useState(1);
  const [prUomFilter, setPrUomFilter] = useState('');
  const [prSearch, setPrSearch] = useState('');
  const [prLoading, setPrLoading] = useState(false);
  const [prEditModal, setPrEditModal] = useState(false);
  const [prEditRow, setPrEditRow] = useState<ProductRuleRow | null>(null);
  const [prForm, setPrForm] = useState({ preferred_zone_code: 'RESERVE', max_level: 'E', allow_pick_face: 1, full_pallet_to_pick: 0, consolidate: 1 });
  const [prSaving, setPrSaving] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const res = await api('locations', 'list', {
        params: { zone: zoneFilter || undefined, available_only: availableOnly ? '1' : undefined, page, per_page: PER_PAGE },
      });
      setRows(res.rows || []);
      setZones(res.zones || []);
      setTotal(Number(res.total) || 0);
    } catch (err: any) {
      toast('error', err.message || 'Gagal memuat data lokasi');
    } finally {
      setLoading(false);
    }
  }, [zoneFilter, availableOnly, page, toast]);

  useEffect(() => { load(); }, [load]);

  const loadUom = useCallback(async () => {
    setUomLoading(true);
    try {
      const res = await api('locations', 'uom_limits_list');
      setUomRows(res.rows || []);
    } catch (err: any) {
      toast('error', err.message || 'Gagal memuat data UOM limits');
    } finally {
      setUomLoading(false);
    }
  }, [toast]);

  const loadPr = useCallback(async () => {
    setPrLoading(true);
    try {
      const res = await api('locations', 'product_rules_list', {
        params: { uom_type: prUomFilter || undefined, search: prSearch || undefined, page: prPage, per_page: PER_PAGE },
      });
      setPrRows(res.rows || []);
      setPrTotal(Number(res.total) || 0);
    } catch (err: any) {
      toast('error', err.message || 'Gagal memuat data product rules');
    } finally {
      setPrLoading(false);
    }
  }, [prUomFilter, prSearch, prPage, toast]);

  useEffect(() => { if (tab === 'uom') loadUom(); }, [tab, loadUom]);
  useEffect(() => { if (tab === 'prules') loadPr(); }, [tab, loadPr]);

  const openCreate = () => { setEditing(null); setForm(emptyForm); setModalOpen(true); };

  const openEdit = (l: LocationRow) => {
    setEditing(l);
    setForm({ location_code: l.location_code || '', aisle: l.aisle || '', rack: l.rack || '', row_name: l.row_name || '', position: l.position || '', zone: l.zone || 'Bulk' });
    setModalOpen(true);
  };

  const handleSave = async (e: FormEvent) => {
    e.preventDefault();
    if (!form.location_code.trim()) { toast('error', 'Kode lokasi wajib diisi'); return; }
    setSaving(true);
    try {
      const payload = { location_code: form.location_code.trim().toUpperCase(), aisle: form.aisle.trim().toUpperCase() || undefined, rack: form.rack.trim() || undefined, row_name: form.row_name.trim().toUpperCase() || undefined, position: form.position.trim() || undefined, zone: form.zone || undefined };
      if (editing) { await api('locations', 'update', { body: { id: editing.id, ...payload } }); toast('success', 'Lokasi berhasil diperbarui'); }
      else { await api('locations', 'create', { body: payload }); toast('success', 'Lokasi berhasil ditambahkan'); }
      setModalOpen(false); load();
    } catch (err: any) { toast('error', err.message || 'Gagal menyimpan lokasi'); } finally { setSaving(false); }
  };

  const toggleActive = async (l: LocationRow) => {
    try {
      await api('locations', 'update', { body: { id: l.id, location_code: l.location_code, aisle: l.aisle || undefined, rack: l.rack || undefined, row_name: l.row_name || undefined, position: l.position || undefined, zone: l.zone || undefined, is_active: Number(l.is_active) === 1 ? 0 : 1 } });
      toast('success', Number(l.is_active) === 1 ? 'Lokasi dinonaktifkan' : 'Lokasi diaktifkan'); load();
    } catch (err: any) { toast('error', err.message || 'Gagal mengubah status lokasi'); }
  };

  const handleDelete = async (l: LocationRow) => {
    try { await api('locations', 'delete', { body: { id: l.id } }); toast('success', 'Lokasi dihapus'); load(); }
    catch (err: any) { toast('error', err.message || 'Gagal menghapus lokasi'); }
  };

  const printBinLabels = async () => {
    try { setLabelsBusy(true); const res: any = await api('locations', 'print_labels', { params: { zone: zoneFilter || undefined } }); setBinLabels(res.rows ?? []); setLabelModalOpen(true); }
    catch (err: any) { toast('error', err.message || 'Gagal memuat label lokasi'); } finally { setLabelsBusy(false); }
  };

  const openUomEdit = (row: UomLimitRow) => {
    setUomEditRow(row);
    setUomForm({ min_level: row.min_level || 'A', max_level: row.max_level || 'E', allow_pick_face: row.allow_pick_face ?? 1, max_weight_kg: row.max_weight_kg != null ? String(row.max_weight_kg) : '', max_height_cm: row.max_height_cm != null ? String(row.max_height_cm) : '', requires_equipment: row.requires_equipment ?? 0 });
    setUomEditModal(true);
  };

  const saveUom = async (e: FormEvent) => {
    e.preventDefault(); if (!uomEditRow) return; setUomSaving(true);
    try {
      await api('locations', 'uom_limits_update', { body: { uom_type: uomEditRow.uom_type, min_level: uomForm.min_level, max_level: uomForm.max_level, allow_pick_face: uomForm.allow_pick_face, max_weight_kg: uomForm.max_weight_kg ? Number(uomForm.max_weight_kg) : null, max_height_cm: uomForm.max_height_cm ? Number(uomForm.max_height_cm) : null, requires_equipment: uomForm.requires_equipment } });
      toast('success', `UOM limits ${uomEditRow.uom_type} berhasil diperbarui`); setUomEditModal(false); loadUom();
    } catch (err: any) { toast('error', err.message || 'Gagal menyimpan UOM limits'); } finally { setUomSaving(false); }
  };

  const openPrEdit = (row: ProductRuleRow) => {
    setPrEditRow(row);
    setPrForm({ preferred_zone_code: row.preferred_zone_code || 'RESERVE', max_level: row.rule_max_level || row.uom_max_level || 'E', allow_pick_face: row.allow_pick_face ?? 1, full_pallet_to_pick: row.full_pallet_to_pick ?? 0, consolidate: row.consolidate ?? 1 });
    setPrEditModal(true);
  };

  const savePr = async (e: FormEvent) => {
    e.preventDefault(); if (!prEditRow) return; setPrSaving(true);
    try {
      await api('locations', 'product_rules_update', { body: { product_id: prEditRow.id, preferred_zone_code: prForm.preferred_zone_code, max_level: prForm.max_level, allow_pick_face: prForm.allow_pick_face, full_pallet_to_pick: prForm.full_pallet_to_pick, consolidate: prForm.consolidate } });
      toast('success', `Rule ${prEditRow.product_code} berhasil diperbarui`); setPrEditModal(false); loadPr();
    } catch (err: any) { toast('error', err.message || 'Gagal menyimpan product rule'); } finally { setPrSaving(false); }
  };

  const set = (k: keyof typeof emptyForm) => (e: any) => setForm((f) => ({ ...f, [k]: e.target.value }));

  const totalPages = Math.max(1, Math.ceil(total / PER_PAGE));
  const prTotalPages = Math.max(1, Math.ceil(prTotal / PER_PAGE));

  const zoneStats = zones.map((z) => ({ label: z.zone || '---', total: Number(z.total) || 0, active: Number(z.active) || 0 }));

  return (
    <div>
      <PageHeader title="Locations" subtitle="Master data lokasi penyimpanan" actions={<>
        {canWrite && tab === 'list' && (<button onClick={openCreate} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-white/15 hover:bg-white/25 text-white text-sm font-semibold border border-white/20"><Plus className="w-4 h-4" /> New Location</button>)}
        <button onClick={() => { setPage(1); load(); }} className="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg bg-white/10 hover:bg-white/20 text-white text-sm font-semibold border border-white/20"><RefreshCw className="w-4 h-4" /> Refresh</button>
      </>} />

      <div className="flex gap-2 mb-5 flex-wrap">
        {TABS.map((t) => (
          <button key={t.key} onClick={() => setTab(t.key)} className={`inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-semibold border transition ${tab === t.key ? 'bg-brand-600 text-white border-brand-600 shadow-sm' : 'bg-white text-gray-600 border-gray-200 hover:bg-brand-50'}`}>
            <t.icon className="w-4 h-4" />{t.label}
          </button>
        ))}
      </div>

      {(tab === 'rackmap' || tab === 'rack3d') && <RackViews tab={tab} />}
      {tab === 'list' && (
      <>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4 mb-5">
        {zoneStats.map((z) => (
          <div key={z.label} className="bg-white rounded-xl border border-gray-200 shadow-sm p-4">
            <div className="text-[11px] font-bold uppercase tracking-wider text-gray-400 mb-1">{z.label}</div>
            <div className="flex items-end justify-between">
              <div className="text-2xl font-extrabold text-brand-700">{fmtNum(z.total, 0)}</div>
              <span className="text-[11px] text-emerald-600 font-semibold">{fmtNum(z.active, 0)} aktif</span>
            </div>
          </div>
        ))}
      </div>

      <Card>
        <div className="mb-4 flex items-end gap-3 flex-wrap">
          <div className="w-56">
            <Field label="Filter Zone">
              <Select value={zoneFilter} onChange={(e) => { setPage(1); setZoneFilter(e.target.value); }}>
                <option value="">Semua Zone</option>
                {ZONE_OPTIONS.map((z) => (<option key={z} value={z}>{z}</option>))}
              </Select>
            </Field>
          </div>
          <label className="flex items-center gap-2 pb-2.5 cursor-pointer">
            <input type="checkbox" checked={availableOnly} onChange={(e) => { setPage(1); setAvailableOnly(e.target.checked); }} className="accent-brand-600" />
            <span className="text-sm text-gray-600 font-medium">Hanya lokasi kosong</span>
          </label>
          <button onClick={() => setPage(1)} className="px-4 py-2 rounded-lg bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 inline-flex items-center gap-2">
            <Search className="w-4 h-4" /> Terapkan
          </button>
          <button onClick={printBinLabels} disabled={labelsBusy} className="px-4 py-2 rounded-lg bg-white text-gray-700 text-sm font-semibold hover:bg-gray-100 border border-gray-300 inline-flex items-center gap-2 disabled:opacity-50" title="Cetak label barcode">
            <Printer className="w-4 h-4" /> {labelsBusy ? 'Memuat...' : 'Cetak Label Lokasi'}
          </button>
        </div>

        {loading ? (
          <Spinner label="Memuat lokasi..." />
        ) : rows.length === 0 ? (
          <EmptyState message="Tidak ada data lokasi" />
        ) : (
          <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[880px]">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold">Kode</th>
                  <th className="px-3 py-2.5 text-left font-bold">Aisle</th>
                  <th className="px-3 py-2.5 text-left font-bold">Rack</th>
                  <th className="px-3 py-2.5 text-left font-bold">Row</th>
                  <th className="px-3 py-2.5 text-left font-bold">Pos</th>
                  <th className="px-3 py-2.5 text-left font-bold">Zone</th>
                  <th className="px-3 py-2.5 text-center font-bold">Status</th>
                  <th className="px-3 py-2.5 text-right font-bold">Qty</th>
                  <th className="px-3 py-2.5 text-right font-bold">Pallet</th>
                  <th className="px-3 py-2.5 text-left font-bold">Batch</th>
                  <th className="px-3 py-2.5 text-center font-bold">Aksi</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {rows.map((l) => (
                  <tr key={l.id} className="hover:bg-brand-50/50">
                    <td className="px-3 py-2.5 font-semibold text-brand-700 font-mono">{l.location_code}</td>
                    <td className="px-3 py-2.5 text-gray-600">{l.aisle || '---'}</td>
                    <td className="px-3 py-2.5 text-gray-600">{l.rack || '---'}</td>
                    <td className="px-3 py-2.5 text-gray-600">{l.row_name || '---'}</td>
                    <td className="px-3 py-2.5 text-gray-600">{l.position || '---'}</td>
                    <td className="px-3 py-2.5"><span className="inline-flex items-center px-2 py-0.5 rounded-md bg-brand-50 text-brand-700 text-[11px] font-bold border border-brand-100">{l.zone || '---'}</span></td>
                    <td className="px-3 py-2.5 text-center">
                      {(l.availability || 'Available') === 'Occupied' ? (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border bg-orange-50 text-orange-700 border-orange-300">Occupied</span>
                      ) : (
                        <span className="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold border bg-emerald-50 text-emerald-700 border-emerald-300">Available</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-right font-semibold">{fmtNum(l.current_qty, 0)}</td>
                    <td className="px-3 py-2.5 text-right text-gray-600">{fmtNum(l.occupied_pallets, 0)}</td>
                    <td className="px-3 py-2.5 text-gray-600">{l.current_batch || '---'}</td>
                    <td className="px-3 py-2.5">
                      <div className="flex items-center justify-center gap-1.5">
                        {canWrite && (<button onClick={() => openEdit(l)} title="Edit" className="p-1.5 rounded-lg bg-brand-50 text-brand-600 hover:bg-brand-100 border border-brand-100"><Pencil className="w-3.5 h-3.5" /></button>)}
                        {canWrite && (<button onClick={() => toggleActive(l)} title={Number(l.is_active) === 1 ? 'Nonaktifkan' : 'Aktifkan'} className={`p-1.5 rounded-lg border ${Number(l.is_active) === 1 ? 'bg-emerald-50 text-emerald-600 border-emerald-100 hover:bg-emerald-100' : 'bg-gray-100 text-gray-500 border-gray-200 hover:bg-gray-200'}`}><MapPin className="w-3.5 h-3.5" /></button>)}
                        {canAdmin && (<ConfirmButton label="Hapus" onConfirm={() => handleDelete(l)} />)}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between gap-3 mt-4 border-t border-gray-100 pt-4 flex-wrap">
            <Pagination page={page} totalPages={totalPages} total={total} onChange={setPage} />
          </div>
          </>
        )}
      </Card>
      </>
      )}
      {tab === 'uom' && (
      <Card>
        <div className="mb-4 flex items-center gap-3 flex-wrap">
          <h3 className="text-sm font-bold text-gray-700">Batasan Level per Tipe Container</h3>
          <span className="text-[11px] text-gray-500">Menentukan level rak mana yang bisa ditempati oleh setiap tipe UOM</span>
        </div>
        {uomLoading ? (
          <Spinner label="Memuat data UOM..." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold">Tipe UOM</th>
                  <th className="px-3 py-2.5 text-center font-bold">Min Level</th>
                  <th className="px-3 py-2.5 text-center font-bold">Max Level</th>
                  <th className="px-3 py-2.5 text-center font-bold">Pick Face</th>
                  <th className="px-3 py-2.5 text-right font-bold">Max Berat (kg)</th>
                  <th className="px-3 py-2.5 text-right font-bold">Max Tinggi (cm)</th>
                  <th className="px-3 py-2.5 text-center font-bold">Alat Bantu</th>
                  <th className="px-3 py-2.5 text-center font-bold">Jml Produk</th>
                  {canWrite && <th className="px-3 py-2.5 text-center font-bold">Aksi</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {uomRows.map((r) => (
                  <tr key={r.uom_type} className="hover:bg-brand-50/50">
                    <td className="px-3 py-2.5 font-bold text-brand-700">{r.uom_type}</td>
                    <td className="px-3 py-2.5 text-center"><span className="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[11px] font-bold font-mono">{r.min_level}</span></td>
                    <td className="px-3 py-2.5 text-center"><span className="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[11px] font-bold font-mono">{r.max_level}</span></td>
                    <td className="px-3 py-2.5 text-center">
                      {r.allow_pick_face ? (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold border border-emerald-200">Ya</span>) : (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[11px] font-bold border border-gray-200">Tidak</span>)}
                    </td>
                    <td className="px-3 py-2.5 text-right text-gray-600">{r.max_weight_kg != null ? String(r.max_weight_kg) : '---'}</td>
                    <td className="px-3 py-2.5 text-right text-gray-600">{r.max_height_cm != null ? String(r.max_height_cm) : '---'}</td>
                    <td className="px-3 py-2.5 text-center">
                      {r.requires_equipment ? (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-orange-50 text-orange-700 text-[11px] font-bold border border-orange-200">Ya</span>) : (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[11px] font-bold border border-gray-200">Tidak</span>)}
                    </td>
                    <td className="px-3 py-2.5 text-center font-semibold">{r.product_count}</td>
                    {canWrite && (<td className="px-3 py-2.5 text-center"><button onClick={() => openUomEdit(r)} className="p-1.5 rounded-lg bg-brand-50 text-brand-600 hover:bg-brand-100 border border-brand-100" title="Edit"><Pencil className="w-3.5 h-3.5" /></button></td>)}
                  </tr>
                ))}
                {uomRows.length === 0 && (<tr><td colSpan={canWrite ? 9 : 8} className="px-3 py-8 text-center text-gray-400 text-sm">Tidak ada data UOM limits</td></tr>)}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      )}
      {tab === 'prules' && (
      <Card>
        <div className="mb-4 flex items-end gap-3 flex-wrap">
          <div className="w-48">
            <Field label="Filter UOM Type">
              <Select value={prUomFilter} onChange={(e) => { setPrPage(1); setPrUomFilter(e.target.value); }}>
                <option value="">Semua UOM</option>
                {UOM_OPTIONS.map((u) => <option key={u} value={u}>{u}</option>)}
              </Select>
            </Field>
          </div>
          <div className="w-56">
            <Field label="Cari Produk">
              <TextInput value={prSearch} onChange={(e) => { setPrPage(1); setPrSearch(e.target.value); }} placeholder="Kode / nama produk..." />
            </Field>
          </div>
        </div>
        {prLoading ? (
          <Spinner label="Memuat data product rules..." />
        ) : (
          <>
          <div className="overflow-x-auto">
            <table className="w-full text-sm min-w-[1000px]">
              <thead>
                <tr className="bg-brand-50 text-[11px] uppercase tracking-wider text-brand-700">
                  <th className="px-3 py-2.5 text-left font-bold">Kode</th>
                  <th className="px-3 py-2.5 text-left font-bold">Nama Produk</th>
                  <th className="px-3 py-2.5 text-center font-bold">UOM</th>
                  <th className="px-3 py-2.5 text-center font-bold">Level Range</th>
                  <th className="px-3 py-2.5 text-left font-bold">Zone Prefer</th>
                  <th className="px-3 py-2.5 text-center font-bold">Max Level</th>
                  <th className="px-3 py-2.5 text-center font-bold">Pick Face</th>
                  <th className="px-3 py-2.5 text-center font-bold">Full Pallet</th>
                  <th className="px-3 py-2.5 text-center font-bold">Konsolidasi</th>
                  {canWrite && <th className="px-3 py-2.5 text-center font-bold">Aksi</th>}
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {prRows.map((r) => (
                  <tr key={r.id} className="hover:bg-brand-50/50">
                    <td className="px-3 py-2.5 font-semibold text-brand-700 font-mono">{r.product_code}</td>
                    <td className="px-3 py-2.5 text-gray-700 max-w-[200px] truncate">{r.product_name}</td>
                    <td className="px-3 py-2.5 text-center"><span className="inline-flex items-center px-2 py-0.5 rounded-md bg-brand-50 text-brand-700 text-[11px] font-bold border border-brand-100">{r.uom_type}</span></td>
                    <td className="px-3 py-2.5 text-center text-[11px] font-mono text-gray-600">{r.uom_min_level || 'A'} - {r.uom_max_level || 'E'}</td>
                    <td className="px-3 py-2.5"><span className="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[11px] font-bold">{r.preferred_zone_code || '---'}</span></td>
                    <td className="px-3 py-2.5 text-center"><span className="inline-flex items-center px-2 py-0.5 rounded-md bg-gray-100 text-gray-700 text-[11px] font-bold font-mono">{r.rule_max_level || r.uom_max_level || 'E'}</span></td>
                    <td className="px-3 py-2.5 text-center">
                      {(r.allow_pick_face ?? 1) ? (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold border border-emerald-200">Ya</span>) : (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[11px] font-bold border border-gray-200">Tidak</span>)}
                    </td>
                    <td className="px-3 py-2.5 text-center">
                      {(r.full_pallet_to_pick ?? 0) ? (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold border border-emerald-200">Ya</span>) : (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[11px] font-bold border border-gray-200">Tidak</span>)}
                    </td>
                    <td className="px-3 py-2.5 text-center">
                      {(r.consolidate ?? 1) ? (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 text-[11px] font-bold border border-emerald-200">Ya</span>) : (<span className="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 text-[11px] font-bold border border-gray-200">Tidak</span>)}
                    </td>
                    {canWrite && (<td className="px-3 py-2.5 text-center"><button onClick={() => openPrEdit(r)} className="p-1.5 rounded-lg bg-brand-50 text-brand-600 hover:bg-brand-100 border border-brand-100" title="Edit"><Pencil className="w-3.5 h-3.5" /></button></td>)}
                  </tr>
                ))}
                {prRows.length === 0 && (<tr><td colSpan={canWrite ? 10 : 9} className="px-3 py-8 text-center text-gray-400 text-sm">Tidak ada data product rules</td></tr>)}
              </tbody>
            </table>
          </div>
          <div className="flex items-center justify-between gap-3 mt-4 border-t border-gray-100 pt-4 flex-wrap">
            <Pagination page={prPage} totalPages={prTotalPages} total={prTotal} onChange={setPrPage} />
          </div>
          </>
        )}
      </Card>
      )}
      <Modal open={modalOpen} onClose={() => setModalOpen(false)} title={editing ? 'Edit Location' : 'New Location'} size="md">
        <form onSubmit={handleSave} className="space-y-4">
          <Field label="Kode Lokasi" required>
            <TextInput value={form.location_code} onChange={set('location_code')} placeholder="Contoh: A-01-01-01" />
          </Field>
          <Grid cols={2}>
            <Field label="Aisle"><TextInput value={form.aisle} onChange={set('aisle')} placeholder="Aisle" /></Field>
            <Field label="Rack"><TextInput value={form.rack} onChange={set('rack')} placeholder="Rack" /></Field>
          </Grid>
          <Grid cols={2}>
            <Field label="Row Name"><TextInput value={form.row_name} onChange={set('row_name')} placeholder="Row" /></Field>
            <Field label="Position"><TextInput value={form.position} onChange={set('position')} placeholder="Posisi" /></Field>
          </Grid>
          <Field label="Zone">
            <Select value={form.zone} onChange={set('zone')}>
              {ZONE_OPTIONS.map((z) => (<option key={z} value={z}>{z}</option>))}
            </Select>
          </Field>
          <div className="flex items-center justify-end gap-2 pt-2">
            <button type="button" onClick={() => setModalOpen(false)} className="px-4 py-2 rounded-lg bg-gray-100 text-gray-600 text-sm font-semibold hover:bg-gray-200">Batal</button>
            <button type="submit" disabled={saving} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold disabled:opacity-60">
              <MapPin className="w-4 h-4" /> {saving ? 'Menyimpan...' : editing ? 'Simpan Perubahan' : 'Tambah Lokasi'}
            </button>
          </div>
        </form>
      </Modal>

      <Modal open={labelModalOpen} onClose={() => setLabelModalOpen(false)} title="Cetak Label Lokasi (rack walk)" size="lg">
        <LocationLabels labels={binLabels} onClose={() => setLabelModalOpen(false)} />
      </Modal>

      <Modal open={uomEditModal} onClose={() => setUomEditModal(false)} title={`Edit UOM Limits - ${uomEditRow?.uom_type || ''}`} size="md">
        <form onSubmit={saveUom} className="space-y-4">
          <Grid cols={2}>
            <Field label="Min Level">
              <Select value={uomForm.min_level} onChange={(e) => setUomForm((f) => ({ ...f, min_level: e.target.value }))}>
                {LEVEL_OPTIONS.map((l) => <option key={l} value={l}>{l} - {l === 'A' ? 'Bottom' : l === 'B' ? 'Lower' : l === 'C' ? 'Middle' : l === 'D' ? 'Upper' : 'Top'}</option>)}
              </Select>
            </Field>
            <Field label="Max Level">
              <Select value={uomForm.max_level} onChange={(e) => setUomForm((f) => ({ ...f, max_level: e.target.value }))}>
                {LEVEL_OPTIONS.map((l) => <option key={l} value={l}>{l} - {l === 'A' ? 'Bottom' : l === 'B' ? 'Lower' : l === 'C' ? 'Middle' : l === 'D' ? 'Upper' : 'Top'}</option>)}
              </Select>
            </Field>
          </Grid>
          <Grid cols={2}>
            <Field label="Max Berat (kg)">
              <TextInput value={uomForm.max_weight_kg} onChange={(e) => setUomForm((f) => ({ ...f, max_weight_kg: e.target.value }))} placeholder="Kosongkan jika tidak terbatas" />
            </Field>
            <Field label="Max Tinggi (cm)">
              <TextInput value={uomForm.max_height_cm} onChange={(e) => setUomForm((f) => ({ ...f, max_height_cm: e.target.value }))} placeholder="Kosongkan jika tidak terbatas" />
            </Field>
          </Grid>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={uomForm.allow_pick_face === 1} onChange={(e) => setUomForm((f) => ({ ...f, allow_pick_face: e.target.checked ? 1 : 0 }))} className="accent-brand-600" />
            <span className="text-sm font-medium text-gray-700">Izinkan Pick Face</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={uomForm.requires_equipment === 1} onChange={(e) => setUomForm((f) => ({ ...f, requires_equipment: e.target.checked ? 1 : 0 }))} className="accent-brand-600" />
            <span className="text-sm font-medium text-gray-700">Membutuhkan Alat Bantu (Forklift)</span>
          </label>
          <div className="flex items-center justify-end gap-2 pt-2">
            <button type="button" onClick={() => setUomEditModal(false)} className="px-4 py-2 rounded-lg bg-gray-100 text-gray-600 text-sm font-semibold hover:bg-gray-200">Batal</button>
            <button type="submit" disabled={uomSaving} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold disabled:opacity-60">
              {uomSaving ? 'Menyimpan...' : 'Simpan'}
            </button>
          </div>
        </form>
      </Modal>

      <Modal open={prEditModal} onClose={() => setPrEditModal(false)} title={`Edit Rule - ${prEditRow?.product_code || ''}`} size="md">
        <form onSubmit={savePr} className="space-y-4">
          <div className="text-sm text-gray-600 mb-2">
            <span className="font-semibold">{prEditRow?.product_name}</span> <span className="text-gray-400">|</span> UOM: <span className="font-mono font-bold text-brand-700">{prEditRow?.uom_type}</span>
          </div>
          <Grid cols={2}>
            <Field label="Max Level">
              <Select value={prForm.max_level} onChange={(e) => setPrForm((f) => ({ ...f, max_level: e.target.value }))}>
                {LEVEL_OPTIONS.map((l) => <option key={l} value={l}>{l} - {l === 'A' ? 'Bottom' : l === 'B' ? 'Lower' : l === 'C' ? 'Middle' : l === 'D' ? 'Upper' : 'Top'}</option>)}
              </Select>
            </Field>
            <Field label="Zone Preferred">
              <Select value={prForm.preferred_zone_code} onChange={(e) => setPrForm((f) => ({ ...f, preferred_zone_code: e.target.value }))}>
                {ZONE_CODE_OPTIONS.map((z) => <option key={z} value={z}>{z}</option>)}
              </Select>
            </Field>
          </Grid>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={prForm.allow_pick_face === 1} onChange={(e) => setPrForm((f) => ({ ...f, allow_pick_face: e.target.checked ? 1 : 0 }))} className="accent-brand-600" />
            <span className="text-sm font-medium text-gray-700">Izinkan Pick Face</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={prForm.full_pallet_to_pick === 1} onChange={(e) => setPrForm((f) => ({ ...f, full_pallet_to_pick: e.target.checked ? 1 : 0 }))} className="accent-brand-600" />
            <span className="text-sm font-medium text-gray-700">Full Pallet langsung ke Pick</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input type="checkbox" checked={prForm.consolidate === 1} onChange={(e) => setPrForm((f) => ({ ...f, consolidate: e.target.checked ? 1 : 0 }))} className="accent-brand-600" />
            <span className="text-sm font-medium text-gray-700">Konsolidasi di Lokasi</span>
          </label>
          <div className="flex items-center justify-end gap-2 pt-2">
            <button type="button" onClick={() => setPrEditModal(false)} className="px-4 py-2 rounded-lg bg-gray-100 text-gray-600 text-sm font-semibold hover:bg-gray-200">Batal</button>
            <button type="submit" disabled={prSaving} className="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-semibold disabled:opacity-60">
              {prSaving ? 'Menyimpan...' : 'Simpan'}
            </button>
          </div>
        </form>
      </Modal>
    </div>
  );
}
