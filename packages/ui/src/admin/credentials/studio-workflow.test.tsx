import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { CertificateBlueprintCatalogue, CertificateElementTypeList, CertificateTemplateDetail } from '@vidyalms/api-sdk';
import { jsonResponse, makeHarness, problemResponse } from '../testing/harness';
import { CertificateBlueprintGallery } from './blueprint-gallery';
import { CertificatePublicationToolbar } from './publication-toolbar';
import { TemplateEditor } from './template-editor';

const detail: CertificateTemplateDetail = {
    id: 4, template_key: 'leadership', name: 'Leadership', renderer: 'pdf', format: 'elements', achievement_type: 'CertificateOfCompletion',
    revision: 5, published_revision: 3, has_unpublished_changes: true, schema_version: 1, locale: null, state: 'published', is_default: false,
    expiry_mode: 'never', expiry_days: null, expiry_at: null, locked_at: null, locked_fields: [], page_count: 0, element_count: 0,
    body: null, pages: [], findings: [], default_selection: { id: 2, revision: 8 },
};
const catalogue: CertificateBlueprintCatalogue = { items: [{ key: 'executive', version: 1, name: 'Executive distinction',
    description: 'A restrained editorial design.', category: 'Professional', category_key: 'professional', paper: 'A4 landscape',
    source_digest: 'a'.repeat(64), format: 'elements', required_providers: [], preview_html: '<main>Authored source preview</main>' }],
    renderer_options: [{ key: 'pdf', label: 'Portable document (PDF)', formats: ['elements'] }] };

