import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CredentialsApp } from './app';
import {
    jsonResponse,
    makeBadgeAward,
    makeConfig,
    makeHarness,
    makeMyCertificate,
    type RecordedRequest,
} from './testing/harness';

/*
 * The holder's own credentials.
 *
 * The rules under test are the ones with consequences. The download URL is
 * asked for at the moment somebody wants the file, because it is signed and
 * expiring and a link fetched with the list is a link that has already died;
 * the raw storage path never appears anywhere. A certificate whose artefact
 * has not been rendered yet answers 409, which is a "try again shortly" and
 * not a failure. And badges answer 200 with `available` false on a build
 * that issues none, so the page says so in words rather than showing an
 * error or an empty table that looks broken.
 */

interface World {
    certificates?: unknown[];
    badges?: { items: unknown[]; available: boolean };
    identities?: { items: unknown[] };
    downloadStatus?: number;
    downloadProblem?: Record<string, unknown>;
    certificateTotal?: number;
    routes?: string[];
}

function renderMine(world: World = {}) {
    const writes: RecordedRequest[] = [];
    const harness = makeHarness((request: RecordedRequest) => {
        if (request.method === 'GET' && request.path.startsWith('/v1/me/certificates?')) {
            return jsonResponse({
                items: world.certificates ?? [makeMyCertificate({ id: 1 })], total: world.certificateTotal ?? 1,
                page: Number(new URL(request.path, 'https://test.invalid').searchParams.get('page')), per_page: 20,
            });
        }
        if (request.method === 'GET' && request.path.startsWith('/v1/me/badges?')) {
            return jsonResponse({
                ...(world.badges ?? { items: [makeBadgeAward({ id: 7 })], available: true }),
                total: 1, page: 1, per_page: 20,
            });
        }
        if (request.method === 'GET' && request.path === '/v1/me/credit-identities') {
            return jsonResponse(world.identities ?? { items: [] });
        }
        if (request.method === 'GET' && request.path === '/v1/me/credentials/publication') {
            return jsonResponse({ publish_learner_name: true, effective_publish_learner_name: false });
        }
        if (request.method === 'PUT' && request.path === '/v1/me/credit-identities') {
            writes.push(request);
            return jsonResponse(world.identities ?? { items: [] });
        }
        if (request.method === 'GET' && /^\/v1\/me\/certificates\/\d+\/download$/.test(request.path)) {
            if (world.downloadProblem) return new Response(JSON.stringify({ type: 'about:blank', title: 'Conflict', status: 409, ...world.downloadProblem }), { status: 409, headers: { 'Content-Type': 'application/problem+json' } });
            if (world.downloadStatus !== undefined) {
                return new Response(JSON.stringify({ type: 'about:blank', title: 'Conflict', status: world.downloadStatus, artefact_status: 'not_rendered' }), { status: world.downloadStatus, headers: { 'Content-Type': 'application/problem+json' } });
            }
            return jsonResponse({
                url: 'https://example.test/signed/abc',
                expires_at: '2026-09-01 10:15:00',
                media_type: 'text/html',
                filename: 'certificate-NL-7QK2-9M4X.html',
                checksum: 'sha256:abc',
            });
        }
        return null;
    }, makeConfig({ screen: 'credentials', routes: world.routes }));
    render(<CredentialsApp />, { wrapper: harness.wrapper });
    return { ...harness, writes };
}

