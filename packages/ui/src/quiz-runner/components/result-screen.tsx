import { useEffect, useRef, useState } from 'react';
import { cn } from '@/shared/cn';
import { Button, ErrorState, VisuallyHidden, buttonVariants } from '@/shared/components';
import type { AttemptQuestion, AttemptView, LessonQuizSummary } from '@/shared/api';
import { useMountConfig } from '@/shared/context';
import { moveFocus } from '@/shared/a11y';
import { quizRunnerStrings as strings } from '../strings';
import { compressQuestionNumbers, formatPoints } from '../lib/answers';
import { questionTypeRegistry, resolveQuestionModule, shapeOfServed } from '@/question-types';
import { formatShortDate } from '../lib/time';

type Tone = 'ok' | 'warn' | 'danger';

function Ring({ pct, tone, size }: { pct: number; tone: Tone; size: number }) {
    const toneVar = { ok: '--_vy-ok', warn: '--_vy-warn', danger: '--_vy-danger' }[tone];
    const inner = size - 14;
    return (
        <span
            aria-hidden="true"
            className="vy-result-ring inline-flex shrink-0 items-center justify-center rounded-full"
            style={{
                width: size,
                height: size,
                background: `conic-gradient(var(${toneVar}) 0 ${pct}%, var(--_vy-surface-2) 0)`,
            }}
        >
            <span
                className="flex items-center justify-center rounded-full bg-surface font-display text-[13px] font-bold"
                style={{ width: inner, height: inner }}
            >
                {pct}%
            </span>
        </span>
    );
}

function promptText(question: AttemptQuestion): string {
    const prompt = question.definition.prompt;
    return typeof prompt === 'string' ? prompt : '';
}

/**
 * The learner's saved answer as plain words for the feedback rows, from the
 * registered module. An unregistered key still reads, through the
 * shape-keyed generic renderer, which is what keeps review and manual
 * grading working when a package is removed.
 */
export function describeAnswer(question: AttemptQuestion, locale = 'en-GB'): string | null {
    const { type, definition, answer } = question;
    if (answer === null || answer === undefined) {
        return null;
    }
    const shape = shapeOfServed(question);
    const resolved = resolveQuestionModule(questionTypeRegistry(), type, definition, shape);

    return resolved.module.describeResponse(answer, definition, {
        locale,
        responseShape: shape === null ? null : { cardinality: shape.cardinality, baseType: shape.baseType },
    });
}

function rowState(question: AttemptQuestion): 'correct' | 'incorrect' | 'partial' | 'pending' {
    const awarded = question.points_awarded;
    if (awarded === null || awarded === undefined) {
        return 'pending';
    }
    if (awarded >= question.points) {
        return 'correct';
    }
    return awarded > 0 ? 'partial' : 'incorrect';
}

function QuestionResultRow({ question, number }: { question: AttemptQuestion; number: number }) {
    const state = rowState(question);
    // The site locale, so a renderer can write its review description in it.
    const answerText = describeAnswer(question, useMountConfig().locale);
    const feedback = question.feedback ?? null;
    const showBody = state !== 'correct' && (answerText !== null || feedback !== null);
    const glyph = { correct: '✓', incorrect: '✕', partial: '◔', pending: '◌' }[state];
    const glyphTone = {
        correct: 'text-ok',
        incorrect: 'text-danger',
        partial: 'text-warn-strong',
        pending: 'text-warn-strong',
    }[state];
    const srState = {
        correct: strings.result.correct,
        incorrect: strings.result.incorrect,
        partial: strings.result.partlyCorrect,
        pending: strings.result.awaitingInstructor,
    }[state];
    return (
        <li
            className={cn(
                'rounded border px-3.5 py-3',
                state === 'incorrect' && 'border-danger bg-danger-soft',
                state === 'partial' && 'border-warn bg-warn-soft',
                (state === 'correct' || state === 'pending') && 'border-border',
            )}
        >
            <div className="flex items-baseline gap-2.5">
                <span className={cn('shrink-0 font-semibold', glyphTone)}>
                    <span aria-hidden="true">{glyph}</span> {strings.result.questionShort(number)}
                    <VisuallyHidden>, {srState}</VisuallyHidden>
                </span>
                <span className="min-w-0 flex-1">{promptText(question)}</span>
                <span className="shrink-0 text-[11.5px] text-fg-2">
                    {state === 'pending'
                        ? strings.result.pendingPts(formatPoints(question.points))
                        : strings.result.rowPts(
                              formatPoints(question.points_awarded ?? 0),
                              formatPoints(question.points),
                          )}
                </span>
            </div>
            {showBody && (
                <div className="mt-2 text-[12.5px] leading-[1.55]">
                    {answerText !== null && (
                        <>
                            <span className="text-fg-2">{strings.result.yourAnswer}</span> {answerText}
                            {'. '}
                        </>
                    )}
                    {feedback !== null && feedback !== '' && (
                        <>
                            <span className="text-fg-2">{strings.result.feedbackLabel}</span> {feedback}
                        </>
                    )}
                </div>
            )}
        </li>
    );
}

