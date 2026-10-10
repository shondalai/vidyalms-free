import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { makeConfig, makeHarness, jsonResponse, problemResponse } from '../testing/harness';
import { ImportWizard, importTransport } from './import-wizard';

afterEach(() => vi.unstubAllGlobals());
const transport = { url: '/import-source', nonceName: 'csrf', nonce: 'test-token' };
const catalogue = {
    max_bytes: 1024,
    importers: [
        {
            key: 'json-course',
            label: 'JSON course',
            accepts_file: true,
            accepts_database: false,
            extensions: ['json'],
            options: [
                {
                    key: 'enrolments',
                    label: 'Include enrolments',
                    kind: 'boolean',
                    help: 'Bring access records with the course.',
                    default: '0',
                    choices: [],
                },
            ],
        },
    ],
};
const preview = {
    id: 7,
    importer: 'json-course',
    source: 'imports/7/course.json',
    status: 'previewed',
    dry_run: true,
    options: { enrolments: true, dry_run: true },
    totals: { courses: 1 },
    notes: [],
    log: '',
    stalled: false,
    created_at: '2026-09-11 00:00:00',
};

describe('import preparation and review', () => {
    it('previews a validated source and reuses the apply key after a failed response', async () => {
        const user = userEvent.setup();
        const submitted: FormData[] = [];
        let writes = 0;
        const opened = vi.fn();
        vi.stubGlobal(
            'fetch',
            vi.fn(async (_url: URL, init: RequestInit) => {
                const body = init.body as FormData;
                submitted.push(body);
                return jsonResponse(body.get('operation') === 'catalogue' ? catalogue : { id: 7 });
            }),
        );
        const harness = makeHarness(
            (request) =>
                request.method === 'GET'
                    ? jsonResponse(preview)
                    : ++writes === 1
                      ? problemResponse(503, 'Temporary outage')
                      : jsonResponse({ ...preview, id: 8, status: 'pending', dry_run: false }),
            makeConfig({ importTransport: transport }),
        );
        render(<ImportWizard onOpen={opened} />, { wrapper: harness.wrapper });
        await user.click(await screen.findByRole('button', { name: /JSON course/ }));
        await user.upload(
            screen.getByLabelText('Import file'),
            new File(['{}'], 'course.json', { type: 'application/json' }),
        );
        await user.selectOptions(screen.getByLabelText('Include enrolments'), '1');
        await user.click(screen.getByRole('button', { name: 'Preview import' }));
        const apply = await screen.findByRole('button', { name: 'Apply this import' });
        await waitFor(() => expect(apply).toBeEnabled());
        expect(submitted[1]?.get('csrf')).toBe('test-token');
        expect(submitted[1]?.get('options[enrolments]')).toBe('1');
        expect(harness.requests.filter((request) => request.method === 'POST')).toHaveLength(0);
        await user.click(apply);
        await waitFor(() => expect(writes).toBe(1));
        await waitFor(() => expect(apply).toBeEnabled());
        await user.click(apply);
        await waitFor(() => expect(opened).toHaveBeenCalledWith(8));
        const requests = harness.requests.filter((request) => request.method === 'POST');
        expect(requests[0]?.headers['idempotency-key']).toBeTruthy();
        expect(requests[1]?.headers['idempotency-key']).toBe(requests[0]?.headers['idempotency-key']);
        expect(requests[0]?.body).toMatchObject({
            source: preview.source,
            dry_run: false,
            options: { enrolments: true, dry_run: false },
        });
    });
    it('refuses an oversized file before uploading it', async () => {
        const user = userEvent.setup();
        const fetcher = vi.fn(async () => jsonResponse(catalogue));
        vi.stubGlobal('fetch', fetcher);
        const harness = makeHarness(() => null, makeConfig({ importTransport: transport }));
        render(<ImportWizard onOpen={vi.fn()} />, { wrapper: harness.wrapper });
        await user.click(await screen.findByRole('button', { name: /JSON course/ }));
        await user.upload(
            screen.getByLabelText('Import file'),
            new File(['x'.repeat(2048)], 'large.json', { type: 'application/json' }),
        );
        await user.click(screen.getByRole('button', { name: 'Preview import' }));
        expect(await screen.findByText('This file exceeds the upload limit.')).toBeInTheDocument();
        expect(fetcher).toHaveBeenCalledTimes(1);
    });
    it('never sends a CMS nonce to another origin', async () => {
        const fetcher = vi.fn();
        vi.stubGlobal('fetch', fetcher);
        await expect(
            importTransport({ ...transport, url: 'https://other.example/upload' }, new FormData()),
        ).rejects.toThrow('must belong to this site');
        expect(fetcher).not.toHaveBeenCalled();
    });
});
