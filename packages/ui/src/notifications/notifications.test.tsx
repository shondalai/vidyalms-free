import { describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NotificationsApp } from './app';
import { jsonResponse, makeConfig, makeHarness, problemResponse, type RecordedRequest } from './testing/harness';

/*
 * The two notification site pages.
 *
 * The rules with legal weight are the ones under test.
 *
 * The unsubscribe GET RENDERS AND ACTS ON NOTHING. Mail security scanners
 * follow links, which is the whole reason the one-click mechanism is
 * specified as a POST; a page that unsubscribed on load would turn a scanner
 * into an opt-out and the person would never know it happened.
 *
 * Nothing beyond the token is demanded: no sign-in, no address to retype, no
 * reason to give, and no charge.
 *
 * An unsubscribe writes a preference row and a consent row and NEVER a
 * suppression row, so the screen names what keeps arriving. A
 * category-scoped click producing an address-scoped block would silence the
 * next certificate mail, which is transactional and something a site may be
 * obliged to deliver.
 */

const CENTRE = {
    rows: [
        {
            category_key: 'course_progress',
            channel_key: 'email',
            frequency: 'immediate',
            policy_state: 'permitted',
            editable: true,
            available_frequencies: ['immediate', 'daily', 'weekly', 'never'],
        },
        {
            category_key: 'credential',
            channel_key: 'email',
            frequency: 'immediate',
            policy_state: 'forced',
            editable: false,
            bypasses_preference: true,
        },
    ],
    channels: [{ key: 'email', label: 'Email', edition: 'free', available: true, capabilities: [] }],
    categories: [
        { key: 'course_progress', label: 'Course progress', class: 'optional' },
        { key: 'credential', label: 'Certificates', class: 'transactional' },
    ],
};

function renderPreferences(writes: RecordedRequest[] = [], centre: Record<string, unknown> = CENTRE) {
    const harness = makeHarness((request: RecordedRequest) => {
        if (request.method === 'GET' && request.path === '/v1/me/notification-preferences') {
            return jsonResponse(centre);
        }
        if (request.method === 'PUT' && request.path === '/v1/me/notification-preferences') {
            writes.push(request);
            return jsonResponse(centre);
        }
        return null;
    });
    render(<NotificationsApp />, { wrapper: harness.wrapper });
    return harness;
}

describe('the preference centre', () => {
    it('offers a frequency per category and channel, with the category as the unit', async () => {
        renderPreferences();
        const control = await screen.findByRole('combobox', { name: 'Course progress, Email' });
        expect(control).toHaveValue('immediate');
        expect(screen.getByRole('option', { name: 'Once a week' })).toBeInTheDocument();
    });

    it('shows a forced cell locked rather than offering a control that does nothing', async () => {
        renderPreferences();
        expect(await screen.findByText('Set by the site')).toBeInTheDocument();
        expect(screen.queryByRole('combobox', { name: 'Certificates, Email' })).not.toBeInTheDocument();
    });

    it('says plainly that a transactional category is not affected by these settings', async () => {
        renderPreferences();
        expect(await screen.findByText('Always sent')).toBeInTheDocument();
        expect(screen.getByText(/A site may be required to send them/)).toBeInTheDocument();
    });

    /*
     * The control used to be handed a value none of its options carried: a
     * learner who chose daily on a site that later lost its digest fold got a
     * select whose value was "daily" and whose only options were immediate and
     * never. Because the save resubmits every editable row, pressing Save sent
     * "daily" back and the 422 refused every other cell with it, so one stale
     * preference made the whole centre unsaveable.
     */
    it('shows a substituted frequency as a real option and says what was stored', async () => {
        const writes: RecordedRequest[] = [];
        renderPreferences(writes, {
            ...CENTRE,
            rows: [
                { ...CENTRE.rows[0], frequency: 'immediate', stored_frequency: 'daily',
                    available_frequencies: ['immediate', 'never'] },
                CENTRE.rows[1],
            ],
        });
        const user = userEvent.setup();

        const control = await screen.findByRole('combobox', { name: 'Course progress, Email' });
        expect(control).toHaveValue('immediate');
        expect(screen.queryByRole('option', { name: 'Once a day' })).not.toBeInTheDocument();
        expect(screen.getByText(/can no longer collect messages once a day/)).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Save preferences' }));

        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.body).toEqual({
            rows: [{ category_key: 'course_progress', channel_key: 'email', frequency: 'immediate' }],
        });
    });

    it('replaces the whole list on save and never sends a row the site has locked', async () => {
        const writes: RecordedRequest[] = [];
        renderPreferences(writes);
        const user = userEvent.setup();

        await user.selectOptions(
            await screen.findByRole('combobox', { name: 'Course progress, Email' }),
            'weekly',
        );
        await user.click(screen.getByRole('button', { name: 'Save preferences' }));

        await waitFor(() => expect(writes).toHaveLength(1));
        expect(writes[0]?.body).toEqual({
            rows: [{ category_key: 'course_progress', channel_key: 'email', frequency: 'weekly' }],
        });
    });
});

