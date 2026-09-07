import { useEffect, useRef, useState } from 'react';
import { Search } from 'lucide-react';
import { api } from '@/lib/api';
import { fmtNum } from '@/lib/format';
import { TextInput } from '@/components/Field';
import { useToast } from '@/components/Toast';

/** Product returned by the search endpoint. */
export interface ProductSearchResult {
  id: number;
  product_code: string;
  product_name: string;
  uom: string;
  uom_per_pallet?: number;
  liters_per_unit?: number;
  stock_qty: number;
}

export interface ProductSearchComboboxProps {
  /** Full API endpoint path, e.g. "inbound/search_products" or "outbound/search_products" */
  endpoint: string;
  /** Currently selected product (shown when non-null) */
  selected: { id: number; code: string; name: string } | null;
  /** Callback when a product is selected from results */
  onSelect: (product: ProductSearchResult) => void;
  /** Callback when the selected product is cleared */
  onClear: () => void;
  /** Placeholder / label text for the search input (default: "Cari produk...") */
  label?: string;
  /** Whether to disable the input */
  disabled?: boolean;
  /** Whether to focus the input on mount */
  autoFocus?: boolean;
}

/**
 * Reusable product search combobox with debounced API search,
 * dropdown results, selected display, and clear button.
 *
 * Used by InboundList, InboundDetail, OutboundList, OutboundDetail, and AsnList.
 */
export default function ProductSearchCombobox({
  endpoint,
  selected,
  onSelect,
  onClear,
  label = 'Cari produk...',
  disabled = false,
  autoFocus,
}: ProductSearchComboboxProps) {
  const toast = useToast();
  const [q, setQ] = useState('');
  const [results, setResults] = useState<ProductSearchResult[]>([]);
  const [open, setOpen] = useState(false);
  const [searching, setSearching] = useState(false);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    if (timer.current) clearTimeout(timer.current);
    if (!q.trim()) {
      setResults([]);
      setOpen(false);
      return;
    }
    timer.current = setTimeout(async () => {
      setSearching(true);
      try {
        const [mod, act] = endpoint.split('/');
        const res = await api(mod, act || 'search_products', { params: { q } });
        setResults(res.results || []);
        setOpen(true);
      } catch (e: any) {
        toast('error', e.message || 'Gagal mencari produk');
      } finally {
        setSearching(false);
      }
    }, 300);
    return () => {
      if (timer.current) clearTimeout(timer.current);
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [q]);

  if (selected) {
    return (
      <div className="flex items-center gap-2">
        <div className="flex-1 px-3 py-2 rounded-lg bg-brand-50 border border-brand-100 text-sm">
          <div className="font-semibold text-brand-900">{selected.code}</div>
          <div className="text-[11px] text-gray-500 truncate">{selected.name}</div>
        </div>
        {!disabled && (
          <button type="button" onClick={onClear} className="px-2 py-1 text-xs font-semibold text-gray-500 hover:text-red-600 flex-shrink-0">
            Clear
          </button>
        )}
      </div>
    );
  }

  return (
    <div className="relative">
      <div className="relative">
        <Search className="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
        <TextInput
          value={q}
          autoFocus={autoFocus}
          disabled={disabled}
          onChange={(e) => setQ(e.target.value)}
          onFocus={() => results.length && setOpen(true)}
          placeholder={label}
          className="pl-9"
        />
        {searching && (
          <span className="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] text-brand-600 font-semibold">Searching...</span>
        )}
      </div>
      {open && (
        <div className="absolute z-30 mt-1 w-full bg-white border border-gray-200 rounded-lg shadow-xl max-h-60 overflow-y-auto">
          {results.length === 0 && !searching && <div className="px-3 py-2 text-xs text-gray-400">No products found</div>}
          {results.map((p) => (
            <button
              key={p.id}
              type="button"
              onClick={() => {
                onSelect(p);
                setOpen(false);
                setQ('');
                setResults([]);
              }}
              className="w-full text-left px-3 py-2 hover:bg-brand-50 flex items-center justify-between gap-2"
            >
              <div className="min-w-0">
                <div className="text-sm font-semibold text-gray-800">{p.product_code}</div>
                <div className="text-[11px] text-gray-500 truncate">{p.product_name}</div>
              </div>
              <div className="text-[11px] text-gray-400 flex-shrink-0">Stock: {fmtNum(p.stock_qty, 0)}</div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
