import { afterEach, describe, expect, it } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { jsonResponse, makeConfig, makeHarness, problemResponse, type RouteHandler } from '../testing/harness';
import { ReportsScreen } from './screen';
import type { Catalogue, ReportData, ReportDefinition } from './model';
import { categoryFor, formatValue, parametersFor, positiveId } from './model';

afterEach(() => {
    window.history.replaceState(null, '', '/');
});
const course: ReportDefinition = {
    key: 'course-overview',
    label: 'Course overview',
    capability: 'vidyalms.reports',
    tabular: false,
    exportable: false,
    honours_visibility: false,
    parameter_schema: { required: ['course_id'], properties: { course_id: { type: 'integer', minimum: 1 } } },
};
const roster: ReportDefinition = {
    ...course,
    key: 'enrolment-roster',
    label: 'Enrolment roster',
    tabular: true,
    exportable: true,
    honours_visibility: true,
    parameter_schema: {
        required: ['course_id'],
        properties: {
            course_id: { type: 'integer', minimum: 1 },
            status: { type: 'string', enum: ['active', 'completed'] },
        },
    },
};
const catalogue: Catalogue = {
    reports: [course, roster],
    total: 2,
    formats: [
        { key: 'csv', label: 'CSV', group: 'text', extension: 'csv', mime_type: 'text/csv', available: true },
        {
            key: 'xlsx',
            label: 'Excel',
            group: 'spreadsheet',
            extension: 'xlsx',
            mime_type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            available: true,
        },
        {
            key: 'pdf',
            label: 'PDF',
            group: 'document',
            extension: 'pdf',
            mime_type: 'application/pdf',
            available: false,
        },
    ],
    deliveries: [{ key: 'notification-link', label: 'Notification link', attaches: false }],
};
const overview = {
    items: [
        {
            course_id: 8,
            title: 'Inclusive leadership',
            state: 'published',
            enrolments: 100,
            active: 50,
            completed: 50,
            completion_rate: 0.5,
        },
    ],
    total: 50,
    page: 1,
    per_page: 20,
    summary: { courses: 50, enrolments: 1000, active: 720, completed: 280, completion_rate: 0.28 },
    generated_at: '2026-09-05 11:00:00',
    period: 'all_time',
    scope: 'assigned_courses',
    completion_basis: 'completed_enrolments',
};
const data: ReportData = {
    report: 'enrolment-roster',
    computed: true,
    computed_by: 'enrolment-roster',
    reason: null,
    items: [
        {
            user_id: 17,
            learner: 'Olivia Rhye',
            course_id: 8,
            status: 'active',
            progress_percent: 80,
            completed_at: null,
        },
    ],
    columns: [
        { key: 'learner', label: 'Learner' },
        { key: 'status', label: 'Status' },
        { key: 'progress_percent', label: 'Progress' },
        { key: 'completed_at', label: 'Completed' },
    ],
    total: 80,
    page: 1,
    per_page: 20,
};
function mount(route = '/', overrides?: RouteHandler) {
    window.history.replaceState(null, '', `/#${route}`);
    const config = makeConfig({ screen: 'reports' });
    config.urls.screens.learners = '/admin/learners';
    const harness = makeHarness((request) => {
        const custom = overrides?.(request);
        if (custom) return custom;
        const url = new URL(request.path, 'http://localhost');
        if (url.pathname === '/v1/manage/reports') return jsonResponse(catalogue);
        if (url.pathname === '/v1/manage/reports/overview') return jsonResponse(overview);
        if (url.pathname === '/v1/manage/courses/8') return jsonResponse({ id: 8, title: 'Inclusive leadership' });
        if (url.pathname === '/v1/manage/courses')
            return jsonResponse({
                items: [{ id: 8, title: 'Inclusive leadership' }],
                total: 2040,
                page: 1,
                per_page: 20,
            });
        if (url.pathname === '/v1/manage/reports/enrolment-roster') return jsonResponse(data);
        if (url.pathname === '/v1/manage/courses/8/reports/course-overview')
            return jsonResponse({
                ...data,
                report: 'course-overview',
                items: [],
                total: 0,
                enrolled_count: 80,
                completed_count: 42,
                average_progress: 72,
            });
        if (url.pathname === '/v1/manage/report-schedules' && request.method === 'GET')
            return jsonResponse({ items: [], total: 0, page: 1, per_page: 20 });
        return null;
    }, config);
    render(<ReportsScreen />, { wrapper: harness.wrapper });
    return harness;
}
describe('the reports workspace', () => {
    it('uses all-result server totals and preserves debounced overview filters', async () => {
        const harness = mount();
        expect(await screen.findByText('1,000')).toBeInTheDocument();
        expect(screen.getByText('28.0%')).toBeInTheDocument();
        expect(screen.getByText('50.0%')).toBeInTheDocument();
        expect(screen.getByText(/Your assigned courses/)).toBeInTheDocument();
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search course titles' }), 'safety');
        await waitFor(() =>
            expect(harness.requests.some((request) => request.path.includes('search=safety'))).toBe(true),
        );
        expect(window.location.hash).toContain('search=safety');
    });
    it('changes overview page size without leaving the filtered view and refreshes its data', async () => {
        const harness = mount('/?state=published&page=2');
        await screen.findByText('1,000');
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Rows per page' }), '50');
        await waitFor(() =>
            expect(
                harness.requests.some((request) =>
                    request.path.includes('/reports/overview?page=1&per_page=50&state=published'),
                ),
            ).toBe(true),
        );
        expect(window.location.hash).toContain('state=published');
        expect(window.location.hash).not.toContain('/report/');
        await waitFor(() => expect(screen.getByRole('button', { name: 'Refresh reports' })).toBeEnabled());
        const before = harness.requests.filter((request) => request.path.includes('/reports/overview')).length;
        await userEvent.click(screen.getByRole('button', { name: 'Refresh reports' }));
        await waitFor(() =>
            expect(
                harness.requests.filter((request) => request.path.includes('/reports/overview')).length,
            ).toBeGreaterThan(before),
        );
    });
    it.each(['/saved/123', '/bogus', '/jobs/55', '/?org=9007199254740993', '/exports/9007199254740993'])(
        'rejects an unsupported destination %s before querying',
        (route) => {
            const harness = mount(route);
            expect(screen.getByText('This destination is not available.')).toBeInTheDocument();
            expect(harness.requests).toHaveLength(0);
        },
    );
    it('recovers after browser history returns to a valid filtered overview', async () => {
        const harness = mount('/bogus');
        act(() => {
            window.history.replaceState(null, '', '/#/?page=2&state=published');
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        });
        await screen.findByText('1,000');
        expect(harness.requests.some((request) => request.path.includes('page=2&per_page=20&state=published'))).toBe(
            true,
        );
    });
    it('keeps a permission refusal distinct from an empty report', async () => {
        mount('/', () => problemResponse(403, 'Forbidden'));
        expect(await screen.findByText('Report access is unavailable')).toBeInTheDocument();
        expect(screen.queryByText('0.0%')).not.toBeInTheDocument();
    });
    it('discovers additional reports and filters the library without hiding unknown providers', async () => {
        mount('/library', (request) =>
            request.path === '/v1/manage/reports'
                ? jsonResponse({
                      ...catalogue,
                      reports: [
                          ...catalogue.reports,
                          { ...roster, key: 'partner-retention', label: 'Partner retention' },
                      ],
                  })
                : null,
        );
        expect(await screen.findByRole('button', { name: /Partner retention/ })).toBeInTheDocument();
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search reports' }), 'Partner');
        expect(screen.queryByRole('button', { name: /Enrolment roster/ })).not.toBeInTheDocument();
    });
    it('selects courses from bounded search results before running a required course report', async () => {
        const harness = mount('/report/course-overview');
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Course: Choose a course' }));
        expect(await screen.findByRole('button', { name: 'Inclusive leadership' })).toBeInTheDocument();
        expect(
            harness.requests.some(
                (request) => request.path.includes('/manage/courses?') && request.path.includes('per_page=20'),
            ),
        ).toBe(true);
        expect(harness.requests.some((request) => request.path.includes('/reports/course-overview'))).toBe(false);
        await user.click(screen.getByRole('button', { name: 'Inclusive leadership' }));
        await user.click(screen.getByRole('button', { name: 'Apply filters' }));
        await screen.findByText('42');
        expect(window.location.hash).toContain('course_id=8');
    });
    it('uses the policy-aware tabular route, applies filters explicitly and carries them through pagination', async () => {
        const harness = mount('/report/enrolment-roster?course_id=8&status=active');
        const user = userEvent.setup();
        await screen.findByText('Olivia Rhye');
        expect(
            harness.requests.some((request) =>
                request.path.includes('/manage/reports/enrolment-roster?course_id=8&status=active'),
            ),
        ).toBe(true);
        await user.selectOptions(screen.getByLabelText('Status'), 'completed');
        expect(harness.requests.some((request) => request.path.includes('status=completed'))).toBe(false);
        await user.click(screen.getByRole('button', { name: 'Apply filters' }));
        await waitFor(() =>
            expect(harness.requests.some((request) => request.path.includes('status=completed'))).toBe(true),
        );
        await user.click(
            within(screen.getByRole('navigation', { name: 'Report pages' })).getByRole('button', { name: /2/ }),
        );
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => request.path.includes('status=completed') && request.path.includes('page=2'),
                ),
            ).toBe(true),
        );
    });
    it('opens record details and links the learner to the existing SPA profile', async () => {
        mount('/report/enrolment-roster?course_id=8');
        await userEvent.click(await screen.findByRole('button', { name: 'View details Olivia Rhye' }));
        expect(screen.getByRole('link', { name: 'Open learner profile' })).toHaveAttribute(
            'href',
            '/admin/learners#/learner/17',
        );
        expect(screen.getByRole('dialog')).toHaveTextContent('80%');
    });
    it('exports the active filters, offers available formats, and preserves the replay key after an ambiguous failure', async () => {
        let attempts = 0;
        const harness = mount('/report/enrolment-roster?course_id=8&status=active', (request) => {
            if (request.method === 'POST') {
                attempts++;
                return attempts === 1
                    ? problemResponse(503, 'Try again')
                    : jsonResponse({
                          id: 42,
                          report: 'enrolment-roster',
                          format: 'xlsx',
                          state: 'queued',
                          expires_at: '2026-10-01T00:00:00Z',
                      });
            }
            if (request.path === '/v1/manage/report-runs/42')
                return jsonResponse({
                    id: 42,
                    report: 'enrolment-roster',
                    format: 'xlsx',
                    state: 'done',
                    row_count: 80,
                    truncated: false,
                    timezone: 'UTC',
                    expires_at: '2026-10-01T00:00:00Z',
                    download_url: 'https://files.example.test/report.xlsx',
                });
            return null;
        });
        const user = userEvent.setup();
        await screen.findByText('Olivia Rhye');
        await user.click(screen.getByRole('button', { name: 'Export report' }));
        expect(screen.queryByRole('option', { name: 'PDF' })).not.toBeInTheDocument();
        await user.selectOptions(screen.getByLabelText('File format'), 'xlsx');
        await user.click(screen.getByRole('button', { name: 'Create export' }));
        await screen.findByText('The export could not be started');
        await user.click(screen.getByRole('button', { name: 'Create export' }));
        expect(await screen.findByRole('link', { name: 'Download report' })).toHaveAttribute(
            'href',
            'https://files.example.test/report.xlsx',
        );
        const posts = harness.requests.filter((request) => request.method === 'POST');
        expect(posts).toHaveLength(2);
        expect(posts[0]?.headers['idempotency-key']).toBeTruthy();
        expect(posts[0]?.headers['idempotency-key']).toBe(posts[1]?.headers['idempotency-key']);
        expect(posts[0]?.path).toContain('course_id=8&status=active&format=xlsx');
    });
    it('loads organization reports only from the selected organization route', async () => {
        const harness = mount('/report/org-learner-progress?org=4', (request) => {
            if (request.path === '/v1/manage/organisations/4/reports')
                return jsonResponse({
                    ...catalogue,
                    reports: [
                        {
                            ...roster,
                            key: 'org-learner-progress',
                            label: 'Organization learner progress',
                            parameter_schema: {},
                        },
                    ],
                });
            if (request.path.startsWith('/v1/manage/organisations/4/reports/org-learner-progress'))
                return jsonResponse(data);
            return null;
        });
        await screen.findByText('Olivia Rhye');
        expect(harness.requests.some((request) => request.path.startsWith('/v1/manage/reports'))).toBe(false);
        expect(
            harness.requests.some((request) =>
                request.path.startsWith('/v1/manage/organisations/4/reports/org-learner-progress'),
            ),
        ).toBe(true);
    });
    it('creates a paused schedule carrying report filters and a human-readable send time', async () => {
        const harness = mount('/report/enrolment-roster?course_id=8&status=active', (request) =>
            request.method === 'POST' ? jsonResponse({ id: 9, title: 'Weekly learning', state: 'disabled' }) : null,
        );
        const user = userEvent.setup();
        await screen.findByText('Olivia Rhye');
        await user.click(screen.getByRole('button', { name: 'Create schedule' }));
        await user.type(screen.getByLabelText('Schedule name'), 'Weekly learning');
        expect(screen.getByLabelText('Send at')).toHaveValue('09:00');
        expect(screen.getByLabelText('Data visibility')).toHaveValue('recipient');
        await user.click(screen.getByRole('button', { name: 'Save schedule' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        const post = harness.requests.find((request) => request.method === 'POST');
        expect(post?.body).toMatchObject({
            state: 'disabled',
            run_at_minute: 540,
            render_as: 'recipient',
            parameters: { course_id: 8, status: 'active' },
        });
        expect(post?.headers['idempotency-key']).toBeTruthy();
    });
    it('shows empty and suppressed values as unavailable rather than zero', () => {
        expect(formatValue(null, 'completion_rate')).toBe('—');
        expect(formatValue(0, 'completion_rate')).toBe('0.0%');
        expect(formatValue('50.00', 'progress_percent')).toBe('50%');
        expect(formatValue('265489', 'user_id')).toBe('265489');
        expect(positiveId('9007199254740993')).toBeNull();
        expect(categoryFor('partner-retention').key).toBe('other');
        expect(
            parametersFor(roster, new URLSearchParams('course_id=8&status=active&org=4&private_override=yes&page=9')),
        ).toEqual({ course_id: 8, status: 'active' });
    });
    it('keeps a site-wide tabular provider on the policy-aware report endpoint when filtering by course', async () => {
        const harness = mount('/report/enrolment-roster?course_id=8', (request) =>
            request.path === '/v1/manage/reports'
                ? jsonResponse({ ...catalogue, reports: [{ ...roster, honours_visibility: false }] })
                : null,
        );
        await screen.findByText('Olivia Rhye');
        expect(harness.requests.some((request) => request.path.includes('/courses/8/reports/'))).toBe(false);
        expect(harness.requests.some((request) => request.path.includes('/reports/enrolment-roster?course_id=8'))).toBe(
            true,
        );
    });
});
