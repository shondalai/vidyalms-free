import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Badge, BadgeAward, CreditScheme } from '@vidyalms/api-sdk';
import { CertificatesScreen } from '../screens/certificates';
import { jsonResponse, makeConfig, makeCourse, makeHarness, type RecordedRequest } from '../testing/harness';

/*
 * Continuing-education credit and badges.
 *
 * The rules under test. The cross-scheme facts are what the certificate
 * freezes at issue, and the scheme owns its own parameters, so the form is
 * drawn from the scheme's settings schema and nothing in the client names a
 * scheme key. A scheme whose package is gone still reports the rows it holds
 * rather than dropping them. And a build that defines no badges answers 200
 * with `available` false: nothing is gated, so the screen says so in words
 * instead of showing a failure.
 */

const SCHEME: CreditScheme = {
    key: 'contact_hours',
    label: 'Contact hours',
    edition: 'free',
    available: true,
    unit: 'hours',
    retention_years: null,
    required_learner_fields: [],
    rule: 'One hour of participation is one contact hour.',
    groups: [
        {
            key: 'amount',
            label: 'Amount',
            fields: [{ key: 'hours', label: 'Hours', type: 'number', default: 0 }],
        },
    ],
};

const BADGE: Badge = {
    id: 4,
    badge_key: 'accessible-forms',
    name: 'Accessible Forms',
    description: null,
    criteria_narrative: null,
    criteria: null,
    criteria_version: 1,
    criteria_locked: true,
    achievement_type: 'Badge',
    course_id: null,
    expiry_mode: 'never',
    expiry_days: null,
    expiry_at: null,
    state: 'published',
    locked_at: '2026-06-01 10:00:00',
    award_count: 2,
};

const AWARD: BadgeAward = {
    id: 21,
    badge_id: 4,
    badge_name: 'Accessible Forms',
    user_id: 482,
    uuid: 'a1b2c3d4-0000-0000-0000-000000000000',
    code: 'NL9BADGE1234',
    status: 'active',
    criteria_version: 1,
    issued_at: '2026-08-01 10:00:00',
    valid_from: null,
    expires_at: null,
    revoked_at: null,
    revocation_reason: null,
    supersedes_id: null,
    superseded_by_id: null,
    verification_url: 'https://example.test/verify/badge/NL9BADGE1234',
    credential_url: null,
};

interface World {
    schemes?: CreditScheme[];
    courseCredits?: unknown[];
    badgesAvailable?: boolean;
}

