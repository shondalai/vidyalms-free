import { useEffect, useState } from 'react';
import { cn } from '@/shared/cn';
import { runnerRuntimeStrings as strings } from '../runtime-strings';
import { formatClock, parseUtc } from '../lib/time';
import type { AttemptRuntime } from '../lib/runtime';

/*
 * What the frozen policy has to say to the learner before it changes what a
 * control does.
 *
 * Every rule the runner enforces from the snapshot is stated where it takes
 * effect. A quiz that removes the back control, refuses a skip, marks
 * nothing until the end or does not count a late submission is a quiz the
 * learner has to be told about first (SC 3.3.2 and, for the moving deadline,
 * SC 2.2.1's own condition that the limit be disclosed).
 */

export type NoticeTone = 'info' | 'warn';

export function Notice({
    tone = 'info',
    title,
    body,
    role,
    className,
}: {
    tone?: NoticeTone;
    title: string;
    body?: string;
    role?: 'status' | 'alert';
    className?: string;
}) {
    return (
        <div
            role={role}
            className={cn(
                'rounded border border-border border-l-[3px] bg-surface px-4 py-3 text-[12.5px] leading-[1.55] text-fg-2',
                tone === 'warn' ? 'border-l-warn' : 'border-l-accent',
                className,
            )}
        >
            <strong className={cn('font-semibold', tone === 'warn' ? 'text-warn-strong' : 'text-fg')}>
                {title}
            </strong>{' '}
            {body !== undefined && <span>{body}</span>}
        </div>
    );
}

/**
 * The rules of this attempt, shown once at the top of the first screen.
 *
 * A substituted behaviour is included because the learner is being marked
 * under a rule the author did not choose, and the registry miss contract
 * says the substitution is recorded rather than hidden.
 */
export function RuntimeNotices({ runtime, className }: { runtime: AttemptRuntime; className?: string }) {
    const notices: { key: string; tone: NoticeTone; title: string; body: string }[] = [];

    if (runtime.substitutedFrom !== null) {
        notices.push({
            key: 'substituted',
            tone: 'warn',
            title: strings.behaviour.substituted(runtime.substitutedFrom),
            body: '',
        });
    }
    if (runtime.navigationMode === 'linear') {
        notices.push({
            key: 'linear',
            tone: 'info',
            title: strings.navigation.linearTitle,
            body: strings.navigation.linearBody,
        });
    }
    if (runtime.submissionMode === 'simultaneous') {
        notices.push({
            key: 'simultaneous',
            tone: 'info',
            title: strings.submission.simultaneousTitle,
            body: strings.submission.simultaneousBody,
        });
    }
    if (runtime.expiryPolicy === 'must_submit') {
        notices.push({
            key: 'must-submit',
            tone: 'warn',
            title: strings.expiry.mustSubmitTitle,
            body: strings.expiry.mustSubmitBody,
        });
    }

    if (notices.length === 0) {
        return null;
    }

    return (
        <div className={cn('grid gap-2.5', className)}>
            {notices.map((notice) => (
                <Notice
                    key={notice.key}
                    tone={notice.tone}
                    title={notice.title}
                    body={notice.body === '' ? undefined : notice.body}
                />
            ))}
        </div>
    );
}

/**
 * What the deadline did, once it arrived.
 *
 * The three expiry policies are three different sentences, because they are
 * three different situations: one submits for the learner, one takes the
 * submit and no more answers, and one does not count the attempt at all.
 * Saying "time is up" for all three would be true and useless.
 */
export function ExpiryNotice({ runtime }: { runtime: AttemptRuntime }) {
    if (runtime.expiryPolicy === 'grace_period') {
        return (
            <Notice
                role="alert"
                tone="warn"
                title={strings.expiry.gracePeriodTitle}
                body={strings.expiry.gracePeriodBody(runtime.graceSeconds)}
            />
        );
    }
    if (runtime.expiryPolicy === 'must_submit' || !runtime.autoSubmit) {
        return (
            <Notice
                role="alert"
                tone="warn"
                title={strings.expiry.manualSubmitTitle}
                body={strings.expiry.manualSubmitBody}
            />
        );
    }
    return null;
}

/**
 * The seconds left before a moment, recomputed on a one second tick and
 * stopping at zero. Used for the cooldown countdown, which is the only
 * countdown in the runner that is not the attempt deadline.
 */
export function useCountdown(until: string | null): number | null {
    const target = parseUtc(until);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        if (target === null) {
            return;
        }
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [target]);

    if (target === null) {
        return null;
    }
    return Math.max(0, Math.ceil((target - now) / 1000));
}

/**
 * The cooldown refusal, rendered as a countdown rather than a bare no.
 *
 * The server carries the earliest permitted start on the 409 and on the quiz
 * summary for exactly this: a learner told "not yet" with no "until when"
 * retries in a loop, and every retry is a refusal the server has to serve.
 */
export function CooldownNotice({
    until,
    formatWhen,
    onElapsed,
}: {
    until: string;
    formatWhen: (value: string) => string;
    onElapsed?: () => void;
}) {
    const remaining = useCountdown(until);

    useEffect(() => {
        if (remaining === 0) {
            onElapsed?.();
        }
    }, [remaining, onElapsed]);

    return (
        <div
            role="status"
            className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-4 py-3.5 text-[13px] leading-[1.55] text-fg"
        >
            <strong className="font-semibold text-warn-strong">{strings.availability.cooldownTitle}</strong>{' '}
            {remaining !== null && remaining > 0
                ? strings.availability.cooldownIn(formatClock(remaining))
                : strings.availability.cooldownUntil(formatWhen(until))}
            <p className="mt-1.5 text-[12px] text-fg-2">{strings.availability.cooldownWhy}</p>
        </div>
    );
}
