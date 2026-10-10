import { describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { CertificateRecord } from '@vidyalms/api-sdk';
import { CertificatesTab } from './certificates-tab';
import {
    jsonResponse,
    makeCertificate,
    makeConfig,
    makeHarness,
    type RecordedRequest,
} from '../testing/harness';

/*
 * The revoke ceremony the design's own rules demand, and the two actions
 * that exist because suspension is a first-class status.
 *
 * The rules under test. Revocation is permanent, so a revoked row offers no
 * lifecycle action at all: stepping back from one would rewrite an
 * evidentiary record. Supersession is history, so a superseded row offers
 * none either: reissuing from a dead link would fork the chain. Suspension
 * is the reversible hold that a boolean revoked flag cannot express, and it
 * asks for a reason for the same reason revocation does. A reissue is a
 * supersession and never an edit, and the dialog says so.
 */

const ROWS: CertificateRecord[] = [
    makeCertificate({ id: 1 }),
    makeCertificate({
        id: 2,
        code: 'NL-SUSP-0002',
        status: 'suspended',
        learner: { id: 484, name: 'Tom Weiss' },
    }),
    makeCertificate({
        id: 3,
        code: 'NL-0000-REVK',
        status: 'revoked',
        revoked_at: '2026-08-01 10:00:00',
        learner: { id: 485, name: 'Ada Byron' },
    }),
    makeCertificate({
        id: 4,
        code: 'NL-SUPD-0004',
        status: 'superseded',
        superseded_by_id: 9,
        learner: { id: 486, name: 'Ida Lovelace' },
    }),
];

function renderScreen() {
    const config = makeConfig({ screen: 'certificates' });
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/admin/certificates') {
            const status = url.searchParams.get('status');
            const items = ROWS.filter((row) => status === null || row.status === status);
            return jsonResponse({ items, total: items.length, page: 1, per_page: 20 });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/certificate-templates') {
            return jsonResponse({ items: [], total: 0, page: 1, per_page: 100 });
        }
        if (request.method === 'GET' && /^\/v1\/admin\/certificates\/\d+$/.test(url.pathname)) {
            return jsonResponse({
                ...makeCertificate({ id: 1 }),
                snapshot_present: false,
                renderer_key: 'html',
                engine_version: null,
                artefact_available: false,
                artefact_checksum: null,
                rendered_at: null,
                credential_format: null,
                proof_type: null,
                status_entries: [],
            });
        }
        if (request.method === 'POST' && /^\/v1\/admin\/certificates\/\d+\/\w+$/.test(url.pathname)) {
            return jsonResponse(makeCertificate({ id: 1 }));
        }
        return null;
    }, config);
    render(<CertificatesTab />, { wrapper: harness.wrapper });
    return harness;
}

