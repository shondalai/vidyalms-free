import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { QuizzesTab } from './tabs/quizzes-tab';
import type { QuestionTypeInfo } from '@vidyalms/api-sdk';
import {
    pickerEntries,
    pickerGroups,
    seedDefinition,
    seedScored,
    type QuestionPresetInfo,
} from './lib/question-picker';
import {
    jsonResponse,
    makeCurriculum,
    makeHarness,
    makeQuestion,
    makeQuiz,
    makeQuizAuthoring,
    preset,
    questionType,
    questionPresetsFixture,
    questionTypesFixture,
    type RecordedRequest,
} from './testing/harness';

/*
 * The "Add question" picker over the registry's own answer: the types a site
 * has, and the presets registered over them. A preset is the author-facing
 * name for a shape the engine stores under an algebra key, so the picker can
 * say "Cloze" and "Matching" while questions.type stays composite and
 * associate, and choosing one starts the editor from that preset's skeleton.
 *
 * Nothing here checks an edition. A free site answers six types and their
 * six aliases and the picker shows six entries; the Pro cases below are the
 * same code over a longer answer.
 */

/** What a Pro site answers: the free six plus the types and presets Pro adds. */
const proTypes: QuestionTypeInfo[] = [
    ...questionTypesFixture,
    questionType('associate', 'Association and matching', 'association', 20),
    questionType('composite', 'Multi-part item', 'composite', 10),
];

const proPresets: QuestionPresetInfo[] = [
    ...questionPresetsFixture,
    preset('matching', 'Matching', 'association', 'associate', 'per_target', {
        arity: 'one_to_one',
        layout_classes: ['qti-match-tabular'],
    }),
    preset('categorization', 'Categorization', 'association', 'associate', 'per_target', { arity: 'many_to_one' }),
    preset('cloze', 'Cloze (fill in multiple blanks)', 'composite', 'composite', 'composite_weighted', {
        layout: 'inline',
    }),
    preset('sequencing', 'Sequencing', 'association', 'ordering', 'lcs'),
    preset('ranking', 'Ranking', 'association', 'ordering', 'kendall_tau'),
];

/**
 * The one preset whose marking rule scores nothing: a scale with no right
 * answer over the single choice type, which the server accepts only when it
 * is authored unscored. Kept beside the Pro list rather than in it, so the
 * grouping cases above keep the picker they describe.
 */
const likert = preset('likert', 'Likert or opinion scale', 'selection', 'single_choice', 'none');

function handler(
    types: unknown[] = questionTypesFixture,
    presets: unknown[] = [],
    overrides: Record<string, Response | (() => Response)> = {},
) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return typeof override === 'function' ? override() : override.clone();
        }
        switch (key) {
            case 'GET /v1/manage/courses/1/quizzes':
                return jsonResponse({ items: [makeQuiz()] });
            case 'GET /v1/manage/quizzes/21':
                return jsonResponse(makeQuizAuthoring());
            case 'GET /v1/question-types':
                return jsonResponse({ items: types, presets });
            case 'GET /v1/manage/courses/1/curriculum':
                return jsonResponse(makeCurriculum());
            default:
                return null;
        }
    };
}

