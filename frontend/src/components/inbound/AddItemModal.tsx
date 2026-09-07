import { useEffect, useState } from 'react';
import { Plus, Trash2, Sparkles } from 'lucide-react';
import { api } from '@/lib/api';
import Modal from '@/components/Modal';
import { Field, TextInput, Select, Grid } from '@/components/Field';
import { useToast } from '@/components/Toast';
import ProductSearchCombobox from '@/components/ProductSearchCombobox';

const ITEM_STATUSES = ['Dues In', 'Goods Received', 'Unserviceable', 'ATP'];

interface AddItemForm {
  product_id: number | null;
  product_code: string;
  product_name: string;
  uom: string;
  batch_number: string;
  od_number: string;
  so_number: string;
  quantity: string;
  manufacture_date: string;
  exp_date: string;
  in_process_status: string;
  cross_dock_outbound_order_id: number | null;
}

interface AddLocRow {
  location_code: string;
  quantity: string;
  is_full: boolean;
}

const EMPTY_FORM: AddItemForm = {
  product_id: null,
  product_code: '',
  product_name: '',
  uom: '',
  batch_number: '',
  od_number: '',
  so_number: '',
  quantity: '',
  manufacture_date: '',
  exp_date: '',
  in_process_status: 'Dues In',
  cross_dock_outbound_order_id: null,
};

function putawayMsg(res: any, fallback: string): string {
  if (res?.message) return res.message;
  const upp = Number(res?.uom_per_pallet ?? 0);
  const n = res?.pallets?.length ?? 0;
  if (!upp || n === 0) return fallback;
  const full = res.pallets.filter((p: any) => p.is_full).length;
  const rem = res.pallets.find((p: any) => !p.is_full)?.quantity;
  const parts: string[] = [];
  if (full > 0) parts.push(`${full} pallet penuh @ ${upp} pcs`);
  if (rem && Number(rem) > 0) parts.push(`sisa ${rem} pcs ke pick-face`);
  return `Saran putaway: ${parts.join(', ')} (${n} lokasi).`;
}

export interface AddItemModalProps {
  open: boolean;
  onClose: () => void;
  inboundId: number;
  crossDockOrders?: any[];
  onSuccess: () => void;
}

