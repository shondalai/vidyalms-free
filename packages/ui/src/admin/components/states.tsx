import { translate as uiText } from '@/shared/i18n';
import type { ReactNode } from 'react';
import { ProblemError } from '@vidyalms/api-sdk';
import { cn } from '@/shared/cn';
import { Button, EmptyState, ErrorState } from '@/shared/components';
import { sharedStrings } from '@/shared/strings';
import { adminStrings } from '../strings';

/*
 * The admin states of artboard 2e: fresh install, counts unavailable,
 * permission, and orphaned Pro data. All four are one row shape: a round
 * glyph, a bold claim, a factual sentence. Neutral tone throughout, no buy
 * link and no padlock anywhere (directory-safe, per the Foundations
 * assumptions).
 */

type Tone = 'neutral' | 'warn' | 'danger';

const toneClass: Record<Tone, string> = {
    neutral: 'bg-surface-2 text-fg-2',
    warn: 'bg-warn-soft text-warn-strong',
    danger: 'bg-danger-soft text-danger-strong',
};

export interface AdminNoticeProps {
    title: string;
    /** The factual sentence after the claim; omitted when the claim is all of it. */
    body?: string;
    /** One short glyph or word; decorative, the text carries the meaning. */
    glyph: string;
    tone?: Tone;
    action?: ReactNode;
    /**
     * Live-region role, for a notice that appears in answer to something the
     * reader did. Omitted on notices that are simply part of the screen when
     * it loads, and on the ones whose text is already spoken through
     * announce(), so nothing is read out twice.
     */
    role?: 'status' | 'alert';
    className?: string;
}

export function AdminNotice({ title, body, glyph, tone = 'neutral', action, role, className }: AdminNoticeProps) {
    return (
        <div
            role={role}
            className={cn(
                'flex flex-wrap items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3 text-[13px] text-fg-2',
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn(
                    'inline-flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-full font-display text-[10px] font-bold',
                    toneClass[tone],
                )}
            >
                {glyph}
            </span>
            <p className="min-w-0 flex-1">
                <strong className="font-semibold text-fg">{title}</strong>
                {body === undefined ? null : uiText("vidyalms.ui.503dc0957148d465", " {p0}", { p0: body })}
            </p>
            {action}
        </div>
    );
}

/** 403 on an admin-only route: the caller manages courses and nothing else. */
export function PermissionNotice({ coursesUrl }: { coursesUrl?: string | null }) {
    return (
        <AdminNotice
            glyph="◇"
            title={adminStrings.permission.title}
            body={adminStrings.permission.body}
            action={
                coursesUrl === undefined || coursesUrl === null ? undefined : (
                    <a
                        href={coursesUrl}
                        className="font-display text-xs font-semibold text-accent underline-offset-2 hover:underline"
                    >
                        {adminStrings.permission.toCourses}
                    </a>
                )
            }
        />
    );
}

/** Pro data on a site without Pro: neutral, factual, no upsell. */
export function ProDataNotice({ count, types }: { count: number; types: string[] }) {
    return (
        <AdminNotice
            glyph="PRO"
            title={adminStrings.proData.title(count, types.join(', '))}
            body={adminStrings.proData.body}
        />
    );
}

/** The dashboard figures failed; every list on the screen still works. */
export function CountsUnavailable({ onRetry }: { onRetry: () => void }) {
    return (
        <AdminNotice
            glyph="!"
            tone="danger"
            role="alert"
            title={adminStrings.dashboard.unavailable.title}
            body={adminStrings.dashboard.unavailable.body}
            action={
                <Button variant="outline" size="sm" onClick={onRetry}>
                    {sharedStrings.retry}
                </Button>
            }
        />
    );
}

/** Fresh install: no courses exist at all. */
export function WelcomeState({ action }: { action?: ReactNode }) {
    return (
        <EmptyState
            title={adminStrings.welcome.title}
            description={adminStrings.welcome.body}
            action={action}
            data-testid="admin-welcome"
        />
    );
}

/**
 * What a failed screen query renders. 401 is a signed-out administrator,
 * 403 is the permission state the design draws, and everything else is the
 * shared error banner with a retry.
 */
export function ScreenError({
    error,
    onRetry,
    coursesUrl,
    forbidden,
}: {
    error: unknown;
    onRetry: () => void;
    coursesUrl?: string | null;
    /** What a 403 renders; the manage-only permission state by default. */
    forbidden?: ReactNode;
}) {
    const status = error instanceof ProblemError ? error.status : 0;
    if (status === 403) {
        return forbidden ?? <PermissionNotice coursesUrl={coursesUrl} />;
    }
    if (status === 401) {
        return (
            <AdminNotice
                glyph="◷"
                role="alert"
                title={adminStrings.signIn.title}
                body={adminStrings.signIn.body}
            />
        );
    }
    return <ErrorState error={error} onRetry={onRetry} />;
}
