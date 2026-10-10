import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { LearnersScreen } from '../screens/learners';
import {
    jsonResponse,
    makeConfig,
    makeCourse,
    makeEnrolment,
    makeHarness,
    makeLearnerRecord,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';
import { readLearnerDirectory } from './directory-state';
import { csvContent } from './workspace';

function setup(hash: string, handler: (request: RecordedRequest) => Response | null = () => null) {
    window.location.hash = hash;
    const harness = makeHarness(
        (request) => {
            const result = handler(request);
            if (result) return result;
            const path = new URL(request.path, 'http://localhost').pathname;
            if (path === '/v1/admin/learners/482') return jsonResponse(makeLearnerRecord());
            if (path === '/v1/admin/learners')
                return jsonResponse({ items: [], total: 0, page: 1, per_page: 20 });
            return null;
        },
        makeConfig({ screen: 'learners' }),
    );
    render(<LearnersScreen />, { wrapper: harness.wrapper });
    return harness;
}

beforeEach(() => {
    window.location.hash = '';
});

describe('modern learner directory', () => {
    it('validates bookmark parameters', () => {
        expect(readLearnerDirectory('/?page=-3&per_page=100000&sort=anything&order=sideways')).toEqual({
            search: '',
            page: 1,
            perPage: 20,
            sort: 'last_active',
            order: 'desc',
        });
        expect(readLearnerDirectory('/?search=Tom&per_page=50&page=2&order=asc')).toMatchObject({
            search: 'Tom',
            perPage: 50,
            page: 2,
            order: 'asc',
        });
    });

    it('changes the server page size and restores it after visiting a profile', async () => {
        const user = userEvent.setup();
        const harness = setup('/?page=2', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (url.pathname !== '/v1/admin/learners') return null;
            return jsonResponse({
                items: [
                    {
                        user_id: 482,
                        user: { id: 482, name: 'Tom Weiss', email: 'tom@example.org' },
                        course_count: 2,
                        active_course_count: 1,
                        completed_course_count: 1,
                        average_progress: 50,
                        last_activity_at: null,
                        last_enrolled_at: '2026-01-01',
                    },
                ],
                total: 10000,
                page: Number(url.searchParams.get('page')),
                per_page: Number(url.searchParams.get('per_page')),
            });
        });
        await screen.findByText('Tom Weiss');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Rows per page' }), '50');
        await waitFor(() => expect(harness.requests.at(-1)?.path).toContain('per_page=50'));
        expect(window.location.hash).toBe('#/?per_page=50');
        await user.click(screen.getByRole('button', { name: 'Open the record of Tom Weiss' }));
        await screen.findByRole('heading', { name: 'Tom Weiss' });
        await user.click(screen.getByRole('tab', { name: 'Credentials' }));
        expect(window.location.hash).toBe('#/learner/482?tab=credentials');
        await user.click(screen.getByRole('button', { name: 'Back to the search' }));
        expect(window.location.hash).toBe('#/?per_page=50');
        expect(screen.getByRole('combobox', { name: 'Rows per page' })).toHaveValue('50');
    });

    it('recovers an empty stale page to the last available server page', async () => {
        const harness = setup('/?page=99', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (url.pathname === '/v1/admin/learners')
                return jsonResponse({
                    items: [],
                    total: 30,
                    page: Number(url.searchParams.get('page')),
                    per_page: 20,
                });
            return null;
        });
        await waitFor(() => expect(window.location.hash).toBe('#/?page=2'));
        expect(harness.requests.some((request) => request.path.includes('page=2'))).toBe(true);
    });

    it('quotes CSV values and neutralizes spreadsheet formulas', () => {
        const csv = csvContent([
            ['Name', 'Email'],
            ['=SUM(A1:A3)', 'a,"b"@example.org'],
            [' \t+123', 'line\nbreak'],
        ]);
        expect(csv).toContain('"\'=SUM(A1:A3)"');
        expect(csv).toContain('"a,""b""@example.org"');
        expect(csv).toContain('"\' \t+123"');
        expect(csv).toContain('"line\nbreak"');
    });
});

