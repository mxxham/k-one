interface KPICardProps {
  label: string;
  value: number | string;
  className?: string;
}

export default function KPICard({ label, value, className = '' }: KPICardProps) {
  return (
    <div className={`bg-white rounded-xl border border-gray-200 shadow-sm px-4 py-3 ${className}`}>
      <div className="text-2xl font-extrabold text-brand-700">{value}</div>
      <div className="text-[11px] font-semibold text-gray-500 uppercase tracking-wide mt-0.5">{label}</div>
    </div>
  );
}
