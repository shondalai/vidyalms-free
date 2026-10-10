import { describe, expect, it } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import type { CertificateDetail, CredentialStatusEntry } from '@vidyalms/api-sdk';
import { CertificateDetailDialog } from './certificate-detail';
import { jsonResponse, makeCertificate, makeConfig, makeHarness, type RecordedRequest } from '../testing/harness';

/*
 * The issuance record, read from the shape the contract declares.
 *
 * This dialog answered its own question wrongly for a whole wave and both
 * sides type-checked while doing it. The handler nested the nine members
 * under provenance, artefact and credential; the screen read them at the top
 * level, because that is where openapi puts them and where the SDK type says
 * they are. Every read came back undefined, so a certificate that
 * demonstrably had a frozen snapshot reported "no snapshot", "no artefact"
 * and "no credential format", which is the exact opposite of the invariant
 * the screen exists to show.
 *
 * Nothing but a live call found it, so the guard is a fixture typed as the
 * SDK's own CertificateDetail (the required nine are a compile error if the
 * shape moves) carrying values that are all present and all different from
 * their falsy defaults, served over the SDK and asserted on the screen.
 */

const ENTRIES: CredentialStatusEntry[] = [
    {
        purpose: 'revocation',
        list_id: '2f0b6c3a-5f7d-4a1b-8e2c-9d0f1a2b3c4d',
        list_index: 4217,
        status_list_url: 'https://example.test/credentials/status/2f0b6c3a-5f7d-4a1b-8e2c-9d0f1a2b3c4d',
    },
    {
        purpose: 'suspension',
        list_id: '7c1d2e3f-4a5b-6c7d-8e9f-0a1b2c3d4e5f',
        list_index: 19,
        status_list_url: null,
    },
];

const RECORD: CertificateDetail = {
    ...makeCertificate({ id: 1 }),
    snapshot_present: true,
    renderer_key: 'elements',
    engine_version: 'elements/1.2.0',
    artefact_available: true,
    artefact_checksum: 'sha256:6b3a9f1c',
    rendered_at: '2026-09-01 10:00:12',
    credential_format: 'vidyalms.json',
    proof_type: 'DataIntegrityProof',
    issuer_earner_id: 'ext-earner-42',
    status_entries: ENTRIES,
};

function renderDialog(record: CertificateDetail) {
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/admin/certificates/1') {
            return jsonResponse(record);
        }
        return null;
    }, makeConfig({ screen: 'certificates' }));
    render(
        <CertificateDetailDialog
            certificateId={1}
            code="NL-7QK2-9M4X"
            open
            onOpenChange={() => undefined}
        />,
        { wrapper: harness.wrapper },
    );
    return harness;
}

describe('the certificate record dialog', () => {
    it('reads the frozen snapshot, the renderer and the engine from the top level of the record', async () => {
        renderDialog(RECORD);
        const dialog = await screen.findByRole('dialog', { name: /NL-7QK2-9M4X/ });

        expect(await within(dialog).findByText('Its own frozen snapshot')).toBeInTheDocument();
        expect(within(dialog).getByText('elements')).toBeInTheDocument();
        expect(within(dialog).getByText('elements/1.2.0')).toBeInTheDocument();
    });

    it('reports the artefact as present with its checksum and the moment it was rendered', async () => {
        renderDialog(RECORD);
        const dialog = await screen.findByRole('dialog', { name: /NL-7QK2-9M4X/ });

        expect(await within(dialog).findByText('sha256:6b3a9f1c')).toBeInTheDocument();
        expect(within(dialog).queryByText(/Not rendered yet/)).toBeNull();
    });

    it('reports the credential format, the proof type and the caller-owned earner id', async () => {
        renderDialog(RECORD);
        const dialog = await screen.findByRole('dialog', { name: /NL-7QK2-9M4X/ });

        expect(await within(dialog).findByText('vidyalms.json')).toBeInTheDocument();
        expect(within(dialog).getByText('DataIntegrityProof')).toBeInTheDocument();
        expect(within(dialog).getByText('ext-earner-42')).toBeInTheDocument();
    });

    it('lists one status entry per purpose, by the list and index the contract names', async () => {
        renderDialog(RECORD);
        const dialog = await screen.findByRole('dialog', { name: /NL-7QK2-9M4X/ });

        expect(await within(dialog).findByText('revocation')).toBeInTheDocument();
        expect(within(dialog).getByText('suspension')).toBeInTheDocument();
        expect(within(dialog).getByText('2f0b6c3a-5f7d-4a1b-8e2c-9d0f1a2b3c4d')).toBeInTheDocument();
        expect(within(dialog).getByText(/Index 4217/)).toBeInTheDocument();
        expect(within(dialog).getByText(/Index 19/)).toBeInTheDocument();
    });

    it('says a row is unfrozen rather than assuming it is, which is the whole point of the screen', async () => {
        renderDialog({
            ...RECORD,
            snapshot_present: false,
            artefact_available: false,
            artefact_checksum: null,
            rendered_at: null,
            credential_format: null,
            proof_type: null,
            status_entries: [],
        });
        const dialog = await screen.findByRole('dialog', { name: /NL-7QK2-9M4X/ });

        expect(await within(dialog).findByText(/issued before the snapshot existed/)).toBeInTheDocument();
        expect(within(dialog).getByText(/Not rendered yet/)).toBeInTheDocument();
        expect(within(dialog).getByText(/No machine-readable credential/)).toBeInTheDocument();
    });
});
