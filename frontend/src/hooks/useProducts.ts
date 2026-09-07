import { useState, useEffect, useCallback } from 'react';
import { api } from '@/lib/api';

export interface ProductOption {
  id: number;
  product_code: string;
  product_name: string;
  uom_type: string;
}

interface LocationOption {
  id: number;
  location_code: string;
}

export function useProducts() {
  const [products, setProducts] = useState<ProductOption[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function fetchProducts() {
      try {
        const data = await api('products', 'list');
        const rows = data.rows || data.products || [];
        setProducts(rows);
      } catch {
        setProducts([]);
      } finally {
        setLoading(false);
      }
    }
    fetchProducts();
  }, []);

  return { products, loading };
}

export function useLocationBins() {
  const [bins, setBins] = useState<LocationOption[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    async function fetchBins() {
      try {
        const data = await api('locations', 'list');
        const allRows: LocationOption[] = data.rows || data.locations || [];
        // Filter for Level A bins: location codes like CA01A01, CA01A02 (ending in A followed by a digit)
        const aBins = allRows.filter(
          (loc: LocationOption) => /\dA\d/.test(loc.location_code),
        );
        setBins(aBins);
      } catch {
        setBins([]);
      } finally {
        setLoading(false);
      }
    }
    fetchBins();
  }, []);

  return { bins, loading };
}
