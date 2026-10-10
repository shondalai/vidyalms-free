import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { AdminDashboard } from '@vidyalms/api-sdk';
import { DashboardScreen } from './dashboard';
import { activityCsv, completionRate } from './overview-panels';
import {
    jsonResponse,
    makeConfig,
    makeCourse,
    makeDashboard,
    makeHarness,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';

/*
 * Artboard 1a and the states of 2e that live on the dashboard: the four
 * counts, needs-attention, the sweep, top courses, the fresh install, the
 * counts-unavailable and permission states (both of which keep the course
 * list working), and the orphaned Pro data notice.
 */

interface World {
    dashboard?: Partial<AdminDashboard>;
    dashboardStatus?: number;
    coursesFail?: boolean;
}

function renderScreen(world: World = {}) {
    const config = makeConfig({ screen: 'dashboard' });
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/admin/dashboard') {
            if (world.dashboardStatus !== undefined) {
                return problemResponse(world.dashboardStatus, 'Refused');
            }
            return jsonResponse(makeDashboard(world.dashboard));
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/courses') {
            if (world.coursesFail === true) {
                return problemResponse(503, 'Unavailable');
            }
            return jsonResponse({
                items: [makeCourse({ id: 1, title: 'Foundations of Web Accessibility' })],
                total: 1,
                page: 1,
                per_page: 5,
            });
        }
        if (request.method === 'POST' && url.pathname === '/v1/manage/courses') {
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
    }, config);
    const view = render(<DashboardScreen />, { wrapper: harness.wrapper });
    return { ...harness, view };
}

