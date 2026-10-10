import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import { QuizzesTab } from './tabs/quizzes-tab';
import {
    jsonResponse,
    makeCurriculum,
    makeConfig,
    makeHarness,
    makeQuestion,
    makeQuiz,
    makeQuizAuthoring,
    noContent,
    problemResponse,
    questionTypesFixture,
    type RecordedRequest,
} from './testing/harness';

/*
 * Artboard 2: the quiz overview and the one question editor that serves
 * every registered type. Correct answers live only here, and the type
 * picker lists exactly what the registry answered, which is what keeps a
 * free site at six types without a single check for an edition.
 */

function handler(overrides: Record<string, Response | (() => Response)> = {}) {
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
                return jsonResponse({ items: questionTypesFixture });
            case 'GET /v1/manage/courses/1/curriculum':
                return jsonResponse(makeCurriculum());
            default:
                return null;
        }
    };
}

function renderTab(route: (request: RecordedRequest) => Response | null = handler()) {
    const harness = makeHarness(route);
    render(<QuizzesTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
    return harness;
}

describe('quiz overview', () => {
    it('does not expose or call Pro-only authoring tabs when the host has no routes', async () => {
        const harness = renderTab();
        expect(await screen.findByRole('tab', { name: /Questions/ })).toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Structure' })).toBeNull();
        expect(screen.queryByRole('tab', { name: 'Question bank' })).toBeNull();
        expect(screen.queryByRole('tab', { name: 'Rubrics' })).toBeNull();
        expect(harness.requests.some((request) => (
            request.path.includes('/slots')
            || request.path.includes('/manage/banks/')
            || request.path.includes('/manage/rubrics')
        ))).toBe(false);
    });

    it('offers installed Pro authoring tabs only when their routes are advertised', async () => {
        const config = makeConfig({
            routes: [
                '/manage/quizzes/{id}/slots',
                '/manage/banks/categories',
                '/manage/banks/entries',
                '/manage/rubrics',
            ],
        });
        const harness = makeHarness(handler(), config);
        render(<QuizzesTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        expect(await screen.findByRole('tab', { name: 'Structure' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Question bank' })).toBeInTheDocument();
        expect(screen.getByRole('tab', { name: 'Rubrics' })).toBeInTheDocument();
    });

    it('shows the settings of the chosen quiz as the design summarises them', async () => {
        renderTab();
        expect(await screen.findByRole('heading', { name: 'Module 2 check' })).toBeInTheDocument();
        expect(screen.getByText('pass mark 70%')).toBeInTheDocument();
        expect(screen.getByText('20 min')).toBeInTheDocument();
        expect(screen.getByText('3 attempts')).toBeInTheDocument();
        expect(screen.getByText('feedback: after submission')).toBeInTheDocument();
    });

    it('names the lesson the quiz is attached to', async () => {
        renderTab();
        expect(await screen.findByText(/In: Perceivable content · Module 2 check/)).toBeInTheDocument();
    });

    it('marks the question a human has to mark', async () => {
        renderTab();
        expect(await screen.findByText('needs manual marking')).toBeInTheDocument();
    });

    it('shows the empty state when the course has no quiz yet', async () => {
        renderTab(handler({ 'GET /v1/manage/courses/1/quizzes': jsonResponse({ items: [] }) }));
        expect(await screen.findByText('No quizzes yet')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add the first quiz' })).toBeInTheDocument();
    });

    it('shows the empty question state when a quiz has none', async () => {
        renderTab(
            handler({ 'GET /v1/manage/quizzes/21': jsonResponse(makeQuizAuthoring({ questions: [] })) }),
        );
        expect(await screen.findByText('No questions yet')).toBeInTheDocument();
    });

    it('renders the permission state when the caller may not teach the course', async () => {
        renderTab(handler({ 'GET /v1/manage/courses/1/quizzes': problemResponse(403) }));
        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });

    it('sends every question id in the wanted order when a question moves', async () => {
        const user = userEvent.setup();
        const harness = renderTab(handler({ 'PUT /v1/manage/quizzes/21/questions/order': noContent() }));
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.click(await screen.findByRole('button', { name: 'Move question 1 down' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.path === '/v1/manage/quizzes/21/questions/order',
            );
            expect(write?.body).toEqual({ question_ids: [102, 101] });
        });
    });
});

describe('question type picker', () => {
    it('lists exactly what the registry answers, which is six on a free site', async () => {
        renderTab();
        const picker = (await screen.findByRole('combobox', { name: 'Type' })) as HTMLSelectElement;
        await waitFor(() => expect(picker.options.length).toBeGreaterThan(0));
        expect([...picker.options].map((option) => option.value)).toEqual([
            'single_choice',
            'multiple_choice',
            'true_false',
            'short_answer',
            'fill_blank',
            'ordering',
        ]);
    });
});

describe('question editor', () => {
    async function openEditor(user: ReturnType<typeof userEvent.setup>) {
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.click(await screen.findByRole('button', { name: 'Edit question 1' }));
        return screen.findByRole('dialog');
    }

    it('opens one pattern: prompt, the type-specific answer block, marking', async () => {
        const user = userEvent.setup();
        renderTab();
        const dialog = await openEditor(user);
        expect(within(dialog).getByLabelText('Prompt')).toHaveValue(
            'Which of these is the primary audience for closed captions?',
        );
        expect(within(dialog).getByLabelText('Option 1')).toHaveValue('People watching in sound-off environments');
        expect(within(dialog).getByLabelText('Points')).toHaveValue('1');
        expect(within(dialog).getByLabelText('Feedback shown after submission')).toBeInTheDocument();
    });

    it('says the type cannot change once a question exists', async () => {
        const user = userEvent.setup();
        renderTab();
        const dialog = await openEditor(user);
        expect(dialog).toHaveTextContent('The type is fixed once a question exists.');
    });

    it('marks exactly one correct option on a single choice question', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({ 'PUT /v1/manage/questions/101': jsonResponse(makeQuestion({ id: 101 })) }),
        );
        const dialog = await openEditor(user);
        await user.click(within(dialog).getByRole('radio', { name: 'Option 1 is correct' }));
        await user.click(within(dialog).getByRole('button', { name: 'Save question' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/questions/101');
            expect(write?.method).toBe('PUT');
            expect(write?.body).toMatchObject({ definition: { correct: 'opt1' }, points: 1 });
        });
    });

    it('refuses to save a choice question with no correct answer marked', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'GET /v1/manage/quizzes/21': jsonResponse(
                    makeQuizAuthoring({
                        questions: [
                            makeQuestion({
                                id: 101,
                                type: 'multiple_choice',
                                definition: {
                                    prompt: 'Which need text alternatives?',
                                    options: [
                                        { key: 'a', label: 'Informative images' },
                                        { key: 'b', label: 'Decorative images' },
                                    ],
                                    correct: ['a'],
                                },
                            }),
                        ],
                    }),
                ),
            }),
        );
        const dialog = await openEditor(user);
        await user.click(within(dialog).getByRole('checkbox', { name: 'Option 1 is correct' }));
        await user.click(within(dialog).getByRole('button', { name: 'Save question' }));
        expect(await screen.findByText('Mark at least one correct answer.')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'PUT')).toBe(false);
    });

    it('warns that a short answer with no accepted answers waits for a human', async () => {
        const user = userEvent.setup();
        renderTab();
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.click(await screen.findByRole('button', { name: 'Edit question 2' }));
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('With no accepted answer this question waits for a human in the Gradebook.');
    });

    it('writes accepted answers as a list and keeps the case flag', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({ 'PUT /v1/manage/questions/102': jsonResponse(makeQuestion({ id: 102 })) }),
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.click(await screen.findByRole('button', { name: 'Edit question 2' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Accepted answers'), 'focus\nfocus ring');
        await user.click(within(dialog).getByRole('checkbox', { name: 'Match upper and lower case exactly' }));
        await user.click(within(dialog).getByRole('button', { name: 'Save question' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/questions/102');
            expect(write?.body).toMatchObject({
                definition: { accepted: ['focus', 'focus ring'], case_sensitive: true },
            });
        });
    });

    it('adds a question of the chosen type with the definition its provider validates', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'POST /v1/manage/quizzes/21/questions': jsonResponse(makeQuestion({ id: 103 }), 201),
            }),
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.selectOptions(await screen.findByRole('combobox', { name: 'Type' }), 'true_false');
        await user.click(screen.getByRole('button', { name: 'Add question' }));
        const dialog = await screen.findByRole('dialog');
        await user.type(within(dialog).getByLabelText('Prompt'), 'Transcripts can replace captions.');
        await user.click(within(dialog).getByRole('radio', { name: 'False' }));
        await user.click(within(dialog).getByRole('button', { name: 'Add question' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.path === '/v1/manage/quizzes/21/questions',
            );
            expect(write?.body).toEqual({
                type: 'true_false',
                definition: { prompt: 'Transcripts can replace captions.', correct: false },
                points: 1,
            });
        });
    });

    it('keeps ordering items in the sequence the author put them in', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'GET /v1/manage/quizzes/21': jsonResponse(
                    makeQuizAuthoring({
                        questions: [
                            makeQuestion({
                                id: 101,
                                type: 'ordering',
                                definition: {
                                    prompt: 'Order the audit',
                                    items: [
                                        { key: 'a', label: 'Define the sample' },
                                        { key: 'b', label: 'Run automated checks' },
                                    ],
                                    correct: ['a', 'b'],
                                },
                            }),
                        ],
                    }),
                ),
                'PUT /v1/manage/questions/101': jsonResponse(makeQuestion({ id: 101 })),
            }),
        );
        const dialog = await openEditor(user);
        await user.click(within(dialog).getByRole('button', { name: 'Move item 2 up' }));
        await user.click(within(dialog).getByRole('button', { name: 'Save question' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/questions/101');
            expect(write?.body).toMatchObject({ definition: { correct: ['b', 'a'] } });
        });
    });

    it('removes a question only after saying what removal keeps', async () => {
        const user = userEvent.setup();
        const harness = renderTab(handler({ 'DELETE /v1/manage/questions/101': noContent() }));
        const dialog = await openEditor(user);
        await user.click(within(dialog).getByRole('button', { name: 'Remove question' }));
        expect(await screen.findByText('Remove this question?')).toBeInTheDocument();
        expect(screen.getByText(/Attempts already made keep it/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Remove question' }));
        await waitFor(() => {
            expect(
                harness.requests.some(
                    (request) => request.method === 'DELETE' && request.path === '/v1/manage/questions/101',
                ),
            ).toBe(true);
        });
    });

    it('shows an unregistered type as read-only instead of dropping its definition', async () => {
        const user = userEvent.setup();
        renderTab(
            handler({
                'GET /v1/manage/quizzes/21': jsonResponse(
                    makeQuizAuthoring({
                        questions: [
                            makeQuestion({
                                id: 101,
                                type: 'matching',
                                definition: { prompt: 'Match the pairs', pairs: [] },
                            }),
                        ],
                    }),
                ),
            }),
        );
        const dialog = await openEditor(user);
        expect(dialog).toHaveTextContent('The matching question type is not available here');
    });
});

