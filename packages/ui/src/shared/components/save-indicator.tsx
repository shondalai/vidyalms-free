import { useEffect, useReducer, useRef, type ComponentProps } from 'react';
import { cn } from '../cn';
import { sharedStrings } from '../strings';
import { VisuallyHidden } from './visually-hidden';

/*
 * Foundations artboard, piece 4: honest heartbeat state, quiet by default.
 * Text timestamp, no toast, no modal. The aria-live region is polite,
 * persistent, and carries only STATE TRANSITIONS (a save landing, a
 * failure, the offline pause): the relative timestamp ticks silently in
 * aria-hidden text, and the routine saving->saved cycle of each heartbeat
 * repeats the same live text, so nothing re-announces every 15 seconds.
 * Escalating to the offline banner after repeated failures is the app's
 * decision, not this component's.
 */
export type SaveState = 'idle' | 'saving' | 'saved' | 'failed' | 'offline';

export interface SaveIndicatorProps extends ComponentProps<'span'> {
    state: SaveState;
    /** When the last successful save happened; drives "just now" / "12s ago". */
    savedAt?: Date | number | null;
    /** Shown as an inline "retry now" action while failed. */
    onRetry?: () => void;
}

function agoText(savedAt: Date | number): string {
    const ms = Date.now() - (savedAt instanceof Date ? savedAt.getTime() : savedAt);
    const seconds = Math.max(0, Math.floor(ms / 1000));
    if (seconds < 10) {
        return sharedStrings.time.justNow;
    }
    if (seconds < 60) {
        return sharedStrings.time.secondsAgo(seconds);
    }
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) {
        return sharedStrings.time.minutesAgo(minutes);
    }
    return sharedStrings.time.hoursAgo(Math.floor(minutes / 60));
}

function labelOf(state: SaveState, savedAt: SaveIndicatorProps['savedAt']): string {
    switch (state) {
        case 'idle':
            return '';
        case 'saving':
            return sharedStrings.save.saving;
        case 'failed':
            return sharedStrings.save.failed;
        case 'offline':
            /* The player's honesty rule (artboard 3c): while offline the
               indicator says so; work continues and syncs on reconnect. */
            return sharedStrings.save.paused;
        case 'saved': {
            if (savedAt === null || savedAt === undefined) {
                return sharedStrings.save.saved;
            }
            const ago = agoText(savedAt);
            return ago === sharedStrings.time.justNow
                ? sharedStrings.save.savedJustNow
                : sharedStrings.save.savedAgo(ago);
        }
    }
}

/**
 * What the live region says for a state. 'saving' returns null: the cycle
 * saving->saved would otherwise toggle the live text every heartbeat, so
 * the region keeps its last stable message until the outcome lands.
 */
function liveLabelOf(state: SaveState): string | null {
    switch (state) {
        case 'idle':
            return '';
        case 'saving':
            return null;
        case 'saved':
            return sharedStrings.save.saved;
        case 'failed':
            return sharedStrings.save.failed;
        case 'offline':
            return sharedStrings.save.paused;
    }
}

export function SaveIndicator({ state, savedAt, onRetry, className, ...props }: SaveIndicatorProps) {
    const [, tick] = useReducer((n: number) => n + 1, 0);

    /* Keep the relative timestamp honest while in the saved state. */
    useEffect(() => {
        if (state !== 'saved' || savedAt === null || savedAt === undefined) {
            return;
        }
        const timer = window.setInterval(tick, 5000);
        return () => window.clearInterval(timer);
    }, [state, savedAt]);

    const label = labelOf(state, savedAt);
    const liveRef = useRef('');
    const liveLabel = liveLabelOf(state);
    if (liveLabel !== null) {
        liveRef.current = liveLabel;
    }
    return (
        <span
            className={cn(
                'inline-flex items-center gap-[7px] text-[12.5px]',
                state === 'failed' ? 'text-danger' : state === 'offline' ? 'text-warn-strong' : 'text-fg-2',
                className,
            )}
            {...props}
        >
            {state !== 'idle' && (
                <span
                    aria-hidden="true"
                    data-testid="save-dot"
                    className={cn('h-[7px] w-[7px] rounded-full', {
                        'bg-warn': state === 'saving' || state === 'offline',
                        'bg-ok': state === 'saved',
                        'bg-danger': state === 'failed',
                    })}
                />
            )}
            {/* The visible text ticks with the timestamp; only transitions
                reach the live region below, so the tick stays silent. */}
            <span aria-hidden="true">{label}</span>
            <VisuallyHidden aria-live="polite">{liveRef.current}</VisuallyHidden>
            {state === 'failed' && onRetry && (
                <button
                    type="button"
                    onClick={onRetry}
                    className="cursor-pointer font-semibold text-accent underline underline-offset-2"
                >
                    {sharedStrings.save.retryNow}
                </button>
            )}
        </span>
    );
}
