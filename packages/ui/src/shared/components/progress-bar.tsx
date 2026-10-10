import type { ComponentProps } from 'react';
import { cn } from '../cn';

/*
 * Foundations artboard, piece 5, bar form: 6px track, full radius,
 * surface-2 rail, accent fill, 11px meta line above ("7 of 12 lessons",
 * "58%"). The ring form belongs to the player's course header and lives
 * with the app.
 */
export interface ProgressBarProps extends Omit<ComponentProps<'div'>, 'children'> {
    /** 0 to 100; clamped. */
    value: number;
    /** Accessible name, e.g. "Course progress". Required: a bar without a name is a mystery meter. */
    label: string;
    /** Left meta text, e.g. "7 of 12 lessons". */
    detail?: string;
    /** Show the numeric percentage on the right of the meta line. */
    showValue?: boolean;
}

export function ProgressBar({ value, label, detail, showValue = false, className, ...props }: ProgressBarProps) {
    const clamped = Math.min(100, Math.max(0, value));
    return (
        <div className={cn('w-full', className)} {...props}>
            {(detail !== undefined || showValue) && (
                <div className="mb-1.5 flex items-center justify-between gap-2 text-[11px] text-fg-2">
                    <span>{detail}</span>
                    {showValue && <span>{Math.round(clamped)}%</span>}
                </div>
            )}
            <div
                role="progressbar"
                aria-label={label}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={Math.round(clamped)}
                className="h-1.5 overflow-hidden rounded-full bg-surface-2"
            >
                <div
                    className="h-full rounded-full bg-accent transition-[width] duration-2"
                    style={{ width: `${clamped}%` }}
                />
            </div>
        </div>
    );
}
