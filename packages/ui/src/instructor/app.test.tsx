import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it } from 'vitest';
import type { CourseCertificatePolicy } from '@vidyalms/api-sdk';
import { InstructorApp } from './app';
import {
    jsonResponse,
    lessonTypesFixture,
    makeConfig,
    makeCourse,
    makeCurriculum,
    makeHarness,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/*
 * The workbench shell: which screen the mount opens, the header the course
 * answers, the five tabs, and every boot state artboard 4b draws.
 */

function bootHandler(overrides: Partial<Record<string, Response>> = {}) {
    return (request: RecordedRequest): Response | null => {
        const key = `${request.method} ${request.path.split('?')[0] ?? ''}`;
        const override = overrides[key];
        if (override !== undefined) {
            return override.clone();
        }
        switch (key) {
            case 'GET /v1/manage/courses/1':
                return jsonResponse(makeCourse());
            case 'GET /v1/manage/courses/1/curriculum':
                return jsonResponse(makeCurriculum());
            case 'GET /v1/lesson-types':
                return jsonResponse({ items: lessonTypesFixture });
            default:
                return null;
        }
    };
}

function renderApp(handler: (request: RecordedRequest) => Response | null, config = makeConfig()) {
    const harness = makeHarness(handler, config);
    render(<InstructorApp />, { wrapper: harness.wrapper });
    return harness;
}

describe('workbench boot', () => {
    it('shows a shape-true skeleton while the course loads', () => {
        renderApp(() => null);
        expect(screen.getByTestId('workbench-skeleton')).toBeInTheDocument();
        expect(screen.getByRole('status')).toHaveTextContent('Loading the course');
    });

    it('renders the course header with state, enrolment count and completion rule', async () => {
        renderApp(bootHandler());
        expect(await screen.findByRole('heading', { name: 'Foundations of Web Accessibility' })).toBeInTheDocument();
        expect(screen.getByText(/Published · 128 enrolled · completion rule: all lessons/)).toBeInTheDocument();
    });

    it('names the percentage threshold in the header when that is the rule', async () => {
        renderApp(
            bootHandler({
                'GET /v1/manage/courses/1': jsonResponse(
                    makeCourse({ completion_rule: 'percentage', completion_threshold: 80 }),
                ),
            }),
        );
        expect(await screen.findByText(/completion rule: 80% of lessons/)).toBeInTheDocument();
    });

    it('offers preview as learner and back to courses from the host URL templates', async () => {
        renderApp(bootHandler());
        const preview = await screen.findByRole('link', { name: /Preview as learner/ });
        expect(preview).toHaveAttribute('href', '/course/web-accessibility');
        expect(screen.getByRole('link', { name: 'Back to courses' })).toHaveAttribute('href', '/admin/courses');
    });

    it('renders the nine tabs when their routes exist, with the gradebook queue depth on its badge', async () => {
        renderApp(bootHandler());
        expect(await screen.findByRole('tablist', { name: 'Course sections' })).toBeInTheDocument();
        expect(screen.getAllByRole('tab')).toHaveLength(9);
        for (const name of ['Curriculum', 'Quizzes', 'Learners', 'Reports', 'Runs', 'Notifications', 'Settings']) {
            expect(screen.getByRole('tab', { name })).toBeInTheDocument();
        }
        /* The badge is a number beside the label, so its meaning is spelled
           out for anyone who cannot see it sitting there. */
        expect(screen.getByRole('tab', { name: /Gradebook.*8 waiting to be graded/ })).toBeInTheDocument();
    });

    it('renders "you don\'t manage this course" for a 403, which is the contract\'s non-instructor answer', async () => {
        renderApp(bootHandler({ 'GET /v1/manage/courses/1': problemResponse(403, 'Forbidden') }));
        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
        expect(screen.getByText('Ask a site admin to add you as its instructor.')).toBeInTheDocument();
    });

    it('asks once for a refused course and leaves the skeleton, rather than retrying a decided answer', async () => {
        const harness = renderApp(bootHandler({ 'GET /v1/manage/courses/1': problemResponse(403, 'Forbidden') }));

        expect(await screen.findByText("You don't manage this course.")).toBeInTheDocument();
        expect(screen.queryByTestId('workbench-skeleton')).not.toBeInTheDocument();
        expect(
            harness.requests.filter((request) => request.path === '/v1/manage/courses/1'),
        ).toHaveLength(1);
    });

    it('renders a not-found state for a 404 without claiming a permission problem', async () => {
        renderApp(bootHandler({ 'GET /v1/manage/courses/1': problemResponse(404, 'Not found') }));
        expect(await screen.findByText('This course was not found.')).toBeInTheDocument();
    });

    /*
     * A 500 is worth one automatic retry, unlike a 4xx, so the server has to
     * keep failing through that one before the error state is reached. Two
     * attempts get there; the reader's own "Try again" is the third.
     */
    it('renders the load error with a retry that asks again', async () => {
        const user = userEvent.setup();
        let attempts = 0;
        const harness = renderApp((request) => {
            if (request.path.startsWith('/v1/manage/courses/1?') || request.path === '/v1/manage/courses/1') {
                attempts += 1;
                return attempts <= 2 ? problemResponse(500, 'Server error') : jsonResponse(makeCourse());
            }
            return bootHandler()(request);
        });
        expect(await screen.findByText("This course couldn't load")).toBeInTheDocument();
        expect(harness.requests.filter((request) => request.path === '/v1/manage/courses/1')).toHaveLength(2);
        await user.click(screen.getByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('heading', { name: 'Foundations of Web Accessibility' })).toBeInTheDocument();
        expect(harness.requests.filter((request) => request.path === '/v1/manage/courses/1')).toHaveLength(3);
    });

    it('renders the session-expired state above everything once a working session times out', async () => {
        const harness = makeHarness(bootHandler({ 'GET /v1/manage/courses/1': problemResponse(401, 'Unauthorized') }));
        harness.sessionTracker.observe(200);
        harness.sessionTracker.observe(401);
        render(<InstructorApp />, { wrapper: harness.wrapper });
        expect(await screen.findByTestId('session-expired')).toBeInTheDocument();
    });
});

describe('tabs', () => {
    afterEach(() => window.history.replaceState(null, '', window.location.pathname));

    it.each(['', '#host-anchor', '#vy/unknown'])('canonicalizes explicit selection of the current tab from "%s"', async (hash) => {
        window.history.replaceState(null, '', hash || window.location.pathname);
        const user = userEvent.setup();
        renderApp(bootHandler());
        const curriculum = await screen.findByRole('tab', { name: 'Curriculum' });
        expect(curriculum).toHaveAttribute('aria-selected', 'true');
        const panel = screen.getByRole('tabpanel');
        await user.click(curriculum);
        expect(window.location.hash).toBe('#vy/curriculum');
        expect(screen.getByRole('tabpanel')).toBe(panel);
    });

    it('canonicalizes the current Settings tab without discarding its certificate draft or bypassing the leave guard', async () => {
        window.history.replaceState(null, '', '#vy/settings/extra');
        const policy: CourseCertificatePolicy = {
            course_id: 1, revision: 3, enabled: true, template_id: null, issue_rule: 'course_completed', co_branding: 'site',
            expiry: { mode: 'inherit_template', days: null, at: null },
            resolved_template: { id: 12, name: 'Executive distinction', published_revision: 8,
                source: 'site_default', available: true, diagnostic: null },
            capabilities: { issue_rules: ['course_completed'], co_branding_modes: ['site'], expiry_modes: ['inherit_template'] },
        };
        const harness = renderApp(bootHandler({
            'GET /v1/manage/courses/1/certificate-policy': jsonResponse(policy),
            'GET /v1/manage/courses/1/certificate-templates': jsonResponse({ items: [], total: 0, page: 1, per_page: 10 }),
        }), makeConfig({ routes: ['/manage/courses/{id}/certificate-policy'] }));
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: /^Certificates Completion/ }));
        const issuance = await screen.findByLabelText('Issue a certificate when this course is completed');
        await user.click(issuance);
        expect(issuance).not.toBeChecked();
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
        await user.click(screen.getByRole('tab', { name: 'Settings' }));
        expect(window.location.hash).toBe('#vy/settings');
        expect(screen.getByLabelText('Issue a certificate when this course is completed')).toBe(issuance);
        expect(issuance).not.toBeChecked();
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
        await user.click(screen.getByRole('tab', { name: 'Curriculum' }));
        const confirmation = screen.getByRole('dialog', { name: 'Leave unsaved certificate settings?' });
        expect(window.location.hash).toBe('#vy/settings');
        await user.click(within(confirmation).getByRole('button', { name: 'Keep editing' }));
        expect(issuance).not.toBeChecked();
        expect(screen.getByRole('tab', { name: 'Settings' })).toHaveAttribute('aria-selected', 'true');
        expect(harness.requests.every((request) => request.method === 'GET')).toBe(true);
    });

    it('moves between tabs with the arrow keys and keeps one tab stop', async () => {
        const user = userEvent.setup();
        renderApp(bootHandler());
        const curriculum = await screen.findByRole('tab', { name: /Curriculum/ });
        curriculum.focus();
        expect(curriculum).toHaveAttribute('tabindex', '0');
        await user.keyboard('{ArrowRight}');
        expect(screen.getByRole('tab', { name: /Quizzes/ })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tab', { name: /Curriculum/ })).toHaveAttribute('tabindex', '-1');
        await user.keyboard('{End}');
        expect(screen.getByRole('tab', { name: /Publishing/ })).toHaveAttribute('aria-selected', 'true');
    });

    it('writes the chosen tab into the namespaced hash', async () => {
        const user = userEvent.setup();
        renderApp(bootHandler());
        await user.click(await screen.findByRole('tab', { name: /Settings/ }));
        expect(window.location.hash).toBe('#vy/settings');
        window.history.replaceState(null, '', ' ');
    });

    it.each([
        ['curriculum', 'Curriculum'],
        ['quizzes', 'Quizzes'],
        ['gradebook', /Gradebook/],
        ['learners', 'Learners'],
        ['settings', 'Settings'],
    ] as const)('opens the %s authoring tab from a direct-link reload', async (hash, name) => {
        window.history.replaceState(null, '', `#vy/${hash}`);
        renderApp(
            bootHandler({
                'GET /v1/manage/courses/1/learners': jsonResponse({ items: [], total: 0, page: 1, per_page: 25 }),
                'GET /v1/manage/courses/1/gradebook': jsonResponse({ items: [], total: 0, page: 1, per_page: 25 }),
            }),
        );
        expect(await screen.findByRole('tab', { name })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('tabpanel')).toHaveAttribute('id', `vy-tabpanel-${hash}`);
        window.history.replaceState(null, '', ' ');
    });
});