/**
 * The post-submit views. Artboard 1d for graded results (pass and fail
 * share the layout; only tone and actions differ), 1c for the partly
 * pending state where a human still has to mark answers and pass or fail
 * is honestly withheld, and a plain confirmation when the quiz never
 * reveals results. Per-question points and feedback render only when the
 * response carries them.
 */
export function ResultScreen({
    view,
    quiz,
    onTryAgain,
    tryAgainPending,
    tryAgainError,
    expired,
}: {
    view: AttemptView;
    quiz: LessonQuizSummary;
    onTryAgain: () => void;
    tryAgainPending: boolean;
    tryAgainError: unknown;
    expired: boolean;
}) {
    const config = useMountConfig();
    const headingRef = useRef<HTMLHeadingElement>(null);
    const [showDetail, setShowDetail] = useState(false);
    useEffect(() => {
        moveFocus(headingRef.current);
    }, []);

    const attempt = view.attempt;
    const questions = [...view.questions].sort((a, b) => a.position - b.position);
    const revealed = quiz.show_results === 'after_submit';
    const pendingManual = attempt.status === 'submitted';
    const totalPoints = questions.reduce((sum, q) => sum + q.points, 0);
    const awardedPoints = questions.reduce((sum, q) => sum + (q.points_awarded ?? 0), 0);
    const date = formatShortDate(attempt.submitted_at, config.locale);

    const expiredBanner = expired ? (
        <p role="status" className="mb-4 rounded border border-border border-l-[3px] border-l-warn px-3.5 py-2.5 text-[12.5px] text-fg-2">
            {strings.timer.expiredNote}
        </p>
    ) : null;

    const backToCourse = (
        <a href={config.urls.course} className={buttonVariants({ variant: 'primary', size: 'md' })}>
            {strings.result.backToCourse}
        </a>
    );

    const tryAgainErrorState =
        tryAgainError !== null && tryAgainError !== undefined ? (
            <ErrorState error={tryAgainError} className="mt-3.5" />
        ) : null;

    /* Partly pending: a human still has to mark at least one answer. */
    if (pendingManual) {
        const pending = questions.filter((q) => q.points_awarded === null || q.points_awarded === undefined);
        const marked = questions.filter((q) => q.points_awarded !== null && q.points_awarded !== undefined);
        const markedPct = totalPoints > 0 ? Math.round((awardedPoints / totalPoints) * 100) : 0;
        const pendingShare =
            totalPoints > 0
                ? Math.round((pending.reduce((sum, q) => sum + q.points, 0) / totalPoints) * 100)
                : 0;
        return (
            <div className="vy-assessment-result">
                {expiredBanner}
                <div className="mb-[18px] text-center">
                    {revealed && <Ring pct={markedPct} tone="warn" size={64} />}
                    <h2 ref={headingRef} tabIndex={-1} className="mt-2.5 font-display text-base font-bold">
                        {revealed ? strings.result.markedSoFar(markedPct) : strings.result.submittedTitle}
                    </h2>
                    <p className="mx-auto mt-1.5 max-w-[44ch] text-[12.5px] text-fg-2">
                        {revealed
                            ? strings.result.pendingBody(pending.length, pendingShare)
                            : strings.result.pendingHiddenBody}
                    </p>
                </div>
                {revealed && (
                    <ul className="grid gap-2 text-[13px]">
                        {marked.length > 0 && (
                            <li className="flex items-center gap-3 rounded border border-border px-3.5 py-[11px]">
                                <span
                                    aria-hidden="true"
                                    className="flex h-[18px] w-[18px] shrink-0 items-center justify-center rounded-full bg-ok text-[10.5px] font-bold text-accent-fg"
                                >
                                    ✓
                                </span>
                                <span className="min-w-0 flex-1">
                                    {strings.result.autoMarkedRow(
                                        compressQuestionNumbers(
                                            marked.map((q) => questions.indexOf(q) + 1),
                                        ),
                                    )}
                                </span>
                                <span className="shrink-0 font-semibold text-ok">
                                    {strings.result.pts(formatPoints(awardedPoints), formatPoints(totalPoints - pending.reduce((s, q) => s + q.points, 0)))}
                                </span>
                            </li>
                        )}
                        {pending.map((question) => (
                            <li
                                key={question.id}
                                className="flex items-center gap-3 rounded border border-warn bg-warn-soft px-3.5 py-[11px]"
                            >
                                <span
                                    aria-hidden="true"
                                    className="box-border h-[18px] w-[18px] shrink-0 rounded-full border-2 border-dashed border-warn"
                                />
                                <span className="min-w-0 flex-1">
                                    {strings.result.awaitingRow(
                                        questions.indexOf(question) + 1,
                                        strings.typeLabels[question.type] ?? question.type,
                                    )}
                                </span>
                                <span className="shrink-0 font-semibold text-warn-strong">
                                    {strings.result.pendingPts(formatPoints(question.points))}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                {revealed && showDetail && (
                    <ul className="mt-3.5 grid gap-2 text-[13px]">
                        {questions.map((question, index) => (
                            <QuestionResultRow key={question.id} question={question} number={index + 1} />
                        ))}
                    </ul>
                )}
                {tryAgainErrorState}
                <div className="mt-[18px] flex flex-wrap justify-center gap-2">
                    {revealed && (
                        <Button variant="outline" onClick={() => setShowDetail((v) => !v)}>
                            {showDetail ? strings.result.hideAnswers : strings.result.reviewAnswers}
                        </Button>
                    )}
                    {backToCourse}
                </div>
            </div>
        );
    }

    /* Graded, results hidden: confirm without inventing numbers. */
    if (!revealed || attempt.percentage === null) {
        return (
            <div className="vy-assessment-result text-center">
                {expiredBanner}
                <h2 ref={headingRef} tabIndex={-1} className="font-display text-base font-bold">
                    {strings.result.submittedTitle}
                </h2>
                <p className="mx-auto mt-1.5 max-w-[44ch] text-[12.5px] text-fg-2">
                    {strings.result.hiddenResultsBody}
                </p>
                {tryAgainErrorState}
                <div className="mt-[18px] flex justify-center">{backToCourse}</div>
            </div>
        );
    }

    /* Graded and revealed: pass or fail against the pass mark. */
    const pct = Math.round(attempt.percentage);
    const passed = attempt.passed ?? quiz.pass_mark === 0;
    const tone: Tone = passed ? 'ok' : 'danger';
    const metaParts = [strings.result.percent(pct)];
    if (quiz.pass_mark > 0) {
        metaParts.push(strings.result.passMark(quiz.pass_mark));
    }
    metaParts.push(strings.result.attemptOf(attempt.attempt_number, quiz.max_attempts));
    if (date !== null) {
        metaParts.push(date);
    }
    const canRetry = !passed && quiz.attempts_remaining !== 0;

    return (
        <div className="vy-assessment-result">
            {expiredBanner}
            <div className="vy-result-hero">
                <Ring pct={pct} tone={tone} size={124} />
                <div className="min-w-0">
                    <h2
                        ref={headingRef}
                        tabIndex={-1}
                        className={cn('font-display text-base font-bold', passed ? 'text-ok' : 'text-danger')}
                    >
                        <span aria-hidden="true">{passed ? '✓' : '✕'}</span>{' '}
                        {passed ? strings.result.passedTitle : strings.result.failedTitle}
                    </h2>
                    <div className="text-[12.5px] text-fg-2">{strings.result.meta(metaParts)}</div>
                </div>
                {canRetry && (
                    <Button className="ml-auto" onClick={onTryAgain} disabled={tryAgainPending}>
                        {strings.result.tryAgain(quiz.attempts_remaining)}
                    </Button>
                )}
            </div>
            <ul className="grid gap-2 text-[13px]">
                {questions.map((question, index) => (
                    <QuestionResultRow key={question.id} question={question} number={index + 1} />
                ))}
            </ul>
            {tryAgainErrorState}
            <div className="mt-[18px] flex flex-wrap justify-center gap-2">
                {passed || quiz.attempts_remaining === 0 ? (
                    backToCourse
                ) : (
                    <a href={config.urls.course} className={buttonVariants({ variant: 'outline', size: 'md' })}>
                        {strings.result.backToCourse}
                    </a>
                )}
            </div>
        </div>
    );
}
