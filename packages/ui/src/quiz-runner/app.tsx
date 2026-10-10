import { useState, type ReactNode } from 'react';
import { ProblemError } from '@vidyalms/api-sdk';
import { conflictKind, useLesson, useStartAttempt } from '@/shared/api';
import { isBlocked, openingReading, reasonText, type AvailabilityAccess } from '@/shared/availability';
import { useMountConfig } from '@/shared/context';
import { useSessionExpired } from '@/shared/session';
import { Card, EmptyState, ErrorState, PermissionCard, SessionExpired, buttonVariants } from '@/shared/components';
import { quizRunnerStrings as strings } from './strings';
import { QuizSkeleton } from './components/quiz-skeleton';
import { QuizSummary } from './components/quiz-summary';
import { AttemptController } from './components/attempt-controller';
import { ArrowLeft, BookOpen } from 'lucide-react';
import { assessmentCopy as copy } from './presentation-strings';

/**
 * A quiz lesson the learner may not enter yet.
 *
 * The same grammar as the player's blocked pane and the suspended state it
 * comes from: state the fact, say what is kept, offer one forward action.
 * Nothing counts down and nothing navigates when the instant passes.
 */
function BlockedState({ access }: { access: AvailabilityAccess }) {
    const config = useMountConfig();
    const opening = openingReading(access.opens_at, access.opens_at_timezone, config.locale);
    return (
        <Card className="px-[22px] py-[18px]">
            <div className="flex items-start gap-3">
                <span
                    aria-hidden="true"
                    className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-2 text-fg-2"
                >
                    ◷
                </span>
                <div className="min-w-0">
                    <p className="font-display text-sm font-bold text-fg">{reasonText(access, config.locale)}</p>
                    {opening !== null && opening.authoritative !== null && opening.timezone !== null && (
                        <p className="mt-0.5 text-[12px] text-fg-2">
                            {strings.availability.authoritative(opening.authoritative, opening.timezone)}
                        </p>
                    )}
                    <p className="mt-1.5 text-[12.5px] text-fg-2">{strings.availability.kept}</p>
                    <p className="mt-3">
                        <a href={config.urls.course} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                            {strings.availability.backToCourse}
                        </a>
                    </p>
                </div>
            </div>
        </Card>
    );
}

/** Artboard 3e, lower half: not enrolled (403) or not signed in (401). */
function PermissionState({ status }: { status: 401 | 403 }) {
    const config = useMountConfig();
    return (
        <PermissionCard
            title={strings.permission.title}
            body={status === 401 ? strings.permission.signInBody : strings.permission.forbiddenBody}
            action={{ href: config.urls.course, label: strings.permission.viewCourse }}
        />
    );
}

/**
 * The quiz runner. Boots from the mount config's lessonId, loads the quiz
 * lesson for the summary panel, and hands an open attempt to the
 * controller. The controller is keyed by attempt id, so "try again" after
 * a result mounts a fresh attempt with clean state.
 */
export function QuizRunnerApp() {
    const config = useMountConfig();
    const sessionExpired = useSessionExpired();
    const lessonQuery = useLesson(config.lessonId);
    const [attemptId, setAttemptId] = useState<number | null>(null);
    const start = useStartAttempt();

    const lesson = lessonQuery.data;
    const quiz = lesson !== undefined && lesson.type === 'quiz' ? lesson.quiz : null;

    const beginAttempt = () => {
        if (quiz === null) {
            return;
        }
        start.mutate(
            { quizId: quiz.id },
            {
                onSuccess: (started) => setAttemptId(started.id),
                onError: (error) => {
                    /* A start conflict (the attempt limit, usually raced
                       from another tab) means the summary is stale: refetch
                       so the terminal no-attempts card can render alongside
                       the server's reason. */
                    if (conflictKind(error) !== null) {
                        void lessonQuery.refetch();
                    }
                },
            },
        );
    };

    const backToCourse = (
        <a href={config.urls.course} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
            {strings.summary.backToCourse}
        </a>
    );

    let content: ReactNode;
    if (sessionExpired) {
        /* An expired host token is neither offline nor a permission
           problem; the shared state says what happened and how to resume. */
        content = (
            <Card>
                <SessionExpired />
            </Card>
        );
    } else if (config.lessonId === null) {
        content = (
            <EmptyState
                title={strings.boot.noLessonTitle}
                description={strings.boot.noLessonBody}
                action={backToCourse}
            />
        );
    } else if (lessonQuery.isPending) {
        content = <QuizSkeleton />;
    } else if (lessonQuery.isError) {
        const error = lessonQuery.error;
        content =
            error instanceof ProblemError && (error.status === 401 || error.status === 403) ? (
                <PermissionState status={error.status} />
            ) : (
                <ErrorState
                    error={error}
                    onRetry={() => {
                        void lessonQuery.refetch();
                    }}
                />
            );
    } else if (quiz === null) {
        content = (
            <EmptyState
                title={strings.boot.notAQuizTitle}
                description={strings.boot.notAQuizBody}
                action={backToCourse}
            />
        );
    } else if (lesson !== undefined && isBlocked(lesson.access) && attemptId === null) {
        /* Availability outranks the summary, and it is checked before an
           attempt is offered rather than after one is refused. An attempt
           already in this session keeps running: the restriction gates
           starting, and the server owns everything after that. */
        content = <BlockedState access={lesson.access} />;
    } else if (attemptId !== null) {
        content = (
            <AttemptController
                key={attemptId}
                attemptId={attemptId}
                quiz={quiz}
                onStartNext={beginAttempt}
                startNextPending={start.isPending}
                startNextError={start.error}
            />
        );
    } else {
        content = (
            <QuizSummary
                quiz={quiz}
                onStart={beginAttempt}
                onResume={() => {
                    if (quiz.last_attempt !== null) {
                        setAttemptId(quiz.last_attempt.id);
                    }
                }}
                onReviewLast={() => {
                    if (quiz.last_attempt !== null) {
                        setAttemptId(quiz.last_attempt.id);
                    }
                }}
                startPending={start.isPending}
                startError={start.error}
            />
        );
    }

    return (
        <section aria-label={strings.region} className="vy-assessment">
            <header className="vy-assessment-topbar">
                <a href={config.urls.player ?? config.urls.course} className="vy-study-back"><ArrowLeft size={17} aria-hidden="true" />{config.urls.player ? copy.backToLesson : strings.summary.backToCourse}</a>
                <span className="vy-assessment-brand"><BookOpen size={18} aria-hidden="true" /><span>{copy.assessment}</span></span>
                {quiz !== null && <strong>{quiz.title}</strong>}
            </header>
            <div className="vy-assessment-main">{content}</div>
        </section>
    );
}