describe('quiz settings', () => {
    it('writes every setting in one replacement, with an empty field meaning its default', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({ 'PUT /v1/manage/quizzes/21': jsonResponse(makeQuizAuthoring()) }),
        );
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.click(screen.getByRole('button', { name: 'Quiz settings' }));
        const dialog = await screen.findByRole('dialog');
        const attempts = within(dialog).getByLabelText('Attempts allowed');
        await user.clear(attempts);
        await user.click(within(dialog).getByRole('button', { name: 'Save settings' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.method === 'PUT' && request.path === '/v1/manage/quizzes/21',
            );
            expect(write?.method).toBe('PUT');
            expect(write?.body).toEqual({
                title: 'Module 2 check',
                description: null,
                lesson_id: 4,
                pass_mark: 70,
                max_attempts: null,
                  time_limit_seconds: 1200,
                  show_results: 'after_submit',
                  is_final: false,
                  policy: { ...makeQuizAuthoring().policy, opens_at: null },
            });
        });
    });

    it('refuses a pass mark that is not a whole percentage', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.click(screen.getByRole('button', { name: 'Quiz settings' }));
        const dialog = await screen.findByRole('dialog');
        const passMark = within(dialog).getByLabelText('Pass mark (%)');
        await user.clear(passMark);
        await user.type(passMark, '140');
        await user.click(within(dialog).getByRole('button', { name: 'Save settings' }));
        expect(await screen.findByText('Enter a whole number between 0 and 100.')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'PUT')).toBe(false);
    });

    it('offers only the quiz lessons of this course as the attachment', async () => {
        const user = userEvent.setup();
        renderTab();
        await screen.findByRole('heading', { name: 'Module 2 check' });
        await user.click(screen.getByRole('button', { name: 'Quiz settings' }));
        const lesson = within(await screen.findByRole('dialog')).getByLabelText('Attached lesson');
        expect(within(lesson).getAllByRole('option').map((option) => option.textContent)).toEqual([
            'Not attached',
        ]);
    });
});