function renderTab(route: (request: RecordedRequest) => Response | null) {
    const harness = makeHarness(route);
    render(<QuizzesTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
    return harness;
}

async function picker() {
    const control = (await screen.findByRole('combobox', { name: 'Type' })) as HTMLSelectElement;
    await waitFor(() => expect(control.options.length).toBeGreaterThan(0));
    return control;
}

function optionValues(control: HTMLSelectElement): string[] {
    return [...control.options].map((option) => option.value);
}

function groupsOf(control: HTMLSelectElement): Array<{ label: string; options: string[] }> {
    return [...control.querySelectorAll('optgroup')].map((group) => ({
        label: group.label,
        options: [...group.querySelectorAll('option')].map((option) => option.textContent ?? ''),
    }));
}

describe('the picker on a free site', () => {
    it('offers the six types the registry answered and nothing else', async () => {
        renderTab(handler(questionTypesFixture, questionPresetsFixture));
        expect(optionValues(await picker())).toEqual([
            'single_choice',
            'multiple_choice',
            'true_false',
            'short_answer',
            'fill_blank',
            'ordering',
        ]);
    });

    it('shows each type once under the author-facing name its own alias carries', async () => {
        renderTab(handler(questionTypesFixture, questionPresetsFixture));
        const control = await picker();
        expect([...control.options].map((option) => option.textContent)).toEqual([
            'Multiple choice, one answer',
            'Multiple response',
            'True or false',
            'Short answer',
            'Fill in the blank',
            'Sequencing',
        ]);
    });

    it('sorts on the family and position each type declares, not on registration order', async () => {
        // The fixture registers fill_blank before short_answer, exactly as the
        // free build does, and every table in the design lists them the other
        // way round.
        renderTab(handler(questionTypesFixture, questionPresetsFixture));
        expect(groupsOf(await picker())).toEqual([
            { label: 'Choosing', options: ['Multiple choice, one answer', 'Multiple response', 'True or false'] },
            { label: 'Typing', options: ['Short answer', 'Fill in the blank'] },
            { label: 'Matching and ordering', options: ['Sequencing'] },
        ]);
    });

    it('reads a site that answers no presets at all, which is a legitimate answer', async () => {
        renderTab(handler(questionTypesFixture, []));
        expect([...(await picker()).options].map((option) => option.textContent)).toEqual([
            'Single choice',
            'Multiple choice',
            'True or false',
            'Short answer',
            'Fill in the blank',
            'Ordering',
        ]);
    });
});

describe('the picker on a site with more packages', () => {
    it('renders the presets grouped by family, in the order the registry gave them', async () => {
        renderTab(handler(proTypes, proPresets));
        expect(groupsOf(await picker())).toEqual([
            { label: 'Choosing', options: ['Multiple choice, one answer', 'Multiple response', 'True or false'] },
            { label: 'Typing', options: ['Short answer', 'Fill in the blank'] },
            {
                label: 'Matching and ordering',
                // The type's own name comes back the moment it has named
                // presets: two entries reading "Sequencing" over one type,
                // differing only in a marking rule the picker does not show,
                // is a picker nobody can use.
                options: ['Ordering', 'Sequencing', 'Ranking', 'Association and matching', 'Matching', 'Categorization'],
            },
            { label: 'Multi-part', options: ['Multi-part item', 'Cloze (fill in multiple blanks)'] },
        ]);
    });

    it('drops a preset whose type this site does not have rather than offering a dead end', async () => {
        renderTab(
            handler(questionTypesFixture, [
                ...questionPresetsFixture,
                preset('cloze', 'Cloze (fill in multiple blanks)', 'composite', 'composite', 'composite_weighted'),
            ]),
        );
        expect(optionValues(await picker())).not.toContain('cloze');
    });
});

describe('choosing a preset', () => {
    it('writes the marking rule it names into the definition the provider validates', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(proTypes, proPresets, {
                'POST /v1/manage/quizzes/21/questions': jsonResponse(makeQuestion({ id: 103 }), 201),
            }),
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.selectOptions(await picker(), 'ranking');
        await user.click(screen.getByRole('button', { name: 'Add question' }));

        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Prompt'), 'Rank these by severity.');
        const items = within(dialog).getAllByRole('textbox', { name: /^Item/ });
        await user.type(items[0] as HTMLElement, 'Blocker');
        await user.type(items[1] as HTMLElement, 'Minor');
        await user.click(within(dialog).getByRole('button', { name: 'Add question' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/quizzes/21/questions');
            expect(write?.body).toMatchObject({
                type: 'ordering',
                definition: { policy: { key: 'kendall_tau', params: {} } },
            });
        });
    });

    it('starts the question from the preset skeleton, for a type this workbench has no editor for', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(proTypes, proPresets, {
                'POST /v1/manage/quizzes/21/questions': jsonResponse(makeQuestion({ id: 104 }), 201),
            }),
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.selectOptions(await picker(), 'cloze');
        await user.click(screen.getByRole('button', { name: 'Add question' }));

        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Add question' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/quizzes/21/questions');
            expect(write?.body).toEqual({
                type: 'composite',
                definition: { layout: 'inline', policy: { key: 'composite_weighted', params: {} } },
                points: 1,
            });
        });
    });

    it('authors the opinion scale unscored, because the rule it names scores nothing', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(proTypes, [...proPresets, likert], {
                'POST /v1/manage/quizzes/21/questions': jsonResponse(makeQuestion({ id: 105 }), 201),
            }),
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.selectOptions(await picker(), 'likert');
        await user.click(screen.getByRole('button', { name: 'Add question' }));

        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Prompt'), 'The pacing suited me.');
        const options = within(dialog).getAllByRole('textbox', { name: /^Option \d+$/ });
        await user.type(options[0] as HTMLElement, 'Agree');
        await user.type(options[1] as HTMLElement, 'Disagree');
        await user.click(within(dialog).getByRole('button', { name: 'Add question' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/quizzes/21/questions');
            expect(write?.body).toMatchObject({
                type: 'single_choice',
                scored: false,
                definition: { policy: { key: 'none', params: {} } },
            });
        });
    });

    it('sends no scored flag for an entry that counts, so the server default stays the contract', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler(proTypes, [...proPresets, likert], {
                'POST /v1/manage/quizzes/21/questions': jsonResponse(makeQuestion({ id: 106 }), 201),
            }),
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.selectOptions(await picker(), 'cloze');
        await user.click(screen.getByRole('button', { name: 'Add question' }));

        const dialog = await screen.findByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Add question' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/quizzes/21/questions');
            expect(write?.body).toBeDefined();
            expect(write?.body).not.toHaveProperty('scored');
        });
    });
});

