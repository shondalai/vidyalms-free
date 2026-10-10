import { useCallback, useEffect, useRef, useState } from 'react';
import { conflictKind, useAttempt, useSubmitAttempt } from '@/shared/api';
import type { AttemptQuestion, AttemptView, LessonQuizSummary } from '@/shared/api';
import { moveFocus, useAnnounce } from '@/shared/a11y';
import { useMountConfig } from '@/shared/context';
import { Button, Card, ErrorState, SaveIndicator, buttonVariants } from '@/shared/components';
import { quizRunnerStrings as strings } from '../strings';
import { answerKey, isAnswered, type AnswerMap, type UiStateMap } from '../lib/answers';
import {
    readServedScreens,
    screenOfQuestion,
    servedQuestions,
    type Screen,
    type ServedQuestion,
    type Stimulus,
} from '../lib/served';
import { ErrorSummary, type ErrorSummaryGroup, type QuestionError } from '@/question-types';
import { sanitizeHtml } from '@/shared/sanitize';
import { useAutosave } from '../hooks/use-autosave';
import { useOnline } from '../hooks/use-online';
import { useQuizTimer } from '../hooks/use-quiz-timer';
import { QuestionBody } from '../questions';
import { QuizSkeleton } from './quiz-skeleton';
import { SegmentedProgress } from './segmented-progress';
import { TimerPill, TimeWarning } from './quiz-timer';
import { ReviewScreen } from './review-screen';
import { ResultScreen } from './result-screen';
import { runnerRuntimeStrings as runtimeStrings } from '../runtime-strings';
import {
    itemAcceptsMoreTries,
    readAttemptRuntime,
    readItemRuntime,
    type AttemptRuntime,
    type ItemRuntime,
} from '../lib/runtime';
import { CertaintySlot, ItemFeedbackSlot, TimerVisibilityToggle } from './panel-slots';
import { ExpiryNotice, Notice, RuntimeNotices } from './runtime-notices';
import { ArrowLeft, ArrowRight, Check } from 'lucide-react';
import { assessmentCopy as copy } from '../presentation-strings';

const MAX_AUTO_RETRIES = 5;
const RETRY_DELAYS = [8, 12, 16, 20] as const;

type SubmitState =
    | { kind: 'idle' }
    | { kind: 'submitting' }
    | { kind: 'failed'; tries: number; retryIn: number | null }
    | { kind: 'conflict'; detail: string };

function seedAnswers(view: AttemptView): AnswerMap {
    const answers: AnswerMap = {};
    for (const question of view.questions) {
        if (question.answer !== null && question.answer !== undefined) {
            answers[answerKey(question.id)] = question.answer;
        }
    }
    return answers;
}

/** The confidence stored with each saved answer, so a resume shows what was declared. */
function seedCertainty(view: AttemptView): CertaintyMap {
    const declared: CertaintyMap = {};
    for (const question of view.questions) {
        const value = (question as unknown as Record<string, unknown>).certainty;
        if (typeof value === 'number') {
            declared[answerKey(question.id)] = value;
        }
    }
    return declared;
}

type CertaintyMap = Record<string, number | null>;

/**
 * The save body.
 *
 * The wire takes either the bare response (the legacy shape) or an envelope
 * carrying what travels beside it, and the local map holds bare responses
 * because every renderer, the palette and the review step read them. The
 * envelope is therefore built at the boundary rather than stored, so a
 * question with no declared confidence still sends exactly what it always
 * sent and no host has to learn which shape this runner speaks.
 */
function wireAnswers(answers: AnswerMap, declared: CertaintyMap): AnswerMap {
    const body: AnswerMap = {};
    for (const [key, response] of Object.entries(answers)) {
        const certainty = declared[key];
        body[key] = certainty === undefined || certainty === null ? response : { response, certainty };
    }
    return body;
}

function OfflineBanner() {
    return (
        <div
            role="alert"
            className="flex flex-wrap gap-2.5 border-b border-border bg-warn-soft px-4 py-[11px] text-[12.5px] text-fg-2"
        >
            <strong className="shrink-0 font-semibold text-warn-strong">{strings.offline.lostTitle}</strong>
            <span>{strings.offline.lostBody}</span>
        </div>
    );
}