export default function AddItemModal({ open, onClose, inboundId, crossDockOrders, onSuccess }: AddItemModalProps) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [addItem, setAddItem] = useState<AddItemForm>(EMPTY_FORM);
  const [addLocations, setAddLocations] = useState<AddLocRow[]>([]);
  const [addSuggesting, setAddSuggesting] = useState(false);
  const [addSuggestMsg, setAddSuggestMsg] = useState('');

  useEffect(() => {
    if (open) {
      setAddItem(EMPTY_FORM);
      setAddLocations([{ location_code: '', quantity: '', is_full: true }]);
      setAddSuggestMsg('');
    }
  }, [open]);

  const saveAddItem = async () => {
    if (!addItem.product_id) {
      toast('error', 'Pilih produk terlebih dahulu');
      return;
    }
    if (!Number(addItem.quantity)) {
      toast('error', 'Quantity wajib diisi');
      return;
    }
    setBusy(true);
    try {
      await api('inbound', 'add_item', {
        body: {
          inbound_id: inboundId,
          item: {
            product_id: addItem.product_id,
            batch_number: addItem.batch_number || undefined,
            od_number: addItem.od_number || undefined,
            so_number: addItem.so_number || undefined,
            quantity: Number(addItem.quantity),
            uom: addItem.uom || undefined,
            actual_qty: Number(addItem.quantity),
            manufacture_date: addItem.manufacture_date || undefined,
            exp_date: addItem.exp_date || undefined,
            in_process_status: addItem.in_process_status,
            cross_dock_outbound_order_id: addItem.cross_dock_outbound_order_id || undefined,
            pallet_locations: addLocations
              .filter((r) => r.location_code.trim() && Number(r.quantity) > 0)
              .map((r, i) => ({
                location_code: r.location_code.trim(),
                pallet_seq: i + 1,
                quantity: Number(r.quantity),
                is_full: r.is_full,
              })),
          },
        },
      });
      toast('success', 'Item berhasil ditambahkan');
      onSuccess();
      onClose();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menyimpan perubahan');
    } finally {
      setBusy(false);
    }
  };

  const updateAddLoc = (i: number, patch: Partial<AddLocRow>) =>
    setAddLocations((arr) => arr.map((r, idx) => (idx === i ? { ...r, ...patch } : r)));
  const removeAddLoc = (i: number) => setAddLocations((arr) => arr.filter((_, idx) => idx !== i));

  const suggestAddLocations = async () => {
    if (!addItem.product_id) {
      toast('error', 'Pilih produk terlebih dahulu');
      return;
    }
    if (!Number(addItem.quantity)) {
      toast('error', 'Quantity wajib diisi');
      return;
    }
    setAddSuggesting(true);
    setAddSuggestMsg('');
    try {
      const params: Record<string, string | number> = { product_id: addItem.product_id, quantity: Number(addItem.quantity) };
      if (addItem.uom) params.uom = addItem.uom;
      const res: any = await api('putaway', 'recommend', { params });
      if (res?.pallets?.length) {
        setAddLocations(
          res.pallets.map((p: any) => ({
            location_code: p.location_code || '',
            quantity: String(p.quantity ?? ''),
            is_full: p.is_full !== false,
          })),
        );
      }
      setAddSuggestMsg(putawayMsg(res, `Saran lokasi putaway: ${res?.pallets?.length ?? 0} pallet.`));
    } catch (e: any) {
      setAddSuggestMsg(e.message || 'Gagal mendapat saran lokasi putaway.');
    } finally {
      setAddSuggesting(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Add Item" size="xl">
      <div className="space-y-4">
        <Field label="Product" required>
          <ProductSearchCombobox
            endpoint="inbound/search_products"
            selected={addItem.product_id ? { id: addItem.product_id, code: addItem.product_code, name: addItem.product_name } : null}
            onSelect={(p) =>
              setAddItem((f) => ({ ...f, product_id: p.id, product_code: p.product_code, product_name: p.product_name, uom: p.uom || '' }))
            }
            onClear={() => setAddItem((f) => ({ ...f, product_id: null, product_code: '', product_name: '', uom: '' }))}
            autoFocus
          />
        </Field>
        <Grid cols={3}>
          <Field label="Batch Number">
            <TextInput value={addItem.batch_number} onChange={(e) => setAddItem((f) => ({ ...f, batch_number: e.target.value }))} />
          </Field>
          <Field label="OD Number">
            <TextInput value={addItem.od_number} onChange={(e) => setAddItem((f) => ({ ...f, od_number: e.target.value }))} />
          </Field>
          <Field label="SO Number">
            <TextInput value={addItem.so_number} onChange={(e) => setAddItem((f) => ({ ...f, so_number: e.target.value }))} />
          </Field>
        </Grid>
        <Grid cols={3}>
          <Field label="Quantity" required>
            <TextInput type="number" min={0} value={addItem.quantity} onChange={(e) => setAddItem((f) => ({ ...f, quantity: e.target.value }))} />
          </Field>
          <Field label="UOM">
            <TextInput value={addItem.uom} onChange={(e) => setAddItem((f) => ({ ...f, uom: e.target.value }))} />
          </Field>
          <Field label="In Process Status">
            <Select value={addItem.in_process_status} onChange={(e) => setAddItem((f) => ({ ...f, in_process_status: e.target.value }))}>
              {ITEM_STATUSES.map((s) => (
                <option key={s} value={s}>
                  {s}
                </option>
              ))}
            </Select>
          </Field>
        </Grid>
        <Grid cols={2}>
          <Field label="Manufacture Date">
            <TextInput type="date" value={addItem.manufacture_date} onChange={(e) => setAddItem((f) => ({ ...f, manufacture_date: e.target.value }))} />
          </Field>
          <Field label="Expiry Date">
            <TextInput type="date" value={addItem.exp_date} onChange={(e) => setAddItem((f) => ({ ...f, exp_date: e.target.value }))} />
          </Field>
        </Grid>

        <Field
          label="Cross-dock ke Outbound Order"
          hint={addItem.cross_dock_outbound_order_id ? 'Item ini TIDAK melalui putaway normal — langsung distage di STAGING untuk outbound tsb.' : 'Opsional. Item akan dilewati dari FEFO/putaway biasa.'}
        >
          <Select
            value={addItem.cross_dock_outbound_order_id ? String(addItem.cross_dock_outbound_order_id) : ''}
            onChange={(e) => setAddItem((f) => ({ ...f, cross_dock_outbound_order_id: e.target.value ? Number(e.target.value) : null }))}
          >
            <option value="">Tidak cross-dock (putaway normal)</option>
            {(crossDockOrders || []).map((o: any) => (
              <option key={o.id} value={o.id}>
                {o.order_number} · {o.display_no || o.so_number || o.do_number} · {o.customer_name || ''}
              </option>
            ))}
          </Select>
        </Field>

        <div>
          <h4 className="text-sm font-bold text-gray-700 mb-2">Pallet Locations</h4>
          <div className="space-y-2">
            {addLocations.map((r, i) => (
              <div key={i} className="grid grid-cols-[1fr_1fr_90px_40px] gap-2 items-center">
                <TextInput value={r.location_code} onChange={(e) => updateAddLoc(i, { location_code: e.target.value })} placeholder="A-01-01" />
                <TextInput type="number" min={0} value={r.quantity} onChange={(e) => updateAddLoc(i, { quantity: e.target.value })} />
                <input
                  type="checkbox"
                  checked={r.is_full}
                  onChange={(e) => updateAddLoc(i, { is_full: e.target.checked })}
                  className="h-4 w-4 accent-brand-600"
                />
                <button type="button" onClick={() => removeAddLoc(i)} className="text-red-500 hover:text-red-700 p-1">
                  <Trash2 className="w-4 h-4" />
                </button>
              </div>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <button
              type="button"
              onClick={() => setAddLocations((arr) => [...arr, { location_code: '', quantity: '', is_full: true }])}
              className="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-brand-50 text-brand-700 border border-brand-200 hover:bg-brand-100"
            >
              <Plus className="w-3.5 h-3.5" /> Add Row
            </button>
            <button
              type="button"
              onClick={suggestAddLocations}
              disabled={addSuggesting}
              className="mt-2 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg bg-violet-50 text-violet-700 border border-violet-200 hover:bg-violet-100 disabled:opacity-50"
            >
              <Sparkles className="w-3.5 h-3.5" /> {addSuggesting ? 'Mencari...' : 'Saran Lokasi (Putaway)'}
            </button>
          </div>
          {addSuggestMsg && <div className="text-xs text-gray-500">{addSuggestMsg}</div>}
        </div>

        <div className="flex justify-end gap-2 border-t border-gray-100 pt-4">
          <button onClick={onClose} className="px-4 py-2 text-sm rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 font-semibold">
            Batal
          </button>
          <button
            onClick={saveAddItem}
            disabled={busy}
            className="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white hover:bg-brand-700 font-semibold disabled:opacity-60"
          >
            {busy ? 'Menyimpan...' : 'Simpan Item'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
