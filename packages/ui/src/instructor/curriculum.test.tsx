import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { FakeXhrServer } from '@/shared/testing/fake-xhr';
import { CurriculumTab } from './tabs/curriculum-tab';
import {
    jsonResponse,
    lessonTypesFixture,
    makeConfig,
    makeCurriculum,
    makeHarness,
    makeLesson,
    makeModule,
    noContent,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/** A picked file; happy-dom keeps the size the constructor gave it. */
function pickedFile(name = 'checklist.pdf', size = 51200): File {
    const file = new File(['x'], name, { type: 'application/pdf' });
    Object.defineProperty(file, 'size', { value: size });
    return file;
}

/*
 * Artboard 1a: explicit order with move buttons and a drag handle, the
 * add-lesson menu driven by the registry, and the per-type lesson form.
 */

function handler(overrides: Record<string, Response | (() => Response)> = {}) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return typeof override === 'function' ? override() : override.clone();
        }
        switch (key) {
            case 'GET /v1/manage/courses/1/curriculum':
                return jsonResponse(makeCurriculum());
            case 'GET /v1/lesson-types':
                return jsonResponse({ items: lessonTypesFixture });
            case 'GET /v1/manage/courses/1/quizzes':
                return jsonResponse({ items: [] });
            default:
                return null;
        }
    };
}

