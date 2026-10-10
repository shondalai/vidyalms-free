import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import {
    conflictKind,
    ProblemError,
    queryKeys,
    useLesson,
    type CurriculumEnrolment,
    type LessonView,
} from '@/shared/api';
import { ErrorState, PermissionCard, Skeleton, VisuallyHidden, buttonVariants } from '@/shared/components';
import { moveFocus, useAnnounce } from '@/shared/a11y';
import { isBlocked } from '@/shared/availability';
import { useMountConfig } from '@/shared/context';
import { playerStrings } from '../strings';
import { presentationStrings as copy } from '../presentation-strings';
import {
    assignmentStage,
    formatDuration,
    lessonCompletionText,
    neighbours,
    quizRunnerHref,
    type AssignmentStage,
    type FlatLesson,
} from '../lib';
import {
    useHeartbeatEngine,
    useHeartbeatSenders,
    type HeartbeatEngine,
    type HeartbeatSnapshot,
} from '../heartbeat';
import { BlockedPane } from './availability-block';
import { FooterNav } from './footer-nav';
import { LessonTypeChip } from './lesson-type-chip';
import { ProPlaceholder } from './player-states';
import { AssignmentPane } from './panes/assignment-pane';
import { QuizPane } from './panes/quiz-pane';
import { ReadingPane } from './panes/reading-pane';
import { ResourcePane } from './panes/resource-pane';
import { VideoPane } from './panes/video-pane';

/*
 * The content pane (artboard 1a, right side): meta line, heading, the
 * per-type body, and the footer bar. Owns the heartbeat engine for the
 * open lesson and the focus convention: moving between lessons moves
 * focus to the pane heading and announces the new lesson.
 */
export interface LessonPaneProps {
    lessonId: number;
    flat: FlatLesson[];
    courseId: number;
    enrolment: CurriculumEnrolment | null;
    offline: boolean;
    onSelect: (lessonId: number) => void;
    onSuspended: () => void;
    onEngine: (engine: HeartbeatEngine) => void;
    /** Lifts the open assignment's stage so the rail can show awaiting-grade. */
    onAssignmentStage?: (lessonId: number, stage: AssignmentStage) => void;
}

const VIEW_COMPLETION_TYPES = new Set(['reading', 'video', 'resource']);

function freshSnapshot(): HeartbeatSnapshot {
    return { progressPercent: null, positionSeconds: null, completed: false };
}

