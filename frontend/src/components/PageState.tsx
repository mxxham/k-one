import { ReactNode } from 'react';
import { AlertTriangle, RefreshCw } from 'lucide-react';
import Spinner from './Spinner';
import { EmptyState } from './Card';

/**
 * Props for the PageState component.
 * Manages the three common page states: loading, error, and empty.
 */
export interface PageStateProps {
  /** Shows a spinner overlay when true */
  loading?: boolean;
  /** Error message string — displays an error banner with retry button when set */
  error?: string;
  /** Shows the empty state when true */
  empty?: boolean;
  /** Callback invoked when the retry button in the error banner is clicked */
  onRetry?: () => void;
  /** Custom message displayed in the empty state */
  emptyMessage?: string;
  /** Content rendered when none of the special states are active */
  children: ReactNode;
}

/**
 * Unified component for page-level loading, error, and empty states.
 *
 * Priority: loading > error > empty > children.
 * Only one state renders at a time.
 *
 * @example
 * <PageState loading={isLoading} error={error} empty={items.length === 0}>
 *   <ItemList items={items} />
 * </PageState>
 */
export function PageState({
  loading = false,
  error,
  empty = false,
  onRetry,
  emptyMessage = 'No data available',
  children,
}: PageStateProps) {
  if (loading) {
    return <Spinner label="Loading…" />;
  }

  if (error) {
    return (
      <div className="rounded-lg border border-red-200 bg-red-50 p-6 text-center">
        <AlertTriangle className="mx-auto h-8 w-8 text-red-500 mb-3" />
        <p className="text-sm font-medium text-red-800 mb-1">Something went wrong</p>
        <p className="text-sm text-red-600 mb-4">{error}</p>
        {onRetry && (
          <button
            onClick={onRetry}
            className="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-semibold text-white bg-red-600 rounded-lg hover:bg-red-700 transition-colors"
          >
            <RefreshCw className="w-4 h-4" />
            Retry
          </button>
        )}
      </div>
    );
  }

  if (empty) {
    return <EmptyState message={emptyMessage} />;
  }

  return <>{children}</>;
}
