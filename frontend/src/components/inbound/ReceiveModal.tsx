import { useEffect, useState } from 'react';
import { api } from '@/lib/api';
import Modal from '@/components/Modal';
import { Field, TextInput, Select } from '@/components/Field';
import { useToast } from '@/components/Toast';
import { todayISO } from '@/lib/format';

export interface ReceiveModalProps {
  open: boolean;
  onClose: () => void;
  inboundId: number;
  users?: { id: number; username: string; full_name: string }[];
  onSuccess: () => void;
}

export default function ReceiveModal({ open, onClose, inboundId, users, onSuccess }: ReceiveModalProps) {
  const toast = useToast();
  const [busy, setBusy] = useState(false);
  const [receivedBy, setReceivedBy] = useState('');
  const [receivedDate, setReceivedDate] = useState(todayISO());

  useEffect(() => {
    if (open) {
      setReceivedBy('');
      setReceivedDate(todayISO());
    }
  }, [open]);

  const advanceToReceiving = async () => {
    if (!receivedBy) {
      toast('error', 'Received by wajib diisi');
      return;
    }
    setBusy(true);
    try {
      await api('inbound', 'advance_status', {
        body: {
          id: inboundId,
          status: 'Receiving',
          received_by_id: Number(receivedBy),
          received_date: receivedDate || todayISO(),
        },
      });
      toast('success', 'Status berhasil diubah ke Receiving');
      onSuccess();
      onClose();
    } catch (e: any) {
      toast('error', e.message || 'Gagal menyimpan perubahan');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} title="Advance to Receiving" size="sm">
      <div className="space-y-4">
        <Field label="Received By" required>
          <Select value={receivedBy} onChange={(e) => setReceivedBy(e.target.value)}>
            <option value="">Pilih penerima...</option>
            {(users || []).map((u) => (
              <option key={u.id} value={u.id}>
                {u.full_name || u.username}
              </option>
            ))}
          </Select>
        </Field>
        <Field label="Received Date" required>
          <TextInput type="date" value={receivedDate} onChange={(e) => setReceivedDate(e.target.value)} />
        </Field>
        <div className="flex justify-end gap-2">
          <button onClick={onClose} className="px-4 py-2 text-sm rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200 font-semibold">
            Batal
          </button>
          <button
            onClick={advanceToReceiving}
            disabled={busy}
            className="px-4 py-2 text-sm rounded-lg bg-brand-600 text-white hover:bg-brand-700 font-semibold disabled:opacity-60"
          >
            {busy ? 'Menyimpan...' : 'Advance'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