describe('modern learner profile', () => {
    it('distinguishes an unavailable course total from ungraded work', async () => {
        setup('/learner/482?tab=assessments', (request) => {
            if (request.path.includes('/gradebook/items')) return jsonResponse({ items: [] });
            if (request.path.includes('/gradebook/grades'))
                return jsonResponse({
                    course_id: 1,
                    cells: [],
                    totals: [
                        {
                            user_id: 482,
                            current: null,
                            final: null,
                            grade_max: null,
                            final_grade_max: null,
                            aggregation_status: 'unknown',
                        },
                    ],
                    next_cursor: null,
                });
            return null;
        });
        expect(
            await screen.findByText(
                'Course totals are unavailable. Individual recorded grades remain visible.',
            ),
        ).toBeInTheDocument();
        expect(screen.getAllByText('Unavailable')).toHaveLength(2);
        expect(screen.queryByText('Not graded')).not.toBeInTheDocument();
    });

    it('keeps denied activity distinct from an empty timeline', async () => {
        const harness = setup('/learner/482?tab=activity', (request) =>
            request.path.includes('/timeline') ? problemResponse(403, 'Reporting access is required') : null,
        );
        expect(await screen.findByText('Activity access is restricted')).toBeInTheDocument();
        expect(screen.queryByText('No activity recorded for this selection.')).not.toBeInTheDocument();
        expect(harness.requests.filter((request) => request.path.includes('/timeline'))).toHaveLength(1);
        expect(screen.getByRole('button', { name: 'Back to the search' })).toBeInTheDocument();
    });

    it('loads learner-specific grades on demand, preserves null grades, and follows the server cursor', async () => {
        const user = userEvent.setup();
        const harness = setup('/learner/482', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (url.pathname.endsWith('/gradebook/items'))
                return jsonResponse({ items: [{ id: 7, title: 'Accessibility assessment' }] });
            if (url.pathname.endsWith('/gradebook/grades'))
                return jsonResponse({
                    course_id: 1,
                    cells: url.searchParams.has('cursor')
                        ? []
                        : [
                              {
                                  id: 9,
                                  grade_item_id: 7,
                                  course_id: 1,
                                  user_id: 482,
                                  raw_grade: null,
                                  raw_grade_max: '25',
                                  grading_progress: 'PendingManual',
                                  activity_progress: 'Submitted',
                                  feedback: 'Awaiting review',
                              },
                          ],
                    totals: [
                        { user_id: 482, current: null, grade_max: '25', final: '0', final_grade_max: '50' },
                    ],
                    next_cursor: url.searchParams.has('cursor') ? null : 'opaque-grade-cursor',
                });
            return null;
        });
        await screen.findByRole('heading', { name: 'Tom Weiss' });
        expect(harness.requests.some((request) => request.path.includes('gradebook'))).toBe(false);
        await user.click(screen.getByRole('tab', { name: 'Assessments' }));
        expect(await screen.findByText('Accessibility assessment')).toBeInTheDocument();
        expect(screen.getByText('0 / 50')).toBeInTheDocument();
        expect(
            within(screen.getByRole('table', { name: 'Assessment results' })).getByText('Not graded'),
        ).toBeInTheDocument();
        const query = new URL(
            harness.requests.find((request) => request.path.includes('gradebook/grades'))?.path ?? '',
            'http://localhost',
        );
        expect(query.searchParams.get('user_id')).toBe('482');
        expect(query.searchParams.get('limit')).toBe('50');
        await user.click(screen.getByRole('button', { name: 'Load more' }));
        await waitFor(() => expect(harness.requests.at(-1)?.path).toContain('cursor=opaque-grade-cursor'));
    });

    it('loads timeline pages only in Activity and resets the cursor for a course filter', async () => {
        const user = userEvent.setup();
        const harness = setup('/learner/482', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (url.pathname.endsWith('/timeline'))
                return jsonResponse({
                    totalItems: 2,
                    orderedItems: [
                        {
                            id: url.searchParams.has('cursor') ? 1 : 2,
                            type: url.searchParams.has('cursor') ? 'course.enrolled' : 'lesson.completed',
                            actor: { id: 482, type: 'user' },
                            object: { id: 5, type: 'lesson' },
                            target: { id: 1, type: 'course' },
                            published: '2026-09-01T10:00:00Z',
                            context: { progress: 100 },
                        },
                    ],
                    next:
                        url.searchParams.has('cursor') || url.searchParams.has('course_id')
                            ? null
                            : 'opaque-event-cursor',
                });
            return null;
        });
        await screen.findByRole('heading', { name: 'Tom Weiss' });
        expect(harness.requests.some((request) => request.path.endsWith('timeline'))).toBe(false);
        await user.click(screen.getByRole('tab', { name: 'Activity' }));
        await screen.findByText('Lesson completed');
        expect(harness.requests.at(-1)?.path).toContain('limit=25');
        await user.click(screen.getByRole('button', { name: 'Load more' }));
        await screen.findByText('Course enrolled');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Course' }), '1');
        await waitFor(() => {
            const query = new URL(harness.requests.at(-1)?.path ?? '', 'http://localhost');
            expect(query.searchParams.get('course_id')).toBe('1');
            expect(query.searchParams.has('cursor')).toBe(false);
        });
        expect(screen.queryByText('Course enrolled')).not.toBeInTheDocument();
    });

    it('shows a named lesson progress drilldown without leaving the profile', async () => {
        const user = userEvent.setup();
        setup('/learner/482', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (url.pathname.endsWith('/curriculum'))
                return jsonResponse({
                    modules: [{ id: 1, lessons: [{ id: 5, title: 'Keyboard navigation' }] }],
                });
            if (url.pathname === '/v1/manage/courses/1/learners/482')
                return jsonResponse({
                    enrolment: makeEnrolment({ id: 5 }),
                    summary: { lessons_total: 2, lessons_completed: 1, percent: 50 },
                    lessons: [
                        {
                            lesson_id: 5,
                            status: 'completed',
                            progress_percent: 100,
                            seconds_spent: 600,
                            completed_at: '2026-09-01',
                        },
                    ],
                });
            return null;
        });
        await user.click(await screen.findByRole('button', { name: 'View learning progress' }));
        const dialog = screen.getByRole('dialog');
        expect(await within(dialog).findByText('Keyboard navigation')).toBeInTheDocument();
        expect(within(dialog).getByText('1 / 2')).toBeInTheDocument();
        expect(window.location.hash).toBe('#/learner/482');
        await user.click(within(dialog).getByRole('button', { name: 'Close' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('supports direct credential bookmarks and filters the complete record', async () => {
        const user = userEvent.setup();
        setup('/learner/482?tab=credentials');
        const link = await screen.findByRole('link', { name: 'Verify credential' });
        expect(link).toHaveAttribute('href', 'https://example.test/verify/NL-7QK2-9M4X');
        await user.selectOptions(screen.getByRole('combobox', { name: 'Credential status' }), 'revoked');
        expect(screen.getByText('No records match these filters.')).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Verify credential' })).not.toBeInTheDocument();
    });

    it('enrols the profile learner through searchable server pages and retries with the same key', async () => {
        const user = userEvent.setup();
        let fail = true;
        const harness = setup('/learner/482', (request) => {
            const url = new URL(request.path, 'http://localhost');
            if (url.pathname === '/v1/manage/courses') {
                const page = Number(url.searchParams.get('page') ?? 1);
                return jsonResponse({
                    items: [makeCourse({ id: page, title: `Course on page ${page}` })],
                    total: 35,
                    page,
                    per_page: 20,
                });
            }
            if (request.method === 'POST' && url.pathname === '/v1/manage/courses/2/learners') {
                if (fail) {
                    fail = false;
                    return problemResponse(422, 'Please retry');
                }
                return jsonResponse({ enrolment: { id: 4 }, entitlement: null });
            }
            return null;
        });
        await user.click(await screen.findByRole('button', { name: 'Enrol in course' }));
        const dialog = screen.getByRole('dialog', { name: 'Grant access' });
        expect(within(dialog).getByText('tom.weiss@example.org')).toBeInTheDocument();
        expect(within(dialog).queryByRole('searchbox', { name: 'Learner' })).not.toBeInTheDocument();
        await within(dialog).findByRole('button', { name: 'Course on page 1' });
        await user.click(within(dialog).getByRole('button', { name: /^Next/ }));
        await user.click(await within(dialog).findByRole('button', { name: 'Course on page 2' }));
        await user.click(within(dialog).getByRole('button', { name: 'Grant access' }));
        await waitFor(() =>
            expect(harness.requests.filter((request) => request.method === 'POST')).toHaveLength(1),
        );
        await user.click(within(dialog).getByRole('button', { name: 'Grant access' }));
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
        const writes = harness.requests.filter((request) => request.method === 'POST');
        expect(writes).toHaveLength(2);
        expect(writes[0]?.body).toEqual({ user_id: 482, expires_at: null });
        expect(writes[0]?.headers['idempotency-key']).toBeTruthy();
        expect(writes[1]?.headers['idempotency-key']).toBe(writes[0]?.headers['idempotency-key']);
        expect(harness.requests.some((request) => request.path.includes('/admin/users'))).toBe(false);
    });
});
