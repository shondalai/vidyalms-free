import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { cleanup, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ProblemError } from '@vidyalms/api-sdk';
import type { AttemptView } from '@vidyalms/api-sdk';
import { RUNNER_PANEL_CONTRACT, runnerPanelRegistry } from '@/runner-panels';
import type { CertaintyPanelProps } from '@/runner-panels';
import { AttemptController } from './components/attempt-controller';
import { QuizSummary } from './components/quiz-summary';
import {
    DEFAULT_RUNTIME,
    itemAcceptsMoreTries,
    readAttemptRuntime,
    readItemRuntime,
    readItemSessionControl,
} from './lib/runtime';
import { availabilityOf, availabilityOfProblem, formatMoment } from './lib/availability';
import {
    makeAttemptView,
    makeQuestion,
    makeQuiz,
    renderWithConfig,
    singleChoiceDefinition,
    trueFalseDefinition,
} from './test-utils';

/*
 * Wave 3b in the runner: the frozen policy snapshot, the item session
 * control, the availability window and the two panel slots Pro fills.
 *
 * Every assertion here is about what the runner does with a snapshot it did
 * not compute. Nothing in this suite asks the quiz: an attempt obeys the
 * policy it started under, which is the whole reason the snapshot exists.
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

type Runtime = Record<string, unknown>;

function viewWith(runtime: Runtime, questionExtras: Record<string, unknown> = {}): AttemptView {
    const view = makeAttemptView([
        makeQuestion({ id: 11, type: 'single_choice', position: 1, definition: singleChoiceDefinition }),
        makeQuestion({ id: 12, type: 'true_false', position: 2, definition: trueFalseDefinition }),
    ]);
    (view.attempt as unknown as Record<string, unknown>).runtime = runtime;
    Object.assign(view.questions[0] as unknown as Record<string, unknown>, questionExtras);
    return view;
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
    mocks.view = viewWith({});
    mocks.save.mockReset();
    mocks.save.mockResolvedValue(undefined);
    mocks.submit.mockReset();
    mocks.refetch.mockReset();
    mocks.refetch.mockResolvedValue({ data: undefined });
});

afterEach(() => {
    cleanup();
});

describe('reading the frozen policy snapshot', () => {
    it('answers the documented defaults for a host that sends no snapshot at all', () => {
        expect(readAttemptRuntime(makeAttemptView([]))).toEqual(DEFAULT_RUNTIME);
    });

    it('never lets the grace fall below the sixty second clock skew floor', () => {
        const view = viewWith({ grace_seconds: 5 });
        expect(readAttemptRuntime(view).graceSeconds).toBe(60);
    });

    it('keeps a grace the quiz widened', () => {
        expect(readAttemptRuntime(viewWith({ grace_seconds: 300 })).graceSeconds).toBe(300);
    });

    it('carries the behaviour that was substituted, because the learner is marked under another rule', () => {
        const runtime = readAttemptRuntime(
            viewWith({ behaviour: 'deferred_feedback', substituted_from: 'certainty_based' }),
        );
        expect(runtime.behaviour).toBe('deferred_feedback');
        expect(runtime.substitutedFrom).toBe('certainty_based');
    });

    it('falls back to a value it understands when a mode arrives it does not', () => {
        const runtime = readAttemptRuntime(viewWith({ navigation_mode: 'sideways' }));
        expect(runtime.navigationMode).toBe('nonlinear');
    });

    it('reads a certainty scale as data and refuses one with no levels', () => {
        expect(readAttemptRuntime(viewWith({ certainty: { levels: [], required: true } })).certainty).toBeNull();
        expect(
            readAttemptRuntime(
                viewWith({ certainty: { levels: [{ value: 1, label: 'Unsure' }], required: true } }),
            ).certainty,
        ).toEqual({ levels: [{ value: 1, label: 'Unsure', description: null }], required: true });
    });

    it('narrows the quiz control with the one the slot resolved for an item', () => {
        const quizControl = readItemSessionControl({ max_attempts: 3, allow_skipping: false });
        expect(quizControl.maxAttempts).toBe(3);
        expect(quizControl.allowSkipping).toBe(false);
        /* An omitted member keeps the level above, which is what "the quiz
           carries the defaults, a slot may narrow them" means. */
        expect(readItemSessionControl({ max_attempts: 1 }, quizControl).allowSkipping).toBe(false);
    });

    it('gives one try per item under simultaneous submission whatever the control says', () => {
        const view = viewWith(
            { submission_mode: 'simultaneous', item_session_control: { max_attempts: 4 } },
            { tries_used: 0 },
        );
        const runtime = readAttemptRuntime(view);
        const first = view.questions[0];
        if (first === undefined) {
            throw new Error('The fixture served no questions.');
        }
        expect(itemAcceptsMoreTries(readItemRuntime(first, runtime), runtime)).toBe(false);
    });

    it('stops offering tries once the item has spent them', () => {
        const view = viewWith({ item_session_control: { max_attempts: 2 } }, { tries_used: 2 });
        const runtime = readAttemptRuntime(view);
        const first = view.questions[0];
        if (first === undefined) {
            throw new Error('The fixture served no questions.');
        }
        expect(readItemRuntime(first, runtime).triesLeft).toBe(false);
    });
});