/**
 * A conflict that is neither already-submitted nor time-expired (an
 * exhausted attempt limit racing another tab, an idempotency replay in
 * flight): the runner must not wedge and must not drop the answers in
 * memory. The alert names the server's reason and offers the two honest
 * ways out; the answers stay saved on the attempt.
 */
function ConflictAlert({ detail, onRetry, courseUrl }: { detail: string; onRetry: () => void; courseUrl: string }) {
    return (
        <div
            role="alert"
            className="rounded border border-border border-l-[3px] border-l-warn bg-surface px-4 py-3.5 text-[13px] leading-[1.55] text-fg"
        >
            <strong className="font-semibold text-warn-strong">{strings.conflict.title}</strong> {detail}{' '}
            {strings.conflict.body}
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button size="sm" onClick={onRetry}>
                    {strings.conflict.retry}
                </Button>
                <a href={courseUrl} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                    {strings.result.backToCourse}
                </a>
            </div>
        </div>
    );
}

function SubmitFailedAlert({
    tries,
    retryIn,
    online,
    onRetry,
}: {
    tries: number;
    retryIn: number | null;
    online: boolean;
    onRetry: () => void;
}) {
    return (
        <div
            role="alert"
            className="rounded border border-border border-l-[3px] border-l-danger bg-surface px-4 py-3.5 text-[13px] leading-[1.55] text-fg"
        >
            <strong className="font-semibold text-danger">{strings.submitError.title}</strong>{' '}
            {strings.submitError.body}
            <div className="mt-3 flex flex-wrap items-center gap-2">
                <Button size="sm" onClick={onRetry} disabled={!online}>
                    {online ? strings.submitError.retry : strings.offline.reconnecting}
                </Button>
                <span className="text-[11.5px] text-fg-2">
                    {retryIn === null
                        ? strings.submitError.autoRetryStopped
                        : online
                          ? strings.submitError.autoRetry(retryIn, Math.min(tries + 1, MAX_AUTO_RETRIES), MAX_AUTO_RETRIES)
                          : strings.offline.reconnecting}
                </span>
            </div>
        </div>
    );
}

/**
 * A shared stimulus renders once per screen, beside the item bodies. It is
 * an entity, never a fake zero-point question: a description pseudo-question
 * pollutes item counts, item statistics and the answered progress display
 * and duplicates the passage once per question.
 *
 * The accessibility alternatives (alt text, long description) live inside
 * the stimulus row, so a round trip through another system preserves them.
 */
function StimulusPanel({ stimulus }: { stimulus: Stimulus }) {
    return (
        <section
            aria-label={stimulus.title ?? strings.attempt.stimulus}
            className="mb-5 rounded border border-border bg-bg px-4 py-3.5"
        >
            {stimulus.title !== null && (
                <h4 className="mb-1.5 font-display text-[13.5px] font-semibold">{stimulus.title}</h4>
            )}
            <div
                className="max-w-[68ch] text-[13.5px] leading-[1.65] text-fg"
                dangerouslySetInnerHTML={{ __html: sanitizeHtml(stimulus.body) }}
            />
            {stimulus.longDescription !== null && (
                <details className="mt-2 text-[12.5px] text-fg-2">
                    <summary className="cursor-pointer font-medium">{strings.attempt.longDescription}</summary>
                    <p className="mt-1 max-w-[68ch] leading-[1.6]">{stimulus.longDescription}</p>
                </details>
            )}
        </section>
    );
}

/**
 * One open attempt, artboard section 1: the question stepper with autosave
 * and timer, the review step before the irreversible submit, and the
 * result. The rule of section 3 applies throughout: the attempt is never
 * destroyed by circumstance. Answers buffer while offline, submit retries
 * with the work intact, expiry submits what was saved, and a 409 resolves
 * idempotently into the already-submitted result.
 */
