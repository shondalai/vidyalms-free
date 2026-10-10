import { Award, Check } from 'lucide-react';
import { ProblemError } from '@vidyalms/api-sdk';
import { Icon, Skeleton, VisuallyHidden, buttonVariants } from '@/shared/components';
import type { Certificate } from '@/shared/api';
import { sharedStrings } from '@/shared/strings';
import { playerStrings } from '../strings';
import { ProgressRing } from './progress-ring';

/*
 * The player's whole-panel states (artboard t3). The host chrome stays
 * unchanged around all of these; each replaces only the app region.
 */

/** 3a: shape-true skeleton of the rail + pane layout, no spinner. */
export function PlayerSkeleton({ narrow }: { narrow: boolean }) {
    return (
        <div role="status" className={narrow ? 'vy-player-skeleton block' : 'vy-player-skeleton grid grid-cols-[290px_1fr]'} data-testid="player-skeleton">
            <VisuallyHidden>{playerStrings.states.loading}</VisuallyHidden>
            {!narrow && (
                <div className="vy-player-skeleton-rail grid content-start gap-2.5 border-r border-border bg-bg p-3.5">
                    <Skeleton className="h-2 w-[70%]" />
                    <Skeleton className="h-3" />
                    <Skeleton className="h-3" />
                    <Skeleton className="h-3 w-[85%]" />
                    <Skeleton className="mt-2 h-2 w-[60%]" />
                    <Skeleton className="h-3" />
                    <Skeleton className="h-3 w-[90%]" />
                </div>
            )}
            <div className="grid content-start gap-3 p-5">
                <Skeleton className="h-2 w-28" />
                <Skeleton className="h-4 w-[65%]" />
                <Skeleton className="aspect-video rounded" />
            </div>
        </div>
    );
}

/**
 * 3b: the course failed to load. Progress is safe on the server; say so,
 * and name the status code the way the artboard does ("error 502") when
 * the failure carried one.
 */
export function CourseError({
    error,
    onRetry,
    myLearningUrl,
}: {
    error?: unknown;
    onRetry: () => void;
    myLearningUrl: string;
}) {
    const code = error instanceof ProblemError && error.status > 0 ? error.status : null;
    return (
        <div role="alert" className="px-8 py-11 text-center">
            <span
                aria-hidden="true"
                className="mb-3 inline-flex h-11 w-11 items-center justify-center rounded-full bg-danger-soft font-display text-lg font-bold text-danger"
            >
                !
            </span>
            <p className="mb-1.5 font-display text-[15px] font-bold text-fg">{playerStrings.states.error.title}</p>
            <p className="mx-auto mb-4 max-w-[40ch] text-[13px] text-fg-2">
                {code !== null ? playerStrings.states.error.bodyWithCode(code) : playerStrings.states.error.body}
            </p>
            <button type="button" onClick={onRetry} className={buttonVariants({ size: 'md' })}>
                {sharedStrings.retry}
            </button>
            <p className="mt-2.5 text-xs">
                <a className="font-semibold text-accent" href={myLearningUrl}>
                    {playerStrings.states.error.backToMyLearning}
                </a>
            </p>
        </div>
    );
}

/** 3d: not enrolled (or signed out). The course page has the options. */
export function NotEnrolled({ courseUrl, myLearningUrl }: { courseUrl: string; myLearningUrl: string }) {
    return (
        <div className="px-8 py-11 text-center">
            <span
                aria-hidden="true"
                className="mb-3 inline-flex h-11 w-11 items-center justify-center rounded-full bg-surface-2 text-lg text-fg-2"
            >
                ◇
            </span>
            <p className="mb-1.5 font-display text-[15px] font-bold text-fg">
                {playerStrings.states.notEnrolled.title}
            </p>
            <p className="mx-auto mb-4 max-w-[42ch] text-[13px] text-fg-2">{playerStrings.states.notEnrolled.body}</p>
            <div className="flex flex-wrap justify-center gap-2">
                <a className={buttonVariants({ variant: 'outline' })} href={myLearningUrl}>
                    {playerStrings.states.notEnrolled.backToMyLearning}
                </a>
                <a className={buttonVariants({})} href={courseUrl}>
                    {playerStrings.states.notEnrolled.viewCourse}
                </a>
            </div>
        </div>
    );
}

/** 3e: suspended enrolment. A re-activation state, never a loss state. */
export interface SuspendedProps {
    courseTitle: string;
    percent: number;
    lessonsDone: number | null;
    lessonsTotal: number | null;
    renewUrl: string;
    courseUrl: string;
}