describe('navigation mode', () => {
    it('leaves the palette and the back control alone under free movement', () => {
        renderController();
        expect(screen.getByRole('button', { name: 'Question 1: current' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: '‹ Previous' })).toBeInTheDocument();
    });

    it('states the rule and removes the jump and the back control under linear navigation', () => {
        mocks.view = viewWith({ navigation_mode: 'linear' });
        renderController();

        expect(screen.getByText('This quiz moves forward only.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Question 1: current' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: '‹ Previous' })).not.toBeInTheDocument();
        expect(screen.getByRole('group', { name: 'Question progress (forward only)' })).toBeInTheDocument();
    });

    it('says that nothing is marked until the end under simultaneous submission', () => {
        mocks.view = viewWith({ submission_mode: 'simultaneous' });
        renderController();

        expect(screen.getByText('Everything is marked at the end.')).toBeInTheDocument();
    });
});

describe('item session control', () => {
    it('removes the skip control and refuses to advance while the item is unanswered', async () => {
        const user = userEvent.setup();
        mocks.view = viewWith({ item_session_control: { allow_skipping: false } });
        renderController();

        expect(screen.queryByRole('button', { name: 'Skip for now' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));

        expect(await screen.findByText('Answer this question before you continue.')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', { name: /Question 1 of 2/ }),
        ).toBeInTheDocument();
    });

    it('advances once the question the control insisted on is answered', async () => {
        const user = userEvent.setup();
        mocks.view = viewWith({ item_session_control: { allow_skipping: false } });
        renderController();

        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));

        expect(await screen.findByRole('heading', { name: /Question 2 of 2/ })).toBeInTheDocument();
    });

    it('states the no-skipping rule where the skip control used to be', () => {
        mocks.view = viewWith({ item_session_control: { allow_skipping: false } });
        renderController();

        expect(
            screen.getByText('Every question on this quiz has to be answered before you move on.'),
        ).toBeInTheDocument();
    });

    it('shows which try this is when the control allows more than one', () => {
        mocks.view = viewWith({ item_session_control: { max_attempts: 3 } }, { tries_used: 1 });
        renderController();

        expect(screen.getByText(/Try 2 of 3/)).toBeInTheDocument();
    });
});

describe('the deadline', () => {
    it('submits by itself at the deadline when the quiz says the browser may', async () => {
        mocks.view = viewWith({ auto_submit: true });
        const view = mocks.view as AttemptView;
        view.attempt.started_at = '2026-09-01 10:00:00';
        view.attempt.expires_at = '2026-09-01 10:00:00';
        mocks.submit.mockResolvedValue(view);
        renderController();

        await waitFor(() => expect(mocks.submit).toHaveBeenCalled());
    });

    it('does not submit for the learner when the quiz says the browser may not', async () => {
        mocks.view = viewWith({ auto_submit: false, expiry_policy: 'grace_period' });
        const view = mocks.view as AttemptView;
        view.attempt.expires_at = '2026-09-01 10:00:00';
        renderController();

        expect(await screen.findByText('Time is up.')).toBeInTheDocument();
        expect(mocks.submit).not.toHaveBeenCalled();
    });

    it('warns before the attempt starts that a late submission is not counted', () => {
        mocks.view = viewWith({ expiry_policy: 'must_submit' });
        renderController();

        expect(screen.getByText('Submit before the time limit.')).toBeInTheDocument();
    });

    it('offers a control that hides the countdown while the limit still applies', async () => {
        const user = userEvent.setup();
        mocks.view = viewWith({});
        const view = mocks.view as AttemptView;
        view.attempt.started_at = '2026-09-01 10:00:00';
        view.attempt.expires_at = '2099-09-01 10:00:00';
        renderController();

        const toggle = screen.getByRole('button', { name: 'Hide the countdown' });
        expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await user.click(toggle);

        expect(screen.getByText('Countdown hidden')).toBeInTheDocument();
        expect(screen.queryByRole('timer')).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Show the countdown' })).toHaveAttribute(
            'aria-pressed',
            'true',
        );
    });
});

describe('the certainty slot', () => {
    afterEach(() => {
        cleanup();
    });

    it('draws nothing at all when the behaviour asks for no confidence', () => {
        renderController();
        expect(screen.queryByText(/confidence/i)).not.toBeInTheDocument();
    });

    it('names what is missing and keeps the attempt usable when nothing registered a panel', () => {
        mocks.view = viewWith({
            behaviour: 'certainty_based',
            certainty: { levels: [{ value: 1, label: 'Unsure' }], required: true },
        });
        renderController();

        expect(screen.getAllByText('The confidence scale is unavailable.').length).toBeGreaterThan(0);
        expect(screen.getByRole('radio', { name: 'Level AA' })).toBeInTheDocument();
    });

    it('hands a registered panel the scale, and sends the declared value in the answer envelope', async () => {
        const user = userEvent.setup();
        const registry = runnerPanelRegistry();
        if (!registry.has('certainty')) {
            registry.register('certainty', {
                version: RUNNER_PANEL_CONTRACT,
                Panel: ({ scale, onChange }: CertaintyPanelProps) => (
                    <button type="button" onClick={() => onChange(scale.levels[0]?.value ?? 1)}>
                        Declare confidence
                    </button>
                ),
            });
        }
        mocks.view = viewWith({
            behaviour: 'certainty_based',
            certainty: { levels: [{ value: 3, label: 'Sure' }], required: false },
        });
        renderController();

        await user.click(screen.getByRole('radio', { name: 'Level AA' }));
        await user.click(screen.getAllByRole('button', { name: 'Declare confidence' })[0] as HTMLElement);
        await user.click(screen.getByRole('button', { name: 'Next question ›' }));

        expect(mocks.save).toHaveBeenCalledWith(
            expect.objectContaining({ answers: { '11': { response: 'aa', certainty: 3 } } }),
        );
    });
});