function renderTab(route: (request: RecordedRequest) => Response | null = handler()) {
    const harness = makeHarness(route);
    render(<CurriculumTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
    return harness;
}

/** The draggable row wrapping a title, which is the DropTarget div. */
function rowOf(title: string): HTMLElement {
    const row = screen.getByText(title).closest('[draggable="true"]');
    if (!(row instanceof HTMLElement)) {
        throw new Error(`no draggable row for "${title}"`);
    }
    return row;
}

function orderedModuleTitles(): string[] {
    return screen
        .getAllByRole('button', { name: /^(Show|Hide) the lessons of / })
        .map((button) => (button.getAttribute('aria-label') ?? '').replace(/^(Show|Hide) the lessons of /, ''));
}

/**
 * happy-dom fires drag events with no DataTransfer, and the drag code reads
 * and writes one, so the test supplies the store the browser would.
 */
function dataTransfer(): DataTransfer {
    const store = new Map<string, string>();
    return {
        dropEffect: 'none',
        effectAllowed: 'none',
        setData: (format: string, value: string) => store.set(format, value),
        getData: (format: string) => store.get(format) ?? '',
    } as unknown as DataTransfer;
}

describe('curriculum tab', () => {
    it('shows the skeleton while the authoring curriculum loads', () => {
        renderTab(() => null);
        expect(screen.getByTestId('list-skeleton')).toBeInTheDocument();
    });

    it('lists modules with their lesson counts and draft lessons included', async () => {
        renderTab();
        expect(await screen.findByText('Perceivable content')).toBeInTheDocument();
        expect(screen.getByText('2 lessons')).toBeInTheDocument();
        expect(screen.getByText('1 lesson')).toBeInTheDocument();
    });

    it('shows the empty course state with the action the design names', async () => {
        renderTab(handler({ 'GET /v1/manage/courses/1/curriculum': jsonResponse({ course_id: 1, modules: [] }) }));
        expect(await screen.findByText('This course has no modules yet')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Add the first module' })).toBeInTheDocument();
    });

    it('renders the permission state when the caller may not teach the course', async () => {
        renderTab(handler({ 'GET /v1/manage/courses/1/curriculum': problemResponse(403) }));
        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
    });

    it('renders the load error with a retry', async () => {
        renderTab(handler({ 'GET /v1/manage/courses/1/curriculum': problemResponse(500) }));
        expect(await screen.findByText("Couldn't load the curriculum.")).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });

    it('expands a module to reveal its lessons in order', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        expect(screen.getByText('Text alternatives')).toBeInTheDocument();
        expect(screen.getByText('Colour checklist')).toBeInTheDocument();
        expect(screen.getByText('Draft')).toBeInTheDocument();
    });

    it('marks a video lesson that has captions, as the design does', async () => {
        const user = userEvent.setup();
        renderTab(
            handler({
                'GET /v1/manage/courses/1/curriculum': jsonResponse(
                    makeCurriculum({
                        modules: [
                            makeModule({
                                id: 2,
                                title: 'Perceivable content',
                                lessons: [
                                    makeLesson({
                                        id: 2,
                                        title: 'Captions and transcripts',
                                        content: {
                                            provider: 'youtube',
                                            url: 'https://youtu.be/abc',
                                            caption_url: 'https://example.test/captions.vtt',
                                        },
                                    }),
                                ],
                            }),
                        ],
                    }),
                ),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        expect(screen.getByText(/captions/)).toBeInTheDocument();
    });
});

describe('explicit order', () => {
    it('sends every module id in the wanted order when a module moves down', async () => {
        const user = userEvent.setup();
        const harness = renderTab(handler({ 'PUT /v1/manage/courses/1/modules/order': noContent() }));
        await screen.findByText('Getting started');
        await user.click(screen.getByRole('button', { name: 'Move module Getting started down' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/courses/1/modules/order');
            expect(write?.body).toEqual({ module_ids: [2, 1] });
        });
    });

    it('disables the move that would leave the list, so order is never implicit', async () => {
        renderTab();
        await screen.findByText('Getting started');
        expect(screen.getByRole('button', { name: 'Move module Getting started up' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Move module Perceivable content down' })).toBeDisabled();
    });

    it('moves a row from the drag handle with the arrow keys too', async () => {
        const user = userEvent.setup();
        const harness = renderTab(handler({ 'PUT /v1/manage/courses/1/modules/order': noContent() }));
        await screen.findByText('Getting started');
        screen.getByRole('button', { name: 'Reorder module Getting started' }).focus();
        await user.keyboard('{ArrowDown}');
        await waitFor(() => {
            expect(
                harness.requests.some((request) => request.path === '/v1/manage/courses/1/modules/order'),
            ).toBe(true);
        });
    });

    it('sends the lessons of one module in their new order', async () => {
        const user = userEvent.setup();
        const harness = renderTab(handler({ 'PUT /v1/manage/modules/2/lessons/order': noContent() }));
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Move lesson Colour checklist up' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/modules/2/lessons/order');
            expect(write?.body).toEqual({ lesson_ids: [3, 2] });
        });
    });

    it('says the write failed and keeps a retry that replays the same key', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        const harness = renderTab(
            handler({
                'PUT /v1/manage/courses/1/modules/order': () => {
                    attempts += 1;
                    return attempts === 1 ? problemResponse(500) : noContent();
                },
            }),
        );
        await screen.findByText('Getting started');
        await user.click(screen.getByRole('button', { name: 'Move module Getting started down' }));
        const retry = await screen.findByRole('button', { name: 'retry now' });
        await user.click(retry);
        await waitFor(() => {
            const writes = harness.requests.filter(
                (request) => request.path === '/v1/manage/courses/1/modules/order',
            );
            expect(writes).toHaveLength(2);
            expect(writes[0]?.headers['idempotency-key']).toBe(writes[1]?.headers['idempotency-key']);
        });
    });

    it('drops a lesson dragged onto another module without touching the module order', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'PUT /v1/manage/courses/1/modules/order': noContent(),
                'PUT /v1/manage/modules/1/lessons/order': noContent(),
                'PUT /v1/manage/modules/2/lessons/order': noContent(),
            }),
        );

        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Getting started' }));
        await user.click(screen.getByRole('button', { name: 'Show the lessons of Perceivable content' }));

        const source = rowOf('Welcome');
        const target = rowOf('Text alternatives');
        const transfer = dataTransfer();

        fireEvent.dragStart(source, { dataTransfer: transfer });
        fireEvent.dragOver(target, { dataTransfer: transfer });
        fireEvent.drop(target, { dataTransfer: transfer });

        await waitFor(() => {
            expect(screen.getByText('Getting started')).toBeInTheDocument();
        });

        expect(
            harness.requests.filter((request) => request.path.endsWith('/order')).map((request) => request.path),
        ).toEqual([]);
        expect(orderedModuleTitles()).toEqual(['Getting started', 'Perceivable content']);
    });

    it('drags a lesson onto a row of its own module and reorders only that module', async () => {
        const user = userEvent.setup();
        const harness = renderTab(handler({ 'PUT /v1/manage/modules/2/lessons/order': noContent() }));

        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));

        const transfer = dataTransfer();
        fireEvent.dragStart(rowOf('Colour checklist'), { dataTransfer: transfer });
        fireEvent.dragOver(rowOf('Text alternatives'), { dataTransfer: transfer });
        fireEvent.drop(rowOf('Text alternatives'), { dataTransfer: transfer });

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/modules/2/lessons/order');
            expect(write?.body).toEqual({ lesson_ids: [3, 2] });
        });
        expect(
            harness.requests.some((request) => request.path === '/v1/manage/courses/1/modules/order'),
        ).toBe(false);
    });

    it('puts the list back the way the server holds it when a reorder is refused', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'PUT /v1/manage/courses/1/modules/order': problemResponse(
                    422,
                    'Validation failed',
                    'The ordered list must contain every module of the course exactly once.',
                ),
            }),
        );
        await screen.findByText('Getting started');
        expect(orderedModuleTitles()).toEqual(['Getting started', 'Perceivable content']);

        await user.click(screen.getByRole('button', { name: 'Move module Getting started down' }));

        await waitFor(() => {
            expect(orderedModuleTitles()).toEqual(['Getting started', 'Perceivable content']);
        });
        await waitFor(() => {
            expect(
                harness.requests.filter((request) => request.path === '/v1/manage/courses/1/curriculum'),
            ).toHaveLength(2);
        });
    });

    it('calls a refused save refused, and offers no retry for a write that cannot succeed', async () => {
        const user = userEvent.setup();
        renderTab(
            handler({
                'PUT /v1/manage/courses/1/modules/order': problemResponse(
                    403,
                    'Forbidden',
                    'You are not an instructor of course 1.',
                ),
            }),
        );
        await screen.findByText('Getting started');
        await user.click(screen.getByRole('button', { name: 'Move module Getting started down' }));

        const banner = await screen.findByRole('alert');
        expect(banner).toHaveTextContent("Not saved. You don't manage this course.");
        expect(banner).not.toHaveTextContent("Couldn't load.");
        expect(within(banner).queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    });

    it('names a save that failed for any other reason and keeps its retry', async () => {
        const user = userEvent.setup();
        renderTab(handler({ 'PUT /v1/manage/courses/1/modules/order': problemResponse(500) }));
        await screen.findByText('Getting started');
        await user.click(screen.getByRole('button', { name: 'Move module Getting started down' }));

        const banner = await screen.findByRole('alert');
        expect(banner).toHaveTextContent('Not saved.');
        expect(within(banner).getByRole('button', { name: 'Try again' })).toBeInTheDocument();
    });
});