export function Suspended({ courseTitle, percent, lessonsDone, lessonsTotal, renewUrl, courseUrl }: SuspendedProps) {
    const kept =
        lessonsDone !== null && lessonsTotal !== null
            ? playerStrings.states.suspended.kept(lessonsDone, lessonsTotal)
            : playerStrings.states.suspended.keptNoCounts;
    return (
        <div className="px-8 py-9" data-testid="suspended-state">
            <div className="mb-3.5 flex items-center gap-3.5">
                <ProgressRing percent={percent} label={playerStrings.header.progressLabel} muted />
                <div>
                    <p className="font-display text-[15px] font-bold text-fg">
                        {playerStrings.states.suspended.title}
                    </p>
                    <p className="text-[12.5px] text-fg-2">{courseTitle}</p>
                </div>
            </div>
            <p className="mb-4 rounded border border-border bg-bg px-4 py-3 text-[13px] text-fg">{kept}</p>
            <div className="flex flex-wrap gap-2">
                <a className={buttonVariants({})} href={renewUrl}>
                    {playerStrings.states.suspended.renew}
                </a>
                <a className={buttonVariants({ variant: 'outline' })} href={courseUrl}>
                    {playerStrings.states.suspended.viewCourse}
                </a>
            </div>
        </div>
    );
}

/** 3f: the completion celebration, certificate included when one exists. */
export interface CourseCompleteProps {
    lessonsTotal: number | null;
    certificate: Certificate | null;
    myLearningUrl: string;
    onReview: () => void;
}

export function CourseComplete({ lessonsTotal, certificate, myLearningUrl, onReview }: CourseCompleteProps) {
    return (
        <div className="vy-course-celebration px-8 py-9 text-center" data-testid="course-complete">
            <span
                aria-hidden="true"
                className="mb-3 inline-flex h-[52px] w-[52px] items-center justify-center rounded-full bg-ok"
            >
                <Icon icon={Check} size={26} strokeWidth={3} className="text-surface" />
            </span>
            <p className="mb-1 font-display text-[17px] font-bold text-fg">{playerStrings.states.complete.title}</p>
            {lessonsTotal !== null && (
                <p className="mb-4 text-[13px] text-fg-2">{playerStrings.states.complete.finished(lessonsTotal)}</p>
            )}
            {certificate !== null && (
                <div className="mb-3.5 flex items-center gap-3.5 rounded-lg border border-border p-4 text-left">
                    <span
                        aria-hidden="true"
                        className="inline-flex h-11 w-11 items-center justify-center rounded bg-accent-soft text-accent"
                    >
                        <Icon icon={Award} size={20} />
                    </span>
                    <div className="min-w-0 flex-1">
                        <p className="text-[13.5px] font-semibold text-fg">
                            {playerStrings.states.complete.certificateTitle}
                        </p>
                        <p className="text-[11.5px] text-fg-2">
                            {playerStrings.states.complete.certificateCode(certificate.code)}
                        </p>
                    </div>
                </div>
            )}
            <div className="flex flex-wrap items-center justify-center gap-3">
                <button
                    type="button"
                    onClick={onReview}
                    className={buttonVariants({ variant: 'outline', size: 'sm' })}
                >
                    {playerStrings.states.complete.reviewLessons}
                </button>
                <a className="text-[12.5px] font-semibold text-accent" href={myLearningUrl}>
                    {playerStrings.states.complete.browseMore}
                </a>
            </div>
        </div>
    );
}

/** 3c's banner: reading continues, saving degrades honestly. */
export function OfflineBanner() {
    return (
        <div
            role="alert"
            className="flex flex-wrap gap-x-2.5 gap-y-1 border-b border-border bg-warn-soft px-4 py-[11px] text-[12.5px]"
        >
            <strong className="shrink-0 font-semibold text-warn-strong">{playerStrings.states.offline.title}</strong>
            <span className="text-fg-2">{playerStrings.states.offline.body}</span>
        </div>
    );
}

/** Foundations piece 18: orphaned add-on data, neutral tone, no upsell. */
export function ProPlaceholder({ type }: { type: string }) {
    return (
        <div className="rounded-lg border border-border bg-bg p-5 text-center" data-testid="pro-placeholder">
            <p className="mb-1 text-[13px] font-semibold text-fg">{playerStrings.pro.title(type)}</p>
            <p className="text-xs text-fg-2">{playerStrings.pro.body}</p>
        </div>
    );
}
