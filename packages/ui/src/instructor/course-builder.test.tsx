import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it } from 'vitest';
import { CourseBuilder } from './course-builder';
import { CourseFields } from './tabs/settings-tab';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import {
    jsonResponse,
    lessonTypesFixture,
    makeCourse,
    makeCurriculum,
    makeHarness,
    makeLesson,
    makeModule,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

function setup(overrides?: (request: RecordedRequest) => Response | null) {
    let lesson = makeLesson({ id: 2, module_id: 2 });
    const harness = makeHarness((request) => {
        const override = overrides?.(request);
        if (override) return override;
        const path = request.path.split('?')[0];
        if (path === '/v1/manage/courses/1') return jsonResponse(makeCourse());
        if (path === '/v1/manage/courses/1/curriculum')
            return jsonResponse(makeCurriculum({ modules: [makeModule({ id: 2, lessons: [lesson] })] }));
        if (path === '/v1/manage/lesson-types') return jsonResponse({ items: lessonTypesFixture });
        if (path === '/v1/manage/lessons/2' && request.method === 'PATCH') {
            lesson = { ...lesson, ...(request.body as object) };
            return jsonResponse(lesson);
        }
        if (path === '/v1/manage/courses/1/instructors') return jsonResponse({ items: [] });
        if (path === '/v1/manage/course-categories')
            return jsonResponse({
                items: [],
                total: 0,
                page: 1,
                per_page: 20,
                management_url: '/categories',
            });
        return null;
    });
    render(<CourseBuilder courseId={1} />, { wrapper: harness.wrapper });
    return harness;
}

beforeEach(() => window.history.replaceState(null, '', '#/course/1'));

describe('administrator course builder', () => {
    it('opens the first lesson and replaces removed management routes with the five authoring sections', async () => {
        window.history.replaceState(null, '', '#/course/1/vy/notifications');
        const harness = setup();
        const tabs = await screen.findByRole('tablist', { name: 'Course authoring' });
        expect(
            within(tabs)
                .getAllByRole('tab')
                .map((tab) => tab.textContent),
        ).toEqual(['Curriculum', 'Course details', 'Access', 'Assessment & completion', 'Publishing']);
        expect(await screen.findByRole('textbox', { name: 'Title' })).toHaveValue('Text alternatives');
        expect(window.location.hash).toBe('#/course/1/vy/curriculum');
        expect(
            harness.requests.some((request) =>
                /notifications|enrolments|reports|gradebook/.test(request.path),
            ),
        ).toBe(false);
    });

    it('keeps the lesson selected after saving and the next save updates the same lesson', async () => {
        const user = userEvent.setup();
        const harness = setup();
        const title = await screen.findByRole('textbox', { name: 'Title' });
        await user.clear(title);
        await user.type(title, 'A clearer lesson');
        await user.click(screen.getByRole('button', { name: 'Save lesson' }));
        await waitFor(() => expect(hasUnsavedWorkspaceChanges()).toBe(false));
        expect(screen.getByRole('heading', { name: 'A clearer lesson' })).toBeInTheDocument();
        await user.type(title, ' title');
        await user.click(screen.getByRole('button', { name: 'Save lesson' }));
        await waitFor(() =>
            expect(harness.requests.filter((request) => request.method === 'PATCH')).toHaveLength(2),
        );
        expect(
            harness.requests
                .filter((request) => request.method === 'PATCH')
                .every((request) => request.path === '/v1/manage/lessons/2'),
        ).toBe(true);
        expect(harness.requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('preserves a draft when navigation is cancelled and discards it only on an explicit choice', async () => {
        const user = userEvent.setup();
        const harness = setup();
        const title = await screen.findByRole('textbox', { name: 'Title' });
        await user.type(title, ' draft');
        await user.click(screen.getByRole('tab', { name: 'Course details' }));
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(title).toHaveValue('Text alternatives draft');
        expect(window.location.hash).toBe('#/course/1/vy/curriculum');
        await user.click(screen.getByRole('tab', { name: 'Access' }));
        await user.click(screen.getByRole('button', { name: 'Discard changes' }));
        expect(await screen.findByRole('combobox', { name: 'Visibility' })).toBeInTheDocument();
        expect(harness.requests.some((request) => request.method !== 'GET')).toBe(false);
    });

    it('guards external hash changes and access subsection changes', async () => {
        const user = userEvent.setup();
        window.history.replaceState(null, '', '#/course/1/vy/access');
        setup();
        await user.selectOptions(await screen.findByRole('combobox', { name: 'Visibility' }), 'private');
        await user.click(screen.getByRole('button', { name: /^Prerequisites/ }));
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('combobox', { name: 'Visibility' })).toHaveValue('private');
        act(() => {
            window.history.replaceState(null, '', '#/course/1/vy/publishing');
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        });
        expect(await screen.findByRole('dialog')).toBeInTheDocument();
        expect(window.location.hash).toBe('#/course/1/vy/access');
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('combobox', { name: 'Visibility' })).toHaveValue('private');
    });

    it('does not claim a failed save succeeded or close the editor', async () => {
        const user = userEvent.setup();
        setup((request) =>
            request.method === 'PATCH'
                ? problemResponse(422, 'Lesson was not saved', 'Fix the video URL')
                : null,
        );
        const title = await screen.findByRole('textbox', { name: 'Title' });
        await user.type(title, ' draft');
        await user.click(screen.getByRole('button', { name: 'Save lesson' }));
        expect(await screen.findByText('Fix the video URL')).toBeInTheDocument();
        expect(title).toHaveValue('Text alternatives draft');
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
    });
});

describe('separate course configuration forms', () => {
    it('saves paid access and restores the saved selection when the course is reopened', async () => {
        const user = userEvent.setup();
        let stored = makeCourse();
        const harness = makeHarness((request) => {
            if (request.method !== 'PATCH') return null;
            stored = { ...stored, ...(request.body as object) };
            return jsonResponse(stored);
        });
        const view = render(<CourseFields course={stored} mode="access" />, { wrapper: harness.wrapper });
        await user.selectOptions(screen.getByLabelText('Course access'), 'paid');
        expect(screen.getByText(/The shop supplies the displayed price/)).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save access settings' }));
        await waitFor(() => expect(hasUnsavedWorkspaceChanges()).toBe(false));
        expect(harness.requests.find((request) => request.method === 'PATCH')?.body).toEqual({
            visibility: 'public', price_mode: 'paid',
        });
        view.unmount();
        render(<CourseFields course={stored} mode="access" />, { wrapper: harness.wrapper });
        expect(screen.getByLabelText('Course access')).toHaveValue('paid');
        await user.selectOptions(screen.getByLabelText('Course access'), 'free');
        await user.click(screen.getByRole('button', { name: 'Save access settings' }));
        await waitFor(() => expect(stored.price_mode).toBe('free'));
    });

    it('clears the dirty state after a successful retry without changing the write identity', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        const harness = makeHarness((request) => {
            if (request.method !== 'PATCH') return null;
            attempts += 1;
            return attempts === 1
                ? problemResponse(422, 'Temporarily unavailable')
                : jsonResponse(makeCourse());
        });
        render(<CourseFields course={makeCourse()} mode="access" />, { wrapper: harness.wrapper });
        await user.selectOptions(screen.getByLabelText('Visibility'), 'private');
        await user.click(screen.getByRole('button', { name: 'Save access settings' }));
        const retry = await screen.findAllByRole('button', { name: /retry/i });
        await user.click(retry[0]!);
        await waitFor(() => expect(hasUnsavedWorkspaceChanges()).toBe(false));
        const writes = harness.requests.filter((request) => request.method === 'PATCH');
        expect(writes).toHaveLength(2);
        expect(writes[0]?.headers['idempotency-key']).toBe(writes[1]?.headers['idempotency-key']);
        expect(screen.getByLabelText('Visibility')).toHaveValue('private');
    });

    it('changes visibility without overwriting catalogue details, scheduling or completion', async () => {
        const user = userEvent.setup();
        const harness = makeHarness(() => jsonResponse(makeCourse({ visibility: 'private' })));
        render(
            <CourseFields
                course={makeCourse({ completion_rule: 'percentage', completion_threshold: 70 })}
                mode="access"
            />,
            { wrapper: harness.wrapper },
        );
        await user.selectOptions(screen.getByLabelText('Visibility'), 'private');
        await user.click(screen.getByRole('button', { name: 'Save access settings' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PATCH')?.body).toEqual({
                visibility: 'private',
                price_mode: 'free',
            }),
        );
    });

    it('validates the completion threshold and sends only completion settings', async () => {
        const user = userEvent.setup();
        const harness = makeHarness(() => jsonResponse(makeCourse()));
        render(
            <CourseFields
                course={makeCourse({ completion_rule: 'percentage', completion_threshold: 70 })}
                mode="completion"
            />,
            { wrapper: harness.wrapper },
        );
        const percent = screen.getByRole('textbox');
        await user.clear(percent);
        await user.type(percent, '120');
        await user.click(screen.getByRole('button', { name: 'Save completion rules' }));
        expect(harness.requests).toHaveLength(0);
        await user.clear(percent);
        await user.type(percent, '85');
        await user.click(screen.getByRole('button', { name: 'Save completion rules' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PATCH')?.body).toEqual({
                completion_rule: 'percentage',
                completion_threshold: 85,
            }),
        );
    });
});
