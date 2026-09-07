import { useState, useCallback } from 'react';
import { api, type ApiResult } from '@/lib/api';

/**
 * Standardised mutation hook for create / update / delete operations.
 *
 * Wraps the `api()` function with `loading` and `error` state management
 * so every page does not re-implement the same try/catch/finally pattern.
 *
 * @typeParam TArgs   - Shape of the payload passed to `mutate()`.
 * @typeParam TResult - The resolved type inside `ApiResult<TResult>`.
 * @param module - The API module name (e.g. `'pickface'`, `'inbound'`).
 * @param action - The API action name (e.g. `'create'`, `'update'`, `'delete'`).
 *
 * @example
 * ```tsx
 * const { mutate, loading, error, reset } = useApiMutation<CreatePayload, Item>(
 *   'products',
 *   'create',
 * );
 *
 * const handleSubmit = async (values: CreatePayload) => {
 *   const result = await mutate(values);
 *   // result.success === true
 * };
 * ```
 */
export function useApiMutation<TArgs, TResult = unknown>(
  module: string,
  action: string,
) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const mutate = useCallback(
    async (args: TArgs): Promise<ApiResult<TResult>> => {
      setLoading(true);
      setError(null);
      try {
        const result = await api<TResult>(module, action, { body: args });
        return result;
      } catch (err: unknown) {
        const message =
          err instanceof Error ? err.message : 'Request failed';
        setError(message);
        throw err;
      } finally {
        setLoading(false);
      }
    },
    [module, action],
  );

  /** Clear the current error state without triggering a new request. */
  const reset = useCallback(() => {
    setError(null);
  }, []);

  return { mutate, loading, error, reset } as const;
}
