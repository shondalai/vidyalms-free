import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ManagedCourseDetail } from '@vidyalms/api-sdk';
import { FakeXhrServer } from '@/shared/testing/fake-xhr';
import { SettingsTab } from './tabs/settings-tab';
import {
    jsonResponse,
    makeConfig,
    makeCourse,
    makeHarness,
    noContent,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/** A picked file; happy-dom keeps the size the constructor gave it. */
function imageFile(name = 'cover.png', size = 2048): File {
    const file = new File(['x'], name, { type: 'image/png' });
    Object.defineProperty(file, 'size', { value: size });
    return file;
}

/*
 * The Settings tab: the course fields, the three completion rules with
 * their threshold, prerequisites, instructors and the two state moves that
 * PATCH deliberately cannot make.
 */

function handler(overrides: Record<string, Response | (() => Response)> = {}) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return typeof override === 'function' ? override() : override.clone();
        }
        switch (key) {
            case 'GET /v1/manage/course-categories':
                return jsonResponse({
                    items: [],
                    total: 0,
                    page: 1,
                    per_page: 20,
                    management_url: '/categories',
                });
            case 'GET /v1/manage/courses/1/instructors':
                return jsonResponse({ items: [{ user_id: 7, name: 'R. Okafor', role: 'lead' }] });
            /* The tab carries the course-level Restrict access box, which
               reads the condition palette and the stored rule. A site with
               no restriction stored answers a null tree, which is an answer
               and not a 404. */
            case 'GET /v1/manage/availability/conditions':
                return jsonResponse({ conditions: [], anchors: [], presets: [], default_visibility: 'shown' });
            case 'GET /v1/manage/courses/1/availability':
                return jsonResponse({
                    subject_type: 'course',
                    subject_id: 1,
                    surface: 'content_gating',
                    tree: null,
                    rule_version: 1,
                    default_visibility: 'shown',
                    updated_at: null,
                });
            case 'GET /v1/manage/courses/1/curriculum':
                return jsonResponse({ course_id: 1, modules: [] });
            case 'GET /v1/manage/courses':
                return jsonResponse({
                    items: [makeCourse(), makeCourse({ id: 2, title: 'Inclusive design' })],
                    total: 2,
                    page: 1,
                    per_page: 100,
                });
            default:
                return null;
        }
    };
}

let initialSection: 'details' | 'access' | 'publishing' | 'team' = 'details';
afterEach(() => { initialSection = 'details'; });

function renderTab(
    course: ManagedCourseDetail = makeCourse(),
    route: (request: RecordedRequest) => Response | null = handler(),
) {
    const harness = makeHarness(route);
    render(<SettingsTab course={course} narrow={false} initialSection={initialSection} />, { wrapper: harness.wrapper });
    return harness;
}

