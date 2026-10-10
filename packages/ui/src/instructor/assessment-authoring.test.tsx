import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { QuizPolicyDialog } from './tabs/quiz-policy-dialog';
import { SlotsEditor } from './tabs/slots-editor';
import { BankManager } from './tabs/bank-manager';
import { RubricsPanel } from './tabs/rubric-editor';
import {
    jsonResponse,
    makeCourse,
    makeHarness,
    makeQuiz,
    noContent,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';
import {
    makeBankEntry,
    makeCategories,
    makeRubric,
    makeSettingsSchema,
    makeSlotTree,
} from './testing/assessment-fixtures';

/*
 * The wave 3b authoring surface: the registry-driven policy form, the slot
 * tree, the question bank and rubrics.
 *
 * The policy suite is the one that has to be read carefully. Nothing in it
 * asserts a field name this client chose, because there are none: every
 * label, bound, default and option comes from GET settings-schema, and the
 * point of the tests is that a site offering different providers gets a
 * different form with no client change.
 */

function handler(overrides: Record<string, Response | (() => Response)> = {}) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return typeof override === 'function' ? override() : override.clone();
        }
        switch (key) {
            case 'GET /v1/manage/courses/1':
                return jsonResponse(makeCourse());
            case 'GET /v1/manage/courses/1/quizzes':
                return jsonResponse({ items: [makeQuiz()] });
            case 'GET /v1/manage/quizzes/21/settings-schema':
                return jsonResponse(makeSettingsSchema());
            case 'PUT /v1/manage/quizzes/21':
                return jsonResponse(makeQuiz());
            case 'GET /v1/manage/quizzes/21/slots':
                return jsonResponse(makeSlotTree());
            case 'PUT /v1/manage/quizzes/21/slots':
                return jsonResponse(makeSlotTree());
            case 'POST /v1/manage/quizzes/21/slots':
                return jsonResponse(makeSlotTree().slots[0], 201);
            case 'PATCH /v1/manage/slots/602':
            case 'PATCH /v1/manage/slots/603':
            case 'PATCH /v1/manage/slots/601':
                return jsonResponse(makeSlotTree().slots[0]);
            case 'DELETE /v1/manage/slots/602':
                return noContent();
            case 'GET /v1/manage/banks/categories':
                return jsonResponse({ items: makeCategories() });
            case 'GET /v1/manage/banks/entries':
                return jsonResponse({ items: [makeBankEntry()], total: 1, page: 1, per_page: 20 });
            case 'GET /v1/manage/banks/entries/301/versions':
                return jsonResponse({ items: [makeBankEntry().latest_version] });
            case 'GET /v1/manage/banks/entries/301/usage':
                return jsonResponse({
                    bank_entry_id: 301,
                    attempts_served: 12,
                    placements: [
                        {
                            slot_id: 602,
                            quiz_id: 21,
                            quiz_title: 'Module 2 check',
                            course_id: 1,
                            kind: 'question',
                            question_version_id: null,
                        },
                    ],
                });
            case 'GET /v1/manage/objectives':
                return jsonResponse({
                    items: [
                        {
                            id: 11,
                            framework_uri: 'https://example.test/framework',
                            identifier: 'WCAG.1.2.2',
                            label: 'Captions (prerecorded)',
                            parent_id: null,
                        },
                    ],
                    total: 1,
                    page: 1,
                    per_page: 20,
                });
            case 'PUT /v1/manage/banks/entries/301/objectives':
            case 'PUT /v1/manage/banks/entries/301/tags':
            case 'PATCH /v1/manage/banks/entries/301':
                return jsonResponse(makeBankEntry());
            case 'DELETE /v1/manage/banks/entries/301':
                return jsonResponse(makeBankEntry({ status: 'archived' }));
            case 'GET /v1/manage/rubrics':
                return jsonResponse({ items: [makeRubric()], total: 1, page: 1, per_page: 20 });
            case 'GET /v1/manage/rubrics/61/versions':
                return jsonResponse({ items: [makeRubric().latest_version] });
            case 'POST /v1/manage/rubrics':
                return jsonResponse(makeRubric(), 201);
            case 'POST /v1/manage/rubrics/61/versions':
                return jsonResponse(makeRubric().latest_version, 201);
            default:
                return null;
        }
    };
}

