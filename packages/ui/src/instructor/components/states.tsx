import type { ReactNode } from 'react';
import { cn } from '@/shared/cn';
import { ErrorState, Skeleton } from '@/shared/components';
import { instructorStrings as strings } from '../strings';
import { deniedFor } from '../lib/problems';

/*
 * The whole-panel states of artboard 4b: a shape in a circle, a bold claim,
 * one factual sentence, and at most one action. Colour never carries the
 * meaning on its own; the glyph and the wording do.
 */

export type StateTone = 'ok' | 'danger' | 'neutral';

const toneClass: Record<StateTone, string> = {
    ok: 'bg-ok-soft text-ok-strong',
    danger: 'bg-danger-soft text-danger',
    neutral: 'bg-surface-2 text-fg-2',
};

export function PanelState({
    glyph,
    tone = 'neutral',
    title,
    body,
    action,
    className,
    role,
}: {
    glyph: string;
    tone?: StateTone;
    title: string;
    body?: string;
    action?: ReactNode;
    className?: string;
    role?: 'alert' | 'status';
}) {
    return (
        <div
            role={role}
            className={cn('flex items-center gap-3 px-5 py-4 text-[13px] text-fg-2', className)}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full font-bold',
                    toneClass[tone],
                )}
            >
                {glyph}
            </span>
            <span className="min-w-0">
                <strong className="font-semibold text-fg">{title}</strong>{' '}
                {body !== undefined && <span>{body}</span>}
                {action !== undefined && <span className="ml-1.5 inline-flex">{action}</span>}
            </span>
        </div>
    );
}

/** "You don't manage this course": a 403 from a course-scoped route. */
export function PermissionDenied({ title, body }: { title?: string; body?: string }) {
    return (
        <PanelState
            glyph="◇"
            title={title ?? strings.states.denied.title}
            body={body ?? strings.states.denied.body}
            role="status"
        />
    );
}

/**
 * What a write that failed renders, everywhere in the workbench.
 *
 * A save is not a load, so the banner never says "Couldn't load.", and a 403
 * on a course-scoped route is not a failure at all: it is the permission
 * state of artboard 4b, arriving on a write instead of a read. Naming it as
 * such also settles the retry, because retrying a refused write cannot
 * succeed and offering the button says otherwise.
 */
export function SaveError({
    error,
    onRetry,
    deniedTitle,
    className,
}: {
    error: unknown;
    onRetry: () => void;
    /** Replaces the generic refusal where a panel knows the narrower reason. */
    deniedTitle?: string;
    className?: string;
}) {
    const denied = deniedFor(error);

    return (
        <ErrorState
            className={className}
            error={error}
            title={denied ? (deniedTitle ?? strings.states.notSavedDenied) : strings.states.notSaved}
            onRetry={denied ? undefined : onRetry}
        />
    );
}

export function NotFoundState() {
    return <PanelState glyph="◇" title={strings.states.notFound.title} body={strings.states.notFound.body} />;
}

/** A shape-true placeholder of the workbench, not a spinner. */
export function WorkbenchSkeleton({ narrow }: { narrow: boolean }) {
    return (
        <div className="p-5" data-testid="workbench-skeleton">
            <span className="sr-only" role="status">
                {strings.states.loading}
            </span>
            <div className="mb-4 flex items-center gap-3">
                <div className="grid flex-1 gap-2">
                    <Skeleton className="h-4 w-2/5" />
                    <Skeleton className="h-3 w-1/4" />
                </div>
                {!narrow && <Skeleton className="h-9 w-28" />}
            </div>
            <Skeleton className="mb-4 h-8 w-full" />
            <div className="grid gap-3">
                <Skeleton className="h-14 w-full" />
                <Skeleton className="h-14 w-full" />
                <Skeleton className="h-14 w-full" />
            </div>
        </div>
    );
}

/** The rows of a table while it loads. */
export function ListSkeleton({ rows = 4, label }: { rows?: number; label: string }) {
    return (
        <div className="grid gap-2 p-4" data-testid="list-skeleton">
            <span className="sr-only" role="status">
                {label}
            </span>
            {Array.from({ length: rows }, (_, index) => (
                <Skeleton key={index} className="h-9 w-full" />
            ))}
        </div>
    );
}
