import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, act, waitFor } from '@testing-library/react';
import ProductSearch from './ProductSearch';
import type { SearchProduct } from './ProductSearch';
import { ToastProvider } from './Toast';

// Mock the api module
vi.mock('@/lib/api', () => ({
  api: vi.fn(),
}));

// Mock the format module
vi.mock('@/lib/format', () => ({
  fmtNum: (n: number) => String(n),
}));

import { api } from '@/lib/api';

function renderWithToast(ui: React.ReactNode) {
  return render(<ToastProvider>{ui}</ToastProvider>);
}

describe('ProductSearch', () => {
  const mockProducts: SearchProduct[] = [
    {
      id: 1,
      product_code: 'PRD-001',
      product_name: 'Product Alpha',
      uom: 'kg',
      uom_per_pallet: 100,
      liters_per_unit: 5.5,
      stock_qty: 500,
    },
    {
      id: 2,
      product_code: 'PRD-002',
      product_name: 'Product Beta',
      uom: 'drums',
      uom_per_pallet: 20,
      stock_qty: 150,
    },
  ];

  beforeEach(() => {
    vi.useFakeTimers();
    vi.mocked(api).mockResolvedValue({ success: true, results: mockProducts });
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it('renders search input when no product is selected', () => {
    renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={null}
        onSelect={vi.fn()}
        onClear={vi.fn()}
      />,
    );
    expect(screen.getByPlaceholderText('Search product...')).toBeInTheDocument();
  });

  it('shows selected product with clear button', () => {
    const onClear = vi.fn();
    renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={{ id: 1, code: 'PRD-001', name: 'Product Alpha' }}
        onSelect={vi.fn()}
        onClear={onClear}
      />,
    );
    expect(screen.getByText('PRD-001')).toBeInTheDocument();
    expect(screen.getByText('Product Alpha')).toBeInTheDocument();
    const clearBtn = screen.getByText('Clear');
    fireEvent.click(clearBtn);
    expect(onClear).toHaveBeenCalledTimes(1);
  });

  it('calls api with correct module when searching', async () => {
    renderWithToast(
      <ProductSearch
        apiModule="outbound"
        selected={null}
        onSelect={vi.fn()}
        onClear={vi.fn()}
      />,
    );
    const input = screen.getByPlaceholderText('Search product...');
    fireEvent.change(input, { target: { value: 'test' } });

    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(api).toHaveBeenCalledWith('outbound', 'search_products', { params: { q: 'test' } });
  });

  it('shows search results after debounce', async () => {
    renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={null}
        onSelect={vi.fn()}
        onClear={vi.fn()}
      />,
    );
    const input = screen.getByPlaceholderText('Search product...');
    fireEvent.change(input, { target: { value: 'prod' } });

    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(screen.getByText('PRD-001')).toBeInTheDocument();
    expect(screen.getByText('PRD-002')).toBeInTheDocument();
    expect(screen.getByText('Product Alpha')).toBeInTheDocument();
    expect(screen.getByText('Product Beta')).toBeInTheDocument();
  });

  it('calls onSelect when a result is clicked', async () => {
    const onSelect = vi.fn();
    renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={null}
        onSelect={onSelect}
        onClear={vi.fn()}
      />,
    );
    const input = screen.getByPlaceholderText('Search product...');
    fireEvent.change(input, { target: { value: 'prod' } });

    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    fireEvent.click(screen.getByText('PRD-001').closest('button')!);
    expect(onSelect).toHaveBeenCalledWith(mockProducts[0]);
  });

  it('shows "No products found" for empty results', async () => {
    vi.mocked(api).mockResolvedValue({ success: true, results: [] });
    renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={null}
        onSelect={vi.fn()}
        onClear={vi.fn()}
      />,
    );
    const input = screen.getByPlaceholderText('Search product...');
    fireEvent.change(input, { target: { value: 'xyz' } });

    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    expect(screen.getByText('No products found')).toBeInTheDocument();
  });

  it('uses custom placeholder when provided', () => {
    renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={null}
        onSelect={vi.fn()}
        onClear={vi.fn()}
        placeholder="Cari produk..."
      />,
    );
    expect(screen.getByPlaceholderText('Cari produk...')).toBeInTheDocument();
  });

  it('clears input and results after selecting a product', async () => {
    const onSelect = vi.fn();
    renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={null}
        onSelect={onSelect}
        onClear={vi.fn()}
      />,
    );
    const input = screen.getByPlaceholderText('Search product...');
    fireEvent.change(input, { target: { value: 'prod' } });

    await act(async () => {
      vi.advanceTimersByTime(300);
    });

    fireEvent.click(screen.getByText('PRD-001').closest('button')!);
    expect(input).toHaveValue('');
  });

  it('clears debounce timer on unmount', async () => {
    const { unmount } = renderWithToast(
      <ProductSearch
        apiModule="inbound"
        selected={null}
        onSelect={vi.fn()}
        onClear={vi.fn()}
      />,
    );
    const input = screen.getByPlaceholderText('Search product...');
    fireEvent.change(input, { target: { value: 'prod' } });
    unmount();
    // Should not throw even though timer fires after unmount
    await act(async () => {
      vi.advanceTimersByTime(300);
    });
  });
});

describe('SearchProduct interface', () => {
  it('includes liters_per_unit as optional', () => {
    // Type-level check: this should compile
    const product: SearchProduct = {
      id: 1,
      product_code: 'PRD-001',
      product_name: 'Test',
      uom: 'kg',
      uom_per_pallet: 100,
      stock_qty: 500,
    };
    expect(product.liters_per_unit).toBeUndefined();
    expect(product.id).toBe(1);
  });
});