function bodyOf(requests: RecordedRequest[], method: string, path: string): Record<string, unknown> {
    const found = requests.find(
        (request) => request.method === method && (request.path.split('?')[0] ?? '') === path,
    );
    if (found === undefined) {
        throw new Error(
            `No ${method} ${path} was sent. Sent: ${requests.map((r) => `${r.method} ${r.path}`).join(', ')}`,
        );
    }
    return (found.body ?? {}) as Record<string, unknown>;
}

describe('the registry-driven policy form', () => {
    function renderPolicy(route = handler(), inline = false) {
        const harness = makeHarness(route);
        render(<QuizPolicyDialog courseId={1} quiz={makeQuiz()} onClose={() => {}} inline={inline} />, {
            wrapper: harness.wrapper,
        });
        return harness;
    }

    it('draws the groups and the fields the site answered, and nothing this client chose', async () => {
        renderPolicy();

        expect(await screen.findByRole('heading', { name: 'Marking behaviour' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { name: 'Attempts' })).toBeInTheDocument();
        expect(screen.getByLabelText('Behaviour')).toBeInTheDocument();
        expect(screen.getByLabelText('Shuffle the questions')).toBeChecked();
        expect(screen.getByText('How and when answers are marked.')).toBeInTheDocument();
    });

    it('edits one policy group inline while preserving settings from the other groups', async () => {
        const user = userEvent.setup();
        const harness = renderPolicy(handler(), true);
        const behaviour = await screen.findByLabelText('Behaviour');
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Attempts' })).not.toBeInTheDocument();
        await user.selectOptions(behaviour, 'manual');
        await user.click(screen.getByRole('button', { name: 'Save' }));
        await waitFor(() => {
            const body = bodyOf(harness.requests, 'PUT', '/v1/manage/quizzes/21');
            expect(body.policy).toMatchObject({
                behaviour: 'manual',
                shuffle_questions: true,
                attempt_aggregation: 'average_best_n',
            });
            expect(body.lesson_id).toBe(makeQuiz().lesson_id);
        });
        expect(screen.getByLabelText('Behaviour')).toHaveValue('manual');
    });

    it('lists only the options this site can honour', async () => {
        renderPolicy();

        const behaviour = (await screen.findByLabelText('Behaviour')) as HTMLSelectElement;
        expect([...behaviour.options].map((option) => option.value)).toEqual(['deferred_feedback', 'manual']);
    });

    it('reports a stored provider the site no longer has, keeps it selected, and says why', async () => {
        renderPolicy();

        expect(
            await screen.findByText(
                'The package that provides "formula_scoring" is not installed on this site.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Stored value: formula_scoring')).toBeInTheDocument();
        const negative = screen.getByLabelText('Negative marking') as HTMLSelectElement;
        expect(negative.value).toBe('formula_scoring');
    });

    it('renders the chosen option parameters from the schema the provider declared', async () => {
        renderPolicy();

        expect(await screen.findByLabelText('How many')).toBeInTheDocument();
        expect(screen.getByText('Options for this choice')).toBeInTheDocument();
    });

    it('renders an object field as a fieldset of its own children', async () => {
        renderPolicy();

        const group = await screen.findByRole('group', { name: 'Per question' });
        expect(within(group).getByLabelText('Tries per question')).toBeInTheDocument();
        expect(within(group).getByLabelText('Allow skipping')).toBeChecked();
    });

    it('shows a duration in minutes and sends it back in seconds', async () => {
        const user = userEvent.setup();
        const harness = renderPolicy();

        const grace = (await screen.findByLabelText(/Grace after the deadline/)) as HTMLInputElement;
        expect(grace.value).toBe('2');
        await user.clear(grace);
        await user.type(grace, '3');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'PUT', '/v1/manage/quizzes/21');
            expect((body.policy as Record<string, unknown>).grace_seconds).toBe(180);
        });
    });

    it('refuses a value outside the bound the schema declared, before any round trip', async () => {
        const user = userEvent.setup();
        const harness = renderPolicy();

        const grace = (await screen.findByLabelText(/Grace after the deadline/)) as HTMLInputElement;
        await user.clear(grace);
        await user.type(grace, '0');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('Enter 1 or more.')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'PUT')).toBe(false);
    });

    it('sends the whole policy set, because the write replaces the quiz', async () => {
        const user = userEvent.setup();
        const harness = renderPolicy();

        await screen.findByLabelText('Behaviour');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'PUT', '/v1/manage/quizzes/21');
            const policy = body.policy as Record<string, unknown>;
            expect(policy.behaviour).toBe('deferred_feedback');
            expect(policy.shuffle_questions).toBe(true);
            expect(policy.attempt_aggregation).toBe('average_best_n');
            /* The quiz's own fields travel back unchanged, or the
               replacement would reset the title. */
            expect(body.title).toBe('Module 2 check');
            expect(body.pass_mark).toBe(70);
        });
    });

    it('renders the field message the server named on a 422', async () => {
        const user = userEvent.setup();
        renderPolicy(
            handler({
                'PUT /v1/manage/quizzes/21': () =>
                    new Response(
                        JSON.stringify({
                            type: 'about:blank',
                            title: 'Unprocessable',
                            status: 422,
                            errors: { 'policy.behaviour': 'This site cannot run that behaviour.' },
                        }),
                        { status: 422, headers: { 'Content-Type': 'application/problem+json' } },
                    ),
            }),
        );

        await screen.findByLabelText('Behaviour');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        expect(await screen.findByText('Not saved.')).toBeInTheDocument();
    });

    it('renders the permission state for a caller who may not teach the course', async () => {
        renderPolicy(
            handler({ 'GET /v1/manage/quizzes/21/settings-schema': problemResponse(403, 'Forbidden') }),
        );

        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });

    it('renders an empty state rather than a blank form when the site offers no policy fields', async () => {
        renderPolicy(
            handler({
                'GET /v1/manage/quizzes/21/settings-schema': jsonResponse(makeSettingsSchema({ groups: [] })),
            }),
        );

        expect(await screen.findByText('No policy settings')).toBeInTheDocument();
    });
});

