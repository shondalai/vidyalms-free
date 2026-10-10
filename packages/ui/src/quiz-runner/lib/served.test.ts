import { describe, expect, it } from 'vitest';
import type { AttemptView } from '@/shared/api';
import { applyChoiceSequence, readServedScreens, screenOfQuestion, servedQuestions } from './served';
import { makeAttemptView, makeLegacyQuestion, makeQuestion } from '../test-utils';
import { singleChoiceDefinition } from '@/question-types/free/fixtures';

/*
 * The served list. An attempt is graded from what the learner was actually
 * shown, so the runner reads the served positions, screens, stimuli and
 * option order rather than re-deriving any of them.
 *
 * openapi.yaml requires those fields, and both ends of the stimulus
 * reference are integers there, so that is the shape these tests are
 * written in. The reader still tolerates their absence, which is what
 * `makeLegacyQuestion` models: a host serving the older flat payload gets
 * one question per screen, no stimuli and the authored option order.
 */

function withExtras(view: AttemptView, extras: Record<string, unknown>): AttemptView {
    return { ...view, ...extras } as AttemptView;
}

describe('readServedScreens', () => {
    it('gives one screen per question when the payload carries no screens', () => {
        const view = makeAttemptView([
            makeLegacyQuestion({ id: 1, type: 'single_choice', position: 1, definition: singleChoiceDefinition }),
            makeLegacyQuestion({ id: 2, type: 'short_answer', position: 2, definition: { prompt: 'Why?' } }),
        ]);
        const screens = readServedScreens(view);
        expect(screens).toHaveLength(2);
        expect(screens[0]?.stimulus).toBeNull();
        expect(servedQuestions(screens).map((entry) => entry.question.id)).toEqual([1, 2]);
    });

    it('orders by the served position, not by the order the array happens to be in', () => {
        const view = makeAttemptView([
            makeQuestion({ id: 9, type: 'short_answer', position: 3, definition: { prompt: 'Third' } }),
            makeQuestion({ id: 7, type: 'short_answer', position: 1, definition: { prompt: 'First' } }),
            makeQuestion({ id: 8, type: 'short_answer', position: 2, definition: { prompt: 'Second' } }),
        ]);
        expect(servedQuestions(readServedScreens(view)).map((entry) => entry.question.id)).toEqual([7, 8, 9]);
    });

    it('folds consecutive questions that share a stimulus into one screen, with the passage once', () => {
        const questions = [
            makeLegacyQuestion({ id: 1, type: 'short_answer', position: 1, definition: { prompt: 'A' } }, { stimulus_id: 's1' }),
            makeLegacyQuestion({ id: 2, type: 'short_answer', position: 2, definition: { prompt: 'B' } }, { stimulus_id: 's1' }),
            makeLegacyQuestion({ id: 3, type: 'short_answer', position: 3, definition: { prompt: 'C' } }, { stimulus_id: 's1' }),
            makeLegacyQuestion({ id: 4, type: 'short_answer', position: 4, definition: { prompt: 'D' } }),
        ];
        const view = withExtras(makeAttemptView(questions), {
            stimuli: [
                {
                    id: 's1',
                    title: 'A short passage',
                    body: '<p>The text.</p>',
                    alt_text: null,
                    long_description: 'Described at length.',
                },
            ],
        });
        const screens = readServedScreens(view);
        expect(screens).toHaveLength(2);
        expect(screens[0]?.questions.map((entry) => entry.question.id)).toEqual([1, 2, 3]);
        expect(screens[0]?.stimulus?.title).toBe('A short passage');
        expect(screens[0]?.stimulus?.longDescription).toBe('Described at length.');
        expect(screens[1]?.stimulus).toBeNull();
        /* The palette and the per-question points stay per question. */
        expect(servedQuestions(screens)).toHaveLength(4);
        expect(screenOfQuestion(screens, 3)).toBe(0);
        expect(screenOfQuestion(screens, 4)).toBe(1);
    });

    it('resolves a stimulus whose id is an integer, which is what the wire sends', () => {
        /* Both ends of the reference are integers in openapi.yaml. Reading
           the question's stimulus_id with a string-only test returned null
           for every real payload: the passage rendered nowhere while a
           fixture written with string ids passed. */
        const questions = [
            makeQuestion({ id: 1, type: 'short_answer', position: 1, screen: 0, stimulus_id: 7, definition: { prompt: 'A' } }),
            makeQuestion({ id: 2, type: 'short_answer', position: 2, screen: 0, stimulus_id: 7, definition: { prompt: 'B' } }),
        ];
        const screens = readServedScreens(
            makeAttemptView(questions, {}, [
                { id: 7, title: 'The passage', body: '<p>The text.</p>', media: null, alt_text: null, long_description: null },
            ]),
        );
        expect(screens).toHaveLength(1);
        expect(screens[0]?.stimulus?.title).toBe('The passage');
    });

    it('still folds by an integer stimulus id when the host sends no screen', () => {
        const questions = [
            makeLegacyQuestion({ id: 1, type: 'short_answer', position: 1, definition: { prompt: 'A' } }, { stimulus_id: 7 }),
            makeLegacyQuestion({ id: 2, type: 'short_answer', position: 2, definition: { prompt: 'B' } }, { stimulus_id: 7 }),
            makeLegacyQuestion({ id: 3, type: 'short_answer', position: 3, definition: { prompt: 'C' } }),
        ];
        const screens = readServedScreens(
            makeAttemptView(questions, {}, [
                { id: 7, title: 'The passage', body: '<p>The text.</p>', media: null, alt_text: null, long_description: null },
            ]),
        );
        expect(screens.map((entry) => entry.questions.length)).toEqual([2, 1]);
        expect(screens[0]?.stimulus?.title).toBe('The passage');
        expect(screens[1]?.stimulus).toBeNull();
    });

    it('honours an explicit screen index when the host sends one', () => {
        const questions = [
            { ...makeQuestion({ id: 1, type: 'short_answer', position: 1, definition: { prompt: 'A' } }), screen: 0 },
            { ...makeQuestion({ id: 2, type: 'short_answer', position: 2, definition: { prompt: 'B' } }), screen: 0 },
            { ...makeQuestion({ id: 3, type: 'short_answer', position: 3, definition: { prompt: 'C' } }), screen: 1 },
        ];
        const screens = readServedScreens(makeAttemptView(questions as never));
        expect(screens.map((entry) => entry.questions.length)).toEqual([2, 1]);
    });

    it('reads the response shape and the stored UI state off the served question', () => {
        const questions = [
            {
                ...makeQuestion({ id: 1, type: 'x_acme_thing', position: 1, definition: { prompt: 'A' } }),
                response_shape: 'multiple:directed_pair',
                ui_state: '{"held":"s1"}',
            },
        ];
        const served = servedQuestions(readServedScreens(makeAttemptView(questions as never)));
        expect(served[0]?.shape).toEqual({ cardinality: 'multiple', baseType: 'directed_pair' });
        expect(served[0]?.uiState).toBe('{"held":"s1"}');
    });
});

