import { useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { Organisation, OrganisationMember, OrganisationRolePage, OrganisationUnit } from '@vidyalms/api-sdk';
import { makeHarness, makeConfig, jsonResponse, problemResponse } from '../testing/harness';
import { StudioDraftProvider } from '../credentials/studio-drafts';
import { OrganisationPeople, type PeopleProps } from './people';
import { OrganisationUnitPicker, OrganisationUnits } from './units';
import { organisationRoute } from './routes';

const org: Organisation = { id: 7, name: 'Acme', slug: 'acme', type: 'organisation', state: 'active', revision: 4, parent_id: null, root_id: 7,
    depth: 0, contact_email: null, timezone: null, locale: null, created_by: 1, archived_at: null, created_at: '2026-09-05 10:00:00', updated_at: '2026-09-05 10:00:00' };
const roles: OrganisationRolePage = { items: [
    { key: 'org_owner', label: 'Owner', actions: ['read', 'people', 'roles'], assignable: true },
    { key: 'org_admin', label: 'Administrator', actions: ['read', 'people'], assignable: true },
    { key: 'org_reporter', label: 'Reporter', actions: ['read'], assignable: true },
    { key: 'org_member', label: 'Member', actions: [], assignable: true },
], missing_providers: [], access: { site_manager: true, actions: ['read', 'people', 'roles'], unit_id: null } };
const unit = (id: number, path = 'Engineering'): OrganisationUnit => ({ id, organisation_id: 7, parent_id: null, name: path, path, depth: 1, state: 'active' });
const member: OrganisationMember = { id: 8, organisation_id: 7, org_unit_id: 90, user_id: 20, user_name: 'Ana Costa', state: 'active', joined_at: '2026-09-05 10:00:00', left_at: null,
    provisioned_by: 'manual', directory_managed: 0, role_keys: ['org_admin'], role_unit_id: 90,
    role_scopes: [{ role_key: 'org_admin', scope_type: 'unit', unit_id: 90 }] };
const page = (items: unknown[], total = items.length) => ({ items, total, page: 1, per_page: 20 });
const props: PeopleProps = { organisation: org, roles, location: organisationRoute('/organisation/7/people'), change: vi.fn(), notify: vi.fn() };

describe('organization units and member reach', () => {
    it('requests bounded list pages and keeps structure mutations out of delegated scope', async () => {
        const change = vi.fn(); const scoped = { ...roles, access: { site_manager: false, actions: ['read', 'people'], unit_id: 90 } };
        const harness = makeHarness(() => jsonResponse(page([unit(90)], 10000)));
        render(<OrganisationUnits {...props} roles={scoped} location={{ ...props.location, page: 3, search: 'Engineering' }} change={change} />, { wrapper: harness.wrapper });
        await screen.findByRole('cell', { name: /Engineering/ });
        expect(harness.requests[0]?.path).toBe('/v1/manage/organisations/7/units?page=3&per_page=20&search=Engineering');
        expect(screen.queryByRole('button', { name: 'Create unit' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit unit' })).not.toBeInTheDocument();
        await userEvent.setup().click(screen.getByRole('button', { name: /Next/ })); expect(change).toHaveBeenCalledWith({ page: 4 });
    });

    it('loads exact selected metadata and searches one unit page at a time without an all-tree fetch', async () => {
        const harness = makeHarness((request) => {
            if (request.path.endsWith('/units/90')) return jsonResponse(unit(90));
            if (request.path.endsWith('/units/91')) return jsonResponse(unit(91, 'Sales'));
            return jsonResponse(page([request.path.includes('?page=2&') ? unit(91, 'Sales') : { ...unit(92, 'Retired'), state: 'inactive' }], 10000));
        });
        function Picker() { const [value, setValue] = useState<number | null>(90); return <OrganisationUnitPicker organisationId={7} label="Membership unit" value={value} allowNull={false} onChange={setValue} />; }
        render(<Picker />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await screen.findByText('Engineering'); expect(harness.requests).toHaveLength(1);
        await user.click(screen.getByRole('button', { name: 'Change unit' }));
        expect(await screen.findByRole('button', { name: 'Retired Inactive' })).toBeDisabled();
        expect(screen.queryByRole('button', { name: 'No unit assigned' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: /Next/ }));
        await user.click(await screen.findByRole('button', { name: 'Sales' })); await screen.findByText('Sales');
        expect(harness.requests.some((request) => request.path.includes('/units?page=2&per_page=20'))).toBe(true);
        expect(harness.requests.some((request) => request.path.endsWith('/units/91'))).toBe(true);
        expect(harness.requests.filter((request) => request.path.includes('/units?'))).toHaveLength(2);
    });

    it('keeps a unit draft and its revision/idempotency identity after an ambiguous write failure', async () => {
        let writes = 0; const notify = vi.fn();
        const harness = makeHarness((request) => request.method === 'POST'
            ? ++writes === 1 ? problemResponse(503, 'Temporary unit failure') : jsonResponse({ organisation_revision: 5, item: unit(93, 'Operations') })
            : jsonResponse(page([])));
        render(<StudioDraftProvider><OrganisationUnits {...props} notify={notify} /></StudioDraftProvider>, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Create unit' }));
        const dialog = screen.getByRole('dialog'); await user.type(within(dialog).getByLabelText('Unit name'), 'Operations');
        await user.click(within(dialog).getByRole('button', { name: 'Save unit' })); await screen.findByText('Temporary unit failure');
        await user.click(within(dialog).getByRole('button', { name: 'Save unit' })); await waitFor(() => expect(notify).toHaveBeenCalledWith('Organization unit saved.'));
        const requests = harness.requests.filter((request) => request.method === 'POST'); expect(requests).toHaveLength(2);
        expect(requests[0]?.body).toEqual({ expected_revision: 4, name: 'Operations', parent_id: null, state: 'active' });
        expect(requests[1]?.body).toEqual(requests[0]?.body); expect(requests[1]?.headers['idempotency-key']).toBe(requests[0]?.headers['idempotency-key']);
    });

    it('sends an explicit role unit scope without moving membership placement', async () => {
        const harness = makeHarness((request) => request.method === 'PATCH' ? jsonResponse({ organisation_revision: 5, item: member })
            : request.path.includes('/members?') ? jsonResponse(page([member]))
                : request.path.endsWith('/units/90') ? jsonResponse(unit(90)) : jsonResponse(page([unit(91, 'Sales')])));
        render(<OrganisationPeople {...props} />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Manage membership' })); const dialog = screen.getByRole('dialog');
        const roleScope = within(dialog).getByRole('group', { name: 'Role scope' });
        await user.click(within(roleScope).getByRole('button', { name: 'Change unit' }));
        await user.click(within(roleScope).getByRole('button', { name: 'Entire organization' }));
        await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(harness.requests.find((request) => request.method === 'PATCH')?.body)
            .toEqual({ expected_revision: 4, role_key: 'org_admin', role_unit_id: null }));
    });

    it('moves a member independently without broadening their existing delegated role', async () => {
        const harness = makeHarness((request) => request.method === 'PATCH' ? jsonResponse({ organisation_revision: 5, item: member })
            : request.path.includes('/members?') ? jsonResponse(page([member]))
                : request.path.endsWith('/units/90') ? jsonResponse(unit(90)) : request.path.endsWith('/units/91') ? jsonResponse(unit(91, 'Sales')) : jsonResponse(page([unit(91, 'Sales')])));
        render(<OrganisationPeople {...props} />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Manage membership' })); const dialog = screen.getByRole('dialog');
        const placement = within(dialog).getByRole('group', { name: 'Membership unit' });
        await user.click(within(placement).getByRole('button', { name: 'Change unit' }));
        await user.click(await within(placement).findByRole('button', { name: 'Sales' }));
        await user.click(within(dialog).getByRole('button', { name: 'Save changes' }));
        await waitFor(() => expect(harness.requests.find((request) => request.method === 'PATCH')?.body)
            .toEqual({ expected_revision: 4, org_unit_id: 91 }));
    });

    it('shows organization-wide reach when a scoped administrator is deliberately changed to owner', async () => {
        const harness = makeHarness((request) => request.path.includes('/members?') ? jsonResponse(page([member])) : jsonResponse(unit(90)));
        render(<OrganisationPeople {...props} />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Manage membership' })); const dialog = screen.getByRole('dialog');
        await user.selectOptions(within(dialog).getByLabelText('Organization role'), 'org_owner');
        const scope = within(dialog).getByRole('group', { name: 'Role scope' });
        expect(within(scope).getByText('Entire organization')).toBeVisible();
        expect(within(scope).queryByText('Engineering')).not.toBeInTheDocument();
    });

    it('refuses hidden invitation views and organization-wide move choices for a unit manager', async () => {
        const scoped = { ...roles, items: roles.items.map((role) => ({ ...role, assignable: role.key === 'org_member' })),
            access: { site_manager: false, actions: ['read', 'people'], unit_id: 90 } };
        const harness = makeHarness((request) => request.path.includes('/members?') ? jsonResponse(page([{ ...member, role_keys: ['org_member'], role_unit_id: null }]))
            : request.path.endsWith('/units/90') ? jsonResponse(unit(90)) : jsonResponse(page([unit(90)])));
        render(<OrganisationPeople {...props} roles={scoped} location={{ ...props.location, people: 'invitations' }} />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Manage membership' }));
        expect(harness.requests.some((request) => request.path.includes('/invitations'))).toBe(false);
        expect(screen.queryByRole('button', { name: 'Add person' })).not.toBeInTheDocument();
        expect(screen.queryByRole('group', { name: 'Role scope' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Change unit' }));
        expect(screen.queryByRole('button', { name: 'No unit assigned' })).not.toBeInTheDocument();
    });

    it('does not offer mutation controls for directory-managed membership', async () => {
        const harness = makeHarness((request) => request.path.includes('/members?') ? jsonResponse(page([{ ...member, directory_managed: 1 }])) : jsonResponse(unit(90)));
        render(<OrganisationPeople {...props} />, { wrapper: harness.wrapper });
        await userEvent.setup().click(await screen.findByRole('button', { name: 'Manage membership' }));
        expect(within(screen.getByRole('dialog')).getByLabelText('Membership status')).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeDisabled();
    });

    it('fails closed when secure invitation routes are absent while retaining direct assignment for site managers', async () => {
        const harness = makeHarness(() => jsonResponse(page([])));
        render(<OrganisationPeople {...props} location={{ ...props.location, people: 'invitations' }} />, { wrapper: harness.wrapper });
        await screen.findByText(/Secure invitation delivery is unavailable/);
        expect(screen.queryByRole('button', { name: 'Invitations' })).not.toBeInTheDocument();
        expect(harness.requests.some((request) => request.path.includes('/invitations'))).toBe(false);
        await userEvent.setup().click(screen.getByRole('button', { name: 'Add person' }));
        const dialog = screen.getByRole('dialog');
        expect(within(dialog).getByLabelText('Existing CMS account')).toBeVisible();
        expect(within(dialog).queryByRole('button', { name: 'Send invitation' })).not.toBeInTheDocument();
        expect(within(dialog).queryByLabelText('Email address')).not.toBeInTheDocument();
    });

    it('does not offer the site account picker to delegated admins when secure invitations are unavailable', async () => {
        const delegated = { ...roles, access: { site_manager: false, actions: ['read', 'people'], unit_id: null } };
        const harness = makeHarness(() => jsonResponse(page([])), makeConfig({ routes: ['/manage/organisations'] }));
        render(<OrganisationPeople {...props} roles={delegated} />, { wrapper: harness.wrapper });
        await screen.findByText(/Secure invitation delivery is unavailable/);
        expect(screen.queryByRole('button', { name: 'Add person' })).not.toBeInTheDocument();
    });

    it('enables invitations only when the exact server route is advertised', async () => {
        const harness = makeHarness(() => jsonResponse(page([])), makeConfig({ routes: ['/manage/organisations/{id}/invitations'] }));
        render(<OrganisationPeople {...props} />, { wrapper: harness.wrapper });
        expect(screen.getByRole('button', { name: 'Invitations' })).toBeVisible();
        await userEvent.setup().click(screen.getByRole('button', { name: 'Add person' }));
        expect(within(screen.getByRole('dialog')).getByLabelText('Email address')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Send invitation' })).toBeDisabled();
        expect(screen.queryByText(/Secure invitation delivery is unavailable/)).not.toBeInTheDocument();
    });
});
