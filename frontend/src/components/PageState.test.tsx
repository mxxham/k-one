import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { PageState } from './PageState';

describe('PageState', () => {
  it('renders children when no special state is active', () => {
    render(
      <PageState>
        <p>Page content</p>
      </PageState>,
    );
    expect(screen.getByText('Page content')).toBeInTheDocument();
  });

  it('shows spinner when loading is true', () => {
    const { container } = render(
      <PageState loading>
        <p>Page content</p>
      </PageState>,
    );
    expect(container.querySelector('.animate-spin')).toBeInTheDocument();
    expect(screen.getByText('Loading…')).toBeInTheDocument();
    expect(screen.queryByText('Page content')).not.toBeInTheDocument();
  });

  it('shows error banner with message when error is set', () => {
    render(
      <PageState error="Network failure">
        <p>Page content</p>
      </PageState>,
    );
    expect(screen.getByText('Something went wrong')).toBeInTheDocument();
    expect(screen.getByText('Network failure')).toBeInTheDocument();
    expect(screen.queryByText('Page content')).not.toBeInTheDocument();
  });

  it('shows retry button when error is set and onRetry is provided', () => {
    const onRetry = vi.fn();
    render(
      <PageState error="Timeout" onRetry={onRetry}>
        <p>Page content</p>
      </PageState>,
    );
    const retryBtn = screen.getByRole('button', { name: /retry/i });
    expect(retryBtn).toBeInTheDocument();
    fireEvent.click(retryBtn);
    expect(onRetry).toHaveBeenCalledTimes(1);
  });

  it('hides retry button when onRetry is not provided', () => {
    render(
      <PageState error="Timeout">
        <p>Page content</p>
      </PageState>,
    );
    expect(screen.queryByRole('button', { name: /retry/i })).not.toBeInTheDocument();
  });

  it('shows empty state when empty is true', () => {
    render(
      <PageState empty emptyMessage="No orders found">
        <p>Page content</p>
      </PageState>,
    );
    expect(screen.getByText('No orders found')).toBeInTheDocument();
    expect(screen.queryByText('Page content')).not.toBeInTheDocument();
  });

  it('shows default empty message when emptyMessage is not provided', () => {
    render(
      <PageState empty>
        <p>Page content</p>
      </PageState>,
    );
    expect(screen.getByText('No data available')).toBeInTheDocument();
  });

  it('prioritises loading over error', () => {
    const { container } = render(
      <PageState loading error="Some error">
        <p>Page content</p>
      </PageState>,
    );
    expect(container.querySelector('.animate-spin')).toBeInTheDocument();
    expect(screen.queryByText('Something went wrong')).not.toBeInTheDocument();
  });

  it('prioritises error over empty', () => {
    render(
      <PageState error="Oops" empty>
        <p>Page content</p>
      </PageState>,
    );
    expect(screen.getByText('Oops')).toBeInTheDocument();
    expect(screen.queryByText('No data available')).not.toBeInTheDocument();
  });

  it('renders custom loading label via Spinner', () => {
    const { container } = render(
      <PageState loading>
        <p>Content</p>
      </PageState>,
    );
    expect(container.querySelector('.animate-spin')).toBeInTheDocument();
    expect(screen.getByText('Loading…')).toBeInTheDocument();
  });
});