/*
 * Archive and restore. Archiving is a state, not a delete: the row leaves
 * the learner curriculum, every bit of progress stays, and the answer says
 * what that did to the course. All of that has to reach the reader.
 */
function withArchived() {
    return makeCurriculum({
        modules: [
            makeModule({
                id: 1,
                title: 'Getting started',
                position: 1,
                lessons: [
                    makeLesson({ id: 1, module_id: 1, title: 'Welcome', type: 'reading' }),
                    makeLesson({
                        id: 5,
                        module_id: 1,
                        title: 'Old introduction',
                        type: 'reading',
                        state: 'archived',
                    }),
                ],
            }),
            makeModule({
                id: 2,
                title: 'Perceivable content',
                position: 2,
                lessons: [makeLesson({ id: 2, module_id: 2, title: 'Text alternatives' })],
            }),
            makeModule({ id: 3, title: 'Retired unit', position: 3, state: 'archived', lessons: [] }),
        ],
    });
}

function archiveHandler(overrides: Record<string, Response | (() => Response)> = {}) {
    return handler({
        'GET /v1/manage/courses/1/curriculum': jsonResponse(withArchived()),
        ...overrides,
    });
}

/**
 * What the contract actually answers for an archive: the entity in its new
 * state and an outcome. The entity is only there because the shape has it;
 * everything the screen says comes from the outcome.
 */