describe('the slot tree', () => {
    function renderSlots(route = handler()) {
        const harness = makeHarness(route);
        render(<SlotsEditor courseId={1} quizId={21} />, { wrapper: harness.wrapper });
        return harness;
    }

    it('renders sections, placed questions and random draws as one ordered tree', async () => {
        renderSlots();

        expect(await screen.findByText('Warm up')).toBeInTheDocument();
        expect(screen.getAllByText('Section').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Random draw').length).toBeGreaterThan(0);
    });

    it('says a question slot resolves the latest version at attempt start', async () => {
        renderSlots();

        expect(await screen.findByText(/Latest version at attempt start/)).toBeInTheDocument();
    });

    it('surfaces a publication warning at the item it names, and the rest above the tree', async () => {
        renderSlots();

        expect(await screen.findByText('On this item')).toBeInTheDocument();
        expect(screen.getByText('The pool holds fewer than three times the draw count.')).toBeInTheDocument();
        expect(
            screen.getByText('A cooldown is configured on a quiz that allows one attempt.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Publication checks')).toBeInTheDocument();
    });

    /**
     * The three provider-keyed fields were a bare text input and a hardcoded
     * predicate list, so a registered predicate could not be authored at all
     * and a typo in a strategy key produced a uniform draw with no error
     * anywhere. They are now selects over what the site actually registers.
     */
    it('offers the strategies, exposure controls and predicate kinds the site registers', async () => {
        renderSlots();

        await screen.findByText('Warm up');

        expect(
            screen.getByRole('option', { name: 'Draw a set number from each difficulty band' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('option', { name: 'Draw from a rotating window of the pool' }),
        ).toBeInTheDocument();
        expect(screen.getByRole('option', { name: 'Measured difficulty between' })).toBeInTheDocument();
    });

    it('replaces the whole tree when a node moves, because a move renumbers every sibling', async () => {
        const user = userEvent.setup();
        const harness = renderSlots();

        await screen.findByText('Warm up');
        await user.click(screen.getByRole('button', { name: 'Move item 2 up' }));

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'PUT', '/v1/manage/quizzes/21/slots');
            const slots = body.slots as { children: { id: number }[] }[];
            expect(slots[0]?.children.map((child) => child.id)).toEqual([603, 602]);
        });
    });

    it('patches one node when one of its fields changes', async () => {
        const user = userEvent.setup();
        const harness = renderSlots();

        await screen.findByText('Warm up');
        const editors = screen.getAllByText('Edit');
        await user.click(editors[0] as HTMLElement);
        const required = screen.getAllByLabelText('Always included')[0] as HTMLElement;
        await user.click(required);

        await waitFor(() => {
            expect(bodyOf(harness.requests, 'PATCH', '/v1/manage/slots/601').required).toBe(true);
        });
    });

    it('configures a random draw before saving its count, strategy and conditions', async () => {
        const user = userEvent.setup();
        const harness = renderSlots();

        await screen.findByText('Warm up');
        await user.click(screen.getAllByRole('button', { name: 'Add random draw' })[0] as HTMLElement);

        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
        const dialog = screen.getByRole('dialog');
        const count = within(dialog).getByLabelText('Questions to draw');
        await user.clear(count);
        await user.type(count, '5');
        await user.click(within(dialog).getByRole('button', { name: 'Add random draw' }));

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'POST', '/v1/manage/quizzes/21/slots');
            expect(body.kind).toBe('pool');
            expect((body.pool as Record<string, unknown>).strategy).toBe('uniform');
            expect(body.pool).toMatchObject({
                count: 5,
                query: { kind: 'status', operator: 'eq', value: 'ready' },
            });
        });
    });

    it('removes a node through its own route', async () => {
        const user = userEvent.setup();
        const harness = renderSlots();

        await screen.findByText('Warm up');
        const removes = screen.getAllByRole('button', { name: /^Remove / });
        const target = removes.find((button) => button.textContent?.includes('Remove'));
        expect(target).toBeDefined();
        await user.click(target as HTMLElement);

        await waitFor(() =>
            expect(harness.requests.some((request) => request.method === 'DELETE')).toBe(true),
        );
    });

    it('renders the pool condition tree and writes an edited leaf back on save', async () => {
        const user = userEvent.setup();
        const harness = renderSlots();

        await screen.findByText('Warm up');
        const editors = screen.getAllByText('Edit');
        /* The third disclosure is the pool node's own. */
        await user.click(editors[2] as HTMLElement);
        const value = screen.getByLabelText('Value') as HTMLInputElement;
        expect(value.value).toBe('wcag');
        await user.clear(value);
        await user.type(value, 'aria');
        await user.click(screen.getAllByRole('button', { name: 'Save' })[0] as HTMLElement);

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'PATCH', '/v1/manage/slots/603');
            const pool = body.pool as { query: { value: string } };
            expect(pool.query.value).toBe('aria');
        });
    });

    it('renders the permission state rather than an error for a 403', async () => {
        renderSlots(handler({ 'GET /v1/manage/quizzes/21/slots': problemResponse(403, 'Forbidden') }));

        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });

    it('renders an empty state that explains the default order', async () => {
        renderSlots(
            handler({
                'GET /v1/manage/quizzes/21/slots': jsonResponse(makeSlotTree({ slots: [], warnings: [] })),
            }),
        );

        expect(await screen.findByText('No structure yet')).toBeInTheDocument();
    });
});

