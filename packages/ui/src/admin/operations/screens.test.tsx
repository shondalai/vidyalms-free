import { afterEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { OperationOverview, OperationTask, components } from '@vidyalms/api-sdk';
import { jsonResponse, makeHarness, makeConfig } from '../testing/harness';
import { ImportsScreen, OperationsScreen } from './screens';

afterEach(() => { window.history.replaceState(null, '', '/'); });
const task: OperationTask = { id: 55, type: 'vidyalms.certificate.reissue', state: 'failed', group_key: 'reissue:template:4:3', attempts: 3, scheduled_at: '2026-09-05 11:00:00', started_at: null, finished_at: null, created_at: '2026-09-05 11:00:00', updated_at: '2026-09-05 11:00:00', diagnostic: 'Publication changed. Review before starting a new run.', audit_url: '/manage/operations/jobs/55' };
const overview: OperationOverview = { generated_at: '2026-09-05 11:00:00', scheduler: { available: true, counts: { total: 900, queued: 10, running: 2, complete: 885, failed: 3, cancelled: 0, due: 9, stalled: 0 } }, imports: { counts: { pending: 3 } }, notifications: { counts: { queued: 5 } }, health_basis: 'persisted_queue', delivery_probe: null, worker_heartbeat: null };
const importJob: components['schemas']['ImportJob'] = { id: 9, importer: 'vidyalms-json', status: 'failed', organisation_id: null, source: null, options: {}, dry_run: false, totals: { courses: 2 }, log: null, notes: [{ level: 'error', message: '<script>Source title</script>' }], created_by: 1, created_at: '2026-09-05 11:00:00', started_at: null, finished_at: null, heartbeat_at: null, stalled: false };
describe('persisted operations', () => {
    it('reads bounded job pages and restores detail/filter state on return', async () => {
        window.history.replaceState(null, '', '/#/?page=3&state=failed');
        const harness = makeHarness((request) => request.path.endsWith('/operations') ? jsonResponse(overview) : request.path.endsWith('/jobs/55') ? jsonResponse(task) : jsonResponse({ available: true, items: [task], total: 10000, page: 3, per_page: 20 }));
        render(<OperationsScreen />, { wrapper: harness.wrapper }); const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Inspect job' }));
        await screen.findByText(task.diagnostic!); expect(window.location.hash).toContain('/jobs/55?page=3&state=failed');
        expect(screen.queryByRole('button', { name: /^Retry job$/ })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Back to jobs' }));
        await screen.findByRole('button', { name: 'Inspect job' }); expect(window.location.hash).toBe('#/?page=3&state=failed');
        expect(harness.requests.some((request) => request.path.includes('page=3&per_page=20&state=failed'))).toBe(true);
    });
    it('does not invent worker or external mail health', async () => {
        const harness = makeHarness((request) => request.path.endsWith('/operations') ? jsonResponse(overview) : jsonResponse({ available: true, items: [], total: 0, page: 1, per_page: 20 }));
        render(<OperationsScreen />, { wrapper: harness.wrapper });
        await screen.findByText(/Observed:/); expect(screen.getAllByText('Not measured')).toHaveLength(2);
    });
    it('opens the known originating template without exposing generic job replay', async () => {
        window.history.replaceState(null, '', '/#/jobs/55');
        const config = makeConfig(); config.urls.screens.certificates = '/admin/certificates';
        const harness = makeHarness(() => jsonResponse({ ...task, audit_url: 'https://evil.test/audit' }), config);
        render(<OperationsScreen />, { wrapper: harness.wrapper });
        expect(await screen.findByRole('link', { name: 'Open certificate template' })).toHaveAttribute('href', `${window.location.origin}/admin/certificates#/templates/4/edit`);
        expect(screen.queryByRole('button', { name: /retry job|cancel job/i })).not.toBeInTheDocument();
        expect(document.querySelector('a[href*="evil.test"]')).toBeNull();
    });
    it('keeps unknown jobs explanatory without treating group text or API audit paths as browser destinations', async () => {
        window.history.replaceState(null, '', '/#/jobs/55');
        const config = makeConfig(); config.urls.screens.certificates = '/admin/certificates';
        const harness = makeHarness(() => jsonResponse({ ...task, type: 'thirdparty.custom', group_key: 'https://evil.test/action' }), config);
        render(<OperationsScreen />, { wrapper: harness.wrapper });
        await screen.findByText('https://evil.test/action');
        expect(screen.getByText(/Use the originating workflow/)).toBeInTheDocument();
        expect(screen.queryByRole('link')).not.toBeInTheDocument();
    });
    it('opens native import configuration and safely renders recorded results after a direct-link refresh', async () => {
        window.history.replaceState(null, '', '/#/jobs/9?page=2');
        const config = makeConfig(); config.urls.nativeImport = '/native/import'; const harness = makeHarness(() => jsonResponse(importJob), config);
        render(<ImportsScreen />, { wrapper: harness.wrapper }); await screen.findByText('<script>Source title</script>');
        expect(screen.getByRole('link', { name: 'Upload or configure import' })).toHaveAttribute('href', '/native/import');
        expect(screen.getByText(/may already have created records/)).toBeInTheDocument(); expect(document.querySelector('script')).toBeNull();
        expect(harness.requests[0]?.path).toBe('/v1/import/9');
    });
});