describe('the item feedback slot', () => {
    it('says what is missing when a multi-try behaviour has no panel registered', () => {
        mocks.view = viewWith({
            behaviour: 'interactive_multi_try',
            item_session_control: { max_attempts: 3 },
        });
        renderController();

        expect(screen.getByText('Per question feedback is unavailable.')).toBeInTheDocument();
    });

    it('is not drawn at all for the one try per item every free behaviour gives', () => {
        renderController();
        expect(screen.queryByText('Per question feedback is unavailable.')).not.toBeInTheDocument();
    });
});

describe('availability', () => {
    const now = Date.parse('2026-09-01T12:00:00Z');

    it('is open when no window and no cooldown apply', () => {
        expect(availabilityOf(makeQuiz(), now)).toEqual({ state: 'open', at: null });
    });

    it('is not yet open before the window opens', () => {
        const quiz = { ...makeQuiz(), opens_at: '2026-09-02 09:00:00' };
        expect(availabilityOf(quiz, now)).toEqual({ state: 'not_yet', at: '2026-09-02 09:00:00' });
    });

    it('is closed after the window closes, and says so ahead of any cooldown', () => {
        const quiz = {
            ...makeQuiz(),
            closes_at: '2026-08-30 09:00:00',
            cooldown_until: '2026-09-01 13:00:00',
        };
        expect(availabilityOf(quiz, now).state).toBe('closed');
    });

    it('is a cooldown while one is running', () => {
        const quiz = { ...makeQuiz(), cooldown_until: '2026-09-01 13:00:00' };
        expect(availabilityOf(quiz, now)).toEqual({ state: 'cooldown', at: '2026-09-01 13:00:00' });
    });

    function cooldownRefusal(until: string): ProblemError {
        return new ProblemError({
            type: 'about:blank',
            title: 'Conflict',
            status: 409,
            detail: 'A cooldown is in force.',
            cooldown_until: until,
        } as never);
    }

    it('reads the same answer off the refusal, for the case another tab spent the attempt', () => {
        expect(availabilityOfProblem(cooldownRefusal('2026-09-01 13:00:00'), now)).toEqual({
            state: 'cooldown',
            at: '2026-09-01 13:00:00',
        });
    });

    it('ignores a refusal whose wait has since elapsed, or a learner who waited it out could not start', () => {
        expect(availabilityOfProblem(cooldownRefusal('2026-09-01 11:00:00'), now)).toBeNull();
    });

    it('answers null for a conflict that carried no moment', () => {
        const problem = new ProblemError(
            { type: 'about:blank', title: 'Conflict', status: 409, detail: 'Attempt limit reached.' },
        );
        expect(availabilityOfProblem(problem)).toBeNull();
    });

    it('keeps a malformed moment readable rather than blanking it', () => {
        expect(formatMoment('not a datetime', 'en-GB')).toBe('not a datetime');
    });
});

describe('the pre-attempt panel under a closed window', () => {
    function renderSummary(quiz: Record<string, unknown>, startError: unknown = null) {
        return renderWithConfig(
            <QuizSummary
                quiz={{ ...makeQuiz(), ...quiz } as never}
                onStart={() => {}}
                onResume={() => {}}
                onReviewLast={() => {}}
                startPending={false}
                startError={startError}
            />,
        );
    }

    it('keeps the start control and says when the quiz opens', () => {
        renderSummary({ opens_at: '2099-09-02 09:00:00' });

        expect(screen.getByText('This quiz has not opened yet.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Start attempt/ })).toBeDisabled();
    });

    it('counts down to the end of a cooldown rather than refusing without a moment', () => {
        renderSummary({ cooldown_until: '2099-09-02 09:00:00' });

        expect(screen.getByText('Another attempt is not available yet.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Start attempt/ })).toBeDisabled();
    });

    it('prefers the refusal the server just gave over the summary it loaded with', () => {
        const problem = new ProblemError({
            type: 'about:blank',
            title: 'Conflict',
            status: 409,
            detail: 'A cooldown is in force.',
            cooldown_until: '2099-09-02 09:00:00',
        } as never);
        renderSummary({}, problem);

        expect(screen.getByText('Another attempt is not available yet.')).toBeInTheDocument();
    });
});
