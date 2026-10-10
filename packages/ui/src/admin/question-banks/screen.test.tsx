import { useState } from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import type { QuestionBank } from '@vidyalms/api-sdk';
import {
    createReactQuestionModule,
    installQuestionTypeBridge,
    questionTypeRegistry,
    registerFreeQuestionTypes,
    type AuthoringContract,
} from '@/question-types';
import { makeBankEntry, makeBankVersion, makeCategories } from '@/instructor/testing/assessment-fixtures';
import { questionType, questionTypesFixture } from '@/instructor/testing/harness';
import {
    jsonResponse,
    makeCourse,
    makeConfig,
    makeHarness,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';
import type { AdminScreenRuntime } from '../screens/registry';
import { QuestionBanksAdminScreen } from './screen';

function bank(overrides: Partial<QuestionBank> = {}): QuestionBank {
    return {
        id: 11,
        owner_scope: 'course',
        owner_id: 7,
        name: 'Leadership core',
        description: 'Reusable leadership assessment items.',
        state: 'active',
        created_by: 1,
        created_at: '2026-08-01 09:00:00',
        updated_at: '2026-09-01 09:00:00',
        question_count: 100_000,
        folder_count: 2,
        ...overrides,
    };
}

function question(id: number) {
    return makeBankEntry({
        id,
        latest_version: {
            stimulus_id: null,
            id: 500 + id,
            bank_entry_id: id,
            version: 1,
            question_id: 900 + id,
            status: 'ready',
            type: 'single_choice',
            definition_version: 1,
            definition: { prompt: `Question ${id}`, options: [], correct: '' },
        },
    });
}

function standardRoute(request: RecordedRequest): Response | null {
    const url = new URL(request.path, 'http://localhost');
    if (request.method === 'GET' && url.pathname === '/v1/manage/banks') {
        return jsonResponse({
            items: Array.from({ length: 20 }, (_, index) =>
                bank({ id: index + 1, name: `Bank ${index + 1}` }),
            ),
            total: 100_000,
            page: Number(url.searchParams.get('page') ?? 1),
            per_page: 20,
        });
    }
    if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11') {
        return jsonResponse(bank());
    }
    if (request.method === 'GET' && url.pathname === '/v1/manage/courses/7') {
        return jsonResponse({ id: 7, title: 'Inclusive leadership' });
    }
    if (request.method === 'POST' && url.pathname === '/v1/manage/banks') {
        const body = request.body as { name: string; description: string | null };
        return jsonResponse(
            bank({
                id: 77,
                owner_scope: 'site',
                owner_id: 0,
                name: body.name,
                description: body.description,
            }),
        );
    }
    if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11') {
        const body = request.body as {
            name?: string;
            description?: string | null;
            state?: 'active' | 'archived';
        };
        return jsonResponse(bank(body));
    }
    if (request.method === 'DELETE' && url.pathname === '/v1/manage/banks/11') {
        return jsonResponse(bank({ state: 'archived' }));
    }
    if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/categories') {
        return jsonResponse({ items: makeCategories() });
    }
    if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries') {
        return jsonResponse({
            items: Array.from({ length: 20 }, (_, index) => question(index + 301)),
            total: 100_000,
            page: Number(url.searchParams.get('page') ?? 1),
            per_page: 20,
        });
    }
    if (request.method === 'GET' && url.pathname === '/v1/question-types') {
        return jsonResponse({ items: questionTypesFixture, presets: [] });
    }
    if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11/entries') {
        return jsonResponse({ items: [question(301)], updated: 1 });
    }
    if (request.method === 'POST' && url.pathname === '/v1/manage/banks/11/entries') {
        return jsonResponse(question(777));
    }
    return null;
}

function renderStage4(
    initialHash: string,
    handler: (request: RecordedRequest) => Response | null = standardRoute,
) {
    const config = makeConfig({
        screen: 'question-banks',
        urls: {
            ...makeConfig().urls,
            screens: { ...makeConfig().urls.screens, 'question-banks': '/admin/question-banks' },
        },
    });
    const harness = makeHarness(handler, config);
    let activeHash = initialHash;

    function Host() {
        const [hash, setHash] = useState(initialHash);
        const move = (next: string) => {
            activeHash = next;
            setHash(next);
        };
        const runtime: AdminScreenRuntime = {
            config: harness.config,
            client: harness.client,
            hash,
            navigate: move,
            replace: move,
            mountEl: null,
        };
        return <QuestionBanksAdminScreen runtime={runtime} />;
    }

    const view = render(<Host />);
    return { ...harness, view, hash: () => activeHash };
}

beforeAll(() => {
    registerFreeQuestionTypes(installQuestionTypeBridge());
    const registry = questionTypeRegistry();
    if (!registry.has('x_test_scale')) {
        const authoring: AuthoringContract = {
            Block: ({ form }) => (
                <input aria-label="Provider note" {...form.register('extra.providerNote')} />
            ),
            toFormValues: (definition) => ({
                extra: {
                    providerNote:
                        typeof definition.provider_note === 'string' ? definition.provider_note : '',
                },
            }),
            fromFormValues: (values) => {
                const extra =
                    typeof values.extra === 'object' && values.extra !== null
                        ? (values.extra as Record<string, unknown>)
                        : {};
                return { provider_note: typeof extra.providerNote === 'string' ? extra.providerNote : '' };
            },
            ownedKeys: () => ['provider_note'],
            blankFormValues: () => ({ extra: { providerNote: '' } }),
            formSchema: (base) => base,
        };
        registry.register(
            'x_test_scale',
            createReactQuestionModule({
                Renderer: ({ definition }) => (
                    <p>Provider preview: {String(definition.provider_note ?? '')}</p>
                ),
                answeredStatus: () => 'notpresented',
                describeResponse: () => null,
                authoring,
                conformanceResult: 'test.generic-provider.1',
            }),
        );
    }
});

beforeEach(() => window.localStorage.clear());

