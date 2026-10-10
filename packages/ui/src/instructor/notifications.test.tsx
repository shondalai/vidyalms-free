import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NotificationsTab } from './tabs/notifications-tab';
import { unknownPlaceholders } from './tabs/notifications/template-editor';
import { jsonResponse, makeHarness, type RecordedRequest } from './testing/harness';

/*
 * Notification rules.
 *
 * Three rules of the design are what these tests actually hold in place.
 *
 * A rule is created SWITCHED OFF, because the evidence on light-touch nudges
 * is that they measure small, are context dependent and are sometimes
 * negative. The default lives in the schema and the screen says why.
 *
 * The MESSAGE CLASS is read from the trigger and is not a field. Making it
 * one would let a site mark a marketing rule transactional and thereby skip
 * consent, drop the unsubscribe headers and defeat the marketing objection
 * rule with one form control.
 *
 * A rule whose trigger, audience or channel no longer resolves is reported
 * as INERT rather than fired with a guessed template, which is the published
 * miss of that registry.
 */

const CATALOGUE = {
    triggers: [
        {
            key: 'vidyalms.mail.enrolled',
            label: 'User enrols in a course',
            kind: 'event',
            edition: 'free',
            category_key: 'course_progress',
            class: 'transactional',
            default_audience_key: 'learner',
            available: true,
            placeholders: ['user.first_name', 'course.title'],
        },
        {
            key: 'vidyalms.sweep.inactivity',
            label: 'User has not logged in for X days',
            kind: 'sweep',
            edition: 'pro',
            category_key: 'course_progress',
            class: 'optional',
            default_audience_key: 'learner',
            available: true,
            supports_one_time_only: true,
            placeholders: ['user.first_name'],
        },
    ],
    audiences: [{ key: 'learner', label: 'The learner', edition: 'free', available: true }],
    channels: [
        { key: 'email', label: 'Email', edition: 'free', available: true, capabilities: ['SupportsHtmlBody'] },
        { key: 'sms', label: 'SMS', edition: 'business', available: false, capabilities: [], quiet_hours_statutory: true },
    ],
    categories: [{ key: 'course_progress', label: 'Course progress', class: 'transactional' }],
    schedule_kinds: [
        { key: 'immediate', label: 'Immediate', params_schema: {} },
        { key: 'relative', label: 'Relative', params_schema: {} },
    ],
    anchors: [{ key: 'enrolment.enrolled_at', label: 'When they enrolled', edition: 'free', available: true }],
};

const RULE = {
    id: 3,
    rule_key: 'welcome',
    trigger_key: 'vidyalms.mail.enrolled',
    audience_key: 'learner',
    category_key: 'course_progress',
    template_key: 'vidyalms.enrolment.welcome',
    schedule: { kind: 'immediate' },
    channels: ['email'],
    state: 'disabled',
    counters: { queued: 4, sent: 3, suppressed: 1, suppressed_by_reason: { frequency_cap: 1 } },
    inert: false,
};

const INERT_RULE = {
    ...RULE,
    id: 4,
    rule_key: 'nudge',
    trigger_key: 'vidyalms.sweep.behind_schedule',
    inert: true,
    missing_providers: ['vidyalms.sweep.behind_schedule'],
    counters: { queued: 0, sent: 0, suppressed: 0 },
};

