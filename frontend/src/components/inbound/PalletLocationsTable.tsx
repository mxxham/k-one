import { Trash2 } from 'lucide-react';
import { TextInput } from '@/components/Field';

export interface PalletRow {
  pallet_seq: number;
  location_code: string;
  quantity: string;
  is_full: boolean;
}

interface PalletLocationsTableProps {
  rows: PalletRow[];
  onUpdate: (index: number, patch: Partial<PalletRow>) => void;
  onRemove: (index: number) => void;
}

export function PalletLocationsTable({ rows, onUpdate, onRemove }: PalletLocationsTableProps) {
  return (
    <div className="space-y-2">
      <div className="grid grid-cols-[1fr_1fr_90px_40px] gap-2 text-[11px] font-bold uppercase tracking-wide text-gray-400 px-1">
        <span>Location Code</span>
        <span>Quantity</span>
        <span>Is Full</span>
        <span></span>
      </div>
      {rows.map((r, i) => (
        <div key={i} className="grid grid-cols-[1fr_1fr_90px_40px] gap-2 items-center">
          <TextInput value={r.location_code} onChange={(e) => onUpdate(i, { location_code: e.target.value })} placeholder="A-01-01" />
          <TextInput type="number" min={0} value={r.quantity} onChange={(e) => onUpdate(i, { quantity: e.target.value })} />
          <input
            type="checkbox"
            checked={r.is_full}
            onChange={(e) => onUpdate(i, { is_full: e.target.checked })}
            className="h-4 w-4 accent-brand-600"
          />
          <button type="button" onClick={() => onRemove(i)} className="text-red-500 hover:text-red-700 p-1">
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      ))}
    </div>
  );
}
