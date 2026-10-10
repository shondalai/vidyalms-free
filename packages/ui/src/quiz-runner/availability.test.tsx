import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import type { LessonView } from '@vidyalms/api-sdk';
import { QuizRunnerApp } from './app';
import { makeQuiz, renderWithConfig } from './test-utils';

/*
 * The quiz runner honours a restriction on its own lesson.
 *
 * The server refuses the attempt anyway, so this is not a second gate: it is
 * the difference between saying no before the learner presses the button and
 * saying no afterwards. An invitation the product already knows will be
 * refused is worse than a plain sentence, and the reason and the instant are
 * already in the payload.
 *
 * Availability is checked BEFORE the summary, so a blocked quiz never offers
 * a start; an attempt already open in this session keeps running, because
 * the restriction gates starting and the server owns everything after that.
 */

const mocks = vi.hoisted(() => ({
    lesson: undefined as unknown,
    start: vi.fn(),
    refetch: vi.fn(),
}));

vi.mock('@/shared/api', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/shared/api')>();
    return {
        conflictKind: actual.conflictKind,
        useLesson: () => ({
            data: mocks.lesson,
            isPending: mocks.lesson === undefined,
            isError: false,
            error: null,
            refetch: mocks.refetch,
        }),
        useStartAttempt: () => ({ mutate: mocks.start, isPending: false, error: null }),
        useAttempt: () => ({ data: undefined, isPending: true, isError: false, error: null, refetch: mocks.refetch }),
        useSubmitAttempt: () => ({ mutateAsync: vi.fn() }),
        useSaveAnswers: () => ({ mutateAsync: vi.fn() }),
    };
});

function quizLesson(access: LessonView['access']): LessonView {
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
        access,
        content: null,
        content_available: true,
        progress: null,
        quiz: makeQuiz({ attempts_used: 0, attempts_remaining: 3 }),
    };
}

beforeEach(() => {
    mocks.lesson = undefined;
    mocks.start.mockReset();
    mocks.refetch.mockReset();
});

describe('a blocked quiz lesson', () => {
    it('states the reason and the date instead of offering a start', () => {
        mocks.lesson = quizLesson({
            state: 'blocked',
            reason: 'availability.date.from',
            reason_parameters: { from: '2099-03-03 09:00:00', tz: 'UTC' },
            opens_at: '2099-03-03 09:00:00',
            opens_at_timezone: 'UTC',
        });
        renderWithConfig(<QuizRunnerApp />);

        expect(screen.getByText(/Available on 3 March/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Start attempt/ })).not.toBeInTheDocument();
        expect(mocks.start).not.toHaveBeenCalled();
    });

    it('says what is kept and offers one forward action, as the suspended state does', () => {
        mocks.lesson = quizLesson({
            state: 'blocked',
            reason: 'availability.completion.required',
            reason_parameters: { title: 'Captions and transcripts', state: 'complete' },
            opens_at: null,
            opens_at_timezone: null,
        });
        renderWithConfig(<QuizRunnerApp />);

        expect(screen.getByText('Available once you complete Captions and transcripts')).toBeInTheDocument();
        expect(screen.getByText(/Your previous attempts and scores are saved/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View the course' })).toHaveAttribute(
            'href',
            '/course/accessibility-basics',
        );
    });

    it('offers the start normally when nothing is stored against the lesson', () => {
        mocks.lesson = quizLesson({
            state: 'available',
            reason: null,
            reason_parameters: {},
            opens_at: null,
            opens_at_timezone: null,
        });
        renderWithConfig(<QuizRunnerApp />);
        expect(screen.getByRole('button', { name: /Start attempt/ })).toBeInTheDocument();
    });
});