describe('my credentials', () => {
    it('uses bounded, independent pages for a large history and discovers optional routes', async () => {
        window.history.replaceState(null, '', '/#/');
        const harness = renderMine({ certificateTotal: 10000, routes: ['/me/certificates'] });
        const nav = await screen.findByRole('navigation', { name: 'Certificate pages' });
        await userEvent.click(within(nav).getByRole('button', { name: 'Next ›' }));
        await waitFor(() => expect(harness.requests.some((request) => request.path === '/v1/me/certificates?page=2&per_page=20')).toBe(true));
        expect(window.location.hash).toBe('#/?certificates=2');
        expect(harness.requests.some((request) => request.path.includes('/me/badges') || request.path.includes('/credit-identities'))).toBe(false);
        expect(harness.requests.some((request) => request.path.includes('/credentials/publication'))).toBe(false);
        window.history.replaceState(null, '', '/#/');
    });
    it('shows the grouped code, the status and the dates', async () => {
        renderMine({
            certificates: [
                makeMyCertificate({
                    id: 1,
                    expires_at: '2027-09-01 00:00:00',
                    credits: [{ scheme: 'contact_hours', scheme_label: 'Contact hours', amount: '6.00', unit: 'hours' }],
                }),
            ],
        });
        expect(await screen.findByText('NL-7QK2-9M4X')).toBeInTheDocument();
        expect(screen.getAllByText('Valid').length).toBeGreaterThan(0);
        expect(screen.getByText('1 September 2027')).toBeInTheDocument();
        expect(screen.getByText('6.00 hours (Contact hours)')).toBeInTheDocument();
    });

    it('asks for the signed URL only when the file is wanted, and never shows a path', async () => {
        const harness = renderMine();
        await screen.findByText('NL-7QK2-9M4X');
        expect(harness.requests.some((request) => request.path.includes('/download'))).toBe(false);

        await userEvent.click(screen.getByRole('button', { name: 'Download the certificate NL-7QK2-9M4X' }));
        await waitFor(() =>
            expect(screen.getByRole('link', { name: 'Open the file' })).toHaveAttribute(
                'href',
                'https://example.test/signed/abc',
            ),
        );
    });

    it('says an unrendered artefact is coming rather than handing out a broken link', async () => {
        renderMine({ downloadStatus: 409 });
        await screen.findByText('NL-7QK2-9M4X');
        await userEvent.click(screen.getByRole('button', { name: 'Download the certificate NL-7QK2-9M4X' }));
        expect(await screen.findByText(/has not been rendered yet/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open the file' })).toBeNull();
    });

    it('does not tell a learner that a withdrawn certificate is waiting to render', async () => {
        renderMine({ downloadProblem: { credential_status: 'revoked', detail: 'This certificate is no longer in force.' } });
        await screen.findByText('NL-7QK2-9M4X');
        await userEvent.click(screen.getByRole('button', { name: 'Download the certificate NL-7QK2-9M4X' }));
        expect(await screen.findByText('This certificate is no longer in force.')).toBeInTheDocument();
        expect(screen.queryByText(/has not been rendered yet/)).not.toBeInTheDocument();
    });

    it('does not mint fresh download links for a known revoked certificate', async () => {
        renderMine({ certificates: [makeMyCertificate({ id: 1, status: 'revoked', revoked_at: '2026-09-01 12:00:00' })] });
        await screen.findByText('NL-7QK2-9M4X');
        expect(screen.getByRole('button', { name: 'Download the certificate NL-7QK2-9M4X' })).toBeDisabled();
    });

    it('offers the machine-readable credential beside the file', async () => {
        renderMine();
        expect(
            await screen.findByRole('link', { name: 'Open the machine-readable credential for NL-7QK2-9M4X' }),
        ).toHaveAttribute('href', 'https://example.test/api/v1/credentials/0f1c');
    });

    it('says a build that issues no badges issues none, rather than failing', async () => {
        renderMine({ badges: { items: [], available: false } });
        expect(await screen.findByText('This site issues no badges.')).toBeInTheDocument();
    });

    it('lists a badge award with its own verification page', async () => {
        renderMine();
        expect(await screen.findByText('Accessible Forms')).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'Open the public verification page for NL9BADGE1234' }),
        ).toHaveAttribute('href', 'https://example.test/verify/badge/NL9BADGE1234');
    });

    it('writes the credit identifiers a scheme asked for, keyed by scheme and field', async () => {
        const harness = renderMine({
            identities: {
                items: [
                    { scheme: 'nasba.cpe', field: 'board_id', label: 'Certifying board ID', value: null, available: true },
                ],
            },
        });
        const control = await screen.findByRole('textbox', { name: 'Certifying board ID' });
        await userEvent.type(control, 'AB-1234');
        await userEvent.click(screen.getByRole('button', { name: 'Save details' }));
        await waitFor(() => expect(harness.writes).toHaveLength(1));
        expect(harness.writes[0]?.body).toEqual({
            items: [{ scheme: 'nasba.cpe', field: 'board_id', value: 'AB-1234' }],
        });
    });

    it('keeps a value whose scheme is no longer installed, and says so', async () => {
        renderMine({
            identities: {
                items: [
                    { scheme: 'iacet.ceu', field: 'member_id', label: 'Member ID', value: 'M-9', available: false },
                ],
            },
        });
        expect(await screen.findByRole('textbox', { name: 'Member ID' })).toHaveValue('M-9');
        expect(screen.getByText('The scheme that asked for this is no longer installed.')).toBeInTheDocument();
    });

    it('says when nothing has been issued to this account', async () => {
        renderMine({ certificates: [], badges: { items: [], available: true } });
        expect(await screen.findByText('Nothing issued to this account yet.')).toBeInTheDocument();
    });
});