describe('DashboardScreen', () => {
    it('shows the four counts with their secondary lines', async () => {
        renderScreen();
        expect(
            within(await screen.findByRole('list', { name: 'Site counts' })).getByText('14'),
        ).toBeInTheDocument();
        expect(screen.getByText('Published courses')).toBeInTheDocument();
        expect(screen.getByText('3 drafts')).toBeInTheDocument();
        expect(
            within(screen.getByRole('list', { name: 'Site counts' })).getByText('1,412'),
        ).toBeInTheDocument();
        expect(screen.getByText('61 suspended')).toBeInTheDocument();
        expect(screen.getByText('27 completions this month')).toBeInTheDocument();
        expect(screen.getByText('0 revoked')).toBeInTheDocument();

        const counts = screen.getByRole('list', { name: 'Site counts' });
        expect(within(counts).getAllByRole('listitem')).toHaveLength(4);
    });

    it('lists what needs attention, linking to the screens that act on it', async () => {
        renderScreen();
        expect(
            await screen.findByText('12 submissions waiting over 3 days across 4 courses'),
        ).toBeInTheDocument();
        expect(screen.getByText('23 entitlements expire within 14 days')).toBeInTheDocument();
        expect(screen.getAllByRole('link', { name: 'Courses' })[0]).toHaveAttribute('href', '/admin/courses');
        expect(screen.getByRole('link', { name: 'Enrolments' })).toHaveAttribute('href', '/admin/enrolments');
    });

    it('reports the sweep as the sweep itself reported it', async () => {
        renderScreen();
        expect(await screen.findByText(/expiry sweep" last ran/)).toHaveTextContent('(runs hourly)');
    });

    it('says so plainly when nothing is waiting', async () => {
        renderScreen({
            dashboard: {
                attention: {
                    grading_waiting: {
                        submissions: 0,
                        attempts: 0,
                        courses: 0,
                        older_than_days: 3,
                    },
                    expiring_entitlements: { count: 0, within_days: 14 },
                    expiry_sweep: { last_run_at: null, schedule: 'hourly' },
                    lessons_without_provider: { count: 0, types: [] },
                },
            },
        });
        expect(
            await screen.findByText(
                'Nothing is waiting. Grading is up to date and no entitlement expires soon.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByText('Scheduled task "expiry sweep" has not run yet')).toBeInTheDocument();
    });

    it('ranks the top courses and links each to its workbench', async () => {
        renderScreen();
        expect(
            await screen.findByRole('link', {
                name: /^Foundations of Web Accessibility/,
            }),
        ).toHaveAttribute('href', '/admin/courses#/course/1');
        expect(screen.getByRole('link', { name: /^ARIA in Practice/ })).toBeInTheDocument();
        expect(screen.getByText('128')).toBeInTheDocument();
    });

    it('counts both kinds of waiting work in one sentence', async () => {
        renderScreen({
            dashboard: {
                attention: {
                    grading_waiting: {
                        submissions: 2,
                        attempts: 1,
                        courses: 2,
                        older_than_days: 3,
                    },
                    expiring_entitlements: { count: 0, within_days: 14 },
                    expiry_sweep: { last_run_at: null, schedule: null },
                    lessons_without_provider: { count: 0, types: [] },
                },
            },
        });
        expect(
            await screen.findByText('2 submissions and 1 quiz attempt waiting over 3 days across 2 courses'),
        ).toBeInTheDocument();
    });

    it('keeps the course list working when the counts fail', async () => {
        renderScreen({ dashboardStatus: 503 });
        expect(await screen.findByText('Counts unavailable.')).toBeInTheDocument();
        expect(
            await screen.findByRole('link', {
                name: 'Open Foundations of Web Accessibility',
            }),
        ).toHaveAttribute('href', '/admin/courses#/course/1');
    });

    it('retries the counts on request', async () => {
        const { requests } = renderScreen({ dashboardStatus: 503 });
        await screen.findByText('Counts unavailable.');
        const before = requests.filter((request) => request.path.startsWith('/v1/admin/dashboard')).length;
        await userEvent.click(screen.getByRole('button', { name: 'Try again' }));
        await waitFor(() =>
            expect(requests.filter((request) => request.path.startsWith('/v1/admin/dashboard')).length).toBe(
                before + 1,
            ),
        );
    });

    it('tells a manager that courses are all they see, and still shows them', async () => {
        renderScreen({ dashboardStatus: 403 });
        expect(await screen.findByText('Managers see courses only.')).toBeInTheDocument();
        expect(
            await screen.findByRole('link', {
                name: 'Open Foundations of Web Accessibility',
            }),
        ).toBeInTheDocument();
    });

    it('shows the signed-out state for a refused administrator session', async () => {
        const { requests } = renderScreen({ dashboardStatus: 401 });
        expect(await screen.findByText('Sign in to continue.')).toBeInTheDocument();
        expect(screen.getByText('This page needs an administrator session.')).toBeInTheDocument();
        expect(screen.queryByText('Counts unavailable.')).toBeNull();
        expect(requests.some((request) => request.path.startsWith('/v1/manage/courses'))).toBe(false);
    });

    it('makes a failed fallback course list visible and retryable', async () => {
        const { requests } = renderScreen({
            dashboardStatus: 503,
            coursesFail: true,
        });
        const claim = await screen.findByText("Couldn't load.");
        const failure = claim.closest('[role="alert"]');
        expect(failure).not.toBeNull();

        const before = requests.filter((request) => request.path.startsWith('/v1/manage/courses')).length;
        await userEvent.click(within(failure as HTMLElement).getByRole('button', { name: 'Try again' }));
        await waitFor(() =>
            expect(requests.filter((request) => request.path.startsWith('/v1/manage/courses')).length).toBe(
                before + 1,
            ),
        );
    });

    it('welcomes a fresh install and creates the first course', async () => {
        const { requests } = renderScreen({
            dashboard: { courses: { published: 0, draft: 0, archived: 0 } },
        });
        expect(await screen.findByText('Welcome to VidyaLMS')).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'New course' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Title' }), 'Accessible Forms');
        await userEvent.click(screen.getByRole('button', { name: 'Create draft' }));

        expect((await screen.findAllByText('Draft created: Accessible Forms.'))[0]).toBeInTheDocument();
        expect(requests.some((request) => request.method === 'POST')).toBe(true);
    });

    it('states orphaned Pro data neutrally, with no upsell', async () => {
        renderScreen({
            dashboard: {
                attention: {
                    grading_waiting: {
                        submissions: 0,
                        attempts: 0,
                        courses: 0,
                        older_than_days: 3,
                    },
                    expiring_entitlements: { count: 0, within_days: 14 },
                    expiry_sweep: { last_run_at: null, schedule: 'hourly' },
                    lessons_without_provider: { count: 3, types: ['scorm'] },
                },
            },
        });
        const claim = await screen.findByText(
            '3 lessons of type scorm need an add-on that is not installed.',
        );
        expect(claim.parentElement).toHaveTextContent(
            'The lesson rows are intact and hidden from learners until the provider returns.',
        );
        expect(screen.queryByRole('link', { name: /buy|upgrade|pro/i })).toBeNull();
    });

    it('shows shape-true skeletons while the counts load', async () => {
        const { view } = renderScreen();
        expect(view.container.querySelector('[aria-busy="true"]')).not.toBeNull();
        /* And they go once the answer arrives. */
        await screen.findByText('Published courses');
        expect(view.container.querySelector('[aria-busy="true"]')).toBeNull();
    });

    it('requests the selected period from the API and changes the chart metric', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('heading', { name: 'Learning activity' });
        expect(screen.getByRole('img', { name: /enrolments: 465 this period, 30/ })).toBeInTheDocument();
        await userEvent.selectOptions(
            screen.getByRole('combobox', { name: 'Learning activity metric' }),
            'completions',
        );
        expect(screen.getByRole('img', { name: /completions: 30 this period, 0/ })).toBeInTheDocument();
        expect(screen.getByText(/No activity in the previous period to compare/)).toBeInTheDocument();
        await userEvent.selectOptions(
            screen.getByRole('combobox', { name: 'Activity reporting period' }),
            '7',
        );
        await waitFor(() =>
            expect(requests.some((request) => request.path.includes('period_days=7'))).toBe(true),
        );
    });

    it('supports keyboard navigation to a real activity feed', async () => {
        renderScreen();
        const overview = await screen.findByRole('tab', { name: 'Overview' });
        await screen.findByRole('heading', { name: 'Learning activity' });
        overview.focus();
        await userEvent.keyboard('{ArrowRight}');
        expect(screen.getByRole('tab', { name: /Activity feed/ })).toHaveFocus();
        expect(screen.getByRole('tabpanel')).toHaveAccessibleName(/Activity feed/);
        expect(
            within(screen.getByRole('tabpanel')).getByRole('link', {
                name: 'Learning systems',
            }),
        ).toHaveAttribute('href', '/admin/courses#/course/1');
    });

    it('keeps empty activity and unknown completion rates honest', async () => {
        const blank = makeDashboard().activity;
        renderScreen({
            dashboard: {
                activity: {
                    ...blank,
                    recent: [],
                    daily: blank.daily.map((day) => ({
                        ...day,
                        enrolments: 0,
                        completions: 0,
                        previous_enrolments: 0,
                        previous_completions: 0,
                    })),
                },
                enrolments: { active: 0, suspended: 0, completed: 0 },
            },
        });
        expect(await screen.findByText(/No learning activity recorded/)).toBeInTheDocument();
        expect(completionRate(0, 0)).toBeNull();
        expect(completionRate(1, 4)).toBe(25);
        expect(within(screen.getByRole('list', { name: 'Site counts' })).getByText('—')).toBeInTheDocument();
    });

    it('exports exactly the daily API values, including the comparison', () => {
        const data = makeDashboard({
            activity: {
                period_days: 7,
                recent: [],
                daily: [
                    {
                        date: '2026-09-01',
                        enrolments: 9,
                        completions: 2,
                        previous_enrolments: 3,
                        previous_completions: 1,
                    },
                ],
            },
        });
        expect(activityCsv(data)).toBe(
            'date_utc,enrolments,completions,previous_enrolments,previous_completions\r\n2026-09-01,9,2,3,1',
        );
    });
});
