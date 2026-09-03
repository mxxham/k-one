import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import ReplenishmentSheet, { type ReplenishmentTaskData, type ReplenishmentItem } from './ReplenishmentSheet';

function task(partial: Partial<ReplenishmentTaskData> = {}): ReplenishmentTaskData {
  return {
    id: 42,
    product_code: 'SKU-001',
    product_name: 'Gadus S3 V220',
    source_location: 'A01R01B03',
    dest_location: 'PICK-01',
    qty: 240,
    order_number: 'IN-202609-0001',
    ...partial,
  };
}

function makeItems(): ReplenishmentItem[] {
  return [
    {
      product_code: 'SKU-001',
      product_name: 'Gadus S3 V220',
      source_location: 'A01R01B03',
      dest_location: 'PICK-01',
      qty: 120,
      batch_no: 'BATCH-A',
    },
    {
      product_code: 'SKU-002',
      product_name: 'Gadus S2 V100',
      source_location: 'A02R01B01',
      dest_location: 'PICK-02',
      qty: 80,
    },
  ];
}

describe('ReplenishmentSheet', () => {
  it('renders SKU code and name in info grid', () => {
    render(<ReplenishmentSheet task={task()} />);
    expect(screen.getByText('SKU-001')).toBeInTheDocument();
    expect(screen.getByText('Gadus S3 V220')).toBeInTheDocument();
  });

  it('renders source and destination locations in info grid', () => {
    render(<ReplenishmentSheet task={task()} />);
    expect(screen.getByText('A01R01B03')).toBeInTheDocument();
    expect(screen.getByText('PICK-01')).toBeInTheDocument();
  });

  it('renders quantity in info grid', () => {
    render(<ReplenishmentSheet task={task({ qty: 1500 })} />);
    expect(screen.getByText('1,500')).toBeInTheDocument();
  });

  it('renders linked order number when provided', () => {
    render(<ReplenishmentSheet task={task({ order_number: 'IN-202609-0001' })} />);
    expect(screen.getByText('IN-202609-0001')).toBeInTheDocument();
  });

  it('renders dash when order_number is null', () => {
    render(<ReplenishmentSheet task={task({ order_number: null })} />);
    expect(screen.getByText('—')).toBeInTheDocument();
  });

  it('has a print button', () => {
    render(<ReplenishmentSheet task={task()} />);
    expect(screen.getByRole('button', { name: /Print/i })).toBeInTheDocument();
  });

  it('calls window.print on print button click', () => {
    const printSpy = vi.spyOn(window, 'print').mockImplementation(() => {});
    render(<ReplenishmentSheet task={task()} />);
    fireEvent.click(screen.getByRole('button', { name: /Print/i }));
    expect(printSpy).toHaveBeenCalled();
    printSpy.mockRestore();
  });

  it('renders the header with logo mark and company name', () => {
    render(<ReplenishmentSheet task={task()} />);
    expect(screen.getByText('K')).toBeInTheDocument();
    expect(screen.getByText(/K/)).toBeInTheDocument();
    expect(screen.getByText('REPLENISHMENT SHEET')).toBeInTheDocument();
  });

  it('renders task id in footer', () => {
    render(<ReplenishmentSheet task={task({ id: 99 })} />);
    expect(screen.getByText('Task #99')).toBeInTheDocument();
  });

  it('renders items table when items provided', () => {
    render(<ReplenishmentSheet task={task()} items={makeItems()} />);
    expect(screen.getByText('Gadus S3 V220')).toBeInTheDocument();
    expect(screen.getByText('Gadus S2 V100')).toBeInTheDocument();
    expect(screen.getByText('BATCH-A')).toBeInTheDocument();
    expect(screen.getByText('TOTAL')).toBeInTheDocument();
  });

  it('does not render items table when items is empty', () => {
    render(<ReplenishmentSheet task={task()} items={[]} />);
    expect(screen.queryByText('Items to Move')).not.toBeInTheDocument();
    expect(screen.queryByText('TOTAL')).not.toBeInTheDocument();
  });

  it('does not render items table when items is undefined', () => {
    render(<ReplenishmentSheet task={task()} />);
    expect(screen.queryByText('Items to Move')).not.toBeInTheDocument();
  });

  it('sums item quantities in info grid when items provided', () => {
    render(<ReplenishmentSheet task={task({ qty: 999 })} items={makeItems()} />);
    // Items total = 120 + 80 = 200, should override task.qty
    expect(screen.getByText('200')).toBeInTheDocument();
  });
});
