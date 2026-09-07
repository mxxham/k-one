import { ChevronRight } from 'lucide-react';
import { Card } from '@/components/Card';
import StatusBadge from '@/components/StatusBadge';

const WORKFLOW_STEPS = ['Draft', 'Dues In', 'Receiving', 'Goods Received', 'ATP', 'Completed'];

interface WorkflowTimelineProps {
  /** Index of the currently active step (< 0 means no step is active) */
  activeIdx: number;
  /** Fallback status label when no step is active */
  status: string;
}

export function WorkflowTimeline({ activeIdx, status }: WorkflowTimelineProps) {
  return (
    <Card title="Workflow">
      <div className="flex items-center justify-between gap-4 flex-wrap">
        <div className="flex items-center gap-1 overflow-x-auto py-1">
          {WORKFLOW_STEPS.map((s, i) => {
            const active = i <= activeIdx;
            return (
              <div key={s} className="flex items-center gap-1 flex-shrink-0">
                <span
                  className={`px-3 py-1.5 rounded-lg text-xs font-bold border whitespace-nowrap ${
                    i === activeIdx
                      ? 'bg-brand-600 text-white border-brand-600'
                      : active
                        ? 'bg-brand-50 text-brand-700 border-brand-200'
                        : 'bg-gray-50 text-gray-400 border-gray-200'
                  }`}
                >
                  {s}
                </span>
                {i < WORKFLOW_STEPS.length - 1 && <ChevronRight className="w-3.5 h-3.5 text-gray-300" />}
              </div>
            );
          })}
        </div>
        <StatusBadge status={activeIdx >= 0 ? WORKFLOW_STEPS[activeIdx] : status} />
      </div>
    </Card>
  );
}
