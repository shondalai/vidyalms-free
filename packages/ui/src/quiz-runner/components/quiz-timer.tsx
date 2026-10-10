import { cn } from '@/shared/cn';
import { Button } from '@/shared/components';
import { quizRunnerStrings as strings } from '../strings';
import { formatClock, formatDuration } from '../lib/time';

/**
 * The timer pill of artboard 1a and the two-minute warning of 3a. The pill
 * is a live role=timer with a spoken label showing the server deadline;
 * the warning is a role=alert that appears once per crossing so a timed
 * quiz never surprise-submits. No extension exists: the deadline is the
 * server's, and a timed exam is an essential exception under WCAG 2.2.1,
 * so the warning explains the limit is fixed and offers Submit now.
 */
export function TimerPill({ remaining, total }: { remaining: number; total: number }) {
    const warn = remaining <= 120;
    return (
        <span
            role="timer"
            aria-label={strings.timer.remainingLabel(formatDuration(remaining), formatDuration(total))}
            className={cn(
                'inline-flex items-center gap-2 rounded-full border px-[13px] py-[7px] font-mono text-[13px] font-semibold tabular-nums',
                warn ? 'border-2 border-warn text-warn-strong' : 'border-border text-fg',
            )}
        >
            <span aria-hidden="true">◷</span>
            {formatClock(remaining)}
            <span className={cn('font-display text-[10.5px] font-medium', warn ? 'text-warn-strong' : 'text-fg-2')}>
                {warn ? strings.timer.left : strings.timer.pillOf(formatClock(total))}
            </span>
        </span>
    );
}

export function TimeWarning({ onSubmitNow }: { onSubmitNow: () => void }) {
    return (
        <div
            role="alert"
            className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-4 py-3.5 text-[13px] leading-[1.55] text-fg"
        >
            <strong className="font-semibold text-warn-strong">{strings.timer.twoMinutes}</strong>{' '}
            {strings.timer.warningBody}
            <div className="mt-3 flex flex-wrap gap-2">
                <Button size="sm" variant="outline" onClick={onSubmitNow}>
                    {strings.timer.submitNow}
                </Button>
            </div>
        </div>
    );
}
