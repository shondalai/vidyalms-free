import { afterEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { OrganisationInvitationPage, parseInvitationHash } from './organisations';
import { jsonResponse, makeConfig, makeHarness, problemResponse } from './testing/harness';

afterEach(() => { window.history.replaceState(null, '', '/'); });
const token = 'a'.repeat(64);
describe('private organization invitation landing', () => {
    it('keeps guests on the fragment landing and never claims without explicit signed-in action', () => {
        window.history.replaceState(null, '', `/#/organisation-invitation/${token}`);
        const harness = makeHarness(() => null, makeConfig({ screen: 'organisations', urls: { home: '/', myLearning: '/learning', verify: '', login: '/login?return=%2Forganisations' } }));
        render(<OrganisationInvitationPage />, { wrapper: harness.wrapper });
        expect(screen.getByRole('link', { name: 'Sign in in a new tab' })).toHaveAttribute('target', '_blank');
        expect(screen.getByRole('link').getAttribute('href')).not.toContain(token);
        expect(harness.requests).toHaveLength(0); expect(window.location.hash).toContain(token);
    });
    it('claims once requested, scrubs the token, and verifies a fresh code before joining', async () => {
        window.history.replaceState(null, '', `/#/organisation-invitation/${token}`);
        const harness = makeHarness((request) => request.path.endsWith('/claim') ? jsonResponse({ claim_id: 42, expires_at: '2026-09-05 11:10:00', delivery: 'queued' }, 201)
            : request.path.endsWith('/accept') ? jsonResponse({ organisation_id: 7, membership_id: 8, organisation_revision: 3, state: 'active' }) : jsonResponse({ items: [], total: 0, page: 1, per_page: 20 }), makeConfig({ screen: 'organisations', user: { id: 5, name: 'Ana' } }));
        render(<OrganisationInvitationPage />, { wrapper: harness.wrapper });
        expect(harness.requests).toHaveLength(0); const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: 'Send verification code' }));
        await screen.findByRole('heading', { name: 'Check your inbox' });
        expect(window.location.hash).toBe('#/organisation-claim/42'); expect(document.body.textContent).not.toContain(token);
        await user.type(screen.getByLabelText('Verification code'), 'SAFE1234ABCD');
        await user.click(screen.getByRole('button', { name: 'Verify and join' }));
        await screen.findByRole('heading', { name: 'You have joined the organization' });
        expect(window.location.hash).toBe('');
        expect(harness.requests.filter((request) => request.method === 'POST').map((request) => request.body)).toEqual([{ token }, { claim_id: 42, code: 'SAFE1234ABCD' }]);
    });
    it('does not report membership when proof is expired or refused', async () => {
        window.history.replaceState(null, '', '/#/organisation-claim/42');
        const harness = makeHarness(() => problemResponse(422, 'Invalid code', 'Code expired'), makeConfig({ user: { id: 5, name: 'Ana' } }));
        render(<OrganisationInvitationPage />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.type(screen.getByLabelText('Verification code'), 'OLD123'); await user.click(screen.getByRole('button', { name: 'Verify and join' }));
        await screen.findByText('Code expired'); expect(screen.queryByText('You have joined the organization')).not.toBeInTheDocument();
    });
    it('paginates own memberships without asking for an organization directory', async () => {
        const harness = makeHarness((request) => jsonResponse({ items: [{ id: 7, name: 'Acme', slug: 'acme', state: 'active', membership_id: 8 }], total: 2000, page: request.path.includes('page=2') ? 2 : 1, per_page: 20 }), makeConfig({ user: { id: 5, name: 'Ana' } }));
        render(<OrganisationInvitationPage />, { wrapper: harness.wrapper }); await screen.findByRole('heading', { name: 'Acme' });
        await userEvent.click(screen.getByRole('button', { name: 'Next ›' }));
        await waitFor(() => expect(harness.requests.some((request) => request.path === '/v1/me/organisations?page=2&per_page=20')).toBe(true));
        expect(harness.requests.every((request) => request.path.startsWith('/v1/me/organisations'))).toBe(true);
    });
    it('rejects malformed, unsafe and excessively long invitation routes', () => {
        for (const hash of ['#/organisation-invitation/<script>', '#/organisation-claim/0', '#/organisation-claim/9007199254740999', `#/organisation-invitation/${'a'.repeat(300)}`, '#/unrelated']) expect(parseInvitationHash(hash).invalid).toBe(true);
        expect(parseInvitationHash('')).toEqual({ token: null, claimId: null, invalid: false });
    });
});
