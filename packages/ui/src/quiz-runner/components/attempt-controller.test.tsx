import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ProblemError } from '@vidyalms/api-sdk';
import type { AttemptView } from '@vidyalms/api-sdk';
import { AttemptController } from './attempt-controller';
import {
    makeAttemptView,
    makeQuestion,
    makeQuiz,
    renderWithConfig,
    singleChoiceDefinition,
    trueFalseDefinition,
} from '../test-utils';

const mocks = vi.hoisted(() => ({
    view: undefined as unknown,
    save: vi.fn<(vars: { attemptId: number; answers: Record<string, unknown> }) => Promise<void>>(),
    submit: vi.fn<(vars: { attemptId: number; idempotencyKey?: string }) => Promise<unknown>>(),
    refetch: vi.fn<() => Promise<{ data: unknown }>>(),
}));

vi.mock('@/shared/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/shared/api')>();
    return {
        conflictKind: actual.conflictKind,
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

function openView(): AttemptView {
    return makeAttemptView([
        makeQuestion({ id: 11, type: 'single_choice', position: 1, definition: singleChoiceDefinition }),
        makeQuestion({ id: 12, type: 'true_false', position: 2, definition: trueFalseDefinition }),
    ]);
}

function gradedView(): AttemptView {
    return makeAttemptView(
        [
            makeQuestion({
                id: 11,
                type: 'single_choice',
                position: 1,
                definition: singleChoiceDefinition,
                answer: 'aa',
                points_awarded: 1,
                feedback: null,
            }),
            makeQuestion({
                id: 12,
                type: 'true_false',
                position: 2,
                definition: trueFalseDefinition,
                answer: false,
                points_awarded: 1,
                feedback: null,
            }),
        ],
        { status: 'graded', submitted_at: '2026-09-01 10:05:00', percentage: 100, passed: true },
    );
}

function renderController(quiz = makeQuiz()) {
    return renderWithConfig(
        <AttemptController
            attemptId={71}
            quiz={quiz}
            onStartNext={() => {}}
            startNextPending={false}
            startNextError={null}
        />,
    );
}

beforeEach(() => {
    mocks.view = openView();
    mocks.save.mockReset();
    mocks.save.mockResolvedValue(undefined);
    mocks.submit.mockReset();
    mocks.refetch.mockReset();
});

describe('AttemptController navigation and autosave', () => {
    it('saves the answers immediately when navigating between questions and moves focus', async () => {
        const user = userEvent.setup();
        renderController();
        expect(
            screen.getByRole('heading', { name: 'Question 1 of 2 · 1 point · answered 0' }),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
        /* The debounce has not elapsed; navigation must not wait for it. */
        expect(mocks.save).not.toHaveBeenCalled();
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));
        expect(mocks.save).toHaveBeenCalledWith(
            expect.objectContaining({ attemptId: 71, answers: { '11': 'aa' } }),
        );
        const heading = await screen.findByRole('heading', {
            name: 'Question 2 of 2 · 1 point · answered 1',
        });
        await waitFor(() => expect(document.activeElement).toBe(heading));
    });

    it('resumes with the saved answers of an open attempt', () => {
        const view = openView();
        const first = view.questions[0];
        if (first !== undefined) {
            first.answer = 'aaa';
        }
        mocks.view = view;
        renderController();
        expect(screen.getByRole('radio', { name: 'Level AAA' })).toBeChecked();
        expect(
            screen.getByRole('heading', { name: 'Question 1 of 2 · 1 point · answered 1' }),
        ).toBeInTheDocument();
    });

    it('shows the result immediately when resuming an already-closed attempt', () => {
        mocks.view = gradedView();
        renderController();
        expect(screen.getByRole('heading', { name: /Passed/ })).toBeInTheDocument();
    });
});

describe('AttemptController review and submit', () => {
    it('surfaces unanswered questions in review and submits to the result view', async () => {
        const user = userEvent.setup();
        mocks.submit.mockResolvedValue(gradedView());
        renderController();
        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));
        await user.click(screen.getByRole('button', { name: 'Review answers ›' }));
        expect(screen.getByRole('heading', { name: 'Review your answers' })).toBeInTheDocument();
        expect(screen.getByText('1 question unanswered.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Go to question 2, skipped' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Submit attempt' }));
        expect(mocks.submit).toHaveBeenCalledWith(
            expect.objectContaining({ attemptId: 71, idempotencyKey: expect.any(String) as string }),
        );
        expect(await screen.findByRole('heading', { name: /Passed/ })).toBeInTheDocument();
    });

    it('resolves a 409 double submit idempotently into the stored result', async () => {
        const user = userEvent.setup();
        mocks.submit.mockRejectedValue(
            new ProblemError({
                type: 'about:blank',
                title: 'Conflict',
                status: 409,
                detail: 'The attempt has already been submitted.',
            }),
        );
        mocks.refetch.mockResolvedValue({ data: gradedView() });
        renderController();
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));
        await user.click(screen.getByRole('button', { name: 'Review answers ›' }));
        await user.click(screen.getByRole('button', { name: 'Submit attempt' }));
        expect(await screen.findByRole('heading', { name: /Passed/ })).toBeInTheDocument();
        expect(screen.queryByText("Couldn't submit.")).not.toBeInTheDocument();
    });

    it('keeps the work and offers retries when submit fails', async () => {
        const user = userEvent.setup();
        mocks.submit.mockRejectedValueOnce(new TypeError('network down')).mockResolvedValue(gradedView());
        renderController();
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));
        await user.click(screen.getByRole('button', { name: 'Review answers ›' }));
        await user.click(screen.getByRole('button', { name: 'Submit attempt' }));
        expect(await screen.findByText("Couldn't submit.")).toBeInTheDocument();
        expect(screen.getByText(/Auto-retry in \ds \(attempt 2 of 5\)/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Retry submit' }));
        expect(await screen.findByRole('heading', { name: /Passed/ })).toBeInTheDocument();
        /* The retry replays the SAME logical submit: one idempotency key. */
        expect(mocks.submit).toHaveBeenCalledTimes(2);
        const keyOf = (call: number) =>
            (mocks.submit.mock.calls[call]?.[0] as { idempotencyKey?: string }).idempotencyKey;
        expect(keyOf(0)).toEqual(expect.any(String));
        expect(keyOf(1)).toBe(keyOf(0));
    });
});

