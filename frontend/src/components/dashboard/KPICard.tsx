import { LucideIcon, TrendingUp, TrendingDown, Minus } from 'lucide-react';
import { fmtNum } from '@/lib/format';

interface KPICardProps {
  label: string;
  value: string | number;
  subtitle?: string;
  icon: LucideIcon;
  gradient: string;
  trend?: number; // Percentage change
  unit?: string;
}

export default function KPICard({ label, value, subtitle, icon: Icon, gradient, trend, unit }: KPICardProps) {
  const getTrendIcon = () => {
    if (trend === undefined || trend === 0) return Minus;
    return trend > 0 ? TrendingUp : TrendingDown;
  };

  const getTrendColor = () => {
    if (trend === undefined || trend === 0) return 'text-brand-700/50';
    return trend > 0 ? 'text-emerald-600' : 'text-red-500';
  };

  const getTrendBg = () => {
    if (trend === undefined || trend === 0) return 'bg-brand-100/60';
    return trend > 0 ? 'bg-emerald-50' : 'bg-red-50';
  };

  const TrendIcon = getTrendIcon();
  const trendColor = getTrendColor();
  const trendBg = getTrendBg();

  return (
    <div
      className={`group rounded-xl bg-gradient-to-br ${gradient} p-5 relative overflow-hidden
        transition-all duration-300 ease-out
        hover:scale-[1.01] hover:shadow-lg`}
    >
      {/* White overlay — softens any gradient for a lighter, more premium feel */}
      <div className="absolute inset-0 bg-white/70" />

      {/* Subtle decorative accent using the gradient's color family */}
      <div className="absolute -right-8 -top-8 w-28 h-28 rounded-full blur-2xl bg-white/40 transition-all duration-500 group-hover:scale-110" />
      <div className="absolute -left-4 -bottom-4 w-20 h-20 rounded-full blur-xl bg-white/30" />

      <div className="relative">
        {/* Top row: icon + trend */}
        <div className="flex items-center justify-between mb-3">
          <div className="w-8 h-8 rounded-lg bg-brand-100 flex items-center justify-center ring-1 ring-brand-200/60">
            <Icon className="w-4 h-4 text-brand-600" strokeWidth={2} />
          </div>
          {trend !== undefined && (
            <div className={`flex items-center gap-1 text-xs font-semibold ${trendColor} ${trendBg} px-2 py-0.5 rounded-full`}>
              <TrendIcon className="w-3 h-3" />
              <span>{Math.abs(trend).toFixed(1)}%</span>
            </div>
          )}
        </div>

        {/* Value */}
        <div className="text-[1.625rem] leading-tight font-extrabold text-brand-900 tracking-tight">
          {typeof value === 'number' ? fmtNum(value, 0) : value}
          {unit && <span className="text-sm font-semibold ml-1 text-brand-500">{unit}</span>}
        </div>

        {/* Label */}
        <div className="text-xs font-semibold uppercase tracking-wider text-brand-500 mt-1">
          {label}
        </div>

        {/* Subtitle */}
        {subtitle && (
          <div className="text-xs text-brand-400 mt-2">{subtitle}</div>
        )}
      </div>
    </div>
  );
}