describe('certificate publication and starter gallery', () => {
    it('retires a previous PDF while a replacement preview is pending and exposes only the new live URL', async () => {
        const live = new Set<string>(); let sequence = 0;
        const create = vi.spyOn(URL, 'createObjectURL').mockImplementation(() => {
            const url = `blob:http://localhost/studio-${++sequence}`; live.add(url); return url;
        });
        const revoke = vi.spyOn(URL, 'revokeObjectURL').mockImplementation((url) => { live.delete(url); });
        let previews = 0; let finish: (() => void) | undefined;
        const pdf = { media_type: 'application/pdf', html: null,
            data_url: `data:application/pdf;base64,${btoa('%PDF-1.7\nfixture')}`, findings: [], unresolved_tokens: [] };
        const harness = makeHarness((request) => {
            if (request.path.endsWith('/preview')) {
                if (++previews === 1) return jsonResponse(pdf);
                return new Response(new ReadableStream({ start(controller) {
                    finish = () => { controller.enqueue(new TextEncoder().encode(JSON.stringify(pdf))); controller.close(); };
                } }), { headers: { 'Content-Type': 'application/json' } });
            }
            if (request.path.endsWith('/element-types')) return jsonResponse({ items: [], tokens: [] });
            if (request.path.endsWith('/certificate-blueprints')) return jsonResponse(catalogue);
            if (request.path.endsWith('/reissue')) return jsonResponse({ template_id: 4, revision: null, state: 'idle', available: true, retention_days: 30 });
            return jsonResponse(detail);
        });
        const view = render(<TemplateEditor templateId={4} onBack={vi.fn()} />, { wrapper: harness.wrapper });
        try {
            await userEvent.click(await screen.findByRole('tab', { name: 'Preview' }));
            const previous = await screen.findByRole('link', { name: 'Download sample PDF' });
            const oldUrl = previous.getAttribute('href')!;
            expect(live.has(oldUrl)).toBe(true);
            await userEvent.click(screen.getByRole('button', { name: 'Preview' }));
            await waitFor(() => expect(screen.queryByRole('link', { name: 'Download sample PDF' })).not.toBeInTheDocument());
            expect(previous.isConnected).toBe(false);
            expect(live.has(oldUrl)).toBe(false);
            if (!finish) throw new Error('The replacement preview did not start.');
            finish();
            const next = await screen.findByRole('link', { name: 'Download sample PDF' });
            expect(next.getAttribute('href')).not.toBe(oldUrl);
            expect(live.has(next.getAttribute('href')!)).toBe(true);
        } finally { view.unmount(); create.mockRestore(); revoke.mockRestore(); }
    });

    it('opens a populated draft with the routed provider palette, token fields and editable enum defaults', async () => {
        // BorderElement::formSchema/defaultPayload and CertificateTokens::families are normalized by
        // CertificatePalettePresenter. The Core routed regression asserts these same wire members.
        const palette: CertificateElementTypeList = { items: [{ key: 'border', label: 'Border', group: 'decoration', order: 10,
            available: true, current_version: 1, supported_payload_versions: [1], capabilities: [],
            groups: [{ key: 'border', label: 'Border', fields: [
                { key: 'style', label: 'Border style', type: 'enum', default: 'double', options: ['solid', 'double', 'dashed', 'dotted'].map((key) => ({ key, label: key })) },
                { key: 'width_pt', label: 'Border width (pt)', type: 'integer', default: 10 },
                { key: 'colour', label: 'Colour', type: 'string', default: 'var(--vy-accent, #24405c)' },
                { key: 'inset_mm', label: 'Inset (mm)', type: 'integer', default: 8 },
                { key: 'radius_mm', label: 'Corner radius (mm)', type: 'integer', default: 0 },
            ] }],
        }], tokens: [{ key: 'user', label: 'The recipient', available: true, fields: [{ key: 'full_name', label: 'user.full_name' }] }] };
        const template: CertificateTemplateDetail = { ...detail, name: 'New certificate draft', published_revision: null, state: 'draft', page_count: 1, element_count: 1,
            pages: [{ id: 10, template_id: 4, sequence: 1, width: 297, height: 210, orientation: 'landscape',
                margin_top: 10, margin_right: 10, margin_bottom: 10, margin_left: 10, background: null,
                elements: [{ id: 11, page_id: 10, sequence: 1, element: 'border', name: null, data: {}, data_version: 1,
                    current_version: 1, available: true, posx: 0, posy: 0, width: null, height: null, refpoint: 'top_left', alignment: 'left' }] }] };
        const harness = makeHarness((request) => {
            if (request.path.endsWith('/element-types')) return jsonResponse(palette);
            if (request.path.endsWith('/certificate-blueprints')) return jsonResponse(catalogue);
            if (request.path.endsWith('/preview')) return jsonResponse({ media_type: 'text/html', html: '<p>Preview</p>', findings: [], unresolved_tokens: [] });
            if (request.path.endsWith('/reissue')) return jsonResponse({ template_id: 4, revision: null, state: 'idle', available: true, retention_days: 30 });
            return jsonResponse(template);
        });
        render(<TemplateEditor templateId={4} onBack={vi.fn()} />, { wrapper: harness.wrapper });
        await screen.findByRole('heading', { name: 'New certificate draft' });
        expect(await screen.findByText('{user.full_name}')).toBeInTheDocument();
        expect(screen.getByRole('combobox', { name: 'Border style' })).toHaveValue('double');
        expect(screen.getByLabelText('Border width (pt)')).toHaveValue('10');
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Border style' }), 'solid');
        expect(screen.getByRole('combobox', { name: 'Border style' })).toHaveValue('solid');
        expect(screen.getByText('Unsaved changes')).toBeInTheDocument();
    });

    it('clones versioned source with a renderer and never silently publishes the draft', async () => {
        const created = vi.fn();
        const harness = makeHarness((request) => request.method === 'GET' ? jsonResponse(catalogue) : jsonResponse(detail));
        render(<CertificateBlueprintGallery onCreated={created} />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(await screen.findByRole('button', { name: 'Customize a copy' }));
        const frame = screen.getByTitle('Preview of Executive distinction');
        expect(frame).toHaveAttribute('sandbox', '');
        expect(frame.getAttribute('srcdoc')).toContain("default-src 'none'");
        await user.click(screen.getByRole('button', { name: 'Create draft' }));
        await waitFor(() => expect(created).toHaveBeenCalledWith(4));
        const writes = harness.requests.filter((request) => request.method === 'POST');
        expect(writes).toHaveLength(1);
        expect(writes[0]?.path).toBe('/v1/manage/certificate-blueprints/executive/clone');
        expect(writes[0]?.body).toEqual({ name: 'Executive distinction - copy', version: 1, renderer: 'pdf' });
        expect(writes[0]?.headers['idempotency-key']).toBeTruthy();
    });
    it('refuses to offer a creation action without an installed compatible renderer', async () => {
        const harness = makeHarness(() => jsonResponse({ ...catalogue, renderer_options: [] }));
        render(<CertificateBlueprintGallery onCreated={vi.fn()} />, { wrapper: harness.wrapper });
        await userEvent.click(await screen.findByRole('button', { name: 'Customize a copy' }));
        expect(screen.getByRole('button', { name: 'Create draft' })).toBeDisabled();
        expect(screen.getByRole('alert')).toHaveTextContent('No compatible output renderer');
    });
    it('requires confirmation and retains the publish revision and replay key on retry', async () => {
        let writes = 0;
        const harness = makeHarness(() => {
            writes += 1; return writes === 1 ? problemResponse(503, 'Temporary publication failure') : jsonResponse({ ...detail, published_revision: 5 });
        });
        const done = vi.fn();
        render(<CertificatePublicationToolbar template={detail} onDone={done} />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: 'Publish design' }));
        expect(harness.requests).toHaveLength(0);
        const dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('button', { name: 'Publish design' }));
        await screen.findByText('Temporary publication failure');
        await user.click(within(dialog).getByRole('button', { name: 'Publish design' }));
        await waitFor(() => expect(done).toHaveBeenCalledWith('Design published'));
        expect(harness.requests[0]?.body).toEqual({ expected_revision: 5 });
        expect(harness.requests[1]?.body).toEqual(harness.requests[0]?.body);
        expect(harness.requests[1]?.headers['idempotency-key']).toBe(harness.requests[0]?.headers['idempotency-key']);
    });
    it('compares the observed default selection atomically when setting a new default', async () => {
        const harness = makeHarness(() => jsonResponse({ ...detail, is_default: true }));
        render(<CertificatePublicationToolbar template={detail} onDone={vi.fn()} />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await user.click(screen.getByRole('button', { name: 'Use as site default' }));
        await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Use as site default' }));
        await waitFor(() => expect(harness.requests).toHaveLength(1));
        expect(harness.requests[0]?.path).toBe('/v1/manage/certificate-templates/4/default');
        expect(harness.requests[0]?.body).toEqual({ expected_revision: 5, expected_default_id: 2, expected_default_revision: 8 });
    });
});
