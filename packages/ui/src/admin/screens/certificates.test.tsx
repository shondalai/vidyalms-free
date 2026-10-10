import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { CertificateRecord } from '@vidyalms/api-sdk';
import { CertificatesScreen } from './certificates';
import {
    jsonResponse,
    makeCertificate,
    makeConfig,
    makeHarness,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';

/*
 * Artboard 2d, upper half: the paged list, the verification link on every
 * code, and revoking behind a confirmation that says what revoking does.
 */

const ROWS: CertificateRecord[] = [
    makeCertificate({ id: 1 }),
    makeCertificate({
        id: 2,
        code: 'NL-3FA8-P2QM',
        issued_at: '2026-07-12 10:00:00',
        learner: { id: 484, name: 'Tom Weiss' },
        course: { id: 3, title: 'ARIA in Practice', slug: 'aria-in-practice' },
    }),
    makeCertificate({
        id: 3,
        code: 'NL-0000-REVK',
        status: 'revoked',
        revoked_at: '2026-08-01 10:00:00',
        learner: { id: 485, name: 'Ada Byron' },
    }),
];

interface World {
    rows?: CertificateRecord[];
    pageRows?: Record<number, CertificateRecord[]>;
    total?: number;
    listStatus?: number;
    listFailures?: number;
    revokeStatus?: number;
}

function renderScreen(world: World = {}) {
    const rows = world.rows ?? ROWS;
    let listAttempts = 0;
    const config = makeConfig({ screen: 'certificates' });
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/admin/certificates') {
            listAttempts += 1;
            if (world.listStatus !== undefined) {
                return problemResponse(world.listStatus, 'Refused');
            }
            if (listAttempts <= (world.listFailures ?? 0)) {
                return problemResponse(400, 'Temporary test failure');
            }
            const status = url.searchParams.get('status');
            const query = (url.searchParams.get('q') ?? '').toLowerCase();
            const page = Number(url.searchParams.get('page') ?? '1');
            const sourceRows = world.pageRows?.[page] ?? rows;
            const items = sourceRows
                .filter((row) => status === null || row.status === status)
                .filter(
                    (row) =>
                        query === '' ||
                        row.code.toLowerCase().includes(query) ||
                        (row.learner.name ?? '').toLowerCase().includes(query),
                );
            return jsonResponse({ items, total: world.total ?? items.length, page, per_page: 20 });
        }
        if (request.method === 'GET' && /^\/v1\/admin\/certificates\/\d+$/.test(url.pathname)) {
            return jsonResponse({
                ...rows.find((row) => row.id === Number(url.pathname.split('/').at(-1))),
                snapshot_present: true,
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
        if (request.method === 'POST' && /^\/v1\/admin\/certificates\/\d+\/revoke$/.test(url.pathname)) {
            if (world.revokeStatus !== undefined) {
                return problemResponse(world.revokeStatus, 'Refused');
            }
            return jsonResponse(
                makeCertificate({ id: 1, status: 'revoked', revoked_at: '2026-09-01 10:00:00' }),
            );
        }
        return null;
    }, config);
    const view = render(<CertificatesScreen />, { wrapper: harness.wrapper });
    return { ...harness, view };
}

describe('CertificatesScreen', () => {
    beforeEach(() => {
        window.location.hash = '';
    });

    it('renders shape-true rows while the first page is pending', () => {
        renderScreen();
        expect(screen.getByRole('table', { name: 'Certificates' })).toHaveAttribute('aria-busy', 'true');
    });

    it('lists code, learner, course and issue date', async () => {
        renderScreen();
        expect(await screen.findByRole('button', { name: 'NL-7QK2-9M4X' })).toBeInTheDocument();
        const table = screen.getByRole('table', { name: 'Certificates' });
        expect(within(table).getByText('Ana Costa')).toBeInTheDocument();
        expect(within(table).getByText('ARIA in Practice')).toBeInTheDocument();
        expect(within(table).getByText('12 Jul')).toBeInTheDocument();
    });

    it('opens a complete record and restores the directory filters on return', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('button', { name: 'NL-7QK2-9M4X' });
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search' }), 'NL-7QK2');
        await waitFor(() =>
            expect(requests.some((request) => request.path.includes('q=NL-7QK2'))).toBe(true),
        );
        await userEvent.click(
            screen.getByRole('button', { name: 'Show the record of certificate NL-7QK2-9M4X' }),
        );
        expect(await screen.findByRole('heading', { name: 'NL-7QK2-9M4X' })).toBeInTheDocument();
        expect(window.location.hash).toBe('#/certificate/1');
        expect(screen.getByRole('link', { name: 'Open public verification' })).toHaveAttribute(
            'href',
            'https://example.test/verify/NL-7QK2-9M4X',
        );
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Back to issued certificates' }));
        expect(await screen.findByRole('searchbox', { name: 'Search' })).toHaveValue('NL-7QK2');
    });

    it('marks a revoked certificate and offers no second revoke', async () => {
        renderScreen();
        await screen.findByRole('button', { name: 'NL-7QK2-9M4X' });
        const table = screen.getByRole('table', { name: 'Certificates' });
        expect(within(table).getByText('Revoked')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Revoke certificate NL-0000-REVK' })).toBeNull();
        expect(
            screen.getByRole('button', { name: 'Show the record of certificate NL-7QK2-9M4X' }),
        ).toBeInTheDocument();
    });

    it('revokes only after the confirmation, and says what it did', async () => {
        const { requests } = renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'NL-7QK2-9M4X' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Revoke certificate NL-7QK2-9M4X' }));
        const dialog = await screen.findByRole('dialog', { name: 'Revoke this certificate?' });
        expect(dialog).toHaveTextContent('the public verification page keeps answering');

        await userEvent.click(within(dialog).getByRole('button', { name: 'Revoke certificate' }));
        await waitFor(() =>
            expect(screen.getByText('Certificate NL-7QK2-9M4X is revoked.')).toBeInTheDocument(),
        );
        expect(requests.some((request) => request.path === '/v1/admin/certificates/1/revoke')).toBe(true);
    });

    it('keeps the certificate when the confirmation is declined', async () => {
        const { requests } = renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'NL-7QK2-9M4X' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Revoke certificate NL-7QK2-9M4X' }));
        const dialog = await screen.findByRole('dialog', { name: 'Revoke this certificate?' });
        await userEvent.click(within(dialog).getByRole('button', { name: 'Keep it' }));
        expect(requests.some((request) => request.method === 'POST')).toBe(false);
    });

    it('searches by code or learner on the server', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('button', { name: 'NL-7QK2-9M4X' });
        await userEvent.type(screen.getByRole('searchbox', { name: 'Search' }), 'NL-3FA8');
        await waitFor(() => {
            const url = new URL(requests[requests.length - 1]?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('q')).toBe('NL-3FA8');
        });
    });

    it('filters to the revoked ones from the chip', async () => {
        const { requests } = renderScreen();
        await userEvent.click(await screen.findByRole('button', { name: 'Revoked' }));
        await waitFor(() => {
            const url = new URL(requests[requests.length - 1]?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('status')).toBe('revoked');
        });
    });

    it('renders only the requested page and resets paging when a filter changes', async () => {
        const first = makeCertificate({ id: 31, code: 'PAGE-ONE' });
        const second = makeCertificate({ id: 32, code: 'PAGE-TWO', status: 'revoked' });
        const { requests } = renderScreen({ pageRows: { 1: [first], 2: [second] }, total: 41 });

        expect(await screen.findByText('PAGE-ONE')).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Page 2' }));
        expect(await screen.findByText('PAGE-TWO')).toBeInTheDocument();
        expect(screen.queryByText('PAGE-ONE')).toBeNull();

        await userEvent.click(screen.getByRole('button', { name: 'Revoked' }));
        await waitFor(() => {
            const url = new URL(requests[requests.length - 1]?.path ?? '', 'http://localhost');
            expect(url.searchParams.get('status')).toBe('revoked');
            expect(url.searchParams.get('page')).toBe('1');
        });
    });

    it('sorts newest first and flips on request', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('button', { name: 'NL-7QK2-9M4X' });
        expect(new URL(requests[0]?.path ?? '', 'http://localhost').searchParams.get('order')).toBe('desc');
        await userEvent.click(screen.getByRole('button', { name: 'Issued' }));
        await waitFor(() =>
            expect(
                new URL(requests[requests.length - 1]?.path ?? '', 'http://localhost').searchParams.get(
                    'order',
                ),
            ).toBe('asc'),
        );
    });

    it('says when nothing matches', async () => {
        renderScreen({ rows: [] });
        expect(await screen.findByText('No certificates match this search.')).toBeInTheDocument();
    });

    it('retries a failed list without leaving the screen', async () => {
        const { requests } = renderScreen({ listFailures: 1 });
        await userEvent.click(await screen.findByRole('button', { name: 'Try again' }));
        expect(await screen.findByRole('button', { name: 'NL-7QK2-9M4X' })).toBeInTheDocument();
        expect(requests.filter((request) => request.path.startsWith('/v1/admin/certificates')).length).toBe(
            2,
        );
    });

    it('renders the signed-out state for an expired administrator session', async () => {
        renderScreen({ listStatus: 401 });
        expect(await screen.findByText('Sign in to continue.')).toBeInTheDocument();
    });

    it('renders the permission state for an account without manage', async () => {
        renderScreen({ listStatus: 403 });
        expect(await screen.findByText('Managers see courses only.')).toBeInTheDocument();
    });

    it('does not expose or call deferred template and badge management surfaces', async () => {
        const { requests } = renderScreen();
        await screen.findByRole('button', { name: 'NL-7QK2-9M4X' });
        expect(screen.queryByRole('tab', { name: 'Templates' })).toBeNull();
        expect(requests.some((request) => request.path.includes('/certificate-templates'))).toBe(false);
        expect(requests.some((request) => request.path.includes('/manage/badges'))).toBe(false);
        expect(screen.getByRole('tab', { name: 'Credits' })).toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Badges' })).toBeNull();
    });
});