describe('the picker model', () => {
    it('keeps every entry pointing at a registered type key', () => {
        for (const entry of pickerEntries(proTypes, proPresets)) {
            expect(proTypes.map((type) => type.key)).toContain(entry.type);
        }
    });

    it('names no family the contract does not have', () => {
        const families = pickerGroups(proTypes, proPresets).map((group) => group.family);
        expect(families).toEqual(['selection', 'text_entry', 'association', 'composite']);
    });

    it('leaves the type default alone for an entry that names no policy of its own', () => {
        const [entry] = pickerEntries(questionTypesFixture, []);
        expect(entry).toBeDefined();
        expect(seedDefinition(entry as NonNullable<typeof entry>)).toEqual({});
    });

    it('lets the alias give up its name to the type without giving up its marking rule', () => {
        const head = pickerEntries(proTypes, proPresets).find((entry) => entry.key === 'ordering');
        expect(head).toEqual({
            key: 'ordering',
            label: 'Ordering',
            family: 'association',
            type: 'ordering',
            skeleton: {},
            policy: 'kendall_tau',
            policyField: 'policy',
        });
    });

    /**
     * A container type keeps its rule under its own field, and the picker
     * asks rather than assuming.
     *
     * Writing every preset's rule into `policy` seeded all five composite
     * presets with a field the composite schema forbids and the composite
     * type ignores: the item saved only because the schema was not enforced,
     * and the rule the engine actually reads was never written. kprime is
     * worse still, because its skeleton already carries the aggregation, so
     * the seed named the rule twice.
     */
    it('seeds a container type rule into the field that type declares', () => {
        const types: QuestionTypeInfo[] = [
            { key: 'composite', label: 'Multi-part item', group: 'composite', order: 10, policy_field: 'aggregation' },
        ] as QuestionTypeInfo[];
        const presets: QuestionPresetInfo[] = [
            preset('cloze', 'Cloze', 'composite', 'composite', 'composite_weighted', { layout: 'inline' }),
        ];

        const cloze = pickerEntries(types, presets).find((entry) => entry.key === 'cloze');
        expect(cloze).toBeDefined();
        const seed = seedDefinition(cloze as NonNullable<typeof cloze>);

        expect(seed).toEqual({ layout: 'inline', aggregation: { key: 'composite_weighted', params: {} } });
        expect(seed.policy).toBeUndefined();
    });

    it('leaves a rule the skeleton already carries alone rather than naming it twice', () => {
        const types: QuestionTypeInfo[] = [
            { key: 'composite', label: 'Multi-part item', group: 'composite', order: 10, policy_field: 'aggregation' },
        ] as QuestionTypeInfo[];
        const presets: QuestionPresetInfo[] = [
            preset('kprime', 'Kprime grid', 'composite', 'composite', 'subset_threshold', {
                layout: 'table',
                aggregation: { key: 'subset_threshold', params: { bands: [] } },
            }),
        ];

        const kprime = pickerEntries(types, presets).find((entry) => entry.key === 'kprime');
        expect(kprime).toBeDefined();
        const seed = seedDefinition(kprime as NonNullable<typeof kprime>);

        expect(seed.aggregation).toEqual({ key: 'subset_threshold', params: { bands: [] } });
    });

    it('carries a skeleton and its policy into the seed, and nothing else', () => {
        const entries = pickerEntries(proTypes, proPresets);
        const matching = entries.find((entry) => entry.key === 'matching');
        expect(matching).toBeDefined();
        expect(seedDefinition(matching as NonNullable<typeof matching>)).toEqual({
            arity: 'one_to_one',
            layout_classes: ['qti-match-tabular'],
            policy: { key: 'per_target', params: {} },
        });
    });

    it('says the entry whose rule scores nothing is unscored, and every other one counts', () => {
        const entries = pickerEntries(proTypes, [...proPresets, likert]);
        const scale = entries.find((entry) => entry.key === 'likert');
        expect(scale).toBeDefined();
        expect(seedScored(scale as NonNullable<typeof scale>)).toBe(false);
        expect(seedDefinition(scale as NonNullable<typeof scale>)).toEqual({ policy: { key: 'none', params: {} } });
        for (const entry of entries.filter((candidate) => candidate.key !== 'likert')) {
            expect(seedScored(entry)).toBe(true);
        }
    });
});
