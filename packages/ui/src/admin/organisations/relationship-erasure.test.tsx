import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Organisation, OrganisationErasureReceipt, OrganisationMember, OrganisationRolePage } from '@vidyalms/api-sdk';
import { makeHarness, jsonResponse, problemResponse } from '../testing/harness';
import { RelationshipErasure, relationshipErasureStrings as strings } from './relationship-erasure';
import { OrganisationPeople } from './people';
import { organisationRoute } from './routes';

const org: Organisation = { id: 7, name: 'Acme', slug: 'acme', type: 'organisation', state: 'active', revision: 4, parent_id: null, root_id: 7,
    depth: 0, contact_email: null, timezone: null, locale: null, created_by: 1, archived_at: null, created_at: '2026-09-05 10:00:00', updated_at: '2026-09-05 10:00:00' };
const member: OrganisationMember = { id: 8, organisation_id: 7, org_unit_id: null, user_id: 20, user_name: 'Ana Costa', state: 'left', joined_at: '2026-09-05 10:00:00', left_at: '2026-09-05 11:00:00',
    provisioned_by: 'manual', directory_managed: 0, role_keys: [], role_unit_id: null, role_scopes: [] };
const receipt: OrganisationErasureReceipt = { state: 'relationship_erased', organisation_revision: 5, erasure_complete: false, next_action: 'Review retained evidence.',
    receipts: { core: { erased: { roles: 1 }, pseudonymised: { memberships: 1 }, retained: { entitlements: 2, seat_events: 3 }, warnings: ['Recorded ledger identities are retained.'] } } };

describe('organization relationship removal', () => {
    it('requires explicit acknowledgement and preserves a failed attempt identity, then shows retained-record counts', async () => {
        let writes = 0;
        const harness = makeHarness((request) => request.method === 'POST'
            ? ++writes === 1 ? problemResponse(503, 'Temporary erasure failure') : jsonResponse(receipt) : jsonResponse(org));
        render(<RelationshipErasure organisation={org} member={member} close={vi.fn()} openCleanup={vi.fn()} />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        const remove = screen.getByRole('button', { name: strings.action }); expect(remove).toBeDisabled();
        expect(screen.getByText(strings.preserved)).toBeVisible();
        await user.click(screen.getByRole('checkbox', { name: strings.acknowledgement })); await user.click(remove);
        await screen.findByText('Temporary erasure failure'); await user.click(remove);
        await screen.findByRole('heading', { name: strings.removed });
        expect(screen.getByText(strings.incomplete)).toBeVisible(); expect(screen.getByText('entitlements')).toBeVisible();
        expect(screen.getByText('2')).toBeVisible(); expect(screen.getByText('seat events')).toBeVisible();
        expect(screen.getByText('Recorded ledger identities are retained.')).toBeVisible();
        expect(screen.queryByRole('button', { name: strings.action })).not.toBeInTheDocument();
        const requests = harness.requests.filter((request) => request.method === 'POST'); expect(requests).toHaveLength(2);
        expect(requests[0]?.path).toBe('/v1/manage/organisations/7/members/8/erase');
        expect(requests[0]?.body).toEqual({ expected_revision: 4, acknowledgement: 'remove_organization_relationship' });
        expect(requests[1]?.headers['idempotency-key']).toBe(requests[0]?.headers['idempotency-key']);
    });

    it('does not call queued cleanup completed and resumes only with a fresh revision, new key and acknowledgement', async () => {
        let writes = 0;
        const harness = makeHarness((request) => request.method === 'POST'
            ? jsonResponse(++writes === 1 ? { state: 'cleanup_queued', organisation_revision: 5, erasure_complete: false, receipts: null, cleanup: { job_id: 91, state: 'queued' }, next_action: 'Wait for cleanup.' } : receipt)
            : jsonResponse({ ...org, revision: 7 }));
        render(<RelationshipErasure organisation={org} member={member} close={vi.fn()} openCleanup={vi.fn()} />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: strings.action }));
        await screen.findByText(strings.queued); expect(screen.getByText('Cleanup job #91')).toBeVisible();
        expect(screen.queryByRole('heading', { name: strings.removed })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: strings.resume })); await screen.findByText(strings.refreshed);
        expect(screen.getByRole('button', { name: strings.action })).toBeDisabled();
        await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: strings.action }));
        await screen.findByRole('heading', { name: strings.removed });
        const requests = harness.requests.filter((request) => request.method === 'POST');
        expect(requests[1]?.body).toEqual({ expected_revision: 7, acknowledgement: 'remove_organization_relationship' });
        expect(requests[1]?.headers['idempotency-key']).not.toBe(requests[0]?.headers['idempotency-key']);
        expect(harness.requests.some((request) => request.method === 'GET' && request.path === '/v1/manage/organisations/7')).toBe(true);
    });

    it('requires a fresh review after a stale revision refusal rather than silently adopting one', async () => {
        const harness = makeHarness((request) => request.method === 'POST' ? problemResponse(409, 'Organization changed') : jsonResponse({ ...org, revision: 6 }));
        render(<RelationshipErasure organisation={org} member={member} close={vi.fn()} openCleanup={vi.fn()} />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(screen.getByRole('checkbox')); await user.click(screen.getByRole('button', { name: strings.action }));
        await screen.findByRole('button', { name: strings.revision });
        expect(harness.requests.every((request) => request.method === 'POST')).toBe(true);
        await user.click(screen.getByRole('button', { name: strings.revision })); await screen.findByText(strings.refreshed);
        expect(screen.getByRole('checkbox')).not.toBeChecked(); expect(screen.getByRole('button', { name: strings.action })).toBeDisabled();
        expect(harness.requests.filter((request) => request.method === 'POST')).toHaveLength(1);
    });

    it('offers relationship removal for a closed member only to an organization-wide settings authority', async () => {
        const roles: OrganisationRolePage = { items: [], missing_providers: [], access: { site_manager: false, actions: ['read', 'settings'], unit_id: null } };
        const harness = makeHarness(() => jsonResponse({ items: [member], total: 1, page: 1, per_page: 20 }));
        const props = { organisation: org, roles, location: organisationRoute('/organisation/7/people'), change: vi.fn(), notify: vi.fn() };
        const view = render(<OrganisationPeople {...props} />, { wrapper: harness.wrapper });
        await userEvent.setup().click(await screen.findByRole('button', { name: strings.action }));
        expect(within(screen.getByRole('dialog')).getByRole('button', { name: strings.action })).toBeDisabled();
        view.unmount();
        render(<OrganisationPeople {...props} roles={{ ...roles, access: { ...roles.access, unit_id: 9 } }} />, { wrapper: harness.wrapper });
        await screen.findByText('Ana Costa'); await waitFor(() => expect(screen.queryByRole('button', { name: strings.action })).not.toBeInTheDocument());
    });
});
