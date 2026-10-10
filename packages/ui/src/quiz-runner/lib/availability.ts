/* Straight from the SDK rather than through the shared re-export: this
   module is imported by the pre-attempt panel, whose suites mock
   @/shared/api, and a value import from a mocked module resolves to
   undefined at run time. */
import { ProblemError } from '@vidyalms/api-sdk';
import type { LessonQuizSummary } from '@/shared/api';
import { parseUtc } from './time';

/*
 * When another attempt may begin.
 *
 * Three separate refusals wear the same "not now" clothes and mean different
 * things: the quiz has not opened, the quiz has closed, and a cooldown after
 * the previous attempt has not elapsed. The server carries the moment on the
 * quiz summary and again on the 409 that refuses the start, precisely so the
 * client renders a countdown instead of inviting a refusal it already knew
 * was coming.
 */

export type AvailabilityState = 'open' | 'not_yet' | 'closed' | 'cooldown';

/**
 * A contract datetime as a date and a time in the mount locale.
 *
 * An availability moment needs both halves: "26 Aug" is useless for a quiz
 * that opens at nine in the morning, and the whole point of sending the
 * moment is that the learner can plan around it. A malformed value falls
 * back to the raw string rather than to nothing, because a support call
 * about a wrong date is easier than one about a blank.
 */
export function formatMoment(value: string, locale: string): string {
    const ms = parseUtc(value);
    if (ms === null) {
        return value;
    }
    try {
        return new Intl.DateTimeFormat(locale, {
            day: 'numeric',
            month: 'short',
            hour: '2-digit',
            minute: '2-digit',
        }).format(new Date(ms));
    } catch {
        return value;
    }
}

export interface Availability {
    state: AvailabilityState;
    /** The moment the state ends, when there is one; a contract datetime. */
    at: string | null;
}

export const AVAILABLE: Availability = { state: 'open', at: null };

function future(value: unknown, now: number): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    const ms = parseUtc(value);
    return ms !== null && ms > now ? value : null;
}

function past(value: unknown, now: number): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    const ms = parseUtc(value);
    return ms !== null && ms <= now ? value : null;
}

/**
 * What the quiz summary says about starting right now.
 *
 * The window is checked before the cooldown, because a closed quiz cannot be
 * started when the cooldown elapses either and a countdown to a moment that
 * changes nothing is a lie.
 */
export function availabilityOf(quiz: LessonQuizSummary, now: number = Date.now()): Availability {
    const raw = quiz as unknown as Record<string, unknown>;
    const opens = future(raw.opens_at, now);
    if (opens !== null) {
        return { state: 'not_yet', at: opens };
    }
    const closed = past(raw.closes_at, now);
    if (closed !== null) {
        return { state: 'closed', at: closed };
    }
    const cooldown = future(raw.cooldown_until, now);
    if (cooldown !== null) {
        return { state: 'cooldown', at: cooldown };
    }
    return AVAILABLE;
}

/**
 * The same answer read off a refusal, for the case the summary was stale
 * because another tab spent the attempt.
 *
 * Both members are extensions on the shared problem shape, following the
 * precedent retry_after already sets there, so there is no second refusal
 * body to parse.
 */
export function availabilityOfProblem(error: unknown, now: number = Date.now()): Availability | null {
    if (!(error instanceof ProblemError) || error.status !== 409) {
        return null;
    }
    const problem = error.problem as unknown as Record<string, unknown>;
    /* Only while the moment is still ahead. A refusal whose wait has since
       elapsed is a stale fact, and holding the start control shut on it
       would mean a learner waits out a cooldown and then cannot start. */
    const cooldown = future(problem.cooldown_until, now);
    if (cooldown !== null) {
        return { state: 'cooldown', at: cooldown };
    }
    const opens = future(problem.available_at, now);
    if (opens !== null) {
        return { state: 'not_yet', at: opens };
    }
    return null;
}
