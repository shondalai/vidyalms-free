import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ProblemError } from '@vidyalms/api-sdk';
import type { AttemptStarted, LessonView } from '@vidyalms/api-sdk';
import { SessionTracker, SessionTrackerContext } from '@/shared/session';
import { QuizRunnerApp } from './app';
import {
    makeAttemptView,
    makeQuestion,
    makeQuiz,
    renderWithConfig,
    singleChoiceDefinition,
} from './test-utils';

const mocks = vi.hoisted(() => ({
    lesson: undefined as unknown,
    lessonError: null as unknown,
    view: undefined as unknown,
    start: vi.fn(),
    save: vi.fn(async () => {}),
    submit: vi.fn(),
    refetch: vi.fn(),
}));

vi.mock('@/shared/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/shared/api')>();
    return {
        conflictKind: actual.conflictKind,
        useLesson: () => ({
            data: mocks.lesson,
            isPending: mocks.lesson === undefined && mocks.lessonError === null,
            isError: mocks.lessonError !== null,
            error: mocks.lessonError,
            refetch: mocks.refetch,
        }),
        useStartAttempt: () => ({ mutate: mocks.start, isPending: false, error: null }),
        useAttempt: () => ({
            data: mocks.view,
            isPending: mocks.view === undefined,
            isError: false,
            error: null,
            refetch: mocks.refetch,
        }),
        useSubmitAttempt: () => ({ mutateAsync: mocks.submit }),
        useSaveAnswers: () => ({ mutateAsync: mocks.save }),
    };
});

function quizLesson(quiz = makeQuiz()): LessonView {
    return {
        id: 7,
        course_id: 3,
        module_id: 2,
        title: 'Module 2 check',
        slug: 'module-2-check',
        type: 'quiz',
        summary: null,
        duration_seconds: null,
        position: 4,
        free_preview: false,
        access: { state: 'available', reason: null, reason_parameters: {}, opens_at: null, opens_at_timezone: null },
        content: null,
        content_available: true,
        progress: null,
        quiz,
    };
}

beforeEach(() => {
    mocks.lesson = undefined;
    mocks.lessonError = null;
    mocks.view = undefined;
    mocks.start.mockReset();
    mocks.submit.mockReset();
    mocks.save.mockClear();
    mocks.refetch.mockReset();
});