function renderUnsubscribe(
    world: { preview?: unknown; onPost?: (request: RecordedRequest) => void; previewFails?: boolean } = {},
) {
    const harness = makeHarness(
        (request: RecordedRequest) => {
            if (request.path === '/v1/notifications/unsubscribe/tok-1') {
                if (request.method === 'GET') {
                    if (world.previewFails === true) {
                        return problemResponse(422, 'Invalid token');
                    }
                    return jsonResponse(
                        world.preview ?? {
                            category_key: 'course_progress',
                            category_label: 'course progress',
                            channels: ['email'],
                            already_unsubscribed: false,
                            keeps_receiving: ['Certificates', 'Account'],
                        },
                    );
                }
                if (request.method === 'POST') {
                    world.onPost?.(request);
                    return jsonResponse({
                        unsubscribed: true,
                        category_key: 'course_progress',
                        channels: ['email'],
                        consent_recorded: true,
                        suppression_written: false,
                        still_receiving: ['Certificates', 'Account'],
                    });
                }
            }
            return null;
        },
        makeConfig({ screen: 'unsubscribe', token: 'tok-1' }),
    );
    render(<NotificationsApp />, { wrapper: harness.wrapper });
    return harness;
}

describe('the unsubscribe page', () => {
    it('reads the token and acts on nothing until a person presses the button', async () => {
        const harness = renderUnsubscribe();
        expect(await screen.findByText(/This will stop course progress messages/)).toBeInTheDocument();
        expect(screen.getByText('Nothing has changed yet. Press the button below to confirm.')).toBeInTheDocument();
        /* A scanner following the link makes exactly one request, and it is
           a GET. */
        expect(harness.requests.every((request) => request.method === 'GET')).toBe(true);
    });

    it('names what keeps arriving, because a category opt-out is not an address block', async () => {
        renderUnsubscribe();
        expect(await screen.findByText('This does not stop:')).toBeInTheDocument();
        expect(screen.getAllByText('Certificates').length).toBeGreaterThan(0);
        expect(screen.getByText(/never blocks your email address/)).toBeInTheDocument();
    });

    it('acts on the POST and confirms what was stopped', async () => {
        const posts: RecordedRequest[] = [];
        renderUnsubscribe({ onPost: (request) => posts.push(request) });

        await userEvent.click(await screen.findByRole('button', { name: 'Unsubscribe' }));

        await waitFor(() => expect(posts).toHaveLength(1));
        expect(await screen.findByText(/Done\. You will not receive course progress messages/)).toBeInTheDocument();
        expect(screen.getByText('You still receive:')).toBeInTheDocument();
    });

    it('demands nothing beyond the token: no sign-in field and no address to retype', async () => {
        renderUnsubscribe();
        await screen.findByRole('button', { name: 'Unsubscribe' });
        expect(screen.queryByRole('textbox')).not.toBeInTheDocument();
    });

    it('offers a way forward when the token no longer verifies rather than a bare refusal', async () => {
        renderUnsubscribe({ previewFails: true });
        expect(await screen.findByText('This link is no longer valid')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Manage all notification settings' })).toHaveAttribute(
            'href',
            '/notifications',
        );
    });

    it('does not offer the action again once the person is already unsubscribed', async () => {
        renderUnsubscribe({
            preview: {
                category_key: 'course_progress',
                category_label: 'course progress',
                channels: ['email'],
                already_unsubscribed: true,
            },
        });
        expect(await screen.findByText('You are already unsubscribed from these messages.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Unsubscribe' })).not.toBeInTheDocument();
    });
});