export function AttemptController({
    attemptId,
    quiz,
    onStartNext,
    startNextPending,
    startNextError,
}: {
    attemptId: number;
    quiz: LessonQuizSummary;
    onStartNext: () => void;
    startNextPending: boolean;
    startNextError: unknown;
}) {
    const announce = useAnnounce();
    const online = useOnline();
    const config = useMountConfig();
    const attemptQuery = useAttempt(attemptId);
    const submitMutation = useSubmitAttempt();
    const view = attemptQuery.data;

    const [answers, setAnswers] = useState<AnswerMap | null>(null);
    const [index, setIndex] = useState(0);
    const [phase, setPhase] = useState<'question' | 'review'>('question');
    const [result, setResult] = useState<AttemptView | null>(null);
    const [submitState, setSubmitState] = useState<SubmitState>({ kind: 'idle' });
    const [expired, setExpired] = useState(false);
    /* Opaque per-question UI state, so a half-completed placement survives
       a jump to another screen and back. */
    const [uiState, setUiState] = useState<UiStateMap>({});
    /* Shape errors the mounted modules reported. They are contributed, not
       rendered, by the modules: one summary is built here. */
    const [errorsById, setErrorsById] = useState<Record<string, readonly QuestionError[]>>({});
    const [showErrors, setShowErrors] = useState(false);
    /* The confidence declared per question, where the behaviour asks for one. */
    const [certainty, setCertainty] = useState<CertaintyMap>({});
    /* SC 2.2.2: hiding the display is not removing the limit, and the copy
       beside the control says exactly that. */
    const [timerHidden, setTimerHidden] = useState(false);
    /* The question whose check is in flight, under a multi-try behaviour. */
    const [checkingKey, setCheckingKey] = useState<string | null>(null);
    /* Why the screen refused to advance, when a session control refused it. */
    const [blockedReason, setBlockedReason] = useState<string | null>(null);

    if (view !== undefined && answers === null) {
        setAnswers(seedAnswers(view));
        setCertainty(seedCertainty(view));
    }
    const answersRef = useRef<AnswerMap>({});
    if (answers !== null) {
        answersRef.current = answers;
    }
    const certaintyRef = useRef<CertaintyMap>({});
    certaintyRef.current = certainty;

    /* Read from the attempt, never from the quiz: changing a setting cannot
       move a learner who is already inside an attempt. */
    const runtime: AttemptRuntime = readAttemptRuntime(view);
    const autoSubmitRef = useRef(runtime.autoSubmit);
    autoSubmitRef.current = runtime.autoSubmit;

    const resultRef = useRef<AttemptView | null>(null);
    const submittingRef = useRef(false);
    /* One key for the whole submit operation: retries replay, not repeat. */
    const submitKeyRef = useRef<string | null>(null);
    const expiredRef = useRef(false);

    const refetchRef = useRef(attemptQuery.refetch);
    refetchRef.current = attemptQuery.refetch;

    const finish = useCallback((finalView: AttemptView) => {
        resultRef.current = finalView;
        setResult(finalView);
        setSubmitState({ kind: 'idle' });
    }, []);

    const resolveConflict = useCallback(async () => {
        const { data } = await refetchRef.current();
        if (data !== undefined && data.attempt.status !== 'in_progress') {
            finish(data);
        }
    }, [finish]);

    /* Declared below; the expiry flow and the autosave conflict need it. */
    const doSubmitRef = useRef<() => Promise<void>>(async () => {});

    /* The artboard's expiry flow: announce once, then submit what was
       saved. Also the landing path for a time-expired save conflict, per
       the core policy (saves stop at the deadline, submit stays open). */
    /*
     * The server applies the expiry policy either way. auto_submit only
     * decides whether the browser gets there before the sweeper does, so a
     * quiz that turns it off must not have its attempt submitted from here:
     * the learner is told the time is up and submits themselves.
     */
    const triggerExpiry = useCallback(() => {
        if (expiredRef.current) {
            return;
        }
        expiredRef.current = true;
        setExpired(true);
        announce(
            autoSubmitRef.current ? strings.timer.timeUp : runtimeStrings.expiry.manualSubmitBody,
            'assertive',
        );
        if (autoSubmitRef.current) {
            void doSubmitRef.current();
        }
    }, [announce]);

    const autosave = useAutosave({
        attemptId,
        /* A body the server decided against: the engine has stopped, so the
           learner is told once, assertively, rather than left watching a
           save indicator flicker for the rest of the attempt. */
        onRejected: (error) => {
            announce(error.problem.detail ?? strings.submitError.body, 'assertive');
        },
        onConflict: (error) => {
            switch (conflictKind(error)) {
                case 'time_expired':
                    /* Saving is over; the attempt is still submittable. */
                    triggerExpiry();
                    break;
                case 'already_submitted':
                    void resolveConflict();
                    break;
                default:
                    setSubmitState({
                        kind: 'conflict',
                        detail: error instanceof Error ? error.message : strings.conflict.title,
                    });
                    break;
            }
        },
    });
    const flushRef = useRef(autosave.flush);
    flushRef.current = autosave.flush;

    const submitRef = useRef(submitMutation.mutateAsync);
    submitRef.current = submitMutation.mutateAsync;

    const doSubmit = useCallback(async () => {
        if (submittingRef.current || resultRef.current !== null) {
            return;
        }
        if (!navigator.onLine) {
            setSubmitState((state) =>
                state.kind === 'failed' ? state : { kind: 'failed', tries: 0, retryIn: 0 },
            );
            return;
        }
        submittingRef.current = true;
        setSubmitState({ kind: 'submitting' });
        try {
            try {
                await flushRef.current();
            } catch (error) {
                const kind = conflictKind(error);
                if (kind === 'already_submitted') {
                    await resolveConflict();
                    return;
                }
                if (kind !== null && kind !== 'time_expired') {
                    setSubmitState({
                        kind: 'conflict',
                        detail: error instanceof Error ? error.message : strings.conflict.title,
                    });
                    return;
                }
                /* time_expired: saves are closed but submit still grades
                   what was stored in time; carry on into the submit. */
                if (kind === null) {
                    throw error;
                }
            }
            const submitKey = submitKeyRef.current ?? crypto.randomUUID();
            submitKeyRef.current = submitKey;
            const finalView = await submitRef.current({ attemptId, idempotencyKey: submitKey });
            finish(finalView);
        } catch (error) {
            const kind = conflictKind(error);
            if (kind === 'already_submitted') {
                await resolveConflict();
                return;
            }
            if (kind !== null) {
                setSubmitState({
                    kind: 'conflict',
                    detail: error instanceof Error ? error.message : strings.conflict.title,
                });
                return;
            }
            setSubmitState((state) => {
                const tries = state.kind === 'failed' ? state.tries + 1 : 1;
                const delay = tries < MAX_AUTO_RETRIES ? (RETRY_DELAYS[tries - 1] ?? 20) : null;
                return { kind: 'failed', tries, retryIn: delay };
            });
        } finally {
            submittingRef.current = false;
        }
    }, [attemptId, finish, resolveConflict]);
    doSubmitRef.current = doSubmit;

    /* Auto-retry countdown; a reconnect while waiting also retries. */
    useEffect(() => {
        if (submitState.kind !== 'failed' || submitState.retryIn === null) {
            return;
        }
        if (submitState.retryIn <= 0) {
            if (online) {
                void doSubmit();
            }
            return;
        }
        const timer = window.setTimeout(() => {
            setSubmitState((state) =>
                state.kind === 'failed' && state.retryIn !== null
                    ? { ...state, retryIn: state.retryIn - 1 }
                    : state,
            );
        }, 1000);
        return () => window.clearTimeout(timer);
    }, [submitState, online, doSubmit]);

    const timer = useQuizTimer({
        startedAt: view?.attempt.started_at ?? null,
        /* The SERVER deadline: remaining time derives from it on every
           tick, so a reload or a resume shows what the server enforces. */
        expiresAt: view?.attempt.expires_at ?? null,
        running: result === null && view !== undefined && view.attempt.status === 'in_progress',
        onExpire: triggerExpiry,
    });

    /* A resumed attempt that is already closed goes straight to its result. */
    const resolvedResult =
        result ?? (view !== undefined && view.attempt.status !== 'in_progress' ? view : null);

    const headingRef = useRef<HTMLHeadingElement>(null);
    const questionCount = view?.questions.length ?? 0;
    useEffect(() => {
        if (phase === 'question' && questionCount > 0 && resolvedResult === null) {
            moveFocus(headingRef.current);
        }
    }, [phase, index, questionCount, resolvedResult]);

    if (attemptQuery.isPending) {
        return <QuizSkeleton />;
    }
    if (attemptQuery.isError) {
        return (
            <ErrorState
                error={attemptQuery.error}
                onRetry={() => {
                    void attemptQuery.refetch();
                }}
            />
        );
    }
    if (view === undefined || answers === null) {
        return <QuizSkeleton />;
    }

    if (resolvedResult !== null) {
        return (
            <Card>
                <ResultScreen
                    view={resolvedResult}
                    quiz={quiz}
                    onTryAgain={onStartNext}
                    tryAgainPending={startNextPending}
                    tryAgainError={startNextError}
                    expired={expired}
                />
            </Card>
        );
    }

    /* The stepper iterates screens; the palette, the answered count and the
       points stay per question. */
    const screens: Screen[] = readServedScreens(view);
    const served: ServedQuestion[] = servedQuestions(screens);
    const questions: AttemptQuestion[] = served.map((entry) => entry.question);
    const screen = screens[index] ?? screens[0];
    if (screen === undefined || questions.length === 0) {
        return <ErrorState error={undefined} />;
    }
    const answeredCount = questions.filter((q) => isAnswered(q.type, answers[answerKey(q.id)], q.definition)).length;
    const lastIndex = screens.length - 1;
    const submitting = submitState.kind === 'submitting';
    const firstOnScreen = screen.questions[0];
    /* The control resolved for each served item: the quiz defaults narrowed
       by the section and slot the item was served through. */
    const itemRuntimes = new Map<number, ItemRuntime>(
        served.map((entry) => [entry.question.id, readItemRuntime(entry.question, runtime)] as const),
    );
    /*
     * Linear means the candidate may not return to a screen already left, so
     * the palette and the back control are not merely disabled, they promise
     * nothing: the segments stay as a position indicator and the jump is
     * gone. Under the grace period the deadline has closed saving, so moving
     * between screens can no longer change an answer either.
     */
    const canJump = runtime.navigationMode === 'nonlinear' && !expired;
    const currentFlatIndex =
        firstOnScreen === undefined
            ? 0
            : Math.max(0, served.findIndex((entry) => entry.question.id === firstOnScreen.question.id));

    const setAnswer = (questionId: number, value: unknown) => {
        const key = answerKey(questionId);
        const next = { ...answersRef.current, [key]: value };
        setAnswers(next);
        setBlockedReason(null);
        autosave.markDirty(wireAnswers(next, certaintyRef.current), key);
    };

    /* Declaring a confidence is a change to that answer, because it travels
       in the same envelope, so it saves exactly like one. */
    const setCertaintyFor = (questionId: number, value: number | null) => {
        const key = answerKey(questionId);
        const next = { ...certaintyRef.current, [key]: value };
        certaintyRef.current = next;
        setCertainty(next);
        autosave.markDirty(wireAnswers(answersRef.current, next), key);
    };

    const goTo = (nextIndex: number) => {
        void flushRef.current().catch(() => undefined);
        setBlockedReason(null);
        setIndex(Math.max(0, Math.min(lastIndex, nextIndex)));
        setPhase('question');
    };

    /* The palette and the review step count questions; the stepper counts
       screens, so a jump resolves one into the other. */
    const goToQuestion = (questionIndex: number) => {
        const target = served[Math.max(0, Math.min(served.length - 1, questionIndex))];
        goTo(target === undefined ? 0 : screenOfQuestion(screens, target.question.id));
    };

    /*
     * What the item session control says about leaving this screen.
     *
     * allow_skipping false means the item has to be answered before the
     * candidate moves on, and validate_responses means an invalid one blocks
     * the move as well. Neither ever blocks a SAVE: an invalid response is
     * stored with its validity report, because refusing the save would wedge
     * the whole attempt and lose the learner's work.
     */
    const screenControls = screen.questions.map(
        (entry) => itemRuntimes.get(entry.question.id)?.control ?? runtime.itemSessionControl,
    );
    const screenUnanswered = screen.questions.some(
        (entry) =>
            !isAnswered(entry.question.type, answers[answerKey(entry.question.id)], entry.definition),
    );
    const screenInvalid = screen.questions.some(
        (entry) => (errorsById[answerKey(entry.question.id)] ?? []).length > 0,
    );
    const skippingAllowed = screenControls.every((control) => control.allowSkipping);
    const advanceBlockedBy =
        !skippingAllowed && screenUnanswered
            ? runtimeStrings.session.answerFirst
            : screenControls.some((control) => control.validateResponses) && screenInvalid
              ? runtimeStrings.session.fixFirst
              : null;

    const leaveScreen = (target: number | 'review') => {
        if (advanceBlockedBy !== null) {
            setBlockedReason(advanceBlockedBy);
            setShowErrors(true);
            announce(advanceBlockedBy, 'assertive');
            return;
        }
        if (target === 'review') {
            enterReview();
            return;
        }
        goTo(target);
    };

    /*
     * One try of one item, under a multi-try behaviour.
     *
     * There is no per-item processing route on this contract, so a check is
     * the save the behaviour already grades plus a re-read of the attempt:
     * the server's own tries_used and resolved feedback come back on the
     * question, which is where the panel reads them. A build that later adds
     * a dedicated route changes this function and nothing else.
     */
    const checkItem = (questionId: number) => {
        const key = answerKey(questionId);
        setCheckingKey(key);
        void flushRef.current()
            .then(() => refetchRef.current())
            .then(() => {
                announce(runtimeStrings.session.checked);
            })
            .catch(() => undefined)
            .finally(() => setCheckingKey(null));
    };

    const errorGroups: ErrorSummaryGroup[] = served.flatMap((entry, position) => {
        const errors = errorsById[answerKey(entry.question.id)] ?? [];
        return errors.length === 0
            ? []
            : [{
                  label: strings.attempt.questionOf(position + 1, served.length),
                  onNavigate: () => goToQuestion(position),
                  errors,
              }];
    });

    const errorSummarySlot =
        showErrors && errorGroups.length > 0 ? (
            <div className="mb-3.5">
                <ErrorSummary groups={errorGroups} />
            </div>
        ) : null;

    const enterReview = () => {
        void flushRef.current().catch(() => undefined);
        /* A construct error is not an input error and must never surface
           before submission (SC 3.3.3); the review step is where the
           learner has asked to be told. */
        setShowErrors(true);
        setPhase('review');
    };

    const failureSlot =
        submitState.kind === 'failed' ? (
            <div className="mb-3.5">
                <SubmitFailedAlert
                    tries={submitState.tries}
                    retryIn={submitState.retryIn}
                    online={online}
                    onRetry={() => {
                        void doSubmit();
                    }}
                />
            </div>
        ) : submitState.kind === 'conflict' ? (
            <div className="mb-3.5">
                <ConflictAlert
                    detail={submitState.detail}
                    courseUrl={config.urls.course}
                    onRetry={() => {
                        setSubmitState({ kind: 'idle' });
                        void resolveConflict();
                    }}
                />
            </div>
        ) : null;

    const headerMeta = [strings.attempt.attemptOf(view.attempt.attempt_number, quiz.max_attempts)];
    if (quiz.pass_mark > 0) {
        headerMeta.push(strings.attempt.passMark(quiz.pass_mark));
    }

    return (
        <div className="vy-attempt-workspace">
            {!online && <OfflineBanner />}
            <div className="vy-attempt-topbar flex flex-wrap items-center gap-3.5 border-b border-border px-5 py-3">
                <div className="min-w-0 flex-1">
                    {/* The runner is embedded: the host page owns h1, the
                        mount starts at h2 like the player. */}
                    <h2 className="font-display text-sm font-bold">{quiz.title}</h2>
                    <div className="text-[11.5px] text-fg-2">{headerMeta.join(' · ')}</div>
                </div>
                {!online && autosave.pendingCount > 0 ? (
                    <span className="inline-flex items-center gap-[7px] text-[11.5px] text-warn-strong" role="status">
                        <span aria-hidden="true" className="h-[7px] w-[7px] rounded-full bg-warn" />
                        {strings.offline.waitingToSync(autosave.pendingCount)}
                    </span>
                ) : (
                    <SaveIndicator
                        state={autosave.saveState}
                        savedAt={autosave.savedAt}
                        onRetry={autosave.retryNow}
                    />
                )}
                {timer.remaining !== null && timer.total !== null && (
                    <span className="inline-flex items-center gap-1.5">
                        {timerHidden ? (
                            <span className="text-[11.5px] text-fg-2">{runtimeStrings.timer.hiddenNote}</span>
                        ) : (
                            <TimerPill remaining={timer.remaining} total={timer.total} />
                        )}
                        <TimerVisibilityToggle
                            hidden={timerHidden}
                            onToggle={() => {
                                setTimerHidden((hidden) => !hidden);
                                announce(timerHidden ? runtimeStrings.timer.shown : runtimeStrings.timer.hidden);
                            }}
                        />
                    </span>
                )}
            </div>

            {expired && (
                <div className="border-b border-border px-5 py-2.5">
                    {submitting && runtime.autoSubmit ? (
                        <Notice role="alert" tone="warn" title={strings.timer.timeUp} />
                    ) : (
                        <ExpiryNotice runtime={runtime} />
                    )}
                </div>
            )}

            {phase === 'question' ? (
                <>
                    {timer.warningOpen && (
                        <div className="px-5 pt-3">
                            <TimeWarning
                                onSubmitNow={() => {
                                    timer.dismissWarning();
                                    void doSubmit();
                                }}
                            />
                        </div>
                    )}
                    <div className="vy-attempt-progress-copy"><span>{copy.questionMap}</span><strong>{copy.answered(answeredCount, questions.length)}</strong></div>
                    <SegmentedProgress
                        questions={questions}
                        answers={answers}
                        currentIndex={currentFlatIndex}
                        onJump={goToQuestion}
                        canJump={canJump}
                        label={canJump ? undefined : runtimeStrings.navigation.linearNoJump}
                        className="vy-attempt-palette"
                    />
                    <div className="vy-question-card">
                        {index === 0 && <RuntimeNotices runtime={runtime} className="mb-4" />}
                        {failureSlot}
                        {errorSummarySlot}
                        {blockedReason !== null && (
                            <Notice role="alert" tone="warn" title={blockedReason} className="mb-3.5" />
                        )}
                        {screen.stimulus !== null && <StimulusPanel stimulus={screen.stimulus} />}
                        <h3
                            ref={headingRef}
                            tabIndex={-1}
                            className="vy-question-position mb-2.5 font-display text-xs font-semibold text-fg-2"
                        >
                            {[
                                screen.questions.length === 1
                                    ? strings.attempt.questionOf(currentFlatIndex + 1, questions.length)
                                    : strings.attempt.questionsOf(
                                          currentFlatIndex + 1,
                                          currentFlatIndex + screen.questions.length,
                                          questions.length,
                                      ),
                                strings.attempt.points(
                                    screen.questions.reduce((sum, entry) => sum + entry.question.points, 0),
                                ),
                                strings.attempt.answeredCount(answeredCount),
                            ].join(' · ')}
                        </h3>
                        {screen.questions.map((entry, position) => {
                            const key = answerKey(entry.question.id);
                            const item = itemRuntimes.get(entry.question.id);
                            const control = item?.control ?? runtime.itemSessionControl;
                            const triesUsed = item?.triesUsed ?? 0;
                            /* Simultaneous mode gives one try per item by
                               definition, so a multi-try panel there would
                               offer a check the server will not run. */
                            const multiTry =
                                runtime.submissionMode === 'individual' && control.maxAttempts !== 1;
                            const moreTries = item !== undefined && itemAcceptsMoreTries(item, runtime);
                            return (
                                <div
                                    key={entry.question.id}
                                    className={position > 0 ? 'mt-6 border-t border-border pt-5' : undefined}
                                >
                                    {(screen.questions.length > 1 || multiTry) && (
                                        <div className="mb-2 font-display text-[11px] font-semibold text-fg-2">
                                            {[
                                                strings.attempt.questionOf(
                                                    currentFlatIndex + position + 1,
                                                    questions.length,
                                                ),
                                                strings.attempt.points(entry.question.points),
                                                ...(multiTry
                                                    ? [
                                                          control.maxAttempts === 0
                                                              ? runtimeStrings.session.triesUnlimited(triesUsed)
                                                              : runtimeStrings.session.triesOf(
                                                                    triesUsed,
                                                                    control.maxAttempts,
                                                                ),
                                                      ]
                                                    : []),
                                            ].join(' · ')}
                                        </div>
                                    )}
                                    <QuestionBody
                                        question={{ ...entry.question, definition: entry.definition }}
                                        value={answers[key]}
                                        onChange={(value) => setAnswer(entry.question.id, value)}
                                        uiState={uiState[key] ?? entry.uiState}
                                        onUiState={(next) => setUiState((current) => ({ ...current, [key]: next }))}
                                        onErrors={(errors) =>
                                            setErrorsById((current) =>
                                                (current[key] ?? []).length === 0 && errors.length === 0
                                                    ? current
                                                    : { ...current, [key]: errors },
                                            )
                                        }
                                    />
                                    {runtime.certainty !== null && (
                                        <CertaintySlot
                                            scale={runtime.certainty}
                                            value={certainty[key] ?? null}
                                            onChange={(value) => setCertaintyFor(entry.question.id, value)}
                                            controlId={(local) => `vy-q${entry.question.id}-${local}`}
                                            readOnly={expired}
                                            locale={config.locale}
                                            behaviour={runtime.behaviour}
                                        />
                                    )}
                                    {multiTry && (
                                        <ItemFeedbackSlot
                                            behaviour={runtime.behaviour}
                                            triesUsed={triesUsed}
                                            maxAttempts={control.maxAttempts}
                                            showFeedback={control.showFeedback}
                                            showSolution={control.showSolution}
                                            feedback={entry.question.feedback ?? null}
                                            checking={checkingKey === key}
                                            onCheck={
                                                moreTries && !expired
                                                    ? () => checkItem(entry.question.id)
                                                    : null
                                            }
                                            locale={config.locale}
                                        />
                                    )}
                                </div>
                            );
                        })}
                        <div className="vy-question-footer mt-5 flex flex-wrap items-center gap-2.5 border-t border-border pt-4">
                            {canJump && (
                                <Button variant="outline" disabled={index === 0} onClick={() => goTo(index - 1)}>
                                    <ArrowLeft size={16} aria-hidden="true" />{strings.attempt.previous}
                                </Button>
                            )}
                            {skippingAllowed ? (
                                <Button
                                    variant="ghost"
                                    onClick={() => leaveScreen(index === lastIndex ? 'review' : index + 1)}
                                >
                                    {strings.attempt.skip}
                                </Button>
                            ) : (
                                /* The rule is stated where the control that
                                   would have obeyed it used to be (SC 3.3.2),
                                   rather than only when it refuses. */
                                <span className="text-[11.5px] text-fg-2">
                                    {runtimeStrings.session.skippingOff}
                                </span>
                            )}
                            <span className="flex-1" />
                            <Button
                                onClick={() => leaveScreen(index === lastIndex ? 'review' : index + 1)}
                                disabled={submitting}
                            >
                                {index === lastIndex ? strings.attempt.toReview : strings.attempt.next}
                                <ArrowRight size={16} aria-hidden="true" />
                            </Button>
                        </div>
                        <p className="vy-assessment-save-note"><Check size={14} aria-hidden="true" />{copy.saved}</p>
                    </div>
                </>
            ) : (
                <ReviewScreen
                    quiz={quiz}
                    attemptNumber={view.attempt.attempt_number}
                    questions={questions}
                    answers={answers}
                    remaining={timer.remaining}
                    onJump={goToQuestion}
                    /* allow_review governs whether a candidate may look at
                       an item again, so an item that forbids it keeps its
                       place in the count (unanswered is still unanswered)
                       and loses only the jump. */
                    canJump={(position) =>
                        canJump &&
                        (itemRuntimes.get(served[position]?.question.id ?? -1)?.control.allowReview ?? true)
                    }
                    onKeepAnswering={() => setPhase('question')}
                    onSubmit={() => {
                        void doSubmit();
                    }}
                    submitting={submitting}
                    offline={!online}
                    failureSlot={
                        <>
                            {errorSummarySlot}
                            {failureSlot}
                        </>
                    }
                />
            )}
        </div>
    );
}