describe('the question-bank admin screen', () => {
    it('chooses a format, keeps the draft across sections, and saves the question with its organization details', async () => {
        const user = userEvent.setup();
        const harness = renderStage4('/banks/11/questions/new');
        await user.type(await screen.findByRole('searchbox', { name: 'Search question types' }), 'true');
        expect(screen.queryByRole('button', { name: 'Single choice' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'True or false' }));
        await user.type(screen.getByRole('textbox', { name: 'Prompt' }), 'Practice improves recall.');
        await user.click(screen.getByRole('tab', { name: 'Organization' }));
        await user.type(screen.getByRole('textbox', { name: 'Tags' }), 'recall, practice');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'ready');
        await user.click(screen.getByRole('tab', { name: 'Content & answers' }));
        expect(screen.getByRole('textbox', { name: 'Prompt' })).toHaveValue('Practice improves recall.');
        await user.click(screen.getByRole('button', { name: 'Back to questions' }));
        expect(screen.getByRole('dialog', { name: 'Discard this question draft?' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(harness.hash()).toBe('/banks/11/questions/new');
        await user.click(screen.getByRole('button', { name: 'Create question' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'POST')?.body).toMatchObject({
                type: 'true_false',
                status: 'ready',
                tags: ['recall', 'practice'],
                definition: { prompt: 'Practice improves recall.' },
            }),
        );
        await waitFor(() => expect(harness.hash()).toBe('/banks/11/questions'));
    });

    it('asks before replacing a format and can retain the existing answer draft', async () => {
        const user = userEvent.setup();
        renderStage4('/banks/11/questions/new?type=true_false');
        await user.type(await screen.findByRole('textbox', { name: 'Prompt' }), 'Keep this draft');
        await user.click(screen.getByRole('button', { name: 'Change format' }));
        await user.click(screen.getByRole('button', { name: 'Single choice' }));
        expect(
            screen.getByRole('dialog', { name: 'Replace the current question format?' }),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        await user.click(screen.getByRole('button', { name: 'Close' }));
        expect(screen.getByRole('textbox', { name: 'Prompt' })).toHaveValue('Keep this draft');
    });

    it('protects a draft changed only through the objective picker', async () => {
        const user = userEvent.setup();
        const harness = renderStage4('/banks/11/questions/new?type=true_false', (request) => {
            if (
                request.method === 'GET' &&
                new URL(request.path, 'http://localhost').pathname === '/v1/manage/objectives'
            ) {
                return jsonResponse({
                    items: [
                        {
                            id: 8,
                            label: 'Explain retrieval practice',
                            identifier: 'OBJ-8',
                            framework_uri: null,
                            parent_id: null,
                        },
                    ],
                    total: 1,
                    page: 1,
                    per_page: 20,
                });
            }
            return standardRoute(request);
        });
        await screen.findByRole('textbox', { name: 'Prompt' });
        await user.click(screen.getByRole('tab', { name: 'Organization' }));
        await user.click(screen.getByRole('button', { name: /Choose objectives/ }));
        await user.click(await screen.findByRole('button', { name: /Explain retrieval practice/ }));
        await user.click(screen.getByRole('button', { name: 'Back to questions' }));
        expect(screen.getByRole('dialog', { name: 'Discard this question draft?' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Discard changes' }));
        await waitFor(() => expect(harness.hash()).toBe('/banks/11/questions'));
        expect(harness.requests.some((request) => request.method !== 'GET')).toBe(false);
    });

    it('opens the scoring section when a hidden points field blocks saving', async () => {
        const user = userEvent.setup();
        const harness = renderStage4('/banks/11/questions/new?type=true_false');
        await user.type(await screen.findByRole('textbox', { name: 'Prompt' }), 'Check points');
        await user.click(screen.getByRole('tab', { name: 'Scoring & feedback' }));
        await user.clear(screen.getByRole('spinbutton', { name: 'Points' }));
        await user.type(screen.getByRole('spinbutton', { name: 'Points' }), '0');
        await user.click(screen.getByRole('tab', { name: 'Content & answers' }));
        await user.click(screen.getByRole('button', { name: 'Create question' }));
        expect(screen.getByRole('tab', { name: 'Scoring & feedback', selected: true })).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('renders only one bounded page when the server reports 100,000 banks', async () => {
        const harness = renderStage4('/?owner_scope=course&owner_id=7');

        await screen.findByText('Bank 20');
        const table = screen.getByRole('table', { name: 'Question banks' });
        expect(within(table).getAllByRole('row')).toHaveLength(21);
        expect(within(table).queryByText('Bank 21')).toBeNull();
        expect(screen.getByText('1 to 20 of 100000')).toBeInTheDocument();
        expect(await within(table).findAllByText('Inclusive leadership')).toHaveLength(20);
        const request = harness.requests.find((candidate) => candidate.path.startsWith('/v1/manage/banks?'));
        expect(new URL(request?.path ?? '', 'http://localhost').searchParams.get('owner_id')).toBe('7');
    });

    it('pages banks on the server and resets the page when changing result size or clearing filters', async () => {
        const harness = renderStage4('/?q=leadership&state=active&page=3');
        const user = userEvent.setup();
        await screen.findByText('Bank 20');
        expect(screen.getByRole('combobox', { name: 'Bank state' })).toHaveValue('active');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Rows per page' }), '50');
        await waitFor(() =>
            expect(
                harness.requests.some((request) => {
                    const url = new URL(request.path, 'http://localhost');
                    return (
                        url.pathname === '/v1/manage/banks' &&
                        url.searchParams.get('per_page') === '50' &&
                        url.searchParams.get('page') === '1' &&
                        url.searchParams.get('q') === 'leadership'
                    );
                }),
            ).toBe(true),
        );
        await user.click(screen.getByRole('button', { name: 'Clear filters' }));
        expect(harness.hash()).toBe('/');
        expect(screen.getByRole('searchbox', { name: 'Search question banks' })).toHaveValue('');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Bank state' }), 'archived');
        expect(screen.getByRole('heading', { name: 'Unpublished banks' })).toBeInTheDocument();
        await waitFor(() =>
            expect(
                harness.requests.some((request) => {
                    const url = new URL(request.path, 'http://localhost');
                    return (
                        url.pathname === '/v1/manage/banks' && url.searchParams.get('state') === 'archived'
                    );
                }),
            ).toBe(true),
        );
    });

    it('reveals advanced controls on demand and keeps a question filter in the paged request', async () => {
        const harness = renderStage4('/banks/11/questions');
        const user = userEvent.setup();
        await screen.findByText('Question 301');
        expect(screen.queryByRole('combobox', { name: 'Difficulty' })).toBeNull();
        await user.click(screen.getByRole('button', { name: 'More filters' }));
        await user.selectOptions(screen.getByRole('combobox', { name: 'Difficulty' }), 'hard');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Rows per page' }), '100');
        await waitFor(() =>
            expect(
                harness.requests.some((request) => {
                    const url = new URL(request.path, 'http://localhost');
                    return (
                        url.pathname === '/v1/manage/banks/11/entries' &&
                        url.searchParams.get('per_page') === '100' &&
                        url.searchParams.get('difficulty') === 'hard'
                    );
                }),
            ).toBe(true),
        );
        await user.click(screen.getByRole('button', { name: /^More filters/ }));
        expect(screen.getByRole('button', { name: /^More filters/ })).toHaveTextContent('(1)');
        await user.type(screen.getByRole('searchbox', { name: 'Find a folder' }), 'no-such-folder');
        expect(screen.getByText('No matching folders.')).toBeInTheDocument();
        expect(screen.getByText('Question 301')).toBeInTheDocument();
    });

    it('keeps an incomplete owner choice in the UI without sending an invalid owner filter', async () => {
        const user = userEvent.setup();
        const harness = renderStage4('/');
        await screen.findByText('Bank 20');

        await user.selectOptions(screen.getByRole('combobox', { name: 'Bank owner' }), 'course');
        expect(harness.hash()).toContain('owner_scope=course');
        expect(screen.getByRole('button', { name: /course/i })).toBeInTheDocument();

        const requests = harness.requests.filter(
            (candidate) => candidate.method === 'GET' && candidate.path.startsWith('/v1/manage/banks?'),
        );
        expect(requests.length).toBeGreaterThan(0);
        expect(
            requests.some((candidate) => {
                const query = new URL(candidate.path, 'http://localhost').searchParams;
                return query.get('owner_scope') === 'course' && query.get('owner_id') === null;
            }),
        ).toBe(false);
        expect(screen.queryByRole('alert')).toBeNull();
    });

    it('lets a course teacher scope a forbidden initial bank list without exposing ids', async () => {
        const user = userEvent.setup();
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks') {
                if (url.searchParams.get('owner_scope') !== 'course') {
                    return problemResponse(403, 'Choose an owned course');
                }
                return jsonResponse({ items: [bank()], total: 1, page: 1, per_page: 20 });
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/courses') {
                return jsonResponse({
                    items: [makeCourse({ id: 7, title: 'Inclusive leadership' })],
                    total: 1,
                    page: 1,
                    per_page: 20,
                });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/', handler);

        expect(await screen.findByRole('heading', { name: 'Choose a course' })).toBeInTheDocument();
        expect(
            screen.getByText('Select a course to view question banks you can manage.'),
        ).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Choose course' }));
        await user.click(await screen.findByRole('button', { name: 'Inclusive leadership' }));

        expect(await screen.findByText('Leadership core')).toBeInTheDocument();
        expect(harness.hash()).toContain('owner_scope=course');
        expect(harness.hash()).toContain('owner_id=7');
        const scoped = harness.requests.find((request) => {
            const url = new URL(request.path, 'http://localhost');
            return (
                url.pathname === '/v1/manage/banks' &&
                url.searchParams.get('owner_scope') === 'course' &&
                url.searchParams.get('owner_id') === '7'
            );
        });
        expect(scoped).toBeDefined();
    });

    it('creates and edits named banks without exposing a raw owner id field', async () => {
        const user = userEvent.setup();
        const createHarness = renderStage4('/new');
        expect(screen.queryByLabelText('Owner ID')).toBeNull();
        await user.type(screen.getByRole('textbox', { name: 'Bank name' }), 'Site accessibility');
        await user.type(screen.getByRole('textbox', { name: 'Description' }), 'Reusable compliance checks.');
        await user.click(screen.getByRole('button', { name: 'Create bank' }));
        await waitFor(() => expect(createHarness.hash()).toBe('/banks/77/questions'));
        expect(createHarness.requests.find((candidate) => candidate.method === 'POST')?.body).toEqual({
            owner_scope: 'site',
            owner_id: 0,
            name: 'Site accessibility',
            description: 'Reusable compliance checks.',
        });

        createHarness.view.unmount();
        const editHarness = renderStage4('/banks/11');
        const name = await screen.findByRole('textbox', { name: 'Bank name' });
        expect(name).toHaveValue('Leadership core');
        expect(await screen.findByRole('button', { name: /Inclusive leadership/ })).toBeDisabled();
        await user.clear(name);
        await user.type(name, 'Leadership academy');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() =>
            expect(
                editHarness.requests.some(
                    (candidate) => candidate.method === 'PATCH' && candidate.path === '/v1/manage/banks/11',
                ),
            ).toBe(true),
        );
        expect(editHarness.requests.find((candidate) => candidate.method === 'PATCH')?.body).toMatchObject({
            name: 'Leadership academy',
        });
    });

    it('confirms bank archival, restores focus on cancel, and sends one replay-safe archive', async () => {
        const user = userEvent.setup();
        const harness = renderStage4('/banks/11');
        await screen.findByRole('textbox', { name: 'Bank name' });
        const trigger = screen.getByRole('button', { name: 'Unpublish bank' });

        await user.click(trigger);
        const confirmation = screen.getByRole('alertdialog', { name: 'Unpublish Leadership core?' });
        expect(harness.requests.filter((request) => request.method === 'PATCH')).toHaveLength(0);
        await user.click(within(confirmation).getByRole('button', { name: 'Cancel' }));
        await waitFor(() => expect(screen.queryByRole('alertdialog')).toBeNull());
        expect(trigger).toHaveFocus();

        await user.click(trigger);
        await user.click(
            within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Unpublish bank' }),
        );
        await waitFor(() => expect(harness.hash()).toBe('/'));
        const deletes = harness.requests.filter((request) => request.method === 'PATCH');
        expect(deletes).toHaveLength(1);
        expect(deletes[0]?.headers['idempotency-key']).toBeTruthy();
    });

    it('keeps a 100,000-question result bounded and preserves all advanced filters in the request', async () => {
        const harness = renderStage4(
            '/banks/11/questions?q=wcag&category_id=41&include_subcategories=true&status=ready&type=single_choice&tag=a11y&objective_id=9&objective_label=WCAG+2.2&difficulty=medium&usage=used&created_by=27&last_used_before=2026-09-01&sort=usage_count&order=asc&page=5',
        );

        await screen.findByText('Question 320');
        const table = screen.getByRole('table', { name: 'Questions' });
        expect(within(table).getAllByRole('row')).toHaveLength(21);
        const request = await waitFor(() => {
            const match = harness.requests.find((candidate) =>
                candidate.path.includes('/v1/manage/banks/11/entries?'),
            );
            expect(match).toBeDefined();
            return match as RecordedRequest;
        });
        const query = new URL(request.path, 'http://localhost').searchParams;
        expect(Object.fromEntries(query)).toMatchObject({
            q: 'wcag',
            category_id: '41',
            include_subcategories: 'true',
            status: 'ready',
            type: 'single_choice',
            tag: 'a11y',
            objective_id: '9',
            difficulty: 'medium',
            usage: 'used',
            created_by: '27',
            last_used_before: '2026-09-01T00:00:00.000Z',
            sort: 'usage_count',
            order: 'asc',
            page: '5',
        });
        expect(screen.getByRole('button', { name: /WCAG 2.2/ })).toBeInTheDocument();
    });

    it('searches and pages objectives on the server before storing the chosen label', async () => {
        const user = userEvent.setup();
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/manage/objectives') {
                const page = Number(url.searchParams.get('page') ?? 1);
                return jsonResponse({
                    items: [
                        {
                            id: page,
                            framework_uri: null,
                            identifier: `OBJ-${page}`,
                            label: `Objective page ${page}`,
                            parent_id: null,
                        },
                    ],
                    total: 40,
                    page,
                    per_page: 20,
                });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions', handler);
        await screen.findByText('Question 301');

        await user.click(screen.getByRole('button', { name: /^More filters/ }));
        await user.click(screen.getByRole('button', { name: /Any objective/ }));
        expect(await screen.findByText('Objective page 1')).toBeInTheDocument();
        await user.click(
            within(screen.getByRole('navigation', { name: 'Objective pages' })).getByRole('button', {
                name: 'Next ›',
            }),
        );
        await screen.findByText('Objective page 2');
        await user.click(screen.getByRole('button', { name: /Objective page 2/ }));

        expect(harness.hash()).toContain('objective_id=2');
        expect(harness.hash()).toContain('objective_label=Objective+page+2');
        const pageTwo = harness.requests.find(
            (candidate) =>
                candidate.path.includes('/v1/manage/objectives?') &&
                new URL(candidate.path, 'http://localhost').searchParams.get('page') === '2',
        );
        expect(pageTwo).toBeDefined();
        expect(new URL(pageTwo?.path ?? '', 'http://localhost').searchParams.get('per_page')).toBe('20');
    });

    it('shows and retries objective search failures instead of claiming there are no matches', async () => {
        const user = userEvent.setup();
        let available = false;
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/manage/objectives') {
                return available
                    ? jsonResponse({
                          items: [
                              {
                                  id: 9,
                                  framework_uri: null,
                                  identifier: 'WCAG-1.1',
                                  label: 'Text alternatives',
                                  parent_id: null,
                              },
                          ],
                          total: 1,
                          page: 1,
                          per_page: 20,
                      })
                    : problemResponse(503, 'Objectives unavailable');
            }
            return standardRoute(request);
        };
        renderStage4('/banks/11/questions', handler);
        await screen.findByText('Question 301');

        await user.click(screen.getByRole('button', { name: /^More filters/ }));
        await user.click(screen.getByRole('button', { name: /Any objective/ }));
        const failure = (await screen.findByText("Couldn't load.")).closest('[role="alert"]');
        expect(failure).not.toBeNull();
        expect(screen.queryByText('No matching objectives.')).toBeNull();
        available = true;
        await user.click(within(failure as HTMLElement).getByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('button', { name: /Text alternatives/ })).toBeInTheDocument();
    });

    it('persists reusable saved filters per administrator and bank', async () => {
        const user = userEvent.setup();
        const first = renderStage4('/banks/11/questions?status=ready&tag=wcag');
        await screen.findByText('Question 301');

        await user.click(screen.getByRole('button', { name: 'Save filters' }));
        await user.type(screen.getByRole('textbox', { name: 'Filter name' }), 'Ready WCAG');
        await user.click(screen.getByRole('button', { name: 'Save' }));
        expect(screen.getByRole('combobox', { name: 'Saved filters' })).toHaveDisplayValue('Ready WCAG');
        expect(window.localStorage.length).toBe(1);

        await user.selectOptions(screen.getByRole('combobox', { name: 'Status' }), 'draft');
        expect(first.hash()).toContain('status=draft');
        await user.selectOptions(
            screen.getByRole('combobox', { name: 'Saved filters' }),
            screen.getByRole('option', { name: 'Ready WCAG' }),
        );
        expect(first.hash()).toContain('status=ready');
        expect(first.hash()).toContain('tag=wcag');

        first.view.unmount();
        renderStage4('/banks/11/questions');
        await screen.findByText('Question 301');
        expect(screen.getByRole('option', { name: 'Ready WCAG' })).toBeInTheDocument();
    });

    it('bulk changes only explicitly selected rows and clears the selection after success', async () => {
        const user = userEvent.setup();
        const harness = renderStage4('/banks/11/questions');
        await screen.findByText('Question 301');

        await user.click(screen.getByRole('checkbox', { name: 'Select Question 301' }));
        expect(screen.getByText('1 question selected')).toBeInTheDocument();
        await user.selectOptions(screen.getByRole('combobox', { name: 'Change status' }), 'archived');
        await user.click(screen.getByRole('button', { name: 'Apply status' }));
        await user.click(
            within(screen.getByRole('alertdialog')).getByRole('button', { name: 'Confirm change' }),
        );

        await screen.findByText('1 selected question was updated.');
        expect(screen.queryByText('1 question selected')).toBeNull();
        const request = harness.requests.find((candidate) => candidate.method === 'PATCH');
        expect(request).toMatchObject({ body: { entry_ids: [301], status: 'archived' } });
    });

    it('retries a refused folder creation with the same key and clears the name after success', async () => {
        let attempts = 0;
        const harness = renderStage4('/banks/11/questions', (request) => {
            if (request.method === 'POST' && request.path === '/v1/manage/banks/11/categories') {
                attempts++;
                return attempts === 1
                    ? problemResponse(422, 'Folder could not be created')
                    : jsonResponse({ ...makeCategories()[0], id: 99, name: 'Review material' });
            }
            return standardRoute(request);
        });
        const user = userEvent.setup();
        await screen.findByText('Question 301');
        await user.type(screen.getByRole('textbox', { name: 'Folder name' }), 'Review material');
        await user.click(screen.getByRole('button', { name: 'Add folder' }));
        await screen.findByText('The folder could not be created.');
        await user.click(screen.getByRole('button', { name: 'Retry now' }));
        await screen.findByText('Folder created.');
        expect(screen.getByRole('textbox', { name: 'Folder name' })).toHaveValue('');
        const writes = harness.requests.filter((request) => request.method === 'POST');
        expect(writes).toHaveLength(2);
        expect(writes[0]?.headers['idempotency-key']).toBe(writes[1]?.headers['idempotency-key']);
    });

    it('renames and reparents a folder without offering itself or descendants as parents', async () => {
        const user = userEvent.setup();
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11/categories/42') {
                return jsonResponse({ ...makeCategories()[0]!.children![0]!, ...(request.body as object) });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions', handler);
        await screen.findByText('Question 301');

        await user.click(screen.getByRole('button', { name: 'Edit folder Perceivable' }));
        const rootDialog = screen.getByRole('dialog', { name: 'Edit Perceivable' });
        expect(within(rootDialog).queryByRole('option', { name: /Perceivable/ })).toBeNull();
        expect(within(rootDialog).queryByRole('option', { name: /Captions/ })).toBeNull();
        await user.click(within(rootDialog).getByRole('button', { name: 'Cancel' }));

        await user.click(screen.getByRole('button', { name: 'Edit folder Captions' }));
        const dialog = screen.getByRole('dialog', { name: 'Edit Captions' });
        const folderName = within(dialog).getByRole('textbox', { name: 'Folder name' });
        await user.clear(folderName);
        await user.type(folderName, 'Recorded captions');
        await user.selectOptions(within(dialog).getByRole('combobox', { name: 'Parent folder' }), '');
        await user.click(within(dialog).getByRole('button', { name: 'Save folder' }));

        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Edit Captions' })).toBeNull());
        const write = harness.requests.find(
            (request) => request.method === 'PATCH' && request.path === '/v1/manage/banks/11/categories/42',
        );
        expect(write?.body).toEqual({ name: 'Recorded captions', parent_id: null });
        expect(write?.headers['idempotency-key']).toBeTruthy();
    });

    it('retries a refused folder rename and move as the same logical update', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11/categories/42') {
                attempts += 1;
                return attempts === 1
                    ? problemResponse(503, 'Folder service unavailable')
                    : jsonResponse({ ...makeCategories()[0]!.children![0]!, ...(request.body as object) });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions', handler);
        await screen.findByText('Question 301');

        await user.click(screen.getByRole('button', { name: 'Edit folder Captions' }));
        const dialog = screen.getByRole('dialog', { name: 'Edit Captions' });
        await user.clear(within(dialog).getByRole('textbox', { name: 'Folder name' }));
        await user.type(within(dialog).getByRole('textbox', { name: 'Folder name' }), 'Recorded captions');
        await user.selectOptions(within(dialog).getByRole('combobox', { name: 'Parent folder' }), '');
        await user.click(within(dialog).getByRole('button', { name: 'Save folder' }));

        const failure = await within(dialog).findByRole('alert');
        expect(failure).toHaveTextContent('Folder not updated.');
        await user.click(within(failure).getByRole('button', { name: 'Try again' }));
        await waitFor(() => expect(attempts).toBe(2));
        await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Edit Captions' })).toBeNull());

        const writes = harness.requests.filter(
            (request) => request.method === 'PATCH' && request.path.endsWith('/categories/42'),
        );
        expect(writes).toHaveLength(2);
        expect(writes[0]?.body).toEqual({ name: 'Recorded captions', parent_id: null });
        expect(writes[1]?.body).toEqual(writes[0]?.body);
        expect(writes[0]?.headers['idempotency-key']).toBeTruthy();
        expect(writes[1]?.headers['idempotency-key']).toBe(writes[0]?.headers['idempotency-key']);
    });

    it('keeps delete-empty confirmation open on conflict and retries with the same key', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'DELETE' && url.pathname === '/v1/manage/banks/11/categories/42') {
                attempts += 1;
                if (attempts === 1) {
                    return new Response(
                        JSON.stringify({ type: 'about:blank', title: 'Folder is not empty', status: 409 }),
                        {
                            status: 409,
                            headers: { 'Content-Type': 'application/problem+json' },
                        },
                    );
                }
                return new Response(null, { status: 204 });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions', handler);
        await screen.findByText('Question 301');

        await user.click(screen.getByRole('button', { name: 'Delete folder Captions' }));
        const dialog = screen.getByRole('alertdialog', { name: 'Delete Captions?' });
        expect(harness.requests.filter((request) => request.method === 'DELETE')).toHaveLength(0);
        await user.click(within(dialog).getByRole('button', { name: 'Delete empty folder' }));
        const failure = await within(dialog).findByRole('alert');
        expect(failure).toHaveTextContent('Folder not deleted.');
        await user.click(within(failure).getByRole('button', { name: 'Try again' }));
        await waitFor(() => expect(attempts).toBe(2));
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog', { name: 'Delete Captions?' })).toBeNull(),
        );

        const deletes = harness.requests.filter((request) => request.method === 'DELETE');
        expect(deletes).toHaveLength(2);
        expect(deletes[0]?.headers['idempotency-key']).toBeTruthy();
        expect(deletes[1]?.headers['idempotency-key']).toBe(deletes[0]?.headers['idempotency-key']);
    });

    it('saves a free single-choice definition accepted by the published closed schema', async () => {
        const user = userEvent.setup();
        const singleChoice = questionType('single_choice', 'Single choice', 'selection', 10, {
            response_shape: 'single:identifier',
            authoring: {
                ...questionTypesFixture[0]!.authoring,
                definition_schema: {
                    type: 'object',
                    additionalProperties: false,
                    required: ['prompt', 'options', 'correct'],
                    properties: {
                        prompt: { type: 'string' },
                        feedback: { type: 'string' },
                        options: { type: 'array' },
                        correct: { type: 'string' },
                        policy: { type: 'object' },
                    },
                },
            },
        });
        const handler = (request: RecordedRequest) => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/question-types') {
                return jsonResponse({ items: [singleChoice], presets: [] });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions/new?type=single_choice', handler);

        await user.type(await screen.findByRole('textbox', { name: 'Prompt' }), 'Which standard applies?');
        const options = await screen.findAllByRole('textbox', { name: /Option \d/ });
        await user.type(options[0]!, 'WCAG');
        await user.type(options[1]!, 'SMTP');
        await user.click(screen.getByRole('button', { name: 'Create question' }));

        const request = await waitFor(() => {
            const match = harness.requests.find(
                (candidate) =>
                    candidate.method === 'POST' && candidate.path === '/v1/manage/banks/11/entries',
            );
            expect(match).toBeDefined();
            return match as RecordedRequest;
        });
        const body = request.body as { definition: Record<string, unknown> };
        const schema = singleChoice.authoring.definition_schema as {
            required: string[];
            properties: Record<string, unknown>;
        };
        expect(body.definition).toMatchObject({ prompt: 'Which standard applies?', correct: 'opt1' });
        expect(body.definition).not.toHaveProperty('schema_version');
        expect(Object.keys(body.definition).every((key) => key in schema.properties)).toBe(true);
        expect(schema.required.every((key) => key in body.definition)).toBe(true);
    });

    it('composes a version-declaring provider through authoring, preview, reopen and immutable version save', async () => {
        const user = userEvent.setup();
        const genericType = questionType('x_test_scale', 'Provider scale', 'control', 10, {
            response_shape: 'single:string',
            authoring: {
                ...questionTypesFixture[0]!.authoring,
                scoring_policies: [
                    {
                        key: 'configured',
                        label: 'Configured scoring',
                        default_params: { mode: 'strict', chance_corrected: true, weights: [1, 2] },
                        params_schema: {
                            type: 'object',
                            additionalProperties: false,
                            properties: {
                                mode: { type: 'string', enum: ['strict', 'lenient'] },
                                chance_corrected: { type: 'boolean' },
                                weights: { type: 'array', minItems: 2, items: { type: 'number' } },
                            },
                        },
                    },
                ],
                definition_schema: {
                    type: 'object',
                    additionalProperties: false,
                    required: ['schema_version', 'prompt', 'provider_note'],
                    properties: {
                        schema_version: { type: 'integer', const: 1 },
                        prompt: { type: 'string' },
                        provider_note: { type: 'string' },
                        policy: { type: 'object' },
                    },
                },
            },
        });
        let current = makeBankEntry({
            id: 777,
            status: 'draft',
            latest_version: {
                stimulus_id: null,
                id: 7771,
                bank_entry_id: 777,
                version: 1,
                question_id: 1777,
                status: 'draft',
                type: 'x_test_scale',
                points: 1,
                definition_version: 1,
                definition: { prompt: 'Provider prompt', provider_note: 'First note' },
            },
        });
        let acceptedVersionKey: string | null = null;
        let failedMetadataOnce = false;
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/question-types') {
                return jsonResponse({ items: [genericType], presets: [] });
            }
            if (request.method === 'POST' && url.pathname === '/v1/manage/banks/11/entries') {
                const body = request.body as { definition: Record<string, unknown> };
                current = makeBankEntry({
                    ...current,
                    latest_version: { ...current.latest_version!, definition: body.definition },
                });
                return jsonResponse(current);
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries') {
                return jsonResponse({ items: [current], total: 1, page: 1, per_page: 20 });
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/777') {
                return jsonResponse(current);
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/777/usage') {
                return jsonResponse({ bank_entry_id: 777, attempts_served: 0, placements: [] });
            }
            if (request.method === 'POST' && url.pathname === '/v1/manage/banks/11/entries/777/versions') {
                const body = request.body as { definition: Record<string, unknown> };
                const key = request.headers['idempotency-key'] ?? null;
                if (acceptedVersionKey === null || acceptedVersionKey !== key) {
                    acceptedVersionKey = key;
                    current = makeBankEntry({
                        ...current,
                        latest_version: {
                            ...current.latest_version!,
                            version: (current.latest_version?.version ?? 1) + 1,
                            definition: body.definition,
                        },
                    });
                }
                return jsonResponse(current.latest_version);
            }
            if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11/entries/777') {
                if (!failedMetadataOnce) {
                    failedMetadataOnce = true;
                    return new Response(
                        JSON.stringify({ type: 'about:blank', title: 'Temporary failure', status: 503 }),
                        {
                            status: 503,
                            headers: { 'Content-Type': 'application/problem+json' },
                        },
                    );
                }
                return jsonResponse(current);
            }
            if (request.method === 'PUT' && url.pathname === '/v1/manage/banks/11/entries/777/tags') {
                return jsonResponse({ items: [] });
            }
            if (request.method === 'PUT' && url.pathname === '/v1/manage/banks/11/entries/777/objectives') {
                return jsonResponse({ items: [] });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions/new?type=x_test_scale', handler);

        await user.type(await screen.findByRole('textbox', { name: 'Prompt' }), 'Provider prompt');
        await user.type(await screen.findByRole('textbox', { name: 'Provider note' }), 'First note');
        await user.click(screen.getByRole('tab', { name: 'Scoring & feedback' }));
        await user.selectOptions(screen.getByRole('combobox', { name: 'Scoring policy' }), 'configured');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Mode' }), 'lenient');
        await user.click(screen.getByRole('checkbox', { name: 'Chance Corrected' }));
        const weights = screen.getAllByRole('spinbutton', { name: /Weights Item/ });
        await user.clear(weights[0]!);
        await user.type(weights[0]!, '3.5');
        await user.click(screen.getByRole('tab', { name: 'Learner preview' }));
        expect(await screen.findByText('Provider preview: First note')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Create question' }));
        const createWrite = await waitFor(() => {
            const request = harness.requests.find(
                (candidate) =>
                    candidate.method === 'POST' && candidate.path === '/v1/manage/banks/11/entries',
            );
            expect(request).toBeDefined();
            return request as RecordedRequest;
        });
        expect(createWrite.body).toMatchObject({
            definition: {
                schema_version: 1,
                prompt: 'Provider prompt',
                provider_note: 'First note',
                policy: {
                    key: 'configured',
                    params: { mode: 'lenient', chance_corrected: false, weights: [3.5, 2] },
                },
            },
        });
        await user.click(await screen.findByText('Provider prompt'));

        const note = await screen.findByRole('textbox', { name: 'Provider note' });
        expect(await screen.findByText("Couldn't load.")).toBeInTheDocument();
        expect(screen.getByText('This question is not placed in a quiz.')).toBeInTheDocument();
        expect(note).toHaveValue('First note');
        await user.clear(note);
        await user.type(note, 'Revised note');
        await user.click(screen.getByRole('tab', { name: 'Learner preview' }));
        expect(await screen.findByText('Provider preview: Revised note')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save new version' }));
        expect(await screen.findByText('The question was not saved. Try again.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'retry now' }));
        await waitFor(() => expect(harness.hash()).toBe('/banks/11/questions'));

        const version = harness.requests.find(
            (request) => request.method === 'POST' && request.path.endsWith('/versions'),
        )!;
        expect(version.body).toMatchObject({
            status: 'draft',
            definition: {
                schema_version: 1,
                prompt: 'Provider prompt',
                provider_note: 'Revised note',
                policy: {
                    key: 'configured',
                    params: { mode: 'lenient', chance_corrected: false, weights: [3.5, 2] },
                },
            },
        });
        const editWrites = harness.requests.filter(
            (request) =>
                request.path.startsWith('/v1/manage/banks/11/entries/777') &&
                ['POST', 'PATCH', 'PUT'].includes(request.method),
        );
        const byEndpoint = new Map<string, RecordedRequest[]>();
        for (const request of editWrites) {
            const existing = byEndpoint.get(request.path) ?? [];
            existing.push(request);
            byEndpoint.set(request.path, existing);
        }
        expect(byEndpoint.size).toBe(4);
        for (const requests of byEndpoint.values()) {
            expect(requests).toHaveLength(2);
            expect(requests[0]?.headers['idempotency-key']).toBeTruthy();
            expect(requests[1]?.headers['idempotency-key']).toBe(requests[0]?.headers['idempotency-key']);
        }
        expect(
            new Set([...byEndpoint.values()].map((requests) => requests[0]?.headers['idempotency-key'])).size,
        ).toBe(4);
        expect(current.latest_version?.version).toBe(2);
    });

    it('keeps a ready edit ready in the immutable version write', async () => {
        const user = userEvent.setup();
        const current = makeBankEntry({
            status: 'ready',
            latest_version: makeBankVersion({
                status: 'ready',
                definition: {
                    prompt: 'Which level applies?',
                    options: [
                        { key: 'a', label: 'Level A' },
                        { key: 'aa', label: 'Level AA' },
                    ],
                    correct: 'aa',
                },
            }),
        });
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/301') {
                return jsonResponse(current);
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/301/usage') {
                return jsonResponse({
                    bank_entry_id: 301,
                    attempts_served: 12,
                    placements: [
                        {
                            slot_id: 81,
                            quiz_id: 8,
                            quiz_title: 'Accessibility checkpoint',
                            course_id: 7,
                            kind: 'pool',
                            question_version_id: null,
                        },
                        {
                            slot_id: 82,
                            quiz_id: 9,
                            quiz_title: 'Final assessment',
                            course_id: 7,
                            kind: 'question',
                            question_version_id: 501,
                        },
                    ],
                });
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/301/versions') {
                return jsonResponse({
                    items: [makeBankVersion({ status: 'ready', version: 2, attempts_served: 12 })],
                });
            }
            if (request.method === 'POST' && url.pathname === '/v1/manage/banks/11/entries/301/versions') {
                return jsonResponse(makeBankVersion({ status: 'ready', version: 3 }));
            }
            if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11/entries/301') {
                return jsonResponse(current);
            }
            if (request.method === 'PUT' && url.pathname === '/v1/manage/banks/11/entries/301/tags') {
                return jsonResponse({ items: current.tags ?? [] });
            }
            if (request.method === 'PUT' && url.pathname === '/v1/manage/banks/11/entries/301/objectives') {
                return jsonResponse({ items: current.objectives ?? [] });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions/301/edit', handler);

        await user.click(await screen.findByRole('tab', { name: 'Organization' }));
        const status = await screen.findByRole('combobox', { name: 'Status' });
        await waitFor(() => expect(status).toHaveValue('ready'));
        expect(await screen.findByText('Version 2 · ready')).toBeInTheDocument();
        expect(screen.getByText(/12 served/)).toBeInTheDocument();
        expect(await screen.findByText('Accessibility checkpoint')).toBeInTheDocument();
        expect(screen.getByText(/Pool placement · Latest version at attempt start/)).toBeInTheDocument();
        expect(screen.getByText('Final assessment')).toBeInTheDocument();
        expect(screen.getByText(/Direct question · Pinned version record 501/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save new version' }));

        const write = await waitFor(() => {
            const request = harness.requests.find(
                (candidate) => candidate.method === 'POST' && candidate.path.endsWith('/versions'),
            );
            expect(request).toBeDefined();
            return request as RecordedRequest;
        });
        expect(write.body).toMatchObject({ status: 'ready' });
    });

    it('keeps an archived question read-only until it is explicitly reactivated as a draft', async () => {
        const user = userEvent.setup();
        const current = makeBankEntry({
            id: 301,
            status: 'archived',
            latest_version: makeBankVersion({
                bank_entry_id: 301,
                status: 'ready',
                definition: {
                    prompt: 'Retired accessibility question',
                    options: [{ key: 'a', label: 'Keep it archived' }],
                    correct: 'a',
                },
            }),
        });
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/301') {
                return jsonResponse(current);
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/301/versions') {
                return jsonResponse({ items: [current.latest_version] });
            }
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries/301/usage') {
                return jsonResponse({ bank_entry_id: 301, attempts_served: 0, placements: [] });
            }
            if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11/entries/301') {
                return jsonResponse({ ...current, status: 'draft' });
            }
            return standardRoute(request);
        };
        const harness = renderStage4('/banks/11/questions/301/edit', handler);

        expect(await screen.findByRole('heading', { name: 'Archived question' })).toBeInTheDocument();
        expect(screen.getByText(/Archived questions are read-only/)).toBeInTheDocument();
        expect(screen.queryByRole('textbox', { name: 'Prompt' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Save new version' })).toBeNull();
        expect(screen.getByRole('list', { name: 'Question version history' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Reactivate as draft' }));

        await waitFor(() => expect(harness.hash()).toBe('/banks/11/questions'));
        const patch = harness.requests.find(
            (request) => request.method === 'PATCH' && request.path.endsWith('/entries/301'),
        );
        expect(patch?.body).toEqual({ status: 'draft' });
        expect(patch?.headers['idempotency-key']).toBeTruthy();
        expect(
            harness.requests.some(
                (request) => request.method === 'POST' && request.path.endsWith('/versions'),
            ),
        ).toBe(false);
    });

    it('does not render a new-question form until its bank exists and is allowed', async () => {
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/404') {
                return new Response(
                    JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }),
                    {
                        status: 404,
                        headers: { 'Content-Type': 'application/problem+json' },
                    },
                );
            }
            return standardRoute(request);
        };
        renderStage4('/banks/404/questions/new?type=single_choice', handler);

        const failureTitle = await screen.findByText("Couldn't load.");
        expect(failureTitle.closest('[role="alert"]')).toHaveTextContent(/wasn't found/i);
        expect(screen.queryByRole('heading', { name: 'Create question' })).toBeNull();
        expect(screen.queryByRole('textbox', { name: 'Prompt' })).toBeNull();
    });

    it('renders the bank permission state instead of a forbidden new-question form', async () => {
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11') {
                return new Response(
                    JSON.stringify({ type: 'about:blank', title: 'Forbidden', status: 403 }),
                    {
                        status: 403,
                        headers: { 'Content-Type': 'application/problem+json' },
                    },
                );
            }
            return standardRoute(request);
        };
        renderStage4('/banks/11/questions/new?type=single_choice', handler);

        expect(await screen.findByText('Managers see courses only.')).toBeInTheDocument();
        expect(screen.queryByRole('heading', { name: 'Create question' })).toBeNull();
        expect(screen.queryByRole('textbox', { name: 'Prompt' })).toBeNull();
    });

    it('shows a retryable catalogue failure before enabling the question editor', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        let allowCatalogue = false;
        const handler = (request: RecordedRequest): Response | null => {
            const url = new URL(request.path, 'http://localhost');
            if (request.method === 'GET' && url.pathname === '/v1/question-types') {
                attempts += 1;
                if (!allowCatalogue) {
                    return new Response(
                        JSON.stringify({ type: 'about:blank', title: 'Catalogue unavailable', status: 503 }),
                        {
                            status: 503,
                            headers: { 'Content-Type': 'application/problem+json' },
                        },
                    );
                }
            }
            return standardRoute(request);
        };
        renderStage4('/banks/11/questions/new', handler);

        const failureTitle = await screen.findByText("Couldn't load.");
        const failure = failureTitle.closest('[role="alert"]');
        expect(failure).not.toBeNull();
        expect(screen.queryByRole('combobox', { name: 'Question type' })).toBeNull();
        allowCatalogue = true;
        await user.click(within(failure as HTMLElement).getByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('button', { name: 'Single choice' })).toBeInTheDocument();
        expect(attempts).toBeGreaterThanOrEqual(2);
    });

    it('shows an explicit route error without fetching a different page', () => {
        const harness = renderStage4('/banks/not-a-number/questions');
        const title = screen.getByRole('heading', { name: 'This page is unavailable.' });
        expect(title.closest('[role="alert"]')).toHaveTextContent('This page is unavailable.');
        expect(harness.requests).toHaveLength(0);
    });
});