describe('the certificate lifecycle actions', () => {
    it('offers suspend, reissue and revoke on a certificate in force', async () => {
        renderScreen();
        await screen.findByRole('button', { name: 'NL-7QK2-9M4X' });
        expect(screen.getByRole('button', { name: 'Suspend certificate NL-7QK2-9M4X' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reissue certificate NL-7QK2-9M4X' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Revoke certificate NL-7QK2-9M4X' })).toBeInTheDocument();
    });

    it('offers reinstate instead of suspend on a suspended certificate', async () => {
        renderScreen();
        await screen.findByRole('button', { name: 'NL-SUSP-0002' });
        expect(screen.getByRole('button', { name: 'Reinstate certificate NL-SUSP-0002' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Suspend certificate NL-SUSP-0002' })).toBeNull();
    });

    it('offers no lifecycle action on a revoked certificate, because revocation is permanent', async () => {
        renderScreen();
        await screen.findByRole('button', { name: 'NL-0000-REVK' });
        for (const label of ['Revoke', 'Suspend', 'Reinstate', 'Reissue']) {
            expect(screen.queryByRole('button', { name: `${label} certificate NL-0000-REVK` })).toBeNull();
        }
        expect(
            screen.getByRole('button', { name: 'Show the record of certificate NL-0000-REVK' }),
        ).toBeInTheDocument();
    });

    it('names the learner, the course and the consequence before revoking', async () => {
        renderScreen();
        await userEvent.click(
            await screen.findByRole('button', { name: 'Revoke certificate NL-7QK2-9M4X' }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Revoke this certificate?' });
        expect(dialog).toHaveTextContent('Ana Costa holds it for "Foundations of Web Accessibility".');
        expect(dialog).toHaveTextContent('the public verification page keeps answering');
    });

    it('offers no lifecycle action on a superseded certificate, because the chain has moved on', async () => {
        renderScreen();
        await screen.findByRole('button', { name: 'NL-SUPD-0004' });
        expect(screen.queryByRole('button', { name: 'Reissue certificate NL-SUPD-0004' })).toBeNull();
    });

    it('suspends behind a confirmation that says the hold is reversible, with a reason', async () => {
        const harness = renderScreen();
        await userEvent.click(
            await screen.findByRole('button', { name: 'Suspend certificate NL-7QK2-9M4X' }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Suspend this certificate?' });
        expect(dialog).toHaveTextContent('reversible hold');
        await userEvent.selectOptions(
            within(dialog).getByRole('combobox', { name: 'Reason' }),
            'under_investigation',
        );
        await userEvent.click(within(dialog).getByRole('button', { name: 'Suspend certificate' }));
        await waitFor(() =>
            expect(screen.getByText('Certificate NL-7QK2-9M4X is suspended.')).toBeInTheDocument(),
        );
        const write = harness.requests.find((request) => request.path === '/v1/admin/certificates/1/suspend');
        expect(write?.body).toEqual({ reason: 'under_investigation' });
    });

    it('reinstates through its own route and says the hold is lifted', async () => {
        const harness = renderScreen();
        await userEvent.click(
            await screen.findByRole('button', { name: 'Reinstate certificate NL-SUSP-0002' }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Reinstate this certificate?' });
        await userEvent.click(within(dialog).getByRole('button', { name: 'Reinstate certificate' }));
        await waitFor(() =>
            expect(screen.getByText('Certificate NL-SUSP-0002 is back in force.')).toBeInTheDocument(),
        );
        expect(
            harness.requests.some((request) => request.path === '/v1/admin/certificates/2/reinstate'),
        ).toBe(true);
    });

    it('states the carry-forward set before reissuing, and sends the renewal flag', async () => {
        const harness = renderScreen();
        await userEvent.click(
            await screen.findByRole('button', { name: 'Reissue certificate NL-7QK2-9M4X' }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Reissue this certificate?' });
        expect(dialog).toHaveTextContent('supersession and never an edit');
        expect(dialog).toHaveTextContent('one copy of every credit row');
        await userEvent.click(
            within(dialog).getByRole('checkbox', { name: 'Recompute the expiry date from the template' }),
        );
        await userEvent.click(within(dialog).getByRole('button', { name: 'Reissue certificate' }));
        await waitFor(() =>
            expect(
                harness.requests.some((request) => request.path === '/v1/admin/certificates/1/reissue'),
            ).toBe(true),
        );
        const write = harness.requests.find((request) => request.path === '/v1/admin/certificates/1/reissue');
        expect(write?.body).toEqual({ renewal: true, reason: null });
    });

    it('reports whether a row renders from its own snapshot or from the live template', async () => {
        renderScreen();
        await userEvent.click(
            await screen.findByRole('button', { name: 'Show the record of certificate NL-7QK2-9M4X' }),
        );
        const dialog = await screen.findByRole('dialog', { name: 'Certificate NL-7QK2-9M4X' });
        expect(dialog).toHaveTextContent('issued before the snapshot existed');
        expect(dialog).toHaveTextContent('Not rendered yet');
    });

    it('filters on the six-value enumeration and on the template a reissue would touch', async () => {
        const harness = renderScreen();
        await screen.findByRole('button', { name: 'NL-7QK2-9M4X' });
        await userEvent.click(screen.getByRole('button', { name: 'Superseded' }));
        await waitFor(() => {
            const url = new URL(harness.requests[harness.requests.length - 1]?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('status')).toBe('superseded');
        });
    });
});
