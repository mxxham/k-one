import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import Modal from '@/components/Modal';
import { Field, TextInput } from '@/components/Field';
import { useToast } from '@/components/Toast';
import type { ItemDetail } from '@/pages/InboundDetail';

export type EditMode = 'quantity' | 'dates' | 'pallet_no' | 'location';

const TITLES: Record<EditMode, string> = {
  quantity: 'Edit Quantity',
  dates: 'Edit Dates',
  pallet_no: 'Update Pallet No',
  location: 'Assign Location',
};

export interface EditItemModalProps {
  open: boolean;
  onClose: () => void;
  item: ItemDetail | null;
  mode: EditMode;
  inboundId: number;
  locations?: string[];
  onSuccess: () => void;
}

export default function EditItemModal({ open, onClose, item, mode, inboundId, locations, onSuccess }: EditItemModalProps) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [qtyValue, setQtyValue] = useState('');
  const [mfgValue, setMfgValue] = useState('');
  const [expValue, setExpValue] = useState('');
  const [palletNoValue, setPalletNoValue] = useState('');
  const [locValue, setLocValue] = useState('');

  useEffect(() => {
    if (open && item) {
      setQtyValue(String(item.quantity ?? ''));
      setMfgValue(item.manufacture_date || '');
      setExpValue(item.exp_date || '');
      setPalletNoValue(item.pallet_no || '');
      setLocValue(item.location || '');
    }
  }, [open, item]);

  if (!item) return null;

  const saveQty = async () => {
    setBusy(true);
    try {
      await api('inbound', 'update_item_qty', { body: { item_id: item.id, quantity: Number(qtyValue), inbound_id: inboundId } });
      toast('success', 'Quantity item diperbarui');
      onSuccess();
      onClose();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menyimpan perubahan');
    } finally {
      setBusy(false);
    }
  };

  const saveDates = async () => {
    setBusy(true);
    try {
      await api('inbound', 'update_item_dates', {
        body: { item_id: item.id, manufacture_date: mfgValue || undefined, exp_date: expValue || undefined },
      });
      toast('success', 'Tanggal item diperbarui');
      onSuccess();
      onClose();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menyimpan perubahan');
    } finally {
      setBusy(false);
    }
  };

  const savePalletNo = async () => {
    setBusy(true);
    try {
      await api('inbound', 'update_item_pallet_no', { body: { item_id: item.id, pallet_no: palletNoValue || undefined } });
      toast('success', 'Pallet no diperbarui');
      onSuccess();
      onClose();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menyimpan perubahan');
    } finally {
      setBusy(false);
    }
  };

  const saveLoc = async () => {
    setBusy(true);
    try {
      await api('inbound', 'save_item_location', { body: { item_id: item.id, inbound_id: inboundId, location: locValue } });
      toast('success', 'Lokasi item disimpan');
      onSuccess();
      onClose();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menyimpan perubahan');
    } finally {
      setBusy(false);
    }
  };

  const saveHandlers: Record<EditMode, () => Promise<void>> = {
    quantity: saveQty,
    dates: saveDates,
    pallet_no: savePalletNo,
    location: saveLoc,
  };

  return (
    <Modal open={open} onClose={onClose} title={TITLES[mode]} size="sm">
      <div className="space-y-4">
        <div className="text-sm text-gray-500">
          <span className="font-semibold text-gray-700">{item.product_code}</span> — {item.product_name}
        </div>

        {mode === 'quantity' && (
          <Field label="Quantity" required>
            <TextInput type="number" min={0} value={qtyValue} onChange={(e) => setQtyValue(e.target.value)} autoFocus />
          </Field>
        )}

        {mode === 'dates' && (
          <>
            <Field label="Manufacture Date">
              <TextInput type="date" value={mfgValue} onChange={(e) => setMfgValue(e.target.value)} />
            </Field>
            <Field label="Expiry Date">
              <TextInput type="date" value={expValue} onChange={(e) => setExpValue(e.target.value)} />
            </Field>
          </>
        )}

        {mode === 'pallet_no' && (
          <Field label="Pallet No">
            <TextInput value={palletNoValue} onChange={(e) => setPalletNoValue(e.target.value)} autoFocus />
          </Field>
        )}

        {mode === 'location' && (
          <Field label="Location" required>
            <TextInput list="edit-item-locations" value={locValue} onChange={(e) => setLocValue(e.target.value)} placeholder="A-01-01" autoFocus />
            <datalist id="edit-item-locations">
              {(locations || []).map((l) => (
                <option key={l} value={l} />
              ))}
            </datalist>
          </Field>
        )}

        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="px-4 py-2 text-sm rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 font-semibold">
            Batal
          </button>
          <button
            onClick={saveHandlers[mode]}
            disabled={busy}
            className="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white hover:bg-brand-700 font-semibold disabled:opacity-60"
          >
            {busy ? 'Menyimpan...' : 'Simpan'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
