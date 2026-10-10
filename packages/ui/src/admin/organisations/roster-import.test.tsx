import { afterEach, beforeEach, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FakeXhrServer } from '@/shared/testing/fake-xhr';
import { jsonResponse, makeConfig, makeHarness } from '../testing/harness';
import { OrganisationRosterImport } from './roster-import';

const uploads = new FakeXhrServer();
beforeEach(() => uploads.install());
afterEach(() => uploads.restore());

it('uploads under the selected organisation and requires a preview before queueing', async () => {
    uploads.answerWith({ status: 201, body: JSON.stringify({ path: 'imports/2/roster.csv', name: 'roster.csv', url: null }) });
    const config = makeConfig();
    config.urls.upload = '/host/upload';
    const harness = makeHarness(({ method, path, body }) => {
        if (method === 'POST' && path === '/v1/manage/organisations/7/imports') {
            const dryRun = (body as { dry_run: boolean }).dry_run;
            return jsonResponse({ id: dryRun ? 20 : 21, status: dryRun ? 'previewed' : 'pending', totals: { created: 1 }, log: null });
        }
        if (method === 'GET' && path === '/v1/manage/organisations/7/imports/21') {
            return jsonResponse({ id: 21, status: 'completed', totals: { created: 1 }, log: null });
        }
        return null;
    }, config);
    render(<OrganisationRosterImport organisationId={7} />, { wrapper: harness.wrapper });
    expect(screen.queryByRole('button', { name: 'Start import' })).not.toBeInTheDocument();
    await userEvent.upload(screen.getByLabelText('Roster file'), new File(['email\nlearner@example.test'], 'roster.csv', { type: 'text/csv' }));
    await userEvent.click(screen.getByRole('button', { name: 'Preview roster' }));
    await screen.findByText('Preview ready');
    expect(uploads.calls[0]?.body?.get('purpose')).toBe('org_import');
    expect(uploads.calls[0]?.body?.get('organisation_id')).toBe('7');
    expect(harness.requests[0]?.body).toEqual({ importer: 'org-members-csv:in', source: 'imports/2/roster.csv', dry_run: true, options: {} });
    await userEvent.click(screen.getByRole('button', { name: 'Start import' }));
    await screen.findByText('Import completed');
    await waitFor(() => expect(harness.requests.filter((request) => request.method === 'POST')).toHaveLength(2));
    const queued = harness.requests.filter((request) => request.method === 'POST')[1];
    expect(queued?.body).toEqual({ importer: 'org-members-csv:in', source: 'imports/2/roster.csv', dry_run: false, options: {} });
    expect(queued?.headers['idempotency-key']).toBeTruthy();
});
