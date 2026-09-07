import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useApiQuery } from './useApiQuery';

const mockApi = vi.hoisted(() => ({
  api: vi.fn(),
}));

vi.mock('@/lib/api', () => mockApi);

// Mock useToast to avoid needing ToastProvider in tests
const mockToast = vi.fn();
vi.mock('@/components/Toast', () => ({
  useToast: () => mockToast,
}));

interface TestRow {
  id: number;
  name: string;
}

interface TestResult {
  rows: TestRow[];
  success: boolean;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('useApiQuery', () => {
  it('starts with loading true and data null', () => {
    mockApi.api.mockImplementation(() => new Promise(() => {}));

    const { result } = renderHook(() =>
      useApiQuery<TestRow[]>('inbound', 'list'),
    );

    expect(result.current.loading).toBe(true);
    expect(result.current.data).toBeNull();
    expect(result.current.error).toBe('');
  });

  it('resolves data on successful fetch', async () => {
    const rows: TestRow[] = [
      { id: 1, name: 'Item A' },
      { id: 2, name: 'Item B' },
    ];
    mockApi.api.mockResolvedValue({ rows });

    const { result } = renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list'),
    );

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(result.current.data).toEqual({ rows });
    expect(result.current.error).toBe('');
  });

  it('sets error message on failed fetch', async () => {
    mockApi.api.mockRejectedValue(new Error('Server error'));

    const { result } = renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list'),
    );

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(result.current.error).toBe('Server error');
    expect(result.current.data).toBeNull();
  });

  it('sets generic error for non-Error throws', async () => {
    mockApi.api.mockRejectedValue('string failure');

    const { result } = renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list'),
    );

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(result.current.error).toBe('An unexpected error occurred');
  });

  it('does not call api when enabled is false', async () => {
    const { result } = renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list', undefined, { enabled: false }),
    );

    expect(result.current.loading).toBe(false);
    expect(result.current.data).toBeNull();
    expect(mockApi.api).not.toHaveBeenCalled();
  });

  it('calls api with correct module, action, and params', async () => {
    mockApi.api.mockResolvedValue({ rows: [] });

    renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list', { status: 'open', page: 1 }),
    );

    await waitFor(() => {
      expect(mockApi.api).toHaveBeenCalled();
    });

    expect(mockApi.api).toHaveBeenCalledWith('inbound', 'list', {
      params: { status: 'open', page: 1 },
      signal: expect.any(AbortSignal),
    });
  });

  it('calls toast on error when toast option is true', async () => {
    mockApi.api.mockRejectedValue(new Error('Fetch failed'));

    renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list', undefined, { toast: true }),
    );

    await waitFor(() => {
      expect(mockToast).toHaveBeenCalledWith('error', 'Fetch failed');
    });
  });

  it('does not call toast on error when toast option is false', async () => {
    mockApi.api.mockRejectedValue(new Error('Fetch failed'));

    renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list', undefined, { toast: false }),
    );

    await waitFor(() => {
      expect(mockApi.api).toHaveBeenCalled();
    });

    expect(mockToast).not.toHaveBeenCalled();
  });

  it('refetch triggers a re-fetch', async () => {
    mockApi.api.mockResolvedValue({ rows: [{ id: 1, name: 'First' }] });

    const { result } = renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list'),
    );

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(result.current.data).toEqual({ rows: [{ id: 1, name: 'First' }] });

    mockApi.api.mockResolvedValue({ rows: [{ id: 2, name: 'Second' }] });

    act(() => {
      result.current.refetch();
    });

    await waitFor(() => {
      expect(result.current.data).toEqual({ rows: [{ id: 2, name: 'Second' }] });
    });

    expect(mockApi.api).toHaveBeenCalledTimes(2);
  });

  it('clears previous error on successful refetch', async () => {
    mockApi.api
      .mockRejectedValueOnce(new Error('fail'))
      .mockResolvedValueOnce({ rows: [{ id: 1, name: 'ok' }] });

    const { result } = renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list'),
    );

    await waitFor(() => {
      expect(result.current.error).toBe('fail');
    });

    act(() => {
      result.current.refetch();
    });

    await waitFor(() => {
      expect(result.current.error).toBe('');
      expect(result.current.data).toEqual({ rows: [{ id: 1, name: 'ok' }] });
    });
  });

  it('aborts previous request when params change', async () => {
    let resolveFirst!: (v: unknown) => void;
    mockApi.api
      .mockImplementationOnce(() => new Promise((r) => { resolveFirst = r; }))
      .mockResolvedValueOnce({ rows: [{ id: 2, name: 'Second' }] });

    const { result, rerender } = renderHook(
      ({ status }) => useApiQuery<TestResult>('inbound', 'list', { status }),
      { initialProps: { status: 'open' } },
    );

    // Second render with different params triggers a new fetch
    rerender({ status: 'closed' });

    await waitFor(() => {
      expect(result.current.loading).toBe(false);
    });

    expect(mockApi.api).toHaveBeenCalledTimes(2);
    expect(result.current.data).toEqual({ rows: [{ id: 2, name: 'Second' }] });
  });

  it('handles abort cleanly without setting error', async () => {
    const abortError = new DOMException('Aborted', 'AbortError');
    mockApi.api.mockRejectedValue(abortError);

    const { result, unmount } = renderHook(() =>
      useApiQuery<TestResult>('inbound', 'list'),
    );

    // Unmount triggers abort
    unmount();

    // Give microtasks a chance to resolve
    await new Promise((r) => setTimeout(r, 10));

    // Error should not be set from abort
    expect(result.current.error).toBe('');
  });
});