export function LessonPane({
    lessonId,
    flat,
    courseId,
    enrolment,
    offline,
    onSelect,
    onSuspended,
    onEngine,
    onAssignmentStage,
}: LessonPaneProps) {
    const config = useMountConfig();
    const queryClient = useQueryClient();
    const announce = useAnnounce();
    const lessonQuery = useLesson(lessonId);
    const lesson = lessonQuery.data ?? null;

    const headingRef = useRef<HTMLHeadingElement>(null);
    const focusedLessonRef = useRef<number | null>(null);

    /* The engine's view of pane progress. Monotonic: a pane can only raise
       the percent and set the completion claim, never lower or clear it. */
    const snapshotRef = useRef<HeartbeatSnapshot>(freshSnapshot());
    const [completedNow, setCompletedNow] = useState(false);
    /* The watched fraction of a video lesson, for the footer hint. */
    const [watchedPercent, setWatchedPercent] = useState<number | null>(null);
    useEffect(() => {
        snapshotRef.current = freshSnapshot();
        setCompletedNow(false);
        setWatchedPercent(null);
    }, [lessonId]);

    const { send, sendOnHide } = useHeartbeatSenders();
    const getSnapshot = useCallback(() => snapshotRef.current, []);
    const onCompleted = useCallback(
        (completedLessonId: number) => {
            setCompletedNow(true);
            const entry = flat.find((candidate) => candidate.lesson.id === completedLessonId);
            announce(playerStrings.announce.lessonComplete(entry?.lesson.title ?? ''));
            /* The one moment a heartbeat refetches anything: a confirmed
               completion changes the rail, the summary and possibly the
               course itself. */
            void queryClient.invalidateQueries({ queryKey: queryKeys.curriculum(courseId) });
            void queryClient.invalidateQueries({ queryKey: queryKeys.myProgress(courseId) });
            void queryClient.invalidateQueries({ queryKey: queryKeys.lesson(completedLessonId) });
        },
        [announce, courseId, flat, queryClient],
    );

    const serverCompleted = lesson?.progress?.status === 'completed';
    const engine = useHeartbeatEngine({
        idleTimeoutMs: config.idleTimeoutSeconds * 1000,
        lessonId,
        enrolmentId: enrolment?.id ?? null,
        enabled:
            lesson !== null &&
            lesson.id === lessonId &&
            /* A blocked lesson has no body to spend time in and the server
               refuses its heartbeat, so the engine never starts on one. */
            !isBlocked(lesson.access) &&
            enrolment !== null &&
            (enrolment.status === 'active' || enrolment.status === 'completed'),
        alreadyCompleted: serverCompleted,
        getSnapshot,
        send,
        sendOnHide,
        onCompleted,
        onSuspended,
    });
    const engineRef = useRef(engine);
    engineRef.current = engine;

    /* Lift the live save state into the header's SaveIndicator. */
    useEffect(() => {
        onEngine(engine);
    }, [engine, onEngine]);

    /* Focus convention: entering another lesson focuses its heading. The
       first lesson of the visit keeps the host page's focus where it was. */
    useEffect(() => {
        if (lesson === null || lesson.id !== lessonId) {
            return;
        }
        if (focusedLessonRef.current === null) {
            focusedLessonRef.current = lessonId;
            return;
        }
        if (focusedLessonRef.current !== lessonId) {
            focusedLessonRef.current = lessonId;
            moveFocus(headingRef.current);
            announce(playerStrings.announce.lessonOpened(lesson.title));
        }
    }, [lesson, lessonId, announce]);

    const reportProgress = useCallback((percent: number | null, position: number | null, completed: boolean) => {
        const snapshot = snapshotRef.current;
        if (percent !== null) {
            snapshot.progressPercent = Math.max(snapshot.progressPercent ?? 0, percent);
            /* Monotonic like the snapshot; equal values bail out of React. */
            setWatchedPercent((current) => Math.max(current ?? 0, Math.round(percent)));
        }
        if (position !== null) {
            snapshot.positionSeconds = position;
        }
        if (completed && !snapshot.completed) {
            snapshot.completed = true;
            /* Do not wait out the cadence to confirm a completion. */
            engineRef.current.flushNow();
        }
    }, []);

    const entry = useMemo(() => flat.find((candidate) => candidate.lesson.id === lessonId) ?? null, [flat, lessonId]);
    const { prev, next } = useMemo(() => neighbours(flat, lessonId), [flat, lessonId]);

    /* A 409 whose detail marks the enrolment suspended lifts the
       re-activation state; any other 409 stays a plain pane error, because
       completed enrolments open lessons normally and must never collapse
       into the suspended screen. */
    const suspendedError = lessonQuery.isError && conflictKind(lessonQuery.error) === 'suspended';
    useEffect(() => {
        if (suspendedError) {
            onSuspended();
        }
    }, [suspendedError, onSuspended]);

    /* Lift the assignment lifecycle stage so the rail's awaiting-grade
       state renders for a submitted-but-ungraded assignment. */
    const stage = lesson !== null && lesson.type === 'assignment' ? assignmentStage(lesson) : null;
    useEffect(() => {
        if (stage !== null && lesson !== null) {
            onAssignmentStage?.(lesson.id, stage);
        }
    }, [stage, lesson, onAssignmentStage]);

    if (lessonQuery.isPending) {
        return (
            <div role="status" className="grid content-start gap-3 p-5 md:p-6">
                <VisuallyHidden>{playerStrings.states.loading}</VisuallyHidden>
                <Skeleton className="h-2 w-28" />
                <Skeleton className="h-4 w-[65%]" />
                <Skeleton className="aspect-video rounded" />
            </div>
        );
    }

    if (lessonQuery.isError) {
        if (suspendedError) {
            /* The effect above lifted it; the app swaps the whole panel. */
            return null;
        }
        /* A 401 or 403 is the server's answer to "may this visitor see it",
           typically a guest in a free preview opening a lesson the course
           does not expose. It is not a failed load, so it gets the
           permission card and no retry that could never succeed; the rail
           stays, so the preview lessons are one click away. An expired
           session is lifted to the whole panel by the session tracker and
           is not this case. */
        const refusal = lessonQuery.error instanceof ProblemError ? lessonQuery.error.status : 0;
        if (refusal === 401 || refusal === 403) {
            const permission = playerStrings.states.permission;
            return (
                <div className="p-5 md:p-6">
                    <PermissionCard
                        title={permission.title}
                        body={refusal === 401 ? permission.signInBody : permission.forbiddenBody}
                        action={{ href: config.urls.course, label: permission.viewCourse }}
                    />
                </div>
            );
        }
        return (
            <div className="p-5 md:p-6">
                <ErrorState
                    error={lessonQuery.error}
                    title={playerStrings.states.paneError.title}
                    onRetry={() => void lessonQuery.refetch()}
                />
            </div>
        );
    }

    if (lesson === null) {
        return null;
    }

    const isViewCompletion = VIEW_COMPLETION_TYPES.has(lesson.type);
    const completed = serverCompleted === true || completedNow;
    /* No enrolment: the server served a free preview (or an author's view),
       and it accepts no heartbeat, submission or attempt from this visitor. */
    const previewing = enrolment === null;
    /*
     * Availability is evaluated on read and enforced in the use cases, so
     * this is the presentation of a decision the server already made rather
     * than a second gate. The pane still shows the type, the number and the
     * title: withholding the name is what a HIDDEN item is for, and a
     * blocked item is deliberately not hidden.
     */
    const blocked = isBlocked(lesson.access);
    const duration = formatDuration(lesson.duration_seconds, lesson.type);
    const completionText = lessonCompletionText(lesson.type, lesson.quiz);
    const metaParts = [
        entry !== null ? playerStrings.meta.lesson(entry.moduleNumber, entry.lessonNumber) : null,
        duration,
        completionText,
    ].filter((part): part is string => part !== null);

    let body;
    if (blocked) {
        body = <BlockedPane access={lesson.access} locale={config.locale} />;
    } else if (!lesson.content_available) {
        body = <ProPlaceholder type={lesson.type} />;
    } else {
        switch (lesson.type) {
            case 'reading':
                body = (
                    <ReadingPane
                        lesson={lesson}
                        saveState={engine.offline ? 'offline' : engine.phase}
                        savedAt={engine.savedAt}
                        onRetrySave={engine.retryNow}
                        onProgress={(percent, atEnd) => reportProgress(percent, null, atEnd)}
                    />
                );
                break;
            case 'video':
                body = <VideoPane lesson={lesson} onProgress={reportProgress} />;
                break;
            case 'resource':
                body = (
                    <ResourcePane
                        lesson={lesson}
                        completed={completed}
                        onDownloaded={() => reportProgress(100, null, true)}
                    />
                );
                break;
            case 'assignment':
                body = <AssignmentPane lesson={lesson} previewing={previewing} />;
                break;
            case 'quiz':
                body = <QuizPane lesson={lesson} previewing={previewing} />;
                break;
            default:
                body = (
                    <ProPlaceholder type={lesson.type} />
                );
        }
    }

    return (
        <div className="vy-lesson-surface" data-lesson-type={lesson.type}>
            <div className="vy-lesson-heading">
            <p className="vy-study-eyebrow">{entry !== null && <>{copy.chapter(entry.moduleNumber)} · </>}{copy.lessonPosition(flat.findIndex((item) => item.lesson.id === lessonId) + 1, flat.length)}</p>
            <h3
                ref={headingRef}
                className="vy-lesson-title"
            >
                {lesson.title}
            </h3>
            <div className="vy-lesson-meta">
                <LessonTypeChip type={lesson.type} form="pane" />
                {duration !== null && <span>{duration}</span>}
                <span className="vy-lesson-status" data-complete={completed}>{blocked ? playerStrings.lessonState.blocked : completed ? playerStrings.nav.lessonComplete : enrolment === null ? copy.preview : playerStrings.lessonState.inProgress}</span>
            </div>
            </div>
            <div className="vy-lesson-body">{body}</div>
            {!blocked && <div className="vy-lesson-guidance"><span>{copy.completion}</span><p>{metaParts.join(' · ')}</p></div>}
            <FooterNav
                onPrev={prev !== null ? () => onSelect(prev.lesson.id) : null}
                onNext={next !== null ? () => onSelect(next.lesson.id) : null}
                nextLabel={
                    lesson.type === 'assignment' && !completed
                        ? playerStrings.nav.continueAnyway
                        : playerStrings.nav.continue
                }
                hint={blocked ? null : footerHint(lesson, completed, isViewCompletion, offline, next, watchedPercent, previewing)}
                action={blocked || previewing ? undefined : quizAction(lesson, config, lessonId)}
                nextTitle={next?.lesson.title}
                returnUrl={config.urls.myLearning}
            />
        </div>
    );
}