describe('AttemptController conflict kinds', () => {
    it('routes a time-expired save conflict into the expiry flow instead of wedging', async () => {
        const user = userEvent.setup();
        mocks.save.mockRejectedValue(
            new ProblemError({
                type: 'about:blank',
                title: 'Conflict',
                status: 409,
                detail: 'The attempt time expired; answers can no longer be saved.',
            }),
        );
        mocks.submit.mockResolvedValue(gradedView());
        renderController(makeQuiz({ time_limit_seconds: 1200 }));
        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
        /* Navigation flushes the save; the 409 routes into expiry. */
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));
        /* The expiry flow submits what was stored in time. */
        expect(await screen.findByRole('heading', { name: /Passed/ })).toBeInTheDocument();
        expect(mocks.submit).toHaveBeenCalledTimes(1);
        expect(
            screen.getByText('Time ran out. The attempt was submitted with the answers you had saved.'),
        ).toBeInTheDocument();
    });

    it('renders an actionable conflict state for other 409s without dropping answers', async () => {
        const user = userEvent.setup();
        mocks.save.mockRejectedValue(
            new ProblemError({
                type: 'about:blank',
                title: 'Conflict',
                status: 409,
                detail: 'A request with this idempotency key is already in flight.',
            }),
        );
        renderController();
        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));
        expect(await screen.findByText('This attempt hit a conflict.')).toBeInTheDocument();
        expect(
            screen.getByText(/A request with this idempotency key is already in flight/),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reload attempt' })).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to course' })).toBeInTheDocument();
        /* The answer in memory is still there, never silently dropped. */
        await user.click(screen.getByRole('button', { name: '‹ Previous' }));
        expect(screen.getByRole('radio', { name: 'Level AA' })).toBeChecked();
    });
});

describe('AttemptController timer', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-01T10:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    function timedView(expiresAt: string): AttemptView {
        const view = openView();
        view.attempt.expires_at = expiresAt;
        return view;
    }

    it('displays the server deadline and auto-submits with clear messaging when it passes', async () => {
        mocks.view = timedView('2026-09-01 10:00:05');
        mocks.submit.mockResolvedValue(gradedView());
        renderController(makeQuiz({ time_limit_seconds: 1200 }));
        expect(screen.getByRole('timer')).toBeInTheDocument();
        await act(async () => {
            vi.advanceTimersByTime(6000);
        });
        await act(async () => {
            vi.advanceTimersByTime(0);
        });
        expect(mocks.submit).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('heading', { name: /Passed/ })).toBeInTheDocument();
        expect(
            screen.getByText('Time ran out. The attempt was submitted with the answers you had saved.'),
        ).toBeInTheDocument();
    });

    it('a resume shows the remaining time of the server deadline, so a reload changes nothing', () => {
        vi.setSystemTime(new Date('2026-09-01T10:07:19Z'));
        mocks.view = timedView('2026-09-01 10:20:00');
        renderController(makeQuiz({ time_limit_seconds: 1200 }));
        expect(screen.getByRole('timer')).toHaveAccessibleName(
            '12 minutes 41 seconds remaining of 20 minutes',
        );
    });

    it('warns at two minutes with fixed-time messaging and no extension affordance', async () => {
        mocks.view = timedView('2026-09-01 10:02:05');
        mocks.submit.mockResolvedValue(gradedView());
        renderController(makeQuiz({ time_limit_seconds: 125 }));
        await act(async () => {
            vi.advanceTimersByTime(6000);
        });
        /* The shell owns a permanent role=alert channel for validation
           failure and connection loss, so the warning is the alert that
           carries text rather than the only alert on the page. */
        const warning = screen
            .getAllByRole('alert')
            .find((element) => element.textContent?.includes('Two minutes left.'));
        expect(warning).toBeDefined();
        expect(warning).toHaveTextContent('The time limit of this quiz is fixed and cannot be extended');
        expect(screen.queryByRole('button', { name: /Add 10 minutes/ })).not.toBeInTheDocument();
    });

    it('lets the learner submit now from the warning', async () => {
        mocks.view = timedView('2026-09-01 10:02:05');
        mocks.submit.mockResolvedValue(gradedView());
        renderController(makeQuiz({ time_limit_seconds: 125 }));
        await act(async () => {
            vi.advanceTimersByTime(6000);
        });
        fireEvent.click(screen.getByRole('button', { name: 'Submit now' }));
        await act(async () => {
            vi.advanceTimersByTime(0);
        });
        expect(mocks.submit).toHaveBeenCalledTimes(1);
    });
});