describe('course fields', () => {
    it('writes the fields the design lists and never the state', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse(),
            handler({ 'PATCH /v1/manage/courses/1': jsonResponse(makeCourse({ title: 'Renamed' })) }),
        );
        const title = screen.getByLabelText('Title');
        await user.clear(title);
        await user.type(title, 'Renamed');
        await user.type(screen.getByLabelText('Summary'), 'A short summary');
        await user.selectOptions(screen.getByLabelText('Visibility'), 'unlisted');
        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.method === 'PATCH');
            expect(write?.path).toBe('/v1/manage/courses/1');
            expect(write?.body).toEqual({
                title: 'Renamed',
                slug: makeCourse().slug, level: makeCourse().level, language: makeCourse().language,
                featured: makeCourse().featured, timezone: null, starts_at: null,
                category_id: null,
                summary: 'A short summary',
                description: null,
                image: null,
                visibility: 'unlisted',
                price_mode: 'free',
                completion_rule: 'all_lessons',
                completion_threshold: null,
            });
        });
    });

    it('asks for a threshold only for the percentage rule, and sends it', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse(),
            handler({ 'PATCH /v1/manage/courses/1': jsonResponse(makeCourse()) }),
        );
        expect(screen.queryByLabelText('Threshold (%)')).not.toBeInTheDocument();
        await user.selectOptions(screen.getByLabelText('How this course completes'), 'percentage');
        const threshold = await screen.findByLabelText('Threshold (%)');
        await user.clear(threshold);
        await user.type(threshold, '75');
        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.method === 'PATCH');
            expect(write?.body).toMatchObject({ completion_rule: 'percentage', completion_threshold: 75 });
        });
    });

    it('chooses a native category and writes its id with the course', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse(),
            handler({
                'GET /v1/manage/course-categories': jsonResponse({
                    items: [
                        {
                            id: 17,
                            name: 'Leadership',
                            parent_id: 3,
                            label: 'Professional development / Leadership',
                            state: 'published',
                            edit_url: '/categories/17/edit',
                        },
                    ],
                    total: 1,
                    page: 1,
                    per_page: 20,
                    management_url: '/categories',
                }),
                'PATCH /v1/manage/courses/1': jsonResponse(makeCourse({ category_id: 17 })),
            }),
        );

        await user.click(screen.getByRole('button', { name: 'Choose category' }));
        await user.click(await screen.findByRole('button', { name: 'Professional development / Leadership' }));
        await user.click(screen.getByRole('button', { name: 'Save settings' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.method === 'PATCH');
            expect(write?.body).toMatchObject({ category_id: 17 });
        });
    });

    it('refuses a threshold outside 1 to 100 before it reaches the server', async () => {
        const user = userEvent.setup();
        const harness = renderTab();
        await user.selectOptions(screen.getByLabelText('How this course completes'), 'percentage');
        const threshold = await screen.findByLabelText('Threshold (%)');
        await user.clear(threshold);
        await user.type(threshold, '0');
        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        expect(await screen.findByText('Enter a whole number between 0 and 100.')).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method === 'PATCH')).toBe(false);
    });

    it('points the final-quiz rule at where that quiz is marked', async () => {
        const user = userEvent.setup();
        renderTab();
        await user.selectOptions(screen.getByLabelText('How this course completes'), 'pass_final');
        expect(await screen.findByText('Mark one quiz as the final quiz in its settings.')).toBeInTheDocument();
    });

    it('says the save failed and offers the retry that replays it', async () => {
        const user = userEvent.setup();
        renderTab(makeCourse(), handler({ 'PATCH /v1/manage/courses/1': problemResponse(500) }));
        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        expect(await screen.findByRole('button', { name: 'retry now' })).toBeInTheDocument();
    });
});

/*
 * The course image: typed as an address, or picked and uploaded to the
 * host's own endpoint. The picker only exists where the host gave one, so a
 * site older than that endpoint keeps exactly the field it had.
 */
describe('course image', () => {
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
        render(<SettingsTab course={makeCourse()} narrow={false} />, { wrapper: harness.wrapper });
        return harness;
    }

    it('offers the typed address alone on a host with no upload endpoint', () => {
        renderTab();
        expect(screen.getByLabelText('Image URL')).toBeInTheDocument();
        expect(screen.queryByLabelText('Upload an image')).not.toBeInTheDocument();
    });

    it('writes the uploaded address into the same field the author typed into', async () => {
        const user = userEvent.setup();
        server.answerWith({
            status: 201,
            body: JSON.stringify({
                path: 'vidyalms/2026/09/cover.png',
                name: 'cover.png',
                size_bytes: 2048,
                mime: 'image/png',
                url: 'https://example.test/media/cover.png',
            }),
        });
        const harness = renderWithUpload(
            handler({ 'PATCH /v1/manage/courses/1': jsonResponse(makeCourse()) }),
        );

        await user.upload(screen.getByLabelText('Upload an image'), imageFile());
        await waitFor(() =>
            expect(screen.getByLabelText('Image URL')).toHaveValue('https://example.test/media/cover.png'),
        );

        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        await waitFor(() => {
            const write = harness.requests.find((request) => request.path === '/v1/manage/courses/1');
            expect(write?.body).toMatchObject({ image: 'https://example.test/media/cover.png' });
        });
    });

    it("leaves the field alone and shows the host's refusal when the upload is refused", async () => {
        const user = userEvent.setup();
        server.answerWith({
            status: 413,
            contentType: 'application/problem+json',
            body: JSON.stringify({ title: 'Too large', detail: 'The site accepts images up to 2 MB.' }),
        });
        renderWithUpload();

        await user.upload(screen.getByLabelText('Upload an image'), imageFile());

        expect(await screen.findByRole('alert')).toHaveTextContent('The site accepts images up to 2 MB.');
        expect(screen.getByLabelText('Image URL')).toHaveValue('');
    });
});

