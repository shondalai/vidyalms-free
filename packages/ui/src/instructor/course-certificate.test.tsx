import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import type { CourseCertificatePolicy } from '@vidyalms/api-sdk';
import { CourseCertificatePolicyPanel } from './tabs/course-certificate-panel';
import { jsonResponse, makeHarness, problemResponse, type RecordedRequest } from './testing/harness';

const policy: CourseCertificatePolicy = {
    course_id: 1, revision: 3, enabled: true, template_id: null, issue_rule: 'course_completed', co_branding: 'site',
    expiry: { mode: 'inherit_template', days: null, at: null },
    resolved_template: { id: 12, name: 'Executive distinction', published_revision: 8, source: 'site_default', available: true, diagnostic: null },
    capabilities: { issue_rules: ['course_completed'], co_branding_modes: ['site'], expiry_modes: ['inherit_template', 'never', 'fixed', 'days_after_issue'] },
};
const choices = { items: [{ id: 12, name: 'Executive distinction', published_revision: 8, renderer: 'pdf', format: 'elements', is_default: true, available: true },
    { id: 13, name: 'Learning milestone', published_revision: 4, renderer: 'pdf', format: 'elements', is_default: false, available: true }], total: 21, page: 1, per_page: 10 };
function setup(write?: (request: RecordedRequest) => Response, read?: () => CourseCertificatePolicy) {
    const harness = makeHarness((request) => {
        if (request.method === 'PUT') return write?.(request) ?? jsonResponse({ ...policy, revision: 4, ...(request.body as object) });
        if (request.path === '/v1/manage/courses/1/certificate-policy') return jsonResponse(read?.() ?? policy);
        if (request.path.startsWith('/v1/manage/courses/1/certificate-templates')) return jsonResponse(choices);
        return null;
    });
    render(<CourseCertificatePolicyPanel courseId={1} />, { wrapper: harness.wrapper });
    return harness;
}
describe('course certificate settings', () => {
    it('saves a selected published design with the observed revision and bounded picker', async () => {
        const user = userEvent.setup(); const harness = setup();
        await user.click(await screen.findByRole('button', { name: /Learning milestone/ }));
        await user.selectOptions(screen.getByLabelText('Expiry policy'), 'days_after_issue');
        await user.type(screen.getByLabelText('Days after issue'), '365');
        await user.click(screen.getByRole('button', { name: 'Save certificate settings' }));
        await screen.findByText('Certificate settings saved.');
        const write = harness.requests.find((request) => request.method === 'PUT');
        expect(write?.body).toEqual({ expected_revision: 3, enabled: true, template_id: 13, issue_rule: 'course_completed',
            co_branding: 'site', expiry: { mode: 'days_after_issue', days: 365, at: null } });
        expect(write?.headers['idempotency-key']).toBeTruthy();
        expect(harness.requests.some((request) => request.path.includes('per_page=10'))).toBe(true);
        await user.click(screen.getByRole('button', { name: 'Page 2' }));
        await waitFor(() => expect(harness.requests.some((request) => request.path.includes('page=2'))).toBe(true));
    });
    it('preserves the same body and retry key after an ambiguous save and a newer background read', async () => {
        let writes = 0; let current = policy;
        const harness = setup((request) => {
            writes += 1;
            if (writes === 1) { current = { ...policy, revision: 4 }; return problemResponse(503, 'Temporary failure'); }
            return jsonResponse({ ...policy, revision: 4, ...(request.body as object) });
        }, () => current);
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: /Learning milestone/ }));
        await user.click(screen.getByRole('button', { name: 'Save certificate settings' }));
        await screen.findByText('Temporary failure');
        await harness.queryClient.invalidateQueries();
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Save certificate settings' }));
        await screen.findByText('Certificate settings saved.');
        const requests = harness.requests.filter((request) => request.method === 'PUT');
        expect(requests[0]?.body).toEqual(requests[1]?.body);
        expect(requests[0]?.headers['idempotency-key']).toBe(requests[1]?.headers['idempotency-key']);
    });
    it('does not replace an unsaved selection when a background read changes', async () => {
        let current = policy; const harness = setup(undefined, () => current);
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: /Learning milestone/ }));
        current = { ...policy, revision: 9, enabled: false };
        await harness.queryClient.invalidateQueries();
        expect(screen.getByRole('button', { name: /Learning milestone/ })).toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByLabelText('Issue a certificate when this course is completed')).toBeChecked();
        await user.click(screen.getByRole('button', { name: 'Reload saved settings' }));
        const dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Discard and reload' }));
        await waitFor(() => expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument());
        expect(screen.getByLabelText('Issue a certificate when this course is completed')).not.toBeChecked();
    });
    it('warns on document navigation until the changed settings are saved', async () => {
        const user = userEvent.setup(); setup();
        await user.click(await screen.findByLabelText('Issue a certificate when this course is completed'));
        const event = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(true);
        await user.click(screen.getByRole('button', { name: 'Save certificate settings' }));
        await screen.findByText('Certificate settings saved.');
        const clean = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(clean);
        expect(clean.defaultPrevented).toBe(false);
    });
});
