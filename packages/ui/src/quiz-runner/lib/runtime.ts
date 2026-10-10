import type { AttemptQuestion, AttemptView } from '@/shared/api';
import type { CertaintyScale } from '@/runner-panels';

/*
 * The frozen policy an attempt runs under, read from the attempt and never
 * from the quiz.
 *
 * Changing a quiz setting cannot move a learner who is already inside an
 * attempt: the whole policy set is resolved once at StartAttempt and frozen
 * on the attempt row, and the runner obeys that snapshot for the life of the
 * attempt (docs/technical/12-assessment-types.md, "Quiz-level policy
 * objects"). Every field is optional on the wire, so a host older than the
 * field answers today's behaviour rather than an error.
 *
 * The defaults below are QTI's own, adopted verbatim so a migrating author
 * meets no surprise and there is no design argument to have.
 */

export type NavigationMode = 'linear' | 'nonlinear';
export type SubmissionMode = 'individual' | 'simultaneous';
export type ExpiryPolicy = 'auto_submit' | 'grace_period' | 'must_submit';

export interface ItemSessionControl {
    /** Tries per item; 0 means unlimited. */
    maxAttempts: number;
    /** Governs feedback after the last try only. */
    showFeedback: boolean;
    allowReview: boolean;
    showSolution: boolean;
    allowComment: boolean;
    allowSkipping: boolean;
    /** Whether an invalid response blocks moving on. Saving never refuses one. */
    validateResponses: boolean;
}

export interface AttemptRuntime {
    behaviour: string;
    /** The behaviour the quiz asked for, when the site could not resolve it. */
    substitutedFrom: string | null;
    navigationMode: NavigationMode;
    submissionMode: SubmissionMode;
    onePerPage: boolean;
    expiryPolicy: ExpiryPolicy;
    /** Never below 60: that is the clock skew floor, not merely the default. */
    graceSeconds: number;
    autoSubmit: boolean;
    itemSessionControl: ItemSessionControl;
    /** The scale to ask for beside each answer, or null for every free behaviour. */
    certainty: CertaintyScale | null;
    suppressIntegrityAutomation: boolean;
}

/** The clock skew floor of the contract; a smaller grace would refuse a submit that left in time. */
export const GRACE_FLOOR_SECONDS = 60;

export const DEFAULT_ITEM_SESSION_CONTROL: ItemSessionControl = {
    maxAttempts: 1,
    showFeedback: false,
    allowReview: true,
    showSolution: false,
    allowComment: false,
    allowSkipping: true,
    validateResponses: false,
};

export const DEFAULT_RUNTIME: AttemptRuntime = {
    behaviour: 'deferred_feedback',
    substitutedFrom: null,
    navigationMode: 'nonlinear',
    submissionMode: 'individual',
    onePerPage: true,
    expiryPolicy: 'auto_submit',
    graceSeconds: GRACE_FLOOR_SECONDS,
    autoSubmit: true,
    itemSessionControl: DEFAULT_ITEM_SESSION_CONTROL,
    certainty: null,
    suppressIntegrityAutomation: false,
};

function record(value: unknown): Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : {};
}

function bool(value: unknown, fallback: boolean): boolean {
    return typeof value === 'boolean' ? value : fallback;
}

function count(value: unknown, fallback: number): number {
    return typeof value === 'number' && Number.isFinite(value) && value >= 0 ? Math.floor(value) : fallback;
}

function oneOf<T extends string>(value: unknown, allowed: readonly T[], fallback: T): T {
    return typeof value === 'string' && (allowed as readonly string[]).includes(value) ? (value as T) : fallback;
}

function textOrNull(value: unknown): string | null {
    return typeof value === 'string' && value !== '' ? value : null;
}

export function readItemSessionControl(
    raw: unknown,
    base: ItemSessionControl = DEFAULT_ITEM_SESSION_CONTROL,
): ItemSessionControl {
    const source = record(raw);
    return {
        maxAttempts: count(source.max_attempts, base.maxAttempts),
        showFeedback: bool(source.show_feedback, base.showFeedback),
        allowReview: bool(source.allow_review, base.allowReview),
        showSolution: bool(source.show_solution, base.showSolution),
        allowComment: bool(source.allow_comment, base.allowComment),
        allowSkipping: bool(source.allow_skipping, base.allowSkipping),
        validateResponses: bool(source.validate_responses, base.validateResponses),
    };
}