describe('the question bank', () => {
    function renderBank(route = handler()) {
        const harness = makeHarness(route);
        render(<BankManager courseId={1} narrow={false} />, { wrapper: harness.wrapper });
        return harness;
    }

    it('offers the scopes this course reaches and never a per-person one', async () => {
        renderBank();

        /* The quiz scopes arrive with the course's quizzes, so the option
           list is complete only once that call has landed. */
        await screen.findByRole('option', { name: /One quiz: Module 2 check/ });
        const scope = screen.getByLabelText('Scope') as HTMLSelectElement;
        const labels = [...scope.options].map((option) => option.textContent);
        expect(labels).toContain('This course');
        expect(labels).toContain('Whole site');
        expect(labels.some((label) => label?.toLowerCase().includes('mine'))).toBe(false);
    });

    it('renders the folder tree of the chosen scope with its counts', async () => {
        renderBank();

        expect(await screen.findByText('Perceivable')).toBeInTheDocument();
        expect(screen.getByText('Captions')).toBeInTheDocument();
        expect(screen.getByText('4 questions')).toBeInTheDocument();
    });

    it('lists the entries with their status, version and placements', async () => {
        renderBank();

        expect(await screen.findByText('Which WCAG level does the directive name?')).toBeInTheDocument();
        expect(screen.getByText(/single_choice · v2 · Ready · 1 placement/)).toBeInTheDocument();
    });

    it('opens an entry and says that an edit will write a new version', async () => {
        const user = userEvent.setup();
        renderBank();

        await user.click(await screen.findByText('Which WCAG level does the directive name?'));

        /* Twice: on the warning above the form and again on the version row
           that was served, which are two different claims about one fact. */
        expect(await screen.findAllByText('Served to 12 attempts')).toHaveLength(2);
        expect(
            screen.getByText(/Saving writes a new version; attempts already begun keep the version/),
        ).toBeInTheDocument();
    });

    it('shows where a question is placed, and whether by name or by draw', async () => {
        const user = userEvent.setup();
        renderBank();

        await user.click(await screen.findByText('Which WCAG level does the directive name?'));

        expect(await screen.findByText(/Module 2 check \(placed\)/)).toBeInTheDocument();
    });

    it('archives rather than deletes, and says what an archive does first', async () => {
        const user = userEvent.setup();
        const harness = renderBank();

        await user.click(await screen.findByText('Which WCAG level does the directive name?'));
        await user.click(await screen.findByRole('button', { name: 'Archive' }));
        expect(
            screen.getByText(/An archived question leaves every search and every draw/),
        ).toBeInTheDocument();
        const confirm = screen.getAllByRole('button', { name: 'Archive' });
        await user.click(confirm[confirm.length - 1] as HTMLElement);

        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'DELETE' &&
                        request.path.startsWith('/v1/manage/banks/entries/301'),
                ),
            ).toBe(true),
        );
    });

    it('replaces the tag list as a whole, because tags belong to the entry', async () => {
        const user = userEvent.setup();
        const harness = renderBank();

        await user.click(await screen.findByText('Which WCAG level does the directive name?'));
        const tags = (await screen.findByLabelText('Tags')) as HTMLTextAreaElement;
        await user.clear(tags);
        await user.type(tags, 'aria\ncaptions');
        await user.tab();

        await waitFor(() =>
            expect(bodyOf(harness.requests, 'PUT', '/v1/manage/banks/entries/301/tags').tags).toEqual([
                'aria',
                'captions',
            ]),
        );
    });

    it('aligns an objective by its framework identifier', async () => {
        const user = userEvent.setup();
        const harness = renderBank();

        await user.click(await screen.findByText('Which WCAG level does the directive name?'));
        await user.click(await screen.findByLabelText('WCAG.1.2.2 Captions (prerecorded)'));

        await waitFor(() =>
            expect(
                bodyOf(harness.requests, 'PUT', '/v1/manage/banks/entries/301/objectives').objective_ids,
            ).toEqual([11]),
        );
    });

    it('renders the empty state of a folder with nothing in it', async () => {
        renderBank(
            handler({
                'GET /v1/manage/banks/entries': jsonResponse({ items: [], total: 0, page: 1, per_page: 20 }),
            }),
        );

        expect(await screen.findByText('Nothing in this folder')).toBeInTheDocument();
    });

    it('renders the permission state for a scope the caller may not see', async () => {
        renderBank(handler({ 'GET /v1/manage/banks/categories': problemResponse(403, 'Forbidden') }));

        expect(await screen.findAllByText("You don't manage this course.")).not.toHaveLength(0);
    });
});

