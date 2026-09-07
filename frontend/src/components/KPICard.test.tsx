import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import KPICard from './KPICard';

describe('KPICard', () => {
  it('renders the label', () => {
    render(<KPICard label="Total Orders" value={42} />);
    expect(screen.getByText('Total Orders')).toBeInTheDocument();
  });

  it('renders a numeric value', () => {
    render(<KPICard label="Revenue" value={1234} />);
    expect(screen.getByText('1234')).toBeInTheDocument();
  });

  it('renders a string value', () => {
    render(<KPICard label="Status" value="Active" />);
    expect(screen.getByText('Active')).toBeInTheDocument();
  });

  it('applies additional className', () => {
    const { container } = render(<KPICard label="Test" value={0} className="custom-class" />);
    expect(container.firstElementChild).toHaveClass('custom-class');
  });

  it('applies base styling classes', () => {
    const { container } = render(<KPICard label="Test" value={0} />);
    const el = container.firstElementChild as HTMLElement;
    expect(el).toHaveClass('bg-white', 'rounded-xl', 'border', 'border-gray-200', 'shadow-sm', 'px-4', 'py-3');
  });
});
