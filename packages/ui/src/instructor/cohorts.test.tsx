import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CohortsTab } from './tabs/cohorts-tab';
import { jsonResponse, makeHarness, type RecordedRequest } from './testing/harness';

/*
 * Runs of one course.
 *
 * The behaviour under test is that the screen is REGISTRY-DRIVEN and not
 * edition-driven. Nothing here asks whether a package is installed: the
 * route answers 200 with an availability flag either way, and a site with
 * nothing registered gets a sentence about what a run would add rather than
 * an error. A screen that branched on an edition would be a licence check by
 * another name, which ADR 0002 forbids.
 *
 * The other rule with teeth is the move. It is three writes and no fourth,
 * and the screen says so BEFORE the operator commits, because "did that
 * delete their work" is the question a move has to answer in advance.
 */

const COHORT = {
    id: 9,
    course_id: 1,
    title: 'March run',
    code: 'MAR26',
    state: 'running',
    pacing: 'fixed',
    timezone: 'Europe/London',
    starts_at: '2026-03-02 09:00:00',
    ends_at: null,
    capacity: 180,
    member_count: 42,
};

const SECOND_COHORT = { ...COHORT, id: 10, title: 'April run', code: 'APR26', member_count: 7 };

function harnessFor(overrides: Record<string, unknown> = {}, onMove?: (request: RecordedRequest) => void) {
    return makeHarness((request: RecordedRequest) => {
        const path = request.path.split('?')[0] ?? '';
        if (request.method === 'GET' && path === '/v1/manage/courses/1/cohorts') {
            return jsonResponse({
                items: [COHORT, SECOND_COHORT],
                total: 2,
                page: 1,
                per_page: 25,
                available: true,
                ...overrides,
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/cohorts/9') {
            return jsonResponse({ ...COHORT, schedule_entry_count: 0, created_by: 7, created_at: '2026-01-01 09:00:00' });
        }
        if (request.method === 'GET' && path === '/v1/manage/cohorts/9/schedule') {
            return jsonResponse({
                cohort_id: 9,
                pacing: 'fixed',
                schedule_version: 1,
                entries: [],
                resolved: [],
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/cohorts/9/members') {
            return jsonResponse({
                items: [
                    {
                        cohort_id: 9,
                        user_id: 42,
                        learner: { id: 42, name: 'Priya Nair' },
                        role: 'learner',
                        state: 'active',
                        joined_at: '2026-03-02 09:00:00',
                        left_at: null,
                        anchor_at: '2026-03-02 09:00:00',
                        source: 'manual',
                        progress_percent: 40,
                        behind_schedule: true,
                    },
                    {
                        cohort_id: 9,
                        user_id: 43,
                        learner: { id: 43, name: 'Tom Weiss' },
                        role: 'learner',
                        state: 'waitlisted',
                        joined_at: '2026-03-02 09:00:00',
                        left_at: null,
                        anchor_at: null,
                        source: 'manual',
                        waitlist_position: 2,
                    },
                ],
                total: 2,
                page: 1,
                per_page: 25,
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/cohorts/9/date-overrides') {
            return jsonResponse({ items: [], merge_order: ['user', 'group', 'cohort'] });
        }
        if (request.method === 'GET' && path === '/v1/manage/availability/conditions') {
            return jsonResponse({ conditions: [], anchors: [], presets: [], default_visibility: 'shown' });
        }
        if (request.method === 'GET' && path === '/v1/manage/courses/1/curriculum') {
            return jsonResponse({ course_id: 1, modules: [] });
        }
        if (request.method === 'POST' && path === '/v1/manage/cohorts/9/members/42/move') {
            onMove?.(request);
            return jsonResponse({
                user_id: 42,
                from_cohort_id: 9,
                to_cohort_id: 10,
                enrolment_id: 501,
                progress_preserved: true,
                tasks_cancelled: 0,
                tasks_scheduled: 3,
            });
        }
        return null;
    });
}

describe('the runs tab', () => {
    it('says what a run would add when nothing registers them, and calls it a normal answer', async () => {
        const harness = makeHarness((request: RecordedRequest) => {
            const path = request.path.split('?')[0] ?? '';
            if (request.method === 'GET' && path === '/v1/manage/courses/1/cohorts') {
                return jsonResponse({
                    items: [],
                    total: 0,
                    page: 1,
                    per_page: 25,
                    available: false,
                    unavailable_reason: 'Scheduled runs need VidyaLMS Pro.',
                });
            }
            return null;
        });
        render(<CohortsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        expect(await screen.findByText('Scheduled runs need VidyaLMS Pro.')).toBeInTheDocument();
        expect(screen.getByText(/without runs it is self paced/)).toBeInTheDocument();
        /* Not an error state: the course works, and nothing here failed. */
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('lists the runs with their state, pacing and how full they are', async () => {
        const harness = harnessFor();
        render(<CohortsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        expect(await screen.findByRole('button', { name: 'March run' })).toBeInTheDocument();
        expect(screen.getAllByText('Running')).toHaveLength(2);
        expect(screen.getByText('42 / 180')).toBeInTheDocument();
    });

    it('shows every membership state, including the closed ones and the waitlist position', async () => {
        const harness = harnessFor();
        render(<CohortsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'March run' }));
        await userEvent.click(await screen.findByRole('tab', { name: 'Members' }));

        expect(await screen.findByText('Priya Nair')).toBeInTheDocument();
        expect(screen.getByText('Behind schedule')).toBeInTheDocument();
        /* Once as the state of Tom Weiss and once in the state filter. */
        expect(screen.getAllByText('Waitlisted').length).toBeGreaterThan(0);
        expect(screen.getByText('Waitlist position 2')).toBeInTheDocument();
    });

    it('states that a move keeps every bit of progress before the operator commits to it', async () => {
        const moves: RecordedRequest[] = [];
        const harness = harnessFor({}, (request) => moves.push(request));
        render(<CohortsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'March run' }));
        await userEvent.click(await screen.findByRole('tab', { name: 'Members' }));
        await userEvent.click((await screen.findAllByRole('button', { name: 'Move to another run' }))[0] as HTMLElement);

        expect(await screen.findByText(/No progress row, attempt, submission, grade or certificate/)).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Move learner' }));
        await waitFor(() => expect(moves).toHaveLength(1));
        expect(moves[0]?.body).toMatchObject({ to_cohort_id: 10 });
    });

    it('prints the override merge order rather than leaving support to guess it', async () => {
        const harness = harnessFor();
        render(<CohortsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'March run' }));
        await userEvent.click(await screen.findByRole('tab', { name: 'Date exceptions' }));

        expect(await screen.findByText(/user, group, cohort/)).toBeInTheDocument();
        expect(screen.getByText(/the most generous value wins/)).toBeInTheDocument();
    });
});