describe('course list mount', () => {
    const listConfig = makeConfig({ course: null });

    it('lists the courses the caller may act on and links into each workbench', async () => {
        renderApp(
            (request) =>
                request.path.startsWith('/v1/manage/courses?')
                    ? jsonResponse({
                          items: [makeCourse(), makeCourse({ id: 2, title: 'Inclusive design', state: 'draft' })],
                          total: 2,
                          page: 1,
                          per_page: 20,
                      })
                    : null,
            listConfig,
        );
        expect(await screen.findByRole('heading', { name: 'Courses' })).toBeInTheDocument();
        expect(
            await screen.findByRole('link', { name: 'Open Foundations of Web Accessibility' }),
        ).toHaveAttribute('href', '/admin/courses/1');
        expect(screen.getByRole('link', { name: 'Open Inclusive design' })).toHaveAttribute('href', '/admin/courses/2');
    });

    it('sends the search and the state filter as query parameters, never a client-side sort', async () => {
        const user = userEvent.setup();
        const harness = renderApp(
            (request) =>
                request.path.startsWith('/v1/manage/courses?')
                    ? jsonResponse({ items: [makeCourse()], total: 1, page: 1, per_page: 20 })
                    : null,
            listConfig,
        );
        await screen.findByRole('heading', { name: 'Courses' });
        await user.type(screen.getByRole('searchbox', { name: 'Search courses' }), 'access');
        await user.selectOptions(screen.getByRole('combobox', { name: 'State' }), 'draft');
        await waitFor(() => {
            const last = harness.requests[harness.requests.length - 1];
            expect(last?.path).toContain('q=access');
            expect(last?.path).toContain('state=draft');
        });
    });

    it('tells a caller with no courses to ask for instructorship instead of showing an empty table', async () => {
        renderApp((request) => (request.path.startsWith('/v1/manage/courses?') ? problemResponse(403) : null), listConfig);
        expect(await screen.findByText("You don't manage any courses.")).toBeInTheDocument();
    });

    it('shows the empty state when the caller may act but has nothing yet', async () => {
        renderApp(
            (request) =>
                request.path.startsWith('/v1/manage/courses?')
                    ? jsonResponse({ items: [], total: 0, page: 1, per_page: 20 })
                    : null,
            listConfig,
        );
        expect(await screen.findByText('No courses yet')).toBeInTheDocument();
    });
});