describe('rubrics', () => {
    function renderRubrics(route = handler()) {
        const harness = makeHarness(route);
        render(<RubricsPanel courseId={1} />, { wrapper: harness.wrapper });
        return harness;
    }

    it('lists the rubrics of the course with their kind and version count', async () => {
        renderRubrics();

        expect(await screen.findByText('Short written answer')).toBeInTheDocument();
        expect(screen.getByText(/Analytic \(one judgement per criterion\) · 3 versions/)).toBeInTheDocument();
    });

    it('creates a rubric with its criteria and levels in one write', async () => {
        const user = userEvent.setup();
        const harness = renderRubrics();

        await user.click(await screen.findByRole('button', { name: 'New rubric' }));
        await user.type(screen.getByLabelText('Name'), 'Essay');
        await user.type(screen.getAllByLabelText('What this criterion judges')[0] as HTMLElement, 'Argument');
        const points = screen.getAllByLabelText('Points');
        await user.clear(points[1] as HTMLElement);
        await user.type(points[1] as HTMLElement, '4');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => {
            const body = bodyOf(harness.requests, 'POST', '/v1/manage/rubrics');
            expect(body.name).toBe('Essay');
            expect(body.owner_scope).toBe('course');
            const criteria = body.criteria as { description: string; levels: { points: number }[] }[];
            expect(criteria[0]?.description).toBe('Argument');
            expect(criteria[0]?.levels[1]?.points).toBe(4);
        });
    });

    it('saves an edit as a NEW version rather than changing the one grades were given under', async () => {
        const user = userEvent.setup();
        const harness = renderRubrics();

        await user.click(await screen.findByRole('button', { name: /Edit: Short written answer/ }));
        expect(
            screen.getByText(
                /Saving criteria writes a new version and leaves every grade already given alone/,
            ),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'POST' && request.path === '/v1/manage/rubrics/61/versions',
                ),
            ).toBe(true),
        );
    });

    it('never lets a criterion reach zero levels, because a criterion with none cannot be graded', async () => {
        const user = userEvent.setup();
        renderRubrics();

        await user.click(await screen.findByRole('button', { name: 'New rubric' }));
        await user.click(screen.getAllByRole('button', { name: /^Remove level/ })[0] as HTMLElement);

        const remaining = screen.getAllByRole('button', { name: /^Remove level/ });
        expect(remaining).toHaveLength(1);
        expect(remaining[0]).toBeDisabled();
    });

    it('never lets the last criterion be removed either', async () => {
        const user = userEvent.setup();
        renderRubrics();

        await user.click(await screen.findByRole('button', { name: 'New rubric' }));

        expect(screen.getByRole('button', { name: /^Remove criterion 1/ })).toBeDisabled();
    });

    it('renders the empty state with the reason a rubric is worth having', async () => {
        renderRubrics(
            handler({
                'GET /v1/manage/rubrics': jsonResponse({ items: [], total: 0, page: 1, per_page: 20 }),
            }),
        );

        expect(await screen.findByText('No rubrics yet')).toBeInTheDocument();
    });

    it('renders the permission state for a 403', async () => {
        renderRubrics(handler({ 'GET /v1/manage/rubrics': problemResponse(403, 'Forbidden') }));

        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });
});
