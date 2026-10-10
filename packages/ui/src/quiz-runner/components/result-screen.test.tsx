import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ResultScreen, describeAnswer } from './result-screen';
import {
    makeAttemptView,
    makeQuestion,
    makeQuiz,
    renderWithConfig,
    shortAnswerDefinition,
    singleChoiceDefinition,
    trueFalseDefinition,
} from '../test-utils';

function renderResult(
    view: ReturnType<typeof makeAttemptView>,
    quiz = makeQuiz(),
    onTryAgain: () => void = () => {},
) {
    return renderWithConfig(
        <ResultScreen
            view={view}
            quiz={quiz}
            onTryAgain={onTryAgain}
            tryAgainPending={false}
            tryAgainError={null}
            expired={false}
        />,
    );
}

describe('ResultScreen, passed', () => {
    it('renders the pass tone, score and per-question points', () => {
        const view = makeAttemptView(
            [
                makeQuestion({
                    id: 1,
                    type: 'single_choice',
                    position: 1,
                    definition: singleChoiceDefinition,
                    answer: 'aa',
                    points_awarded: 1,
                    feedback: null,
                }),
                makeQuestion({
                    id: 2,
                    type: 'true_false',
                    position: 2,
                    definition: trueFalseDefinition,
                    answer: false,
                    points_awarded: 1,
                    feedback: null,
                }),
            ],
            {
                status: 'graded',
                submitted_at: '2026-08-26 09:00:00',
                score: 2,
                max_score: 2,
                percentage: 100,
                passed: true,
            },
        );
        renderResult(view);
        expect(screen.getByRole('heading', { name: /Passed/ })).toBeInTheDocument();
        expect(screen.getByText(/100% · pass mark 70% · attempt 2 of 3/)).toBeInTheDocument();
        expect(screen.getAllByText('1/1')).toHaveLength(2);
        expect(screen.queryByRole('button', { name: /Try again/ })).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Back to course' })).toHaveAttribute(
            'href',
            '/course/accessibility-basics',
        );
    });
});