describe('course schedule', () => {
    it('keeps the API UTC timestamp format when saving an existing scheduled course', async () => {
        const user = userEvent.setup();
        const course = makeCourse({ starts_at: '2026-10-01 04:30:00', timezone: 'Asia/Kolkata' });
        const harness = renderTab(course, handler({ 'PATCH /v1/manage/courses/1': jsonResponse(course) }));
        await user.click(screen.getByRole('button', { name: 'Save settings' }));
        await waitFor(() => expect(harness.requests.find((request) => request.method === 'PATCH')?.body)
            .toMatchObject({ starts_at: '2026-10-01 04:30:00', timezone: 'Asia/Kolkata' }));
    });
});

describe('publishing', () => {
    beforeEach(() => { initialSection = 'publishing'; });
    it('publishes through its own route, not through a field', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse({ state: 'draft' }),
            handler({ 'POST /v1/manage/courses/1/publish': jsonResponse(makeCourse()) }),
        );
        expect(screen.getByText('This course is a draft. Learners cannot see it.')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Publish course' }));
        await waitFor(() => {
            expect(
                harness.requests.some((request) => request.path === '/v1/manage/courses/1/publish'),
            ).toBe(true);
        });
    });

    it('explains the 409 a course with no published lesson answers', async () => {
        const user = userEvent.setup();
        renderTab(
            makeCourse({ state: 'draft', lesson_count: 0 }),
            handler({
                'POST /v1/manage/courses/1/publish': problemResponse(
                    409,
                    'Conflict',
                    'A course needs at least one published lesson.',
                ),
            }),
        );
        await user.click(screen.getByRole('button', { name: 'Publish course' }));
        expect(await screen.findByText('A course needs at least one published lesson.')).toBeInTheDocument();
    });

    it('confirms an archive and says what enrolled learners keep', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse(),
            handler({ 'POST /v1/manage/courses/1/archive': jsonResponse(makeCourse({ state: 'archived' })) }),
        );
        await user.click(screen.getByRole('button', { name: 'Archive course' }));
        const dialog = await screen.findByRole('dialog');
        expect(dialog).toHaveTextContent('Everyone already enrolled keeps their access and their progress.');
        await user.click(within(dialog).getByRole('button', { name: 'Archive course' }));
        await waitFor(() => {
            expect(
                harness.requests.some((request) => request.path === '/v1/manage/courses/1/archive'),
            ).toBe(true);
        });
    });

    it('offers publishing again on an archived course', () => {
        renderTab(makeCourse({ state: 'archived' }));
        expect(screen.getByRole('button', { name: 'Publish again' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Archive course' })).not.toBeInTheDocument();
    });
});

describe('prerequisites', () => {
    beforeEach(() => { initialSection = 'access'; });
    it('writes the whole list, because a course left out is dropped', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse(),
            handler({
                'PUT /v1/manage/courses/1/prerequisites': jsonResponse({
                    items: [{ id: 2, title: 'Inclusive design', slug: 'inclusive-design' }],
                }),
            }),
        );
        expect(screen.getByText('No prerequisites.')).toBeInTheDocument();
        const picker = (await screen.findByRole('combobox', { name: 'Course' })) as HTMLSelectElement;
        await waitFor(() => expect(picker.options.length).toBeGreaterThan(1));
        await user.selectOptions(picker, '2');
        await user.click(screen.getByRole('button', { name: 'Add prerequisite' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.path === '/v1/manage/courses/1/prerequisites',
            );
            expect(write?.method).toBe('PUT');
            expect(write?.body).toEqual({ course_ids: [2] });
        });
    });

    it('never offers the course itself, which the server would refuse', async () => {
        renderTab();
        const picker = (await screen.findByRole('combobox', { name: 'Course' })) as HTMLSelectElement;
        await waitFor(() => expect(picker.options.length).toBeGreaterThan(1));
        expect([...picker.options].map((option) => option.value)).toEqual(['', '2']);
    });

    it('searches a bounded server page instead of downloading the course catalogue', async () => {
        const user = userEvent.setup();
        const harness = renderTab();

        await user.type(screen.getByRole('searchbox', { name: 'Find a prerequisite course' }), 'inclusive');

        await waitFor(() => {
            const request = harness.requests.find(
                (candidate) =>
                    candidate.method === 'GET' &&
                    candidate.path.startsWith('/v1/manage/courses?') &&
                    candidate.path.includes('q=inclusive'),
            );
            expect(request?.path).toContain('per_page=20');
            expect(request?.path).not.toContain('per_page=100');
        });
    });

    it('removing one writes the list that is left', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse({ prerequisites: [{ id: 2, title: 'Inclusive design', slug: 'inclusive-design' }] }),
            handler({ 'PUT /v1/manage/courses/1/prerequisites': jsonResponse({ items: [] }) }),
        );
        await user.click(screen.getByRole('button', { name: 'Remove the prerequisite Inclusive design' }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.path === '/v1/manage/courses/1/prerequisites',
            );
            expect(write?.body).toEqual({ course_ids: [] });
        });
    });

    it('puts a refused removal back, because a gate the server still holds must still be shown', async () => {
        const user = userEvent.setup();
        renderTab(
            makeCourse({ prerequisites: [{ id: 2, title: 'Inclusive design', slug: 'inclusive-design' }] }),
            handler({
                'PUT /v1/manage/courses/1/prerequisites': problemResponse(
                    403,
                    'Forbidden',
                    'You are not an instructor of course 1.',
                ),
            }),
        );
        await user.click(screen.getByRole('button', { name: 'Remove the prerequisite Inclusive design' }));

        expect(await screen.findByText("Not saved. You don't manage this course.")).toBeInTheDocument();
        await waitFor(() => expect(screen.getByText('Inclusive design')).toBeInTheDocument());
        expect(screen.queryByText('No prerequisites.')).not.toBeInTheDocument();
    });
});

