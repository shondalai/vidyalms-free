import { translate as uiText } from '@/shared/i18n';
import { useMemo, useState } from 'react';
import { Badge, Button, Card, ErrorState, buttonVariants } from '@/shared/components';
import type { LessonQuizSummary } from '@/shared/api';
import { useMountConfig } from '@/shared/context';
import { quizRunnerStrings as strings } from '../strings';
import { runnerRuntimeStrings as runtimeStrings } from '../runtime-strings';
import { formatLimitShort } from '../lib/time';
import { availabilityOf, availabilityOfProblem, formatMoment, type Availability } from '../lib/availability';
import { CooldownNotice, Notice } from './runtime-notices';
import { ArrowRight, ClipboardCheck } from 'lucide-react';
import { assessmentCopy as copy } from '../presentation-strings';

function StatTile({ value, label }: { value: string; label: string }) {
    return (
        <div className="vy-assessment-fact">
            <div className="font-display text-base font-bold">{value}</div>
            <div className="text-[11px] text-fg-2">{label}</div>
        </div>
    );
}

function LastAttemptTable({ quiz }: { quiz: LessonQuizSummary }) {
    const last = quiz.last_attempt;
    if (last === null || last.status === 'in_progress' || quiz.attempts_used === 0) {
        return null;
    }
    const resultsHidden = quiz.show_results === 'never';
    const result = resultsHidden ? (
        /* show_results "never": the contract withholds percentage and
           passed. The row records the attempt without inventing a score
           or a pending-grade promise that will never resolve here. */
        <Badge variant="neutral">{strings.summary.recorded}</Badge>
    ) : last.status === 'submitted' ? (
        <Badge variant="warn">{strings.summary.pendingGrade}</Badge>
    ) : last.passed === true ? (
        <Badge variant="ok">{strings.summary.passed}</Badge>
    ) : last.passed === false ? (
        <Badge variant="danger">{strings.summary.notPassed}</Badge>
    ) : (
        <Badge variant="neutral">{strings.summary.pendingGrade}</Badge>
    );
    const showBest =
        quiz.best_percentage !== null &&
        (last.percentage === null || quiz.best_percentage !== last.percentage);
    return (
        <div className="vy-last-attempt mt-3.5">
            <div className="overflow-hidden rounded border border-border text-[12.5px]">
                <div className="grid grid-cols-[1fr_90px_110px] items-center gap-2 bg-surface-2 px-3 py-[7px] font-display text-[10.5px] font-semibold uppercase tracking-wide text-fg-2">
                    <span>{strings.summary.previousAttempts}</span>
                    <span>{strings.summary.colScore}</span>
                    <span>{strings.summary.colResult}</span>
                </div>
                <div className="grid grid-cols-[1fr_90px_110px] items-center gap-2 px-3 py-[9px]">
                    <span>{strings.summary.attemptRow(quiz.attempts_used)}</span>
                    <span>{last.percentage !== null ? uiText("vidyalms.ui.d4bdf581de91640f", "{p0}%", { p0: last.percentage }) : strings.summary.scoreWithheld}</span>
                    <span>{result}</span>
                </div>
            </div>
            {resultsHidden && <div className="mt-1.5 text-xs text-fg-2">{strings.summary.resultsHidden}</div>}
            {showBest && quiz.best_percentage !== null && (
                <div className="mt-1.5 text-xs text-fg-2">{strings.summary.bestScore(quiz.best_percentage)}</div>
            )}
        </div>
    );
}

/**
 * Why the start control is refusing, with the moment it stops refusing.
 *
 * A cooldown gets a live countdown because it is usually minutes away; an
 * availability window gets a date and a time because it is usually not.
 */
function AvailabilityPanel({
    availability,
    locale,
    onElapsed,
}: {
    availability: Availability;
    locale: string;
    onElapsed: () => void;
}) {
    const at = availability.at;
    if (at === null) {
        return null;
    }
    if (availability.state === 'cooldown') {
        return (
            <CooldownNotice
                until={at}
                formatWhen={(value) => formatMoment(value, locale)}
                onElapsed={onElapsed}
            />
        );
    }
    return (
        <Notice
            role="status"
            tone="warn"
            title={
                availability.state === 'closed'
                    ? runtimeStrings.availability.closedTitle
                    : runtimeStrings.availability.notOpenTitle
            }
            body={
                availability.state === 'closed'
                    ? runtimeStrings.availability.closedAt(formatMoment(at, locale))
                    : runtimeStrings.availability.opensAt(formatMoment(at, locale))
            }
        />
    );
}

/**
 * The pre-attempt panel, player artboard 2d: what the quiz asks of the
 * learner before any timer starts, with Start or Resume. When every
 * attempt is used and none is open, the calm terminal card of artboard 3c
 * renders instead; the score stands and the instructor can grant more.
 */