describe('ResultScreen, not passed', () => {
    it('renders the fail tone, feedback on wrong answers and the retry path', async () => {
        const user = userEvent.setup();
        const onTryAgain = vi.fn();
        const view = makeAttemptView(
            [
                makeQuestion({
                    id: 1,
                    type: 'single_choice',
                    position: 1,
                    definition: singleChoiceDefinition,
                    answer: 'a',
                    points_awarded: 0,
                    feedback: 'Revisit Captions and transcripts, 04:41.',
                }),
                makeQuestion({
                    id: 2,
                    type: 'true_false',
                    position: 2,
                    definition: trueFalseDefinition,
                    answer: false,
                    points_awarded: 1,
                    feedback: null,
                }),
            ],
            {
                status: 'graded',
                submitted_at: '2026-08-26 09:00:00',
                score: 1,
                max_score: 2,
                percentage: 50,
                passed: false,
            },
        );
        renderResult(view, makeQuiz(), onTryAgain);
        expect(screen.getByRole('heading', { name: /Not passed/ })).toBeInTheDocument();
        expect(screen.getByText(/Your answer:/)).toBeInTheDocument();
        expect(screen.getByText(/Level A/)).toBeInTheDocument();
        expect(screen.getByText(/Revisit Captions and transcripts/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Try again (2 left)' }));
        expect(onTryAgain).toHaveBeenCalledTimes(1);
    });

    it('hides the retry button when no attempts remain', () => {
        const view = makeAttemptView(
            [
                makeQuestion({
                    id: 1,
                    type: 'true_false',
                    position: 1,
                    definition: trueFalseDefinition,
                    answer: true,
                    points_awarded: 0,
                    feedback: null,
                }),
            ],
            { status: 'graded', submitted_at: '2026-08-26 09:00:00', percentage: 0, passed: false },
        );
        renderResult(view, makeQuiz({ attempts_remaining: 0, attempts_used: 3 }));
        expect(screen.queryByRole('button', { name: /Try again/ })).not.toBeInTheDocument();
    });
});

describe('ResultScreen, partly pending', () => {
    it('withholds pass or fail and shows marked-so-far with the pending share', () => {
        const view = makeAttemptView(
            [
                makeQuestion({
                    id: 1,
                    type: 'single_choice',
                    position: 1,
                    definition: singleChoiceDefinition,
                    answer: 'aa',
                    points: 9,
                    points_awarded: 7.8,
                    feedback: null,
                }),
                makeQuestion({
                    id: 2,
                    type: 'short_answer',
                    position: 2,
                    definition: shortAnswerDefinition,
                    answer: 'Because focus would be lost.',
                    points: 1,
                    points_awarded: null,
                    feedback: null,
                }),
            ],
            { status: 'submitted', submitted_at: '2026-08-26 09:00:00', percentage: null, passed: null },
        );
        renderResult(view);
        expect(screen.getByRole('heading', { name: 'Marked so far: 78%' })).toBeInTheDocument();
        expect(screen.getByText(/One answer \(10% of the total\) needs your instructor/)).toBeInTheDocument();
        expect(screen.getByText('Q1 · auto-marked')).toBeInTheDocument();
        expect(screen.getByText('Q2 · short answer · awaiting instructor')).toBeInTheDocument();
        expect(screen.getByText('? / 1 pt')).toBeInTheDocument();
        expect(screen.getByText('7.8 / 9 pts')).toBeInTheDocument();
        expect(screen.queryByText(/Not passed/)).not.toBeInTheDocument();
        expect(screen.queryByText(/^Passed/)).not.toBeInTheDocument();
    });

    it('expands the per-question detail on request', async () => {
        const user = userEvent.setup();
        const view = makeAttemptView(
            [
                makeQuestion({
                    id: 2,
                    type: 'short_answer',
                    position: 1,
                    definition: shortAnswerDefinition,
                    answer: 'Because focus would be lost.',
                    points: 1,
                    points_awarded: null,
                    feedback: null,
                }),
            ],
            { status: 'submitted', submitted_at: '2026-08-26 09:00:00', percentage: null, passed: null },
        );
        renderResult(view);
        await user.click(screen.getByRole('button', { name: 'Review answers' }));
        expect(screen.getByText(/Because focus would be lost/)).toBeInTheDocument();
    });
});

describe('ResultScreen, results hidden', () => {
    it('confirms the submission without inventing numbers', () => {
        const view = makeAttemptView(
            [
                makeQuestion({
                    id: 1,
                    type: 'true_false',
                    position: 1,
                    definition: trueFalseDefinition,
                    answer: true,
                }),
            ],
            { status: 'graded', submitted_at: '2026-08-26 09:00:00', percentage: null, passed: null },
        );
        renderResult(view, makeQuiz({ show_results: 'never' }));
        expect(screen.getByRole('heading', { name: 'Attempt submitted' })).toBeInTheDocument();
        expect(
            screen.getByText('This quiz does not show detailed results. Your submission was recorded.'),
        ).toBeInTheDocument();
    });
});

describe('describeAnswer', () => {
    it('turns each wire shape into plain words', () => {
        expect(
            describeAnswer(
                makeQuestion({ id: 1, type: 'single_choice', position: 1, definition: singleChoiceDefinition, answer: 'aa' }),
            ),
        ).toBe('Level AA');
        expect(
            describeAnswer(
                makeQuestion({ id: 2, type: 'true_false', position: 2, definition: trueFalseDefinition, answer: false }),
            ),
        ).toBe('False');
        expect(
            describeAnswer(
                makeQuestion({
                    id: 3,
                    type: 'fill_blank',
                    position: 3,
                    definition: { prompt: 'A [[a]] and [[b]].', blanks: [{ key: 'a' }, { key: 'b' }] },
                    answer: { b: '3', a: '4.5' },
                }),
            ),
        ).toBe('4.5, 3');
        expect(describeAnswer(makeQuestion({ id: 4, type: 'short_answer', position: 4, definition: shortAnswerDefinition, answer: null }))).toBeNull();
    });
});
