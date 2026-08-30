import { Grid3X3, Maximize2, Minimize2 } from 'lucide-react';

export type LabelTemplateId = 'lpn-small' | 'lpn-medium' | 'lpn-large' | 'gs1-small' | 'gs1-medium' | 'sscc-medium';

export interface LabelTemplate {
  id: LabelTemplateId;
  name: string;
  description: string;
  width: string;
  height: string;
  icon: 'small' | 'medium' | 'large';
}

/** All available label templates */
export const LABEL_TEMPLATES: LabelTemplate[] = [
  {
    id: 'lpn-small',
    name: 'LPN Small',
    description: 'Compact pallet label (200px)',
    width: '200px',
    height: 'auto',
    icon: 'small',
  },
  {
    id: 'lpn-medium',
    name: 'LPN Medium',
    description: 'Standard pallet label (320px)',
    width: '320px',
    height: 'auto',
    icon: 'medium',
  },
  {
    id: 'lpn-large',
    name: 'LPN Large',
    description: 'Large pallet label (480px)',
    width: '480px',
    height: 'auto',
    icon: 'large',
  },
  {
    id: 'gs1-small',
    name: 'GS1 Small',
    description: 'Compact GS1 product label (250px)',
    width: '250px',
    height: 'auto',
    icon: 'small',
  },
  {
    id: 'gs1-medium',
    name: 'GS1 Medium',
    description: 'Standard GS1 product label (350px)',
    width: '350px',
    height: 'auto',
    icon: 'medium',
  },
  {
    id: 'sscc-medium',
    name: 'SSCC Medium',
    description: 'Standard SSCC shipping label (400px)',
    width: '400px',
    height: 'auto',
    icon: 'medium',
  },
];

/** Get template by ID */
export function getTemplate(id: LabelTemplateId): LabelTemplate {
  return LABEL_TEMPLATES.find((t) => t.id === id) ?? LABEL_TEMPLATES[1]; // default to medium
}

/** Filter templates by label type prefix */
export function getTemplatesByType(type: 'lpn' | 'gs1' | 'sscc'): LabelTemplate[] {
  return LABEL_TEMPLATES.filter((t) => t.id.startsWith(type));
}

// ─── Component ──────────────────────────────────────────────────────────────

interface LabelTemplatePickerProps {
  /** Currently selected template ID */
  selected: LabelTemplateId;
  /** Callback when template is changed */
  onChange: (templateId: LabelTemplateId) => void;
  /** Filter to only show templates of this type (optional) */
  filterType?: 'lpn' | 'gs1' | 'sscc';
  /** Additional CSS classes */
  className?: string;
}

function IconForSize({ size }: { size: 'small' | 'medium' | 'large' }) {
  if (size === 'small') return <Minimize2 className="w-4 h-4" />;
  if (size === 'large') return <Maximize2 className="w-4 h-4" />;
  return <Grid3X3 className="w-4 h-4" />;
}

/**
 * Label template size picker.
 *
 * Displays available label templates as selectable cards with size
 * indicators. Filters by label type when specified.
 */
export default function LabelTemplatePicker({
  selected,
  onChange,
  filterType,
  className = '',
}: LabelTemplatePickerProps) {
  const templates = filterType ? getTemplatesByType(filterType) : LABEL_TEMPLATES;

  return (
    <div className={className}>
      <label className="block text-sm font-semibold text-gray-700 mb-2">Label Size</label>
      <div className="grid grid-cols-3 gap-2">
        {templates.map((tpl) => (
          <button
            key={tpl.id}
            onClick={() => onChange(tpl.id)}
            className={`flex flex-col items-center gap-1.5 p-3 rounded-xl border-2 transition-all text-center ${
              selected === tpl.id
                ? 'border-brand-600 bg-brand-50 shadow-sm'
                : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50'
            }`}
          >
            <IconForSize size={tpl.icon} />
            <span className={`text-xs font-semibold ${selected === tpl.id ? 'text-brand-700' : 'text-gray-700'}`}>
              {tpl.name}
            </span>
            <span className="text-[10px] text-gray-400 leading-tight">{tpl.description}</span>
          </button>
        ))}
      </div>
    </div>
  );
}