describe('applyChoiceSequence', () => {
    it('reorders the DOM order to the order the learner was actually shown', () => {
        const reordered = applyChoiceSequence(singleChoiceDefinition, { options: ['aaa', 'a', 'aa'] });
        expect((reordered.options as { key: string }[]).map((option) => option.key)).toEqual(['aaa', 'a', 'aa']);
    });

    it('keeps anything the sequence did not name, so a stale sequence loses no option', () => {
        const reordered = applyChoiceSequence(singleChoiceDefinition, { options: ['aa'] });
        expect((reordered.options as { key: string }[]).map((option) => option.key)).toEqual(['aa', 'a', 'aaa']);
    });

    it('is written against the field name, so a third-party type inherits it', () => {
        const definition = { bank: [{ key: 'b1' }, { key: 'b2' }], other: 'left alone' };
        const reordered = applyChoiceSequence(definition, { bank: ['b2', 'b1'] });
        expect((reordered.bank as { key: string }[]).map((entry) => entry.key)).toEqual(['b2', 'b1']);
        expect(reordered.other).toBe('left alone');
    });

    it('returns the definition untouched when there is no sequence', () => {
        expect(applyChoiceSequence(singleChoiceDefinition, null)).toBe(singleChoiceDefinition);
        expect(applyChoiceSequence(singleChoiceDefinition, {})).toBe(singleChoiceDefinition);
    });
});

describe('screen identity', () => {
    /*
     * The runner reads the screens in its render body and the question host
     * remounts a module when the served definition identity changes, so a
     * shuffled item would tear its renderer down on every render: focus lost
     * mid-keystroke, a half-completed placement thrown away.
     */
    it('is stable for one view, so a re-render never remounts a renderer', () => {
        const questions = [
            {
                ...makeQuestion({ id: 31, type: 'single_choice', position: 1, definition: singleChoiceDefinition }),
                choice_sequence: { options: ['aaa', 'aa', 'a'] },
            },
        ];
        const view = makeAttemptView(questions as never);
        const first = readServedScreens(view);
        const second = readServedScreens(view);
        expect(second).toBe(first);
        expect(second[0]?.questions[0]?.definition).toBe(first[0]?.questions[0]?.definition);
        /* And the shuffled definition is genuinely a different object from
           the authored one, which is why the identity matters. */
        expect(first[0]?.questions[0]?.definition).not.toBe(singleChoiceDefinition);
    });
});
