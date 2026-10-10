import { act, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { InstructorApp } from './app';
import { CourseBuilder } from './course-builder';
import { jsonResponse, makeConfig, makeCourse, makeCurriculum, makeHarness } from './testing/harness';

afterEach(() => window.history.replaceState(null, '', window.location.pathname));

function harnessFor(routes: string[] | undefined) {
    return makeHarness((request) => {
        if (request.path === '/v1/manage/courses/1') return jsonResponse(makeCourse());
        if (request.path === '/v1/manage/courses/1/curriculum') return jsonResponse(makeCurriculum({ modules: [] }));
        return null;
    }, makeConfig({ routes }));
}

describe('optional course routes', () => {
    it.each([[], undefined])('hides unavailable workbench tabs and rejects their deep links (routes=%j)', async (routes) => {
        window.history.replaceState(null, '', '#vy/cohorts');
        const harness = harnessFor(routes);
        render(<InstructorApp />, { wrapper: harness.wrapper });
        expect(await screen.findByRole('tab', { name: 'Curriculum' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.queryByRole('tab', { name: 'Runs' })).not.toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Reports' })).not.toBeInTheDocument();
        expect(window.location.hash).toBe('#vy/curriculum');
        act(() => {
            window.history.replaceState(null, '', '#vy/reports');
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        });
        await waitFor(() => expect(window.location.hash).toBe('#vy/curriculum'));
        expect(harness.requests.some(({ path }) => /cohorts|item-analysis/.test(path))).toBe(false);
    });

    it('hides the builder Course runs tool and canonicalizes unsupported course links', async () => {
        window.history.replaceState(null, '', '#/course/1/vy/cohorts');
        const harness = harnessFor([]);
        render(<CourseBuilder courseId={1} />, { wrapper: harness.wrapper });
        expect(await screen.findByRole('tab', { name: 'Curriculum' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.queryByRole('button', { name: 'Course runs', hidden: true })).not.toBeInTheDocument();
        expect(window.location.hash).toBe('#/course/1/vy/curriculum');
        expect(harness.requests.some(({ path }) => path.includes('/cohorts'))).toBe(false);
    });

    it.each([
        ['/manage/courses/{id}/cohorts', 'Runs', 'Reports'],
        ['/manage/courses/{id}/reports/item-analysis', 'Reports', 'Runs'],
    ])('offers only the declared workbench route %s', async (route, present, absent) => {
        const harness = harnessFor([route]);
        render(<InstructorApp />, { wrapper: harness.wrapper });
        expect(await screen.findByRole('tab', { name: present })).toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: absent })).not.toBeInTheDocument();
    });

    it('keeps the builder Course runs tool when its route exists', async () => {
        const harness = harnessFor(['/manage/courses/{id}/cohorts']);
        render(<CourseBuilder courseId={1} />, { wrapper: harness.wrapper });
        expect(await screen.findByRole('button', { name: 'Course runs', hidden: true })).toBeInTheDocument();
    });
});