export function QuizSummary({
    quiz,
    onStart,
    onResume,
    onReviewLast,
    startPending,
    startError,
}: {
    quiz: LessonQuizSummary;
    onStart: () => void;
    onResume: () => void;
    onReviewLast: () => void;
    startPending: boolean;
    startError: unknown;
}) {
    const config = useMountConfig();
    /* The countdown ticks inside its own panel, so the moment it reaches
       zero has to reach this component too or the start control stays shut
       on a wait that is over. */
    const [elapsed, setElapsed] = useState(0);
    const open = quiz.last_attempt !== null && quiz.last_attempt.status === 'in_progress';
    const exhausted = quiz.attempts_remaining === 0 && !open;
    /* A refusal the server just gave beats the summary this screen loaded
       with, because another tab can have spent the attempt since. */
    const availability: Availability = useMemo(
        () => availabilityOfProblem(startError) ?? availabilityOf(quiz),
        // The tick is the dependency: nothing else changes when a wait ends.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [startError, quiz, elapsed],
    );
    const blocked = !open && availability.state !== 'open';

    if (exhausted) {
        return (
            <Card className="vy-assessment-summary vy-assessment-terminal">
                <h2 className="mb-1.5 font-display text-[15px] font-bold">{strings.noAttempts.title}</h2>
                <p className="mx-auto mb-3.5 max-w-[44ch] text-[13px] text-fg-2">
                    {quiz.max_attempts !== null && quiz.best_percentage !== null
                        ? strings.noAttempts.bodyWithBest(quiz.max_attempts, quiz.best_percentage)
                        : strings.noAttempts.body(quiz.max_attempts ?? quiz.attempts_used)}
                </p>
                <div className="flex flex-wrap justify-center gap-2">
                    {quiz.last_attempt !== null && (
                        <Button variant="outline" onClick={onReviewLast}>
                            {strings.noAttempts.reviewLast}
                        </Button>
                    )}
                    <a href={config.urls.course} className={buttonVariants({ variant: 'primary', size: 'md' })}>
                        {strings.summary.backToCourse}
                    </a>
                </div>
            </Card>
        );
    }

    return (
        <Card className="vy-assessment-summary">
            <div className="vy-assessment-welcome"><span className="vy-activity-icon"><ClipboardCheck size={30} aria-hidden="true" /></span><span className="vy-study-eyebrow">{copy.beforeYouBegin}</span></div>
            <div className="flex flex-wrap items-baseline gap-2.5">
                <Badge variant="accent" glyph={null} className="rounded font-mono text-[10px] uppercase">
                    {strings.summary.chip}
                </Badge>
                <span className="text-[11.5px] text-fg-2">
                    {quiz.pass_mark > 0
                        ? strings.summary.completesOnPass(quiz.pass_mark)
                        : strings.summary.completesOnSubmit}
                </span>
            </div>
            <h2 className="vy-assessment-title">{quiz.title}</h2>
            <p className="vy-assessment-lead">{copy.description}</p>
            {quiz.description !== null && quiz.description !== '' && (
                <p className="mb-3 max-w-[70ch] text-[13px] text-fg-2">{quiz.description}</p>
            )}
            <div className="vy-assessment-facts">
                <StatTile value={String(quiz.question_count)} label={strings.summary.statQuestions} />
                <StatTile
                    value={quiz.time_limit_seconds !== null ? formatLimitShort(quiz.time_limit_seconds) : strings.summary.none}
                    label={strings.summary.statTimeLimit}
                />
                <StatTile
                    value={quiz.pass_mark > 0 ? `${quiz.pass_mark}%` : strings.summary.none}
                    label={strings.summary.statPassMark}
                />
                <StatTile
                    value={
                        quiz.max_attempts === null || quiz.attempts_remaining === null
                            ? strings.summary.unlimited
                            : strings.summary.attemptsOf(quiz.attempts_remaining, quiz.max_attempts)
                    }
                    label={strings.summary.statAttemptsLeft}
                />
            </div>
            <LastAttemptTable quiz={quiz} />
            {blocked && availability.at !== null && (
                <div className="mt-3.5">
                    <AvailabilityPanel
                        availability={availability}
                        locale={config.locale}
                        onElapsed={() => setElapsed((tick) => tick + 1)}
                    />
                </div>
            )}
            {/* A refusal that carried its own countdown is already answered
                above; repeating it as a raw problem would say the same thing
                twice, once helpfully and once not. */}
            {startError !== null && startError !== undefined && !blocked && (
                <ErrorState error={startError} className="mt-3.5" />
            )}
            <div className="vy-assessment-actions">
                <a
                    href={config.urls.course}
                    className={buttonVariants({ variant: 'outline', size: 'md' })}
                >
                    {strings.summary.backToCourse}
                </a>
                <span className="flex-1" />
                <span className="text-xs text-fg-2">
                    {open
                        ? strings.summary.resumeHint
                        : quiz.time_limit_seconds !== null
                          ? strings.summary.timerStartsWhenYouDo
                          : null}
                </span>
                {open ? (
                    <Button onClick={onResume}>{strings.summary.resume}<ArrowRight size={16} aria-hidden="true" /></Button>
                ) : (
                    /* Disabled rather than hidden: the control is what the
                       learner came for, and a screen that removes it says
                       less than one that keeps it and states why. */
                    <Button onClick={onStart} disabled={startPending || blocked}>
                        {strings.summary.start(quiz.attempts_used + 1)}
                        <ArrowRight size={16} aria-hidden="true" />
                    </Button>
                )}
            </div>
        </Card>
    );
}
