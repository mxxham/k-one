import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useApiMutation } from './useApiMutation';

const mockApi = vi.hoisted(() => ({
  api: vi.fn(),
}));

vi.mock('@/lib/api', () => mockApi);

interface CreatePayload {
  name: string;
  value: number;
}

interface CreatedItem {
  id: number;
  name: string;
  value: number;
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe('useApiMutation', () => {
  it('returns initial state: loading false, error null', () => {
    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('products', 'create'),
    );
    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBeNull();
  });

  it('sets loading true during mutation and returns result on success', async () => {
    const response = { success: true, id: 1, name: 'Widget', value: 42 };
    mockApi.api.mockResolvedValue(response);

    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('products', 'create'),
    );

    let resolved: unknown;
    await act(async () => {
      resolved = await result.current.mutate({ name: 'Widget', value: 42 });
    });

    expect(resolved).toEqual(response);
    expect(result.current.loading).toBe(false);
    expect(result.current.error).toBeNull();
    expect(mockApi.api).toHaveBeenCalledWith('products', 'create', {
      body: { name: 'Widget', value: 42 },
    });
  });

  it('sets loading true while the request is in flight', async () => {
    let resolveRequest!: (v: unknown) => void;
    mockApi.api.mockImplementation(
      () => new Promise((resolve) => { resolveRequest = resolve; }),
    );

    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('products', 'create'),
    );

    act(() => {
      result.current.mutate({ name: 'Widget', value: 42 });
    });

    // Mutation is in flight — loading should be true
    expect(result.current.loading).toBe(true);
    expect(result.current.error).toBeNull();

    await act(async () => {
      resolveRequest({ success: true });
    });

    expect(result.current.loading).toBe(false);
  });

  it('sets error on failure and rethrows the error', async () => {
    mockApi.api.mockRejectedValue(new Error('Server error'));

    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('products', 'create'),
    );

    await act(async () => {
      await expect(
        result.current.mutate({ name: 'Widget', value: 42 }),
      ).rejects.toThrow('Server error');
    });

    expect(result.current.error).toBe('Server error');
    expect(result.current.loading).toBe(false);
  });

  it('sets a generic message when the thrown value is not an Error', async () => {
    mockApi.api.mockRejectedValue('string failure');

    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('products', 'create'),
    );

    await act(async () => {
      await expect(
        result.current.mutate({ name: 'Widget', value: 42 }),
      ).rejects.toBe('string failure');
    });

    expect(result.current.error).toBe('Request failed');
  });

  it('clears the error when reset() is called', async () => {
    mockApi.api.mockRejectedValue(new Error('fail'));

    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('products', 'create'),
    );

    await act(async () => {
      await expect(
        result.current.mutate({ name: 'Widget', value: 42 }),
      ).rejects.toThrow();
    });

    expect(result.current.error).toBe('fail');

    act(() => {
      result.current.reset();
    });

    expect(result.current.error).toBeNull();
  });

  it('clears previous error before a new mutation', async () => {
    mockApi.api
      .mockRejectedValueOnce(new Error('first fail'))
      .mockResolvedValueOnce({ success: true });

    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('products', 'create'),
    );

    // First call — fails
    await act(async () => {
      await expect(
        result.current.mutate({ name: 'A', value: 1 }),
      ).rejects.toThrow();
    });
    expect(result.current.error).toBe('first fail');

    // Second call — succeeds, error should be cleared
    await act(async () => {
      await result.current.mutate({ name: 'B', value: 2 });
    });

    expect(result.current.error).toBeNull();
    expect(result.current.loading).toBe(false);
  });

  it('calls api with the correct module and action', async () => {
    mockApi.api.mockResolvedValue({ success: true });

    const { result } = renderHook(() =>
      useApiMutation<CreatePayload, CreatedItem>('inbound', 'receive'),
    );

    await act(async () => {
      await result.current.mutate({ name: 'X', value: 99 });
    });

    expect(mockApi.api).toHaveBeenCalledTimes(1);
    expect(mockApi.api).toHaveBeenCalledWith('inbound', 'receive', {
      body: { name: 'X', value: 99 },
    });
  });
});