describe('QuizRunnerApp summary', () => {
    it('shows the quiz facts and the start button from the lesson', () => {
        mocks.lesson = quizLesson(
            makeQuiz({
                time_limit_seconds: 1200,
                attempts_used: 1,
                attempts_remaining: 2,
                best_percentage: 60,
                last_attempt: { id: 55, status: 'graded', percentage: 60, passed: false, expires_at: null },
            }),
        );
        renderWithConfig(<QuizRunnerApp />);
        expect(screen.getByRole('heading', { name: 'Module 2 check' })).toBeInTheDocument();
        expect(screen.getByText('questions')).toBeInTheDocument();
        expect(screen.getByText('20 min')).toBeInTheDocument();
        expect(screen.getByText('70%')).toBeInTheDocument();
        expect(screen.getByText('2 of 3')).toBeInTheDocument();
        expect(screen.getByText('Attempt 1')).toBeInTheDocument();
        expect(screen.getByText('Not passed')).toBeInTheDocument();
        expect(screen.getByText('The timer starts when you do')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Start attempt 2 ›' })).toBeInTheDocument();
    });

    it('starts an attempt through the API and enters the runner', async () => {
        const user = userEvent.setup();
        mocks.lesson = quizLesson(makeQuiz({ attempts_used: 0, attempts_remaining: 3 }));
        mocks.view = makeAttemptView([
            makeQuestion({ id: 11, type: 'single_choice', position: 1, definition: singleChoiceDefinition }),
        ]);
        mocks.start.mockImplementation(
            (_vars: { quizId: number }, opts?: { onSuccess?: (started: AttemptStarted) => void }) => {
                opts?.onSuccess?.({
                    id: 71,
                    quiz_id: 21,
                    attempt_number: 1,
                    status: 'in_progress',
                    started_at: '2026-09-01 10:00:00',
                    expires_at: null,
                });
            },
        );
        renderWithConfig(<QuizRunnerApp />);
        await user.click(screen.getByRole('button', { name: 'Start attempt 1 ›' }));
        expect(mocks.start).toHaveBeenCalledWith({ quizId: 21 }, expect.anything());
        expect(
            await screen.findByRole('heading', { name: /Question 1 of 1/ }),
        ).toBeInTheDocument();
    });
});

describe('QuizRunnerApp resume path', () => {
    it('offers resume for an in_progress last attempt and reopens it without a new POST', async () => {
        const user = userEvent.setup();
        mocks.lesson = quizLesson(
            makeQuiz({
                last_attempt: { id: 71, status: 'in_progress', percentage: null, passed: null, expires_at: null },
            }),
        );
        mocks.view = makeAttemptView([
            makeQuestion({
                id: 11,
                type: 'single_choice',
                position: 1,
                definition: singleChoiceDefinition,
                answer: 'aa',
            }),
        ]);
        renderWithConfig(<QuizRunnerApp />);
        expect(
            screen.getByText('An attempt is in progress. The timer continues where it left off.'),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Resume attempt ›' }));
        expect(mocks.start).not.toHaveBeenCalled();
        expect(await screen.findByRole('radio', { name: 'Level AA' })).toBeChecked();
        expect(
            screen.getByRole('heading', { name: 'Question 1 of 1 · 1 point · answered 1' }),
        ).toBeInTheDocument();
    });
});

describe('QuizRunnerApp terminal and error states', () => {
    it('renders the calm no-attempts-left card with the best score standing', async () => {
        const user = userEvent.setup();
        mocks.lesson = quizLesson(
            makeQuiz({
                attempts_used: 3,
                attempts_remaining: 0,
                best_percentage: 60,
                last_attempt: { id: 55, status: 'graded', percentage: 60, passed: false, expires_at: null },
            }),
        );
        mocks.view = makeAttemptView(
            [
                makeQuestion({
                    id: 11,
                    type: 'single_choice',
                    position: 1,
                    definition: singleChoiceDefinition,
                    answer: 'a',
                    points_awarded: 0,
                    feedback: null,
                }),
            ],
            { id: 55, status: 'graded', submitted_at: '2026-08-26 09:00:00', percentage: 60, passed: false },
        );
        renderWithConfig(<QuizRunnerApp />);
        expect(screen.getByRole('heading', { name: 'No attempts remaining' })).toBeInTheDocument();
        expect(screen.getByText(/Your best score, 60%, is recorded/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Start attempt/ })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Review last attempt' }));
        expect(await screen.findByRole('heading', { name: /Not passed/ })).toBeInTheDocument();
    });

    it('renders the permission state for a 403 with a way to the course', () => {
        mocks.lessonError = new ProblemError({ type: 'about:blank', title: 'Forbidden', status: 403 });
        renderWithConfig(<QuizRunnerApp />);
        expect(screen.getByText("This quiz isn't available to you.")).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View the course' })).toHaveAttribute(
            'href',
            '/course/accessibility-basics',
        );
    });

    it('renders the not-a-quiz state for a lesson of another type', () => {
        mocks.lesson = { ...quizLesson(), type: 'video', quiz: null };
        renderWithConfig(<QuizRunnerApp />);
        expect(screen.getByText('No quiz here')).toBeInTheDocument();
    });

    it('refetches the quiz summary when starting answers a 409 attempt-limit conflict', async () => {
        const user = userEvent.setup();
        mocks.lesson = quizLesson(makeQuiz({ attempts_used: 2, attempts_remaining: 1 }));
        mocks.start.mockImplementation(
            (_vars: { quizId: number }, opts?: { onError?: (error: unknown) => void }) => {
                opts?.onError?.(
                    new ProblemError({
                        type: 'about:blank',
                        title: 'Conflict',
                        status: 409,
                        detail: 'The attempt limit of 3 has been reached.',
                    }),
                );
            },
        );
        renderWithConfig(<QuizRunnerApp />);
        await user.click(screen.getByRole('button', { name: 'Start attempt 3 ›' }));
        /* The stale summary refetches so the terminal card can render. */
        expect(mocks.refetch).toHaveBeenCalled();
    });

    it('renders the session-expired state instead of permission or offline copy', () => {
        const tracker = new SessionTracker();
        tracker.observe(200);
        tracker.observe(401);
        mocks.lessonError = new ProblemError({ type: 'about:blank', title: 'Unauthorized', status: 401 });
        renderWithConfig(
            <SessionTrackerContext.Provider value={tracker}>
                <QuizRunnerApp />
            </SessionTrackerContext.Provider>,
        );
        expect(screen.getByText('Your session has expired.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reload and sign in again' })).toBeInTheDocument();
        expect(screen.queryByText("This quiz isn't available to you.")).not.toBeInTheDocument();
    });
});

describe('QuizRunnerApp results withheld', () => {
    it('shows a recorded attempt without scores when show_results is never', () => {
        mocks.lesson = quizLesson(
            makeQuiz({
                show_results: 'never',
                attempts_used: 1,
                attempts_remaining: 2,
                best_percentage: null,
                last_attempt: { id: 55, status: 'graded', percentage: null, passed: null, expires_at: null },
            }),
        );
        renderWithConfig(<QuizRunnerApp />);
        expect(screen.getByText('Recorded')).toBeInTheDocument();
        expect(screen.queryByText('Pending grade')).not.toBeInTheDocument();
        expect(screen.queryByText('0%')).not.toBeInTheDocument();
        expect(
            screen.getByText('This quiz does not reveal scores. Attempts are recorded and count toward completion.'),
        ).toBeInTheDocument();
    });
});