function renderScreen(world: World = {}) {
    const config = makeConfig({ screen: 'certificates' });
    const writes: RecordedRequest[] = [];
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/manage/credit-schemes') {
            return jsonResponse({ items: world.schemes ?? [SCHEME] });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/courses') {
            return jsonResponse({
                items: [makeCourse({ id: 1, title: 'Foundations of Web Accessibility' })],
                total: 1,
                page: 1,
                per_page: 100,
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/courses/1/credits') {
            return jsonResponse({ items: world.courseCredits ?? [] });
        }
        if (request.method === 'PUT' && url.pathname === '/v1/manage/courses/1/credits') {
            writes.push(request);
            return jsonResponse({ items: world.courseCredits ?? [] });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/badges') {
            const available = world.badgesAvailable ?? true;
            return jsonResponse({
                items: available ? [BADGE] : [],
                total: available ? 1 : 0,
                page: 1,
                per_page: 20,
                available,
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/badges/4/awards') {
            return jsonResponse({ items: [AWARD], total: 1, page: 1, per_page: 20 });
        }
        if (request.method === 'POST' && url.pathname === '/v1/manage/badge-awards/21/revoke') {
            writes.push(request);
            return jsonResponse({ ...AWARD, status: 'revoked', revoked_at: '2026-09-01 10:00:00' });
        }
        return null;
    }, config);
    /* Badge management is a contract-backed development surface until its
       core routes are registered; production CertificatesScreen keeps it
       hidden rather than exposing a guaranteed 404. */
    render(<CertificatesScreen enableBadges />, { wrapper: harness.wrapper });
    return { ...harness, writes };
}

describe('continuing-education credit', () => {
    it('lists the registered schemes with what each asks the learner for', async () => {
        renderScreen({
            schemes: [
                {
                    ...SCHEME,
                    key: 'nasba.cpe',
                    label: 'NASBA CPE',
                    unit: 'CPE',
                    retention_years: 5,
                    required_learner_fields: ['board_id'],
                    available: false,
                },
            ],
        });
        await userEvent.click(await screen.findByRole('tab', { name: 'Credits' }));
        const table = await screen.findByRole('table', { name: 'Registered schemes' });
        expect(within(table).getByText('NASBA CPE')).toBeInTheDocument();
        expect(within(table).getByText('5 years')).toBeInTheDocument();
        expect(within(table).getByText('board_id')).toBeInTheDocument();
        expect(within(table).getByText('not installed')).toBeInTheDocument();
    });

    it('writes a course credit row through the scheme own parameters and version', async () => {
        const harness = renderScreen();
        await userEvent.click(await screen.findByRole('tab', { name: 'Credits' }));
        await userEvent.click(
            await screen.findByRole('button', { name: 'Foundations of Web Accessibility' }),
        );
        await userEvent.selectOptions(
            await screen.findByRole('combobox', { name: 'Add credit' }),
            'contact_hours',
        );
        await userEvent.clear(await screen.findByRole('textbox', { name: 'Hours' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Hours' }), '6');
        await userEvent.click(screen.getByRole('button', { name: 'Save credit' }));
        await waitFor(() => expect(harness.writes).toHaveLength(1));
        expect(harness.writes[0]?.body).toEqual({
            items: [{ scheme: 'contact_hours', params: { hours: 6 }, params_version: 1 }],
        });
    });

    it('keeps unsaved course credit when changing course is cancelled', async () => {
        const harness = renderScreen();
        await userEvent.click(await screen.findByRole('tab', { name: 'Credits' }));
        await userEvent.click(
            await screen.findByRole('button', { name: 'Foundations of Web Accessibility' }),
        );
        await userEvent.selectOptions(
            await screen.findByRole('combobox', { name: 'Add credit' }),
            'contact_hours',
        );
        await userEvent.clear(await screen.findByRole('textbox', { name: 'Hours' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Hours' }), '9');
        await userEvent.click(screen.getByRole('button', { name: 'Change course' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Keep editing' }));
        expect(screen.getByRole('textbox', { name: 'Hours' })).toHaveValue('9');
        expect(harness.writes).toHaveLength(0);
    });

    it('says nothing can compute a row whose scheme is not installed', async () => {
        renderScreen({
            schemes: [{ ...SCHEME, available: false }],
            courseCredits: [
                {
                    scheme: 'contact_hours',
                    scheme_label: 'Contact hours',
                    available: false,
                    params: { hours: 6 },
                    params_version: 1,
                    derived: null,
                },
            ],
        });
        await userEvent.click(await screen.findByRole('tab', { name: 'Credits' }));
        await userEvent.click(
            await screen.findByRole('button', { name: 'Foundations of Web Accessibility' }),
        );
        expect(await screen.findByText(/the scheme that owns it is not installed/i)).toBeInTheDocument();
    });
});

describe('badges', () => {
    it('says a build with no badge package defines none, rather than failing', async () => {
        renderScreen({ badgesAvailable: false });
        await userEvent.click(await screen.findByRole('tab', { name: 'Badges' }));
        expect(await screen.findByText(/No package on this build defines badges/)).toBeInTheDocument();
    });

    it('lists a badge with the criteria lock stated', async () => {
        renderScreen();
        await userEvent.click(await screen.findByRole('tab', { name: 'Badges' }));
        const table = await screen.findByRole('table', { name: 'Badges' });
        expect(within(table).getByText('Accessible Forms')).toBeInTheDocument();
        expect(within(table).getByText('Criteria locked')).toBeInTheDocument();
    });

    it('revokes one award behind a confirmation that says the record stays', async () => {
        const harness = renderScreen();
        await userEvent.click(await screen.findByRole('tab', { name: 'Badges' }));
        await userEvent.click(
            await screen.findByRole('button', { name: 'Show the awards of Accessible Forms' }),
        );
        await userEvent.click(await screen.findByRole('button', { name: 'Revoke award NL9BADGE1234' }));
        const dialog = await screen.findByRole('dialog', { name: 'Revoke this award?' });
        expect(dialog).toHaveTextContent('keeps answering');
        await userEvent.click(within(dialog).getByRole('button', { name: 'Revoke award' }));
        await waitFor(() => expect(harness.writes).toHaveLength(1));
        expect(harness.writes[0]?.path).toBe('/v1/manage/badge-awards/21/revoke');
    });
});
