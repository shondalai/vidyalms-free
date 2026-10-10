import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { FakeXhrServer } from '@/shared/testing/fake-xhr';
import { NewCourseDialog } from './new-course-dialog';
import { jsonResponse, makeConfig, makeCourse, makeHarness, type RecordedRequest } from '../testing/harness';
import type { AdminConfig } from '../config';

/*
 * The catalogue image of a new course: typed as an address, or picked and
 * uploaded to the host's own endpoint. A host with no endpoint keeps the
 * typed address, which is the only way this ever worked.
 */

const uploaded = {
    path: 'vidyalms/2026/09/cover.png',
    name: 'cover.png',
    size_bytes: 2048,
    mime: 'image/png',
    url: 'https://example.test/media/cover.png',
};

function imageFile(name = 'cover.png', size = 2048): File {
    const file = new File(['x'], name, { type: 'image/png' });
    Object.defineProperty(file, 'size', { value: size });
    return file;
}

function created(request: RecordedRequest): Response | null {
    if (request.method === 'GET' && request.path.startsWith('/v1/manage/course-categories?')) {
        return jsonResponse({ items: [], total: 0, page: 1, per_page: 20, management_url: '/categories' });
    }
    if (request.method === 'POST' && request.path === '/v1/manage/courses') {
        const body = request.body as { title: string };
        return jsonResponse({
            ...makeCourse({ id: 42, title: body.title, state: 'draft' }),
            description: null,
            created_by: 1,
            created_at: '2026-09-01 10:00:00',
            prerequisites: [],
            pending_grading_count: 0,
        });
    }
    return null;
}

function renderDialog(config: AdminConfig) {
    const harness = makeHarness(created, config);
    render(<NewCourseDialog open onOpenChange={() => undefined} onCreated={() => undefined} />, {
        wrapper: harness.wrapper,
    });
    return harness;
}

function withUpload(): AdminConfig {
    return makeConfig({
        urls: {
            workbench: '/admin/course/{course_id}',
            preview: '/course/{slug}',
            settings: '/admin/settings',
            upload: '/host/upload',
            screens: {},
        },
    });
}

describe('NewCourseDialog image', () => {
    const server = new FakeXhrServer();

    beforeEach(() => {
        server.install();
    });

    afterEach(() => {
        server.restore();
    });

    it('offers the typed address alone on a host with no upload endpoint', () => {
        renderDialog(makeConfig());
        expect(screen.getByLabelText('Image address')).toBeInTheDocument();
        expect(screen.queryByLabelText('Upload an image')).not.toBeInTheDocument();
    });

    it('creates the draft with the address the upload answered', async () => {
        const user = userEvent.setup();
        server.answerWith({ status: 201, body: JSON.stringify(uploaded) });
        const harness = renderDialog(withUpload());

        await user.type(screen.getByLabelText('Title'), 'Accessible Forms');
        await user.upload(screen.getByLabelText('Upload an image'), imageFile());
        await waitFor(() =>
            expect(screen.getByLabelText('Image address')).toHaveValue('https://example.test/media/cover.png'),
        );
        await user.click(screen.getByRole('button', { name: 'Create draft' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.method === 'POST');
            expect(write?.body).toEqual({
                title: 'Accessible Forms',
                category_id: null,
                image: 'https://example.test/media/cover.png',
                completion_rule: 'all_lessons',
                completion_threshold: null,
            });
        });
    });

    it('refuses an image over the limit before it reaches the host', async () => {
        const user = userEvent.setup();
        const harness = renderDialog(withUpload());

        await user.upload(screen.getByLabelText('Upload an image'), imageFile('huge.png', 9 * 1024 * 1024));

        expect(await screen.findByRole('alert')).toHaveTextContent('That file is larger than 8 MB.');
        expect(server.calls).toEqual([]);
        expect(harness.requests.filter((request) => request.method !== 'GET')).toEqual([]);
    });

    it("shows the host's refusal and creates nothing on its own", async () => {
        const user = userEvent.setup();
        const onCreated = vi.fn();
        server.answerWith({
            status: 403,
            contentType: 'application/json',
            body: JSON.stringify({ code: 'rest_forbidden', message: 'Sorry, you may not upload files.' }),
        });
        const harness = makeHarness(created, withUpload());
        render(<NewCourseDialog open onOpenChange={() => undefined} onCreated={onCreated} />, {
            wrapper: harness.wrapper,
        });

        await user.upload(screen.getByLabelText('Upload an image'), imageFile());

        expect(await screen.findByRole('alert')).toHaveTextContent('Sorry, you may not upload files.');
        expect(screen.getByLabelText('Image address')).toHaveValue('');
        expect(onCreated).not.toHaveBeenCalled();
    });
});

describe('NewCourseDialog category', () => {
    it('searches native categories and creates the course with the chosen id', async () => {
        const user = userEvent.setup();
        const harness = makeHarness((request) => {
            if (request.method === 'GET' && request.path.startsWith('/v1/manage/course-categories?')) {
                return jsonResponse({
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
                });
            }
            return created(request);
        });
        render(<NewCourseDialog open onOpenChange={() => undefined} onCreated={() => undefined} />, {
            wrapper: harness.wrapper,
        });

        await user.type(screen.getByLabelText('Title'), 'Leadership essentials');
        await user.click(screen.getByRole('button', { name: 'Choose category' }));
        await user.click(await screen.findByRole('button', { name: 'Professional development / Leadership' }));
        await user.click(screen.getByRole('button', { name: 'Create draft' }));

        await waitFor(() => {
            const write = harness.requests.find((request) => request.method === 'POST');
            expect(write?.body).toMatchObject({ category_id: 17 });
        });
    });
});

/*
 * Creating a course is not replay-safe on its own, and the SDK mints a fresh
 * Idempotency-Key per attempt. A form that did not hold its own key made a
 * second course out of a second submit of the same form.
 */
describe('NewCourseDialog idempotency', () => {
    it('sends a key, and the same one when the same form is submitted again', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        const harness = makeHarness((request) => {
            if (request.method === 'POST' && request.path === '/v1/manage/courses') {
                attempts += 1;
                if (attempts === 1) {
                    return new Response(
                        JSON.stringify({ type: 'about:blank', title: 'Server error', status: 500 }),
                        { status: 500, headers: { 'Content-Type': 'application/problem+json' } },
                    );
                }
            }
            return created(request);
        }, makeConfig());

        render(<NewCourseDialog open onOpenChange={() => undefined} onCreated={() => undefined} />, {
            wrapper: harness.wrapper,
        });

        await user.type(screen.getByLabelText('Title'), 'Foundations of Tea');
        await user.click(screen.getByRole('button', { name: 'Create draft' }));
        await screen.findByRole('alert');
        await user.click(screen.getByRole('button', { name: 'Create draft' }));

        await waitFor(() => {
            const writes = harness.requests.filter((request) => request.method === 'POST');
            expect(writes).toHaveLength(2);
        });

        const [first, second] = harness.requests.filter((request) => request.method === 'POST');
        expect(first?.headers['idempotency-key']).toBeDefined();
        expect(second?.headers['idempotency-key']).toBe(first?.headers['idempotency-key']);
    });
});