describe('instructors', () => {
    beforeEach(() => { initialSection = 'team'; });
    it('lists the instructors of the course with their role', async () => {
        renderTab();
        expect(await screen.findByText('R. Okafor')).toBeInTheDocument();
        expect(screen.getByText('lead')).toBeInTheDocument();
    });

    it('assigns an instructor found through the account search', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse(),
            handler({
                'GET /v1/admin/users': jsonResponse({
                    items: [{ id: 55, name: 'Ana Costa', email: 'ana@example.test' }],
                }),
                'POST /v1/manage/courses/1/instructors': jsonResponse(
                    { user_id: 55, name: 'Ana Costa', role: 'lead' },
                    201,
                ),
            }),
        );
        await user.type(screen.getByLabelText('Add instructor'), 'ana');
        await user.click(await screen.findByRole('button', { name: /Ana Costa/ }));
        await waitFor(() => {
            const write = harness.requests.find(
                (request) => request.method === 'POST' && request.path === '/v1/manage/courses/1/instructors',
            );
            expect(write?.body).toEqual({ user_id: 55 });
        });
    });

    it('removes an instructor through the delete route', async () => {
        const user = userEvent.setup();
        const harness = renderTab(
            makeCourse(),
            handler({ 'DELETE /v1/manage/courses/1/instructors/7': noContent() }),
        );
        await user.click(await screen.findByRole('button', { name: 'Remove R. Okafor as an instructor' }));
        await waitFor(() => {
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'DELETE' && request.path === '/v1/manage/courses/1/instructors/7',
                ),
            ).toBe(true);
        });
    });

    it('says only a site manager may change instructors when the server refuses', async () => {
        const user = userEvent.setup();
        renderTab(
            makeCourse(),
            handler({ 'DELETE /v1/manage/courses/1/instructors/7': problemResponse(403) }),
        );
        await user.click(await screen.findByRole('button', { name: 'Remove R. Okafor as an instructor' }));
        expect(await screen.findByText('Only a site manager may change instructors.')).toBeInTheDocument();
    });
});