function footerHint(
    lesson: LessonView,
    completed: boolean,
    isViewCompletion: boolean,
    offline: boolean,
    next: FlatLesson | null,
    watchedPercent: number | null,
    previewing: boolean,
): string | null {
    if (offline && (lesson.type === 'video' || next?.lesson.type === 'video')) {
        return playerStrings.nav.offlineVideoHint;
    }
    if (previewing) {
        /* Every other hint below promises a completion nothing will record. */
        return playerStrings.preview.hint;
    }
    if (completed && isViewCompletion) {
        return playerStrings.nav.lessonComplete;
    }
    if (lesson.type === 'video' && watchedPercent !== null && watchedPercent > 0) {
        /* Artboard 1a: "62% watched, keep going to complete". */
        return playerStrings.video.watchedHint(Math.min(watchedPercent, 100));
    }
    if (lesson.type === 'reading') {
        return playerStrings.reading.completeHint;
    }
    if (lesson.type === 'quiz' && canStartQuiz(lesson)) {
        return lesson.quiz !== null && lesson.quiz.time_limit_seconds !== null
            ? playerStrings.quiz.timerHint
            : null;
    }
    return null;
}

function canStartQuiz(lesson: LessonView): boolean {
    const quiz = lesson.quiz;
    if (quiz === null) {
        return false;
    }
    const open = quiz.last_attempt !== null && quiz.last_attempt.status === 'in_progress';
    return open || quiz.attempts_remaining === null || quiz.attempts_remaining > 0;
}

/**
 * Open the assessment introduction before the learner starts or resumes
 * an attempt. Completed or exhausted quizzes keep Continue in the footer.
 */
function quizAction(lesson: LessonView, config: ReturnType<typeof useMountConfig>, lessonId: number) {
    if (lesson.type !== 'quiz' || !canStartQuiz(lesson) || lesson.progress?.status === 'completed') {
        return undefined;
    }
    const quiz = lesson.quiz;
    if (quiz === null) {
        return undefined;
    }
    return (
        <a className={buttonVariants({})} href={quizRunnerHref(config, lessonId)}>
            {copy.assessmentDetails} <span aria-hidden="true">›</span>
        </a>
    );
}
