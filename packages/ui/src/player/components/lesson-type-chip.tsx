import { cn } from '@/shared/cn';
import { isAccentType, paneChipLabel, railChipLabel } from '../lib';

/*
 * Foundations artboard, piece 3 (LessonType chip): mono uppercase tag that
 * tells the learner how the lesson completes. Quiz and assignment render
 * in accent; view-completion types stay neutral. The rail form uses the
 * short labels (READ, FILE, TASK), the pane form the full type names.
 */
export interface LessonTypeChipProps {
    type: string;
    form?: 'rail' | 'pane';
    /** The rail's current row inverts the chip onto the surface. */
    current?: boolean;
    className?: string;
}

export function LessonTypeChip({ type, form = 'rail', current = false, className }: LessonTypeChipProps) {
    const accent = isAccentType(type);
    const label = form === 'rail' ? railChipLabel(type) : paneChipLabel(type);
    return (
        <span
            className={cn(
                'shrink-0 rounded-sm font-mono font-semibold leading-none',
                form === 'rail' ? 'px-1.5 py-[3px] text-[9px]' : 'px-2 py-[4px] text-[10px]',
                current
                    ? 'border border-accent-soft bg-surface text-accent'
                    : accent
                      ? 'bg-accent-soft text-accent'
                      : form === 'rail'
                        ? 'bg-surface-2 text-fg-2'
                        : 'bg-surface-2 text-fg',
                className,
            )}
        >
            {label}
        </span>
    );
}
