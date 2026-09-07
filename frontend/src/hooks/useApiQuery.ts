import { useState, useEffect, useCallback, useRef } from 'react';
import { api } from '@/lib/api';
import { useToast } from '@/components/Toast';

/**
 * Options for the useApiQuery hook.
 */
export interface UseApiQueryOptions {
  /** When false, the query will not execute. Defaults to true. */
  enabled?: boolean;
  /** When true, show a toast notification on error. Defaults to false. */
  toast?: boolean;
}

/**
 * Return type for the useApiQuery hook.
 */
export interface UseApiQueryResult<T> {
  /** The fetched data, or null if not yet loaded / on error. */
  data: T | null;
  /** True while the request is in flight. */
  loading: boolean;
  /** Error message if the request failed, empty string otherwise. */
  error: string;
  /** Triggers a re-fetch of the data. */
  refetch: () => void;
}

/**
 * Standardized data-fetching hook that replaces the ~15-line
 * useState + useEffect + try/catch pattern repeated across 40+ pages.
 *
 * @param module - The API module name (e.g. 'inbound', 'outbound', 'products')
 * @param action - The API action name (e.g. 'list', 'detail', 'stats')
 * @param params - Optional query parameters passed to the API
 * @param options - Optional configuration (enabled, toast)
 * @returns Object with data, loading, error, and refetch
 *
 * @example
 * ```tsx
 * const { data, loading, error, refetch } = useApiQuery<InboundRow[]>(
 *   'inbound',
 *   'list',
 *   { status: 'open', page: 1 },
 *   { toast: true }
 * );
 * ```
 */
export function useApiQuery<T = unknown>(
  module: string,
  action: string,
  params?: Record<string, unknown>,
  options?: UseApiQueryOptions,
): UseApiQueryResult<T> {
  const [data, setData] = useState<T | null>(null);
  const [loading, setLoading] = useState<boolean>(true);
  const [error, setError] = useState<string>('');
  const [trigger, setTrigger] = useState(0);
  const toast = useToast();
  const abortRef = useRef<AbortController | null>(null);

  const enabled = options?.enabled ?? true;
  const showToast = options?.toast ?? false;

  const fetchData = useCallback(() => {
    setTrigger((n) => n + 1);
  }, []);

  useEffect(() => {
    if (!enabled) {
      setLoading(false);
      return;
    }

    // Cancel any in-flight request
    abortRef.current?.abort();
    const controller = new AbortController();
    abortRef.current = controller;

    let cancelled = false;

    async function run() {
      setLoading(true);
      setError('');
      try {
        const result = await api<T>(module, action, {
          params: params ?? {},
          signal: controller.signal,
        });
        if (!cancelled) {
          setData(result as T);
        }
      } catch (err: unknown) {
        if (cancelled || (err instanceof DOMException && err.name === 'AbortError')) {
          return;
        }
        const message = err instanceof Error ? err.message : 'An unexpected error occurred';
        if (!cancelled) {
          setError(message);
          setData(null);
          if (showToast) {
            toast('error', message);
          }
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    run();

    return () => {
      cancelled = true;
      controller.abort();
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [module, action, JSON.stringify(params), enabled, trigger, showToast]);

  return { data, loading, error, refetch: fetchData };
}
