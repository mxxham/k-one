import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';

// Mock modules before importing the component
vi.mock('@/lib/api', () => ({
  api: vi.fn(),
  apiHref: vi.fn(() => ''),
  webBase: vi.fn(() => ''),
  getToken: vi.fn(() => 'test-token'),
}));

vi.mock('@/context/AuthContext', () => ({
  useAuth: vi.fn(() => ({ canWrite: true, role: 'admin' })),
}));

vi.mock('@/components/Toast', () => ({
  useToast: vi.fn(() => vi.fn()),
}));

vi.mock('@/components/Spinner', () => ({
  default: ({ label }: { label?: string }) => <div data-testid="spinner">{label}</div>,
}));

vi.mock('@/components/ScanInput', () => ({
  default: ({ onScan, ...props }: any) => (
    <input data-testid="scan-input" onChange={(e) => onScan?.(e.target.value)} {...props} />
  ),
}));

vi.mock('@/components/ConfirmButton', () => ({
  default: ({ label }: { label?: string }) => <button>{label}</button>,
}));

vi.mock('@/components/Field', () => ({
  Select: (props: any) => <select {...props} />,
  TextInput: (props: any) => <input {...props} />,
}));

vi.mock('@/components/WebBtn', () => ({
  WebBtn: ({ label }: { label?: string }) => <button>{label}</button>,
}));

import PicklistDetail from './PicklistDetail';
import { api } from '@/lib/api';

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/picklist/1']}>
      <Routes>
        <Route path="/picklist/:id" element={<PicklistDetail />} />
        <Route path="/picklist" element={<div>Picklist List</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

const mockPicklist = {
  id: 1,
  picklist_number: 'PKL-20260901-0001',
  status: 'Picking',
  created_date: '2026-09-01T10:00:00',
  outbound_order_id: 10,
  outbound_number: 'OB-202609-0001',
  wave_id: null,
  wave_number: null,
  wave_carrier: null,
  notes: 'Test picklist',
  created_by_name: 'Admin',
  confirmed_at: '2026-09-01T10:05:00',
  picked_at: null,
  completed_at: null,
};

beforeEach(() => {
  vi.clearAllMocks();
});

describe('PicklistDetail — Awaiting Replenishment badge', () => {
  it('shows awaiting replenishment badge when blocked_on_replen_task_id is set', async () => {
    const mockApi = vi.mocked(api);
    mockApi.mockResolvedValueOnce({
      picklist: mockPicklist,
      items: [
        {
          id: 101,
          product_code: 'SKU-001',
          product_name: 'Gadus S3',
          batch_no: 'B001',
          location: 'A01R01',
          quantity: 100,
          uom: 'CTN',
          pallet: null,
          picked_quantity: null,
          status: 'Pending',
          picked_at: null,
          picker_id: null,
          notes: null,
          blocked_on_replen_task_id: 5,
        },
      ],
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Awaiting Replenishment')).toBeInTheDocument();
    });
  });

  it('does not show badge when blocked_on_replen_task_id is null', async () => {
    const mockApi = vi.mocked(api);
    mockApi.mockResolvedValueOnce({
      picklist: mockPicklist,
      items: [
        {
          id: 102,
          product_code: 'SKU-002',
          product_name: 'Shell Rimula',
          batch_no: 'B002',
          location: 'A02R01',
          quantity: 50,
          uom: 'CTN',
          pallet: null,
          picked_quantity: null,
          status: 'Pending',
          picked_at: null,
          picker_id: null,
          notes: null,
          blocked_on_replen_task_id: null,
        },
      ],
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Shell Rimula')).toBeInTheDocument();
    });

    expect(screen.queryByText('Awaiting Replenishment')).not.toBeInTheDocument();
  });

  it('does not show badge when blocked_on_replen_task_id is undefined', async () => {
    const mockApi = vi.mocked(api);
    mockApi.mockResolvedValueOnce({
      picklist: mockPicklist,
      items: [
        {
          id: 103,
          product_code: 'SKU-003',
          product_name: 'Helix Ultra',
          batch_no: 'B003',
          location: 'A03R01',
          quantity: 30,
          uom: 'CS',
          pallet: null,
          picked_quantity: null,
          status: 'Pending',
          picked_at: null,
          picker_id: null,
          notes: null,
          // blocked_on_replen_task_id is omitted (undefined)
        },
      ],
    });

    renderPage();

    await waitFor(() => {
      expect(screen.getByText('Helix Ultra')).toBeInTheDocument();
    });

    expect(screen.queryByText('Awaiting Replenishment')).not.toBeInTheDocument();
  });
});