/**
 * The declared scale, or null.
 *
 * A scale with no levels is null rather than an empty control: the panel
 * would otherwise render a required question the learner cannot answer,
 * which is worse than not asking.
 */
export function readCertaintyScale(raw: unknown): CertaintyScale | null {
    const source = record(raw);
    const rawLevels = Array.isArray(source.levels) ? source.levels : [];
    const levels = rawLevels.flatMap((entry) => {
        const level = record(entry);
        return typeof level.value === 'number' && typeof level.label === 'string'
            ? [{ value: level.value, label: level.label, description: textOrNull(level.description) }]
            : [];
    });
    return levels.length === 0 ? null : { levels, required: bool(source.required, false) };
}

/** The frozen snapshot, with the contract's documented default for anything absent. */
export function readAttemptRuntime(view: AttemptView | undefined): AttemptRuntime {
    if (view === undefined) {
        return DEFAULT_RUNTIME;
    }
    const raw = record((view.attempt as unknown as Record<string, unknown>).runtime);
    return {
        behaviour:
            typeof raw.behaviour === 'string' && raw.behaviour !== ''
                ? raw.behaviour
                : DEFAULT_RUNTIME.behaviour,
        substitutedFrom: textOrNull(raw.substituted_from),
        navigationMode: oneOf(raw.navigation_mode, ['linear', 'nonlinear'] as const, 'nonlinear'),
        submissionMode: oneOf(raw.submission_mode, ['individual', 'simultaneous'] as const, 'individual'),
        onePerPage: bool(raw.one_per_page, DEFAULT_RUNTIME.onePerPage),
        expiryPolicy: oneOf(
            raw.expiry_policy,
            ['auto_submit', 'grace_period', 'must_submit'] as const,
            'auto_submit',
        ),
        graceSeconds: Math.max(GRACE_FLOOR_SECONDS, count(raw.grace_seconds, GRACE_FLOOR_SECONDS)),
        autoSubmit: bool(raw.auto_submit, DEFAULT_RUNTIME.autoSubmit),
        itemSessionControl: readItemSessionControl(raw.item_session_control),
        certainty: readCertaintyScale(raw.certainty),
        suppressIntegrityAutomation: bool(raw.suppress_integrity_automation, false),
    };
}

export interface ItemRuntime {
    control: ItemSessionControl;
    /** Tries already spent, from the append-only event history. */
    triesUsed: number;
    /** The confidence stored with the saved answer, where one was asked for. */
    certainty: number | null;
    /** False once the item has spent every try the control allows. */
    triesLeft: boolean;
}

/**
 * The control resolved for one item: the quiz defaults narrowed by the
 * section and slot it was served through.
 *
 * It is per item rather than global because navigation, retries and feedback
 * are properties of a structural node in QTI, and a single global flag
 * cannot express "this block is linear while the rest is free".
 */
export function readItemRuntime(question: AttemptQuestion, runtime: AttemptRuntime): ItemRuntime {
    const raw = question as unknown as Record<string, unknown>;
    const control = readItemSessionControl(raw.session_control, runtime.itemSessionControl);
    const triesUsed = count(raw.tries_used, 0);
    const certainty = typeof raw.certainty === 'number' ? raw.certainty : null;
    return {
        control,
        triesUsed,
        certainty,
        triesLeft: control.maxAttempts === 0 || triesUsed < control.maxAttempts,
    };
}

/**
 * Whether this item may be checked before the attempt ends.
 *
 * Simultaneous submission defers all response processing to the end of the
 * part, so the candidate gets exactly one try per item BY DEFINITION and no
 * feedback can be shown during the test. That is a rule of the mode, not a
 * second setting, so it is applied here rather than trusted to arrive as a
 * max_attempts of 1.
 */
export function itemAcceptsMoreTries(item: ItemRuntime, runtime: AttemptRuntime): boolean {
    if (runtime.submissionMode === 'simultaneous') {
        return false;
    }
    return item.control.maxAttempts !== 1 && item.triesLeft;
}