function archiveResult(
    kind: 'module' | 'lesson',
    outcome: { lessons_archived: number; enrolments_affected: number; progress_recompute: 'queued' | 'not_needed' },
): Record<string, unknown> {
    return { [kind]: { id: 1, state: 'archived' }, outcome };
}

/**
 * The tab itself. Notes are read through this rather than through the whole
 * document, because announce() puts the same sentence in the live region
 * outside the tab and a bare query would match both.
 */
function curriculum(): HTMLElement {
    return screen.getByRole('region', { name: 'Curriculum' });
}

describe('archiving a module or a lesson', () => {
    it('asks first, and the question states what archiving does', async () => {
        const user = userEvent.setup();
        renderTab(archiveHandler());
        await user.click(await screen.findByRole('button', { name: 'Archive module Getting started' }));

        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('Archive this module?');
        expect(dialog).toHaveTextContent('leave the learner curriculum');
        expect(dialog).toHaveTextContent('keeps the progress they already have');
        expect(dialog).toHaveTextContent('may complete the course because of this');
    });

    it('leaves everything alone when the question is answered with Keep it', async () => {
        const user = userEvent.setup();
        const harness = renderTab(archiveHandler());
        await user.click(await screen.findByRole('button', { name: 'Archive module Getting started' }));
        await user.click(await screen.findByRole('button', { name: 'Keep it' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(harness.requests.some((request) => request.method === 'DELETE')).toBe(false);
    });

    it('closes on Escape without archiving, so the keyboard is a way out too', async () => {
        const user = userEvent.setup();
        const harness = renderTab(archiveHandler());
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Archive lesson Text alternatives' }));
        await screen.findByRole('dialog');
        await user.keyboard('{Escape}');

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        expect(harness.requests.some((request) => request.method === 'DELETE')).toBe(false);
    });

    it('archives a module and explains the recompute the outcome reports', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            archiveHandler({
                'DELETE /v1/manage/modules/1': jsonResponse(
                    archiveResult('module', { lessons_archived: 3, enrolments_affected: 2, progress_recompute: 'queued' }),
                ),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Archive module Getting started' }));
        await user.click(await screen.findByRole('button', { name: 'Archive module' }));

        await waitFor(() =>
            expect(
                within(curriculum()).getByText(/Module Getting started is archived\..*2 enrolments/),
            ).toBeInTheDocument(),
        );
        const write = harness.requests.find((request) => request.method === 'DELETE');
        expect(write?.path).toBe('/v1/manage/modules/1');
        expect(write?.headers['idempotency-key']).toBeDefined();
    });

    it('archives a lesson from its own row', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            archiveHandler({
                'DELETE /v1/manage/lessons/2': jsonResponse(
                    archiveResult('lesson', {
                        lessons_archived: 1,
                        enrolments_affected: 0,
                        progress_recompute: 'not_needed',
                    }),
                ),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Archive lesson Text alternatives' }));
        await user.click(await screen.findByRole('button', { name: 'Archive lesson' }));

        await waitFor(() =>
            expect(
                within(curriculum()).getByText('Lesson Text alternatives is archived.'),
            ).toBeInTheDocument(),
        );
        expect(harness.requests.find((request) => request.method === 'DELETE')?.path).toBe(
            '/v1/manage/lessons/2',
        );
    });

    it('promises no recompute when the server queued none', async () => {
        const user = userEvent.setup();
        renderTab(
            archiveHandler({
                'DELETE /v1/manage/modules/1': jsonResponse(
                    archiveResult('module', {
                        lessons_archived: 0,
                        enrolments_affected: 0,
                        progress_recompute: 'not_needed',
                    }),
                ),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Archive module Getting started' }));
        await user.click(await screen.findByRole('button', { name: 'Archive module' }));

        await waitFor(() =>
            expect(within(curriculum()).getByText('Module Getting started is archived.')).toBeInTheDocument(),
        );
    });

    it('lets the note be dismissed, and says nothing until the next one', async () => {
        const user = userEvent.setup();
        renderTab(
            archiveHandler({
                'DELETE /v1/manage/modules/1': jsonResponse(
                    archiveResult('module', {
                        lessons_archived: 1,
                        enrolments_affected: 1,
                        progress_recompute: 'queued',
                    }),
                ),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Archive module Getting started' }));
        await user.click(await screen.findByRole('button', { name: 'Archive module' }));
        await waitFor(() =>
            expect(within(curriculum()).getByText(/1 enrolment;/)).toBeInTheDocument(),
        );

        await user.click(within(curriculum()).getByRole('button', { name: 'Dismiss' }));
        expect(within(curriculum()).queryByText(/1 enrolment;/)).not.toBeInTheDocument();
    });

    it('finishes on a retry that lands, because the row is archived either way', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        const harness = renderTab(
            archiveHandler({
                'DELETE /v1/manage/modules/1': () => {
                    attempts += 1;
                    return attempts === 1
                        ? problemResponse(500)
                        : jsonResponse(
                              archiveResult('module', {
                                  lessons_archived: 1,
                                  enrolments_affected: 1,
                                  progress_recompute: 'queued',
                              }),
                          );
                },
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Archive module Getting started' }));
        await user.click(await screen.findByRole('button', { name: 'Archive module' }));
        await user.click(await screen.findByRole('button', { name: 'Try again' }));

        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        await waitFor(() =>
            expect(within(curriculum()).getByText(/Module Getting started is archived\./)).toBeInTheDocument(),
        );
        const writes = harness.requests.filter((request) => request.method === 'DELETE');
        expect(writes).toHaveLength(2);
        /* The retry replays the same logical write, so a first attempt that
           actually landed cannot archive twice. */
        expect(writes[0]?.headers['idempotency-key']).toBe(writes[1]?.headers['idempotency-key']);
    });

    it('keeps the row and names the refusal when the caller may not archive', async () => {
        const user = userEvent.setup();
        renderTab(
            archiveHandler({
                'DELETE /v1/manage/modules/1': problemResponse(
                    403,
                    'Forbidden',
                    'You are not an instructor of course 1.',
                ),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Archive module Getting started' }));
        await user.click(await screen.findByRole('button', { name: 'Archive module' }));

        expect(await screen.findByRole('alert')).toHaveTextContent("Not saved. You don't manage this course.");
        expect(screen.getByText('Getting started')).toBeInTheDocument();
    });
});

describe('the archive', () => {
    it('keeps archived rows out of the way until the reader asks for them', async () => {
        const user = userEvent.setup();
        renderTab(archiveHandler());
        await screen.findByText('Getting started');
        expect(screen.queryByText('Retired unit')).not.toBeInTheDocument();

        await user.click(screen.getByLabelText('Show 2 archived items'));
        expect(screen.getByText('Retired unit')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Restore module Retired unit' })).toBeInTheDocument();
    });

    it('counts the archived lessons of a module without listing them', async () => {
        renderTab(archiveHandler());
        expect(await screen.findByText(/1 archived lesson/)).toBeInTheDocument();
        expect(screen.queryByText('Old introduction')).not.toBeInTheDocument();
    });

    it('shows an archived lesson inside its module once archived rows are shown', async () => {
        const user = userEvent.setup();
        renderTab(archiveHandler());
        await user.click(await screen.findByLabelText('Show 2 archived items'));
        await user.click(screen.getByRole('button', { name: 'Show the lessons of Getting started' }));

        expect(screen.getByText('Old introduction')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Restore lesson Old introduction' })).toBeInTheDocument();
    });

    it('offers no edit and no move on an archived module, which would take it back out of the archive', async () => {
        const user = userEvent.setup();
        renderTab(archiveHandler());
        await user.click(await screen.findByLabelText('Show 2 archived items'));

        expect(screen.queryByRole('button', { name: 'Edit module Retired unit' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Move module Retired unit up' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Archive module Retired unit' })).not.toBeInTheDocument();
    });

    it('restores a module as a draft, and says that publishing is a separate decision', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            archiveHandler({
                'PATCH /v1/manage/modules/3': jsonResponse({
                    id: 3,
                    course_id: 1,
                    title: 'Retired unit',
                    summary: null,
                    position: 3,
                    state: 'draft',
                }),
            }),
        );
        await user.click(await screen.findByLabelText('Show 2 archived items'));
        await user.click(screen.getByRole('button', { name: 'Restore module Retired unit' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/modules/3');
            expect(write?.method).toBe('PATCH');
            expect(write?.body).toEqual({ state: 'draft' });
        });
        await waitFor(() =>
            expect(
                within(curriculum()).getByText(
                    'Module Retired unit is restored as a draft. Publish it to put it back in front of learners.',
                ),
            ).toBeInTheDocument(),
        );
    });

    it('restores a lesson the same way', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            archiveHandler({
                'PATCH /v1/manage/lessons/5': jsonResponse(
                    makeLesson({ id: 5, module_id: 1, title: 'Old introduction', type: 'reading' }),
                ),
            }),
        );
        await user.click(await screen.findByLabelText('Show 2 archived items'));
        await user.click(screen.getByRole('button', { name: 'Show the lessons of Getting started' }));
        await user.click(screen.getByRole('button', { name: 'Restore lesson Old introduction' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/lessons/5');
            expect(write?.method).toBe('PATCH');
            expect(write?.body).toEqual({ state: 'draft' });
        });
    });

    it('names the refusal when a restore is refused, and offers no retry that cannot work', async () => {
        const user = userEvent.setup();
        renderTab(archiveHandler({ 'PATCH /v1/manage/modules/3': problemResponse(403, 'Forbidden') }));
        await user.click(await screen.findByLabelText('Show 2 archived items'));
        await user.click(screen.getByRole('button', { name: 'Restore module Retired unit' }));

        const banner = await screen.findByRole('alert');
        expect(banner).toHaveTextContent("Not saved. You don't manage this course.");
        expect(within(banner).queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    });

    it('still carries an archived module in the order it writes, because it is a row of the course', async () => {
        const user = userEvent.setup();
        const harness = renderTab(archiveHandler({ 'PUT /v1/manage/courses/1/modules/order': noContent() }));
        await screen.findByText('Getting started');
        await user.click(screen.getByRole('button', { name: 'Move module Getting started down' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/courses/1/modules/order');
            expect(write?.body).toEqual({ module_ids: [2, 1, 3] });
        });
    });

    it('moves a lesson past its visible neighbour and keeps the archived one in the list', async () => {
        const user = userEvent.setup();
        const curriculum = makeCurriculum({
            modules: [
                makeModule({
                    id: 1,
                    title: 'Getting started',
                    position: 1,
                    lessons: [
                        makeLesson({ id: 1, module_id: 1, title: 'Welcome', type: 'reading' }),
                        makeLesson({ id: 5, module_id: 1, title: 'Old introduction', state: 'archived' }),
                        makeLesson({ id: 6, module_id: 1, title: 'Second lesson', type: 'reading' }),
                    ],
                }),
            ],
        });
        const harness = renderTab(
            handler({
                'GET /v1/manage/courses/1/curriculum': jsonResponse(curriculum),
                'PUT /v1/manage/modules/1/lessons/order': noContent(),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Getting started' }));
        await user.click(screen.getByRole('button', { name: 'Move lesson Second lesson up' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/modules/1/lessons/order');
            /* Only the moved lesson changes place: Welcome still comes
               before the archived row, exactly as the server holds it. */
            expect(write?.body).toEqual({ lesson_ids: [6, 1, 5] });
        });
    });
});

/*
 * Resource lesson files: typed as a storage path, or picked and uploaded to
 * the host's own endpoint. What the upload answers goes into the very
 * fields an author filled in by hand, and the stored payload is the same
 * either way.
 */
describe('resource files', () => {
    const server = new FakeXhrServer();

    beforeEach(() => {
        server.install();
    });

    afterEach(() => {
        server.restore();
    });

    function renderWithUpload(route: (request: RecordedRequest) => Response | null = handler()) {
        const config = makeConfig({
            urls: {
                courses: '/admin/courses',
                workbench: '/admin/courses/{course_id}',
                preview: '/course/{slug}',
                upload: '/host/upload',
            },
        });
        const harness = makeHarness(route, config);
        render(<CurriculumTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
        return harness;
    }

    async function openResourceForm(user: ReturnType<typeof userEvent.setup>) {
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Add a lesson to Perceivable content' }));
        await user.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: /Resource/ }));
        await screen.findByRole('region', { name: 'Edit lesson' });
    }

    it('keeps the typed storage path and offers no picker on a host with no upload endpoint', async () => {
        const user = userEvent.setup();
        renderTab();
        await openResourceForm(user);

        expect(screen.getByLabelText('Storage path')).toBeInTheDocument();
        expect(screen.queryByLabelText('Upload file 1')).not.toBeInTheDocument();
    });

    it('fills the path, the name and the size of the row it belongs to, and stores the path', async () => {
        const user = userEvent.setup();
        server.answerWith({
            status: 201,
            body: JSON.stringify({
                path: 'vidyalms/private/2026/09/checklist.pdf',
                name: 'checklist.pdf',
                size_bytes: 51200,
                mime: 'application/pdf',
                /* A private purpose answers no address: the API mints a
                   signed one when a learner asks for the file. */
                url: null,
            }),
        });
        const harness = renderWithUpload(
            handler({ 'POST /v1/manage/modules/2/lessons': jsonResponse(makeLesson({ id: 21, type: 'resource' }), 201) }),
        );
        await openResourceForm(user);

        await user.upload(screen.getByLabelText('Upload file 1'), pickedFile());

        await waitFor(() =>
            expect(screen.getByLabelText('Storage path')).toHaveValue(
                'vidyalms/private/2026/09/checklist.pdf',
            ),
        );
        expect(screen.getByLabelText('File name')).toHaveValue('checklist.pdf');
        expect(screen.getByLabelText('Size in bytes')).toHaveValue('51200');

        await user.type(screen.getByLabelText('Title'), 'Colour checklist');
        await user.click(screen.getByRole('button', { name: 'Add lesson' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/modules/2/lessons');
            expect(write?.body).toMatchObject({
                type: 'resource',
                content: {
                    files: [
                        {
                            path: 'vidyalms/private/2026/09/checklist.pdf',
                            name: 'checklist.pdf',
                            size_bytes: 51200,
                        },
                    ],
                },
            });
        });
    });

    it("shows the host's refusal and leaves the row empty", async () => {
        const user = userEvent.setup();
        server.answerWith({
            status: 415,
            contentType: 'application/problem+json',
            body: JSON.stringify({ title: 'Unsupported', detail: 'Executable files are never stored.' }),
        });
        renderWithUpload();
        await openResourceForm(user);

        await user.upload(screen.getByLabelText('Upload file 1'), pickedFile());

        expect(await screen.findByText('Executable files are never stored.')).toBeInTheDocument();
        expect(screen.getByLabelText('Storage path')).toHaveValue('');
    });
});

describe('add lesson menu', () => {
    it('lists exactly the lesson types the registry answers, with how each completes', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Add a lesson to Perceivable content' }));
        const menu = screen.getByRole('menu', { name: 'Lesson types' });
        const items = within(menu).getAllByRole('menuitem');
        expect(items).toHaveLength(5);
        expect(items[0]).toHaveTextContent('Video embed');
        expect(items[0]).toHaveTextContent('completes on view');
        expect(items[3]).toHaveTextContent('completes when graded');
        expect(items[4]).toHaveTextContent('completes on pass');
    });

    it('moves through the menu with the arrow keys and closes on Escape', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        const trigger = screen.getByRole('button', { name: 'Add a lesson to Perceivable content' });
        await user.click(trigger);
        await user.keyboard('{ArrowDown}');
        expect(within(screen.getByRole('menu')).getAllByRole('menuitem')[1]).toHaveFocus();
        await user.keyboard('{Escape}');
        expect(screen.queryByRole('menu')).not.toBeInTheDocument();
        expect(trigger).toHaveFocus();
    });

    it('opens the lesson form of the type that was chosen', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Add a lesson to Perceivable content' }));
        await user.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: /Reading/ }));
        expect(await screen.findByRole('region', { name: 'Edit lesson' })).toHaveTextContent('Add Reading lesson');
        expect(await screen.findByLabelText('Body')).toBeInTheDocument();
    });
});

describe('lesson form', () => {
    it('creates a video lesson with the payload its type validates', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'POST /v1/manage/modules/2/lessons': jsonResponse(makeLesson({ id: 9 }), 201),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Add a lesson to Perceivable content' }));
        await user.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: /Video embed/ }));
        await user.type(await screen.findByLabelText('Title'), 'Captions and transcripts');
        await user.type(screen.getByLabelText('Video URL'), 'https://youtu.be/xyz');
        await user.type(screen.getByLabelText('Duration (minutes)'), '5');
        await user.click(screen.getByRole('button', { name: 'Add lesson' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/modules/2/lessons');
            expect(write?.body).toMatchObject({
                title: 'Captions and transcripts',
                type: 'video',
                content: { provider: 'youtube', url: 'https://youtu.be/xyz', caption_url: null },
                state: 'published',
            });
        });
    });

    it('refuses to send a video lesson without a real address', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Add a lesson to Perceivable content' }));
        await user.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: /Video embed/ }));
        await user.type(await screen.findByLabelText('Title'), 'Broken');
        await user.type(screen.getByLabelText('Video URL'), 'not-a-url');
        await user.click(screen.getByRole('button', { name: 'Add lesson' }));
        expect(await screen.findByText('Enter an http or https address.')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('writes the assignment rules as their own replacement after the lesson', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({
                'POST /v1/manage/modules/2/lessons': jsonResponse(
                    makeLesson({ id: 12, type: 'assignment', content: { instructions: 'Audit' } }),
                    201,
                ),
                'PUT /v1/manage/lessons/12/assignment': jsonResponse({
                    lesson_id: 12,
                    instructions: 'Audit',
                    max_points: 100,
                    allowed_file_types: [],
                    max_file_size_kb: null,
                    max_files: 1,
                    due_at: null,
                    allow_resubmission: false,
                }),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Add a lesson to Perceivable content' }));
        await user.click(within(screen.getByRole('menu')).getByRole('menuitem', { name: /Assignment/ }));
        await user.type(await screen.findByLabelText('Title'), 'Audit exercise');
        await user.type(screen.getByLabelText('Instructions'), 'Audit a page');
        await user.click(screen.getByRole('button', { name: 'Add lesson' }));
        await waitFor(() => {
            expect(
                harness.requests.some((request) => request.path === '/v1/manage/lessons/12/assignment'),
            ).toBe(true);
        });
        const write = harness.requests.find((request) => request.path === '/v1/manage/lessons/12/assignment');
        expect(write?.body).toMatchObject({ max_points: 100, max_files: 1, allow_resubmission: false });
    });

    it('edits a stored lesson through PATCH and keeps its type', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            handler({ 'PATCH /v1/manage/lessons/2': jsonResponse(makeLesson({ id: 2, title: 'Renamed' })) }),
        );
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Edit lesson Text alternatives' }));
        const title = await screen.findByLabelText('Title');
        await user.clear(title);
        await user.type(title, 'Renamed');
        await user.click(screen.getByRole('button', { name: 'Save lesson' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/lessons/2');
            expect(write?.method).toBe('PATCH');
            expect(write?.body).toMatchObject({ title: 'Renamed' });
        });
    });

    it('keeps an unavailable type editable as metadata and never rewrites its content', async () => {
        const user = userEvent.setup();
        const curriculum = makeCurriculum({
            modules: [
                makeModule({
                    id: 2,
                    title: 'Perceivable content',
                    lessons: [
                        makeLesson({
                            id: 15,
                            title: 'SCORM package',
                            type: 'scorm',
                            content: { package: 'course.zip' },
                            content_available: false,
                        }),
                    ],
                }),
            ],
        });
        const harness = renderTab(
            handler({
                'GET /v1/manage/courses/1/curriculum': jsonResponse(curriculum),
                'PATCH /v1/manage/lessons/15': jsonResponse(makeLesson({ id: 15, type: 'scorm' })),
            }),
        );
        await user.click(await screen.findByRole('button', { name: 'Show the lessons of Perceivable content' }));
        await user.click(screen.getByRole('button', { name: 'Edit lesson SCORM package' }));
        expect(await screen.findByText('This lesson uses the scorm add-on')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save lesson' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/lessons/15');
            expect(write?.body).not.toHaveProperty('content');
        });
    });
});
