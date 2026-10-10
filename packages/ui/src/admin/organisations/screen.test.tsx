import { afterEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Organisation, OrganisationRolePage } from '@vidyalms/api-sdk';
import { jsonResponse, makeHarness, problemResponse } from '../testing/harness';
import { OrganisationsScreen } from './screen';
import { organisationHash, organisationRoute } from './routes';

afterEach(() => {
    window.history.replaceState(null, '', '/');
});
const org: Organisation = {
    id: 7,
    name: 'Acme Learning',
    slug: 'acme',
    type: 'organisation',
    state: 'active',
    revision: 4,
    parent_id: null,
    root_id: 7,
    depth: 0,
    contact_email: null,
    timezone: null,
    locale: null,
    created_by: 1,
    archived_at: null,
    created_at: '2026-09-05 10:00:00',
    updated_at: '2026-09-05 10:00:00',
};
const roles: OrganisationRolePage = {
    items: [
        {
            key: 'org_owner',
            label: 'Owner',
            actions: ['read', 'people', 'settings', 'events', 'learning', 'seats'],
            assignable: true,
        },
    ],
    missing_providers: [],
    access: {
        site_manager: true,
        actions: ['read', 'people', 'settings', 'events', 'learning', 'seats'],
        unit_id: null,
    },
};
const summary = {
    organisation_id: 7,
    active_members: 2000,
    released_courses: 35,
    enrolments: 3800,
    seat_pools: 7,
    pending_seats: 3,
};
const page = (items: unknown[], total = items.length) => ({ items, total, page: 1, per_page: 20 });
describe('organization workspace', () => {
    it('uses bounded directory pages and preserves the filtered return address', async () => {
        window.history.replaceState(null, '', '/#/?page=3&search=acme&state=active');
        const harness = makeHarness((request) =>
            request.path.includes('/events?') || request.path.includes('/seat-pools?')
                ? jsonResponse(page([]))
                : request.path.endsWith('/7/roles')
                  ? jsonResponse(roles)
                  : request.path.endsWith('/7/summary')
                    ? jsonResponse(summary)
                    : request.path.endsWith('/7')
                      ? jsonResponse(org)
                      : jsonResponse(page([org], 10000)),
        );
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Open workspace' }));
        await screen.findByRole('heading', { name: 'Acme Learning' });
        expect(screen.getByRole('tab', { name: 'Course access' })).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Back to organizations' }));
        await screen.findByRole('button', { name: 'Open workspace' });
        expect(window.location.hash).toBe('#/?page=3&search=acme&state=active');
        expect(
            harness.requests.some((request) =>
                request.path.includes('page=3&per_page=20&search=acme&state=active'),
            ),
        ).toBe(true);
    });
    it('falls back to own memberships without exposing global creation for a delegated manager', async () => {
        const harness = makeHarness((request) =>
            request.path.startsWith('/v1/manage/organisations')
                ? problemResponse(403)
                : jsonResponse(
                      page([
                          { id: 7, name: 'Acme Learning', slug: 'acme', state: 'active', membership_id: 4 },
                      ]),
                  ),
        );
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        await screen.findByText('Acme Learning');
        expect(screen.getByRole('heading', { name: 'My organizations' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Create organization' })).not.toBeInTheDocument();
    });
    it('creates a draft organization with stable retry identity and a guarded form', async () => {
        let writes = 0;
        const harness = makeHarness((request) => {
            if (request.method === 'POST') {
                writes++;
                return writes === 1 ? problemResponse(503, 'Temporary failure') : jsonResponse(org);
            }
            return request.path.endsWith('/7/roles')
                ? jsonResponse(roles)
                : request.path.endsWith('/7/summary')
                  ? jsonResponse(summary)
                  : request.path.endsWith('/7')
                    ? jsonResponse(org)
                    : jsonResponse(page([]));
        });
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Create organization' }));
        const dialog = screen.getByRole('dialog');
        await user.type(within(dialog).getByLabelText('Organization name'), 'Acme Learning');
        await user.click(within(dialog).getByRole('button', { name: 'Cancel' }));
        await user.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(within(dialog).getByLabelText('Organization name')).toHaveValue('Acme Learning');
        await user.click(within(dialog).getByRole('button', { name: 'Review details' }));
        await user.click(within(dialog).getByRole('button', { name: 'Create organization' }));
        await screen.findByText('Temporary failure');
        await user.click(within(dialog).getByRole('button', { name: 'Create organization' }));
        await screen.findByRole('heading', { name: 'Acme Learning' });
        const requests = harness.requests.filter((request) => request.method === 'POST');
        expect(requests).toHaveLength(2);
        expect(requests[0]?.body).toEqual({ name: 'Acme Learning', slug: 'acme-learning' });
        expect(requests[1]?.headers['idempotency-key']).toBe(requests[0]?.headers['idempotency-key']);
    });
    it('assigns a released course only through a selected active member with CAS', async () => {
        window.history.replaceState(null, '', '/#/organisation/7/learning?state=access');
        const harness = makeHarness((request) => {
            if (request.method === 'POST')
                return jsonResponse({ organisation_revision: 5, entitlement_id: 9, state: 'active' });
            if (request.path.endsWith('/roles')) return jsonResponse(roles);
            if (request.path.endsWith('/7')) return jsonResponse(org);
            if (request.path.includes('/members?'))
                return jsonResponse(
                    page([
                        {
                            id: 8,
                            user_id: 20,
                            user_name: 'Ana Costa',
                            state: 'active',
                            role_keys: ['org_member'],
                        },
                    ]),
                );
            if (request.path.includes('/releases?'))
                return jsonResponse(
                    page([{ id: 8, course_id: 12, course_title: 'Inclusive leadership', state: 'active' }]),
                );
            return jsonResponse(page([]));
        });
        render(<OrganisationsScreen />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Assign learning' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByRole('button', { name: 'Assign learning' })).toBeDisabled();
        await user.click(await within(dialog).findByRole('button', { name: 'Ana Costa' }));
        await user.click(await within(dialog).findByRole('button', { name: 'Inclusive leadership' }));
        await user.click(within(dialog).getByRole('button', { name: 'Assign learning' }));
        await waitFor(() => expect(harness.requests.some((request) => request.method === 'POST')).toBe(true));
        expect(harness.requests.find((request) => request.method === 'POST')?.body).toEqual({
            expected_revision: 4,
            course_id: 12,
            user_id: 20,
            expires_at: null,
        });
    });
    it('round-trips deep pool links and rejects unsafe organization identifiers', () => {
        const route = organisationRoute('/organisation/7/seats?pool=92&page=3&state=ledger');
        expect(route).toMatchObject({ id: 7, pool: 92, page: 3, state: 'ledger', invalid: false });
        expect(organisationRoute(organisationHash(route))).toEqual(route);
        expect(organisationRoute('/organisation/9007199254740999/people').invalid).toBe(true);
    });
});
