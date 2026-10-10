import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NotificationsScreen } from './screens/notifications';
import { jsonResponse, makeHarness, type RecordedRequest } from './testing/harness';

/*
 * The site side of notifications.
 *
 * Three privacy rules are what these tests hold in place.
 *
 * An address is stored and shown as a DIGEST and never in plain text, so the
 * suppression list is not a second copy of the mailing list.
 *
 * A manual suppression stops EVERY message class including the ones a site
 * may be obliged to send, so the screen warns before the operator does it
 * rather than after.
 *
 * The policy matrix offers only the states each cell may take. A forced
 * marketing category is refused at save because an objection to direct
 * marketing admits no balancing, and the form mirrors the rule instead of
 * inventing a second one.
 */

function harnessFor(writes: RecordedRequest[] = []) {
    return makeHarness((request: RecordedRequest) => {
        const path = request.path.split('?')[0] ?? '';
        if (request.method === 'GET' && path === '/v1/manage/providers/Notification') {
            return jsonResponse({
                registry_key: 'Notification',
                scope_type: 'site',
                scope_id: 0,
                providers: [
                    {
                        key: 'email',
                        label: 'Email',
                        group: 'channel',
                        order: 10,
                        edition: 'free',
                        available: true,
                        package: 'vidyalms-core',
                        groups: [
                            {
                                key: 'stream',
                                label: 'Stream',
                                fields: [{ key: 'reply_to', label: 'Reply-to address', type: 'string' }],
                            },
                        ],
                    },
                ],
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/providers/NotificationPolicy') {
            return jsonResponse({ registry_key: 'NotificationPolicy', scope_type: 'site', scope_id: 0, providers: [{
                key: 'quiet_hours', label: 'Quiet hours', group: 'NotificationPolicy', order: 0,
                edition: 'free', available: true, enabled: true, secret_ref_set: false,
                groups: [{ key: 'quiet', label: 'Quiet hours', fields: [
                    { key: 'enabled', label: 'Use quiet hours', type: 'boolean', default: false },
                    { key: 'start', label: 'Start time', type: 'string', default: '22:00' },
                ] }],
            }] });
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/catalogue') {
            return jsonResponse({ triggers: [{ key: 'enrolled', label: 'A learner enrols' }],
                audiences: [{ key: 'learner', label: 'The learner' }], channels: [], categories: [], anchors: [] });
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/rules') {
            return jsonResponse({ items: [{ id: 9, trigger_key: 'enrolled', audience_key: 'learner', channels: ['email'],
                course_id: 1, state: 'enabled', inert: false }], total: 1, page: 1, per_page: 25 });
        }
        if (request.method === 'PATCH' && path === '/v1/manage/notifications/rules/9') {
            writes.push(request);
            return jsonResponse({ id: 9, state: 'disabled' });
        }
        if (request.method === 'GET' && path === '/v1/manage/providers/DeliveryFeedbackMapper') {
            return jsonResponse({
                registry_key: 'DeliveryFeedbackMapper',
                scope_type: 'site',
                scope_id: 0,
                providers: [
                    {
                        key: 'rfc5965',
                        label: 'RFC 5965 abuse report',
                        group: 'feedback',
                        order: 10,
                        edition: 'free',
                        available: true,
                        groups: [],
                    },
                ],
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/policies') {
            return jsonResponse({
                cells: [
                    {
                        category_key: 'credential',
                        channel_key: 'email',
                        state: 'forced',
                        default_frequency: 'immediate',
                        allowed_states: ['disallowed', 'permitted', 'forced'],
                    },
                    {
                        category_key: 'marketing',
                        channel_key: 'email',
                        state: 'permitted',
                        default_frequency: 'never',
                        allowed_states: ['disallowed', 'permitted'],
                    },
                ],
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/suppressions') {
            return jsonResponse({
                items: [
                    {
                        id: 2,
                        channel_key: 'email',
                        address_digest: 'a3f1c0d9e8b7a6f5c4d3e2b1a0f9e8d7c6b5a4f3e2d1c0b9a8f7e6d5c4b3a2f1',
                        reason: 'hard_bounce',
                        soft_bounce_count: 0,
                        first_seen_at: '2026-08-01 09:00:00',
                        last_seen_at: '2026-08-02 09:00:00',
                        released_at: null,
                        active: true,
                    },
                ],
                total: 1,
                page: 1,
                per_page: 25,
            });
        }
        if (request.method === 'GET' && path === '/v1/manage/notifications/consents') {
            return jsonResponse({
                items: [
                    {
                        id: 4,
                        user_id: 42,
                        category_key: 'marketing',
                        state: 'withdrawn',
                        basis: 'soft_opt_in',
                        source: 'unsubscribe link',
                        occurred_at: '2026-08-20 12:00:00',
                    },
                ],
                total: 1,
                page: 1,
                per_page: 25,
            });
        }
        if (request.method === 'GET' && path === '/v1/admin/users') {
            return jsonResponse({ items: [{ id: 42, name: 'Priya Nair', email: 'priya@example.test' }] });
        }
        if (request.method === 'PUT' && path.startsWith('/v1/manage/providers/')) {
            writes.push(request);
            return jsonResponse({ registry_key: 'Notification', scope_type: 'site', scope_id: 0, providers: [] });
        }
        if (request.method === 'PUT' && path === '/v1/manage/notifications/policies') {
            writes.push(request);
            return jsonResponse({ cells: [] });
        }
        if (request.method === 'POST' && path === '/v1/manage/notifications/suppressions') {
            writes.push(request);
            return jsonResponse({
                id: 3,
                channel_key: 'email',
                address_digest: 'digest',
                reason: 'manual',
                first_seen_at: '2026-09-04 09:00:00',
                last_seen_at: '2026-09-04 09:00:00',
                released_at: null,
                active: true,
            });
        }
        return null;
    });
}

describe('provider settings', () => {
    it('shows channel configuration and a meaningful switch without the registry dropdown', async () => {
        const harness = harnessFor();
        render(<NotificationsScreen />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByText('Configure'));
        expect(await screen.findByLabelText('Reply-to address')).toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Providers' })).not.toBeInTheDocument();
        expect(screen.getByRole('switch', { name: 'Enable channel: Email' })).toBeChecked();
    });

    it('writes one provider settings row at the scope it was read at', async () => {
        const writes: RecordedRequest[] = [];
        const harness = harnessFor(writes);
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();

        await user.click(await screen.findByText('Configure'));
        await user.type(await screen.findByLabelText('Reply-to address'), 'help@example.test');
        await user.click(screen.getByRole('button', { name: 'Save changes' }));

        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.path).toBe('/v1/manage/providers/Notification/email/settings');
        expect(writes[0]?.body).toMatchObject({
            scope_type: 'site',
            scope_id: 0,
            params: { reply_to: 'help@example.test' },
        });
    });
});

describe('channel enablement', () => {
    it('saves the disabled state together with existing settings', async () => {
        const writes: RecordedRequest[] = [];
        const harness = harnessFor(writes);
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(await screen.findByRole('switch', { name: 'Enable channel: Email' }));
        expect(screen.getByText(/including account and certificate messages/)).toBeVisible();
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.body).toMatchObject({ enabled: false, params: { reply_to: null } });
        expect(screen.getByRole('switch', { name: 'Enable channel: Email' })).not.toBeChecked();
    });

    it('discarding an edit restores the channel switch without saving', async () => {
        const writes: RecordedRequest[] = [];
        const harness = harnessFor(writes);
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(await screen.findByRole('switch', { name: 'Enable channel: Email' }));
        await user.click(screen.getByRole('button', { name: 'Discard changes' }));
        expect(screen.getByRole('switch', { name: 'Enable channel: Email' })).toBeChecked();
        expect(writes).toHaveLength(0);
    });
});

describe('the site policy matrix', () => {
    it('offers only the states each cell may take, so forced is absent on a marketing category', async () => {
        const harness = harnessFor();
        render(<NotificationsScreen />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('tab', { name: 'Learner preferences' }));

        const credential = await screen.findByRole('combobox', { name: 'Certificates and credentials, Email' });
        expect(credential.querySelectorAll('option')).toHaveLength(3);

        const marketing = screen.getByRole('combobox', { name: 'Marketing, Email' });
        expect(marketing.querySelectorAll('option')).toHaveLength(2);
        expect(marketing.textContent).not.toContain('locked');
    });

    it('states the marketing rule on the screen rather than leaving it to a save-time refusal', async () => {
        const harness = harnessFor();
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        await userEvent.click(await screen.findByRole('tab', { name: 'Learner preferences' }));
        expect(await screen.findByText(/Marketing messages always respect consent/)).toBeInTheDocument();
    });
});

describe('suppressions', () => {
    it('shows a digest and never an address', async () => {
        const harness = harnessFor();
        render(<NotificationsScreen />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('tab', { name: 'Blocked addresses' }));

        expect(await screen.findByText('a3f1c0d9e8b7a6f5')).toBeInTheDocument();
        expect(screen.getByText(/never shown/)).toBeInTheDocument();
        expect(screen.queryByText(/@example.test/)).not.toBeInTheDocument();
    });

    it('warns that a manual suppression stops certificate and account mail too', async () => {
        const harness = harnessFor();
        render(<NotificationsScreen />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('tab', { name: 'Blocked addresses' }));
        expect(await screen.findByText(/including certificate and account mail/)).toBeInTheDocument();
    });

    it('sends the address for hashing rather than hashing it in the browser', async () => {
        const writes: RecordedRequest[] = [];
        const harness = harnessFor(writes);
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();

        await user.click(await screen.findByRole('tab', { name: 'Blocked addresses' }));
        await user.type(await screen.findByLabelText('Look up an address'), 'bounce@example.test');
        await user.click(screen.getByRole('button', { name: 'Suppress this address' }));

        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.body).toMatchObject({
            channel_key: 'email',
            address: 'bounce@example.test',
            action: 'suppress',
        });
    });
});

describe('the consent record', () => {
    it('shows the lawful basis on the row, which is what an audit can query', async () => {
        const harness = harnessFor();
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();

        await user.click(await screen.findByRole('tab', { name: 'Consent history' }));
        await user.type(await screen.findByLabelText('Whose record'), 'Priya');
        await user.click(await screen.findByRole('button', { name: /Priya Nair/ }));

        expect(await screen.findByText('Soft opt-in')).toBeInTheDocument();
        expect(screen.getByText('Withdrawn')).toBeInTheDocument();
        expect(screen.getByText(/A withdrawal is a new row and never a deleted one/)).toBeInTheDocument();
    });
});

describe('delivery feedback', () => {
    it('names the endpoint per channel and the mappers that can read a report', async () => {
        const harness = harnessFor();
        render(<NotificationsScreen />, { wrapper: harness.wrapper });

        await userEvent.click(await screen.findByRole('tab', { name: 'Delivery reports' }));

        expect(await screen.findByText('POST /v1/notifications/feedback/email')).toBeInTheDocument();
        expect(screen.getByText(/RFC 5965 abuse report/)).toBeInTheDocument();
        expect(screen.getByText(/X-VidyaLMS-Feedback-Secret/)).toBeInTheDocument();
        /* A payload nobody claims is accepted and logged, never an error. */
        expect(screen.getByText(/logged unparsed and writes no suppression/)).toBeInTheDocument();
    });
});


describe('delivery controls and message rules', () => {
    it('saves a typed quiet-hours setting without disabling its policy provider', async () => {
        const writes: RecordedRequest[] = [];
        const harness = harnessFor(writes);
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(screen.getByRole('tab', { name: 'Delivery controls' }));
        await user.click(await screen.findByRole('switch', { name: 'Use quiet hours' }));
        await user.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.body).toMatchObject({ params: { enabled: true, start: '22:00' } });
        expect(writes[0]?.body).not.toHaveProperty('enabled');
    });

    it('pauses the selected rule and links back to its course notification editor', async () => {
        const writes: RecordedRequest[] = [];
        const harness = harnessFor(writes);
        render(<NotificationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(screen.getByRole('tab', { name: 'Notification rules' }));
        await user.click(await screen.findByRole('button', { name: 'Pause: A learner enrols' }));
        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.body).toEqual({ state: 'disabled' });
        expect(screen.getByRole('link', { name: 'Edit in course builder' }).getAttribute('href')).toContain('/vy/notifications');
    });
});
