import { useState, useEffect, useCallback } from 'react';
import { api } from '@/lib/api';

export interface PickfaceConfig {
  id: number;
  sku_id: number;
  product_code: string;
  product_name: string;
  uom_type: string;
  uom_per_pallet: number;
  pickface_bin_id: number | null;
  pickface_location_code: string | null;
  inbound_pickface_bin_id: number | null;
  inbound_pickface_location_code: string | null;
  pickface_min: number;
  pickface_max: number;
  assigned: boolean;
  created_at: string;
  updated_at: string;
}

export function usePickfaceConfigs(search?: string, statusFilter?: string) {
  const [data, setData] = useState<PickfaceConfig[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const fetchData = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const params: Record<string, any> = {};
      if (search) params.search = search;
      if (statusFilter && statusFilter !== 'all') params.status = statusFilter;
      const result = await api('pickface', 'list', { params });
      setData(result.configs || []);
    } catch (err: any) {
      setError(err.message || 'Failed to load pickface configs');
      setData([]);
    } finally {
      setLoading(false);
    }
  }, [search, statusFilter]);

  useEffect(() => { fetchData(); }, [fetchData]);

  return { data, loading, error, refetch: fetchData };
}

export function useUpdatePickfaceConfig() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mutate = useCallback(async (data: { id: number; pickface_bin_id: number | null; pickface_min: number; pickface_max: number }) => {
    setLoading(true);
    setError(null);
    try {
      const result = await api('pickface', 'update', { body: data });
      return result;
    } catch (err: any) {
      setError(err.message || 'Failed to update');
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return { mutate, loading, error };
}

export function useCreatePickfaceConfig() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mutate = useCallback(async (data: { sku_id: number; pickface_bin_id: number | null; pickface_min: number; pickface_max: number }) => {
    setLoading(true);
    setError(null);
    try {
      const result = await api('pickface', 'create', { body: data });
      return result;
    } catch (err: any) {
      setError(err.message || 'Failed to create');
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return { mutate, loading, error };
}

export function useDeletePickfaceConfig() {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mutate = useCallback(async (id: number) => {
    setLoading(true);
    setError(null);
    try {
      const result = await api('pickface', 'delete', { params: { id } });
      return result;
    } catch (err: any) {
      setError(err.message || 'Failed to delete');
      throw err;
    } finally {
      setLoading(false);
    }
  }, []);

  return { mutate, loading, error };
}
