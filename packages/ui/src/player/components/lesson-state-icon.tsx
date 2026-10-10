import { Check } from 'lucide-react';
import { cn } from '@/shared/cn';
import { clampPercent } from '../lib';

/*
 * Foundations artboard, piece 2 (LessonState): shape + colour, never colour
 * alone. The icon itself is decorative; the row that renders it pairs it
 * with a visually hidden state label.
 *
 *   not started    empty circle
 *   in progress    partial warn ring
 *   complete       filled ok circle with a check
 *   awaiting grade dashed warn ring
 */
export type LessonUiState = 'not_started' | 'in_progress' | 'completed' | 'awaiting_grade';

export interface LessonStateIconProps {
    state: LessonUiState;
    /** Fill of the partial ring while in progress; defaults to 40%. */
    percent?: number;
    size?: number;
    className?: string;
}

const NOT_STARTED_RING = 'color-mix(in srgb, var(--_vy-fg-2) 45%, var(--_vy-border))';

export function LessonStateIcon({ state, percent = 40, size = 16, className }: LessonStateIconProps) {
    const box = { width: size, height: size };
    if (state === 'completed') {
        return (
            <span
                aria-hidden="true"
                data-state="completed"
                className={cn('inline-flex shrink-0 items-center justify-center rounded-full bg-ok', className)}
                style={box}
            >
                <Check aria-hidden="true" size={Math.round(size * 0.65)} strokeWidth={3.5} className="text-surface" />
            </span>
        );
    }
    if (state === 'in_progress') {
        const fill = Math.max(10, clampPercent(percent));
        return (
            <span
                aria-hidden="true"
                data-state="in_progress"
                className={cn('inline-block shrink-0 rounded-full border-2 border-warn', className)}
                style={{
                    ...box,
                    background: `conic-gradient(var(--_vy-warn) 0 ${fill}%, transparent ${fill}%)`,
                }}
            />
        );
    }
    if (state === 'awaiting_grade') {
        return (
            <span
                aria-hidden="true"
                data-state="awaiting_grade"
                className={cn('inline-block shrink-0 rounded-full border-2 border-dashed border-warn', className)}
                style={box}
            />
        );
    }
    return (
        <span
            aria-hidden="true"
            data-state="not_started"
            className={cn('inline-block shrink-0 rounded-full border-2', className)}
            style={{ ...box, borderColor: NOT_STARTED_RING }}
        />
    );
}