function harnessFor(writes: RecordedRequest[] = []) {
    return makeHarness((request: RecordedRequest) => {
        const path = request.path.split('?')[0] ?? '';
        if (request.method === 'GET' && path === '/v1/manage/notifications/catalogue') {
            return jsonResponse(CATALOGUE);
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/rules') {
            return jsonResponse({ items: [RULE, INERT_RULE], total: 2, page: 1, per_page: 25 });
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/queue') {
            return jsonResponse({
                items: [
                    {
                        id: 1,
                        rule_id: 3,
                        trigger_key: 'vidyalms.mail.enrolled',
                        category_key: 'course_progress',
                        class: 'transactional',
                        recipient_user_id: 42,
                        recipient: { id: 42, name: 'Priya Nair' },
                        channel_key: 'email',
                        template_key: 'vidyalms.enrolment.welcome',
                        state: 'deferred',
                        occurrence_key: 'once',
                        run_at: '2026-09-04 22:10:00',
                        next_attempt_at: '2026-09-05 08:00:00',
                        attempts: 1,
                    },
                ],
                total: 1,
                page: 1,
                per_page: 25,
                counts_by_state: { deferred: 1 },
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/availability/conditions') {
            return jsonResponse({ conditions: [], anchors: [], presets: [], default_visibility: 'shown' });
        }
        if (request.method === 'GET' && path === '/v1/manage/courses/1/curriculum') {
            return jsonResponse({ course_id: 1, modules: [] });
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/templates') {
            return jsonResponse({ items: [], total: 0, page: 1, per_page: 25 });
        }
        if (request.method === 'PATCH' && path === '/v1/manage/notifications/rules/3') {
            writes.push(request);
            return jsonResponse({ ...RULE, state: 'enabled' });
        }
        if (request.method === 'POST' && path === '/v1/manage/notifications/rules') {
            writes.push(request);
            return jsonResponse({ ...RULE, id: 5 });
        }
        return null;
    });
}

describe('the rules list', () => {
    it('shows the per-rule counters grouped by suppression reason', async () => {
        const harness = harnessFor();
        render(<NotificationsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        expect(await screen.findByRole('button', { name: 'welcome' })).toBeInTheDocument();
        expect(screen.getByText('4 / 3 / 1')).toBeInTheDocument();
        /* One suppression count cannot tell a site whether a rule is
           throttled, blocked by consent or silenced by another rule. */
        expect(screen.getByText(/frequency_cap 1/)).toBeInTheDocument();
    });

    it('reports an unresolvable rule as inert and names what is missing', async () => {
        const harness = harnessFor();
        render(<NotificationsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        expect(await screen.findByRole('button', { name: 'nudge' })).toBeInTheDocument();
        expect(screen.getByText(/vidyalms.sweep.behind_schedule.*nothing registers/s)).toBeInTheDocument();
        expect(screen.getByText('Inert')).toBeInTheDocument();
    });

    it('says a new rule starts switched off, and why', async () => {
        const harness = harnessFor();
        render(<NotificationsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });
        expect(await screen.findByText(/turns one on deliberately/)).toBeInTheDocument();
    });

    it('turns a rule on through the contract rather than in local state', async () => {
        const writes: RecordedRequest[] = [];
        const harness = harnessFor(writes);
        render(<NotificationsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click((await screen.findAllByRole('button', { name: 'Turn on' }))[0] as HTMLElement);
        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.body).toMatchObject({ state: 'enabled' });
    });
});

describe('the rule editor', () => {
    it('reads the message class from the trigger and offers no control to change it', async () => {
        const harness = harnessFor();
        render(<NotificationsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'welcome' }));

        expect(await screen.findByText('Transactional')).toBeInTheDocument();
        expect(screen.getByText(/never set on a rule/)).toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Message class' })).not.toBeInTheDocument();
    });

    it('marks a channel the site does not have rather than hiding it', async () => {
        const harness = harnessFor();
        render(<NotificationsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('button', { name: 'welcome' }));
        expect(await screen.findByLabelText(/SMS \(Not installed\)/)).toBeInTheDocument();
    });
});

describe('placeholder validation', () => {
    /* Published per trigger, so the offered list and the accepted list are
       the same list, and an unknown placeholder is refused when the template
       is saved rather than left to fail at send. */
    it('names every placeholder the trigger does not offer', () => {
        expect(
            unknownPlaceholders(
                ['Hello {user.first_name}', 'You joined {course.title} on {course.secret_field}'],
                ['user.first_name', 'course.title'],
            ),
        ).toEqual(['course.secret_field']);
    });

    it('accepts a body that uses only what the trigger declares', () => {
        expect(unknownPlaceholders(['{user.first_name}'], ['user.first_name', 'course.title'])).toEqual([]);
    });

    it('ignores text that is not a placeholder at all', () => {
        expect(unknownPlaceholders(['{ not a token }', 'plain text'], [])).toEqual([]);
    });
});

describe('the queue', () => {
    it('says a deferred row is delayed and not dropped', async () => {
        const harness = harnessFor();
        render(<NotificationsTab courseId={1} narrow={false} />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('tab', { name: 'Queue' }));
        expect(await screen.findByText(/moves a send forward to the next permitted hour and never backwards/)).toBeInTheDocument();
        expect(screen.getByText('Priya Nair')).toBeInTheDocument();
        /* The next attempt is what the row is really waiting for. */
        expect(screen.getByText('2026-09-05 08:00:00')).toBeInTheDocument();
    });
});
