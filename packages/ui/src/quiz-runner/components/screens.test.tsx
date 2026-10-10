import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AttemptView } from '@vidyalms/api-sdk';
import { AttemptController } from './attempt-controller';
import { makeAttemptView, makeQuestion, makeQuiz, renderWithConfig, singleChoiceDefinition } from '../test-utils';

/*
 * The stepper iterates SCREENS, not questions.
 *
 * A reading passage with six questions used to render as six screens, none
 * of which could show the passage, which made the "use a stimulus when the
 * parts can be answered independently" rule unbuildable. One screen, the
 * passage once, the palette and the points still per question.
 */

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

/*
 * Shaped exactly as the wire declares it: the server decides the screens,
 * and both ends of the stimulus reference are integers. A fixture that
 * typed them as strings would pass while every real payload rendered no
 * passage at all.
 */
function stimulusView(): AttemptView {
    const questions = [
        makeQuestion({
            id: 21,
            type: 'single_choice',
            position: 1,
            screen: 0,
            stimulus_id: 7,
            definition: singleChoiceDefinition,
        }),
        makeQuestion({
            id: 22,
            type: 'short_answer',
            position: 2,
            screen: 0,
            stimulus_id: 7,
            definition: { prompt: 'What does the passage claim?' },
        }),
        makeQuestion({
            id: 23,
            type: 'short_answer',
            position: 3,
            screen: 1,
            definition: { prompt: 'A standalone question' },
        }),
    ];
    return makeAttemptView(questions, {}, [
        {
            id: 7,
            title: 'Reading passage',
            body: '<p>Accessibility is a process, not a state.</p>',
            media: null,
            alt_text: null,
            long_description: null,
        },
    ]);
}

function shuffledView(): AttemptView {
    const questions = [
        makeQuestion({
            id: 31,
            type: 'single_choice',
            position: 1,
            definition: singleChoiceDefinition,
            choice_sequence: { options: ['aaa', 'aa', 'a'] },
        }),
    ];
    return makeAttemptView(questions);
}

function renderController() {
    return renderWithConfig(
        <AttemptController
            attemptId={71}
            quiz={makeQuiz()}
            onStartNext={() => {}}
            startNextPending={false}
            startNextError={null}
        />,
    );
}

beforeEach(() => {
    mocks.view = undefined;
    mocks.save.mockReset().mockResolvedValue(undefined);
    mocks.submit.mockReset();
    mocks.refetch.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('shared stimulus screens', () => {
    it('renders the passage once with both of its questions, and counts them separately', async () => {
        mocks.view = stimulusView();
        renderController();

        expect(screen.getAllByText('Accessibility is a process, not a state.')).toHaveLength(1);
        expect(screen.getByRole('radio', { name: 'Level AA' })).toBeInTheDocument();
        expect(screen.getByRole('textbox', { name: /What does the passage claim/ })).toBeInTheDocument();
        expect(screen.getByText(/Questions 1 to 2 of 3/)).toBeInTheDocument();
        /* Three questions in the palette, two screens in the stepper. */
        expect(screen.getAllByRole('button', { name: /^Question \d: /})).toHaveLength(3);
    });

    it('steps to the next screen, which is the standalone question', async () => {
        const user = userEvent.setup();
        mocks.view = stimulusView();
        renderController();
        await user.click(screen.getByRole('button', { name: /Next question/ }));
        await waitFor(() => {
            expect(screen.getByRole('textbox', { name: /A standalone question/ })).toBeInTheDocument();
        });
        expect(screen.queryByText('Accessibility is a process, not a state.')).toBeNull();
        expect(screen.getByText(/Question 3 of 3/)).toBeInTheDocument();
    });
});

describe('the served choice order', () => {
    it('renders the options in the order the learner was actually shown, in the DOM', () => {
        mocks.view = shuffledView();
        renderController();
        /* Shuffle reorders the DOM, never CSS order: a visually correct but
           DOM-unchanged shuffle breaks reading order and tab order while
           looking fine (SC 1.3.2, SC 2.4.3). */
        const labels = screen.getAllByRole('radio').map((radio) => radio.getAttribute('value'));
        expect(labels).toEqual(['aaa', 'aa', 'a']);
    });
});
