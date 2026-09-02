export default function Skeleton({
  className = '',
  lines = 1,
  variant = 'text',
}: {
  className?: string;
  lines?: number;
  variant?: 'text' | 'card' | 'table-row';
}) {
  if (variant === 'card') {
    return (
      <div className={`bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden ${className}`}>
        <div className="px-5 py-3.5 border-b border-gray-100">
          <div className="h-4 w-32 bg-gray-200 rounded animate-pulse" />
        </div>
        <div className="p-5 space-y-3">
          {Array.from({ length: lines || 3 }).map((_, i) => (
            <div key={i} className="h-3 bg-gray-200 rounded animate-pulse"             style={{ width: `${[90, 75, 85, 70, 95][i % 5]}%` }} />
          ))}
        </div>
      </div>
    );
  }

  if (variant === 'table-row') {
    return (
      <div className={`flex gap-4 items-center py-3 px-4 ${className}`}>
        {Array.from({ length: lines || 4 }).map((_, i) => (
          <div
            key={i}
            className="h-3 bg-gray-200 rounded animate-pulse"
            style={{ flex: i === 0 ? 2 : 1 }}
          />
        ))}
      </div>
    );
  }

  // text variant
  return (
    <div className={`space-y-2.5 ${className}`}>
      {Array.from({ length: lines }).map((_, i) => (
        <div
          key={i}
          className="h-3 bg-gray-200 rounded animate-pulse"
          style={{ width: i === lines - 1 ? '60%' : '100%' }}
        />
      ))}
    </div>
  );
}
