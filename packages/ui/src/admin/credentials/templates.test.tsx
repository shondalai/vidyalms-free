import { beforeAll, describe, expect, it } from 'vitest';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type {
    CertificateElementType,
    CertificateTemplate,
    CertificateTemplateDetail,
    CertificateTemplateElement,
    CertificateTemplatePage,
} from '@vidyalms/api-sdk';
import { CERTIFICATE_DESIGNER_CONTRACT, certificateDesignerRegistry } from '@/certificate-designer';
import type { CertificatePageDesignerProps } from '@/certificate-designer';
import { CertificatesScreen } from '../screens/certificates';
import {
    jsonResponse,
    makeConfig,
    makeHarness,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';
import { credentialKeys } from './api';

/*
 * The template aggregate, edited as three levels.
 *
 * The rules under test. The palette is the element registry as this template
 * sees it, so an element the server refuses to offer is not addable and says
 * why; nothing in the client names an element key. An element whose provider
 * is missing is kept exactly as authored and still positionable, because
 * dropping it would lose an author's work the moment a package is
 * uninstalled. Order is written as the whole list rather than as a swap.
 * And the drag-and-drop designer is a registration: with none, the form
 * editor is what an author uses and the screen says so.
 */

const ELEMENT: CertificateTemplateElement = {
    id: 11,
    page_id: 5,
    sequence: 1,
    element: 'learner_name',
    name: null,
    data: { font_size: 18 },
    data_version: 1,
    current_version: 2,
    available: true,
    posx: 20,
    posy: 40,
    width: null,
    height: null,
    refpoint: 'top_left',
    alignment: 'centre',
};

const ORPHAN: CertificateTemplateElement = {
    ...ELEMENT,
    id: 12,
    sequence: 2,
    element: 'acme.seal',
    name: 'Issuer seal',
    available: false,
    data_version: 1,
    current_version: 1,
};

const DETAIL: CertificateTemplateDetail = {
    id: 4,
    template_key: 'standard',
    name: 'Standard',
    renderer: 'elements',
    format: 'elements',
    achievement_type: 'CertificateOfCompletion',
    revision: 3,
    published_revision: 3,
    has_unpublished_changes: false,
    schema_version: 1,
    locale: null,
    state: 'published',
    is_default: true,
    expiry_mode: 'never',
    expiry_days: null,
    expiry_at: null,
    locked_at: '2026-07-01 10:00:00',
    locked_fields: ['expiry_mode', 'expiry_days', 'expiry_at'],
    page_count: 1,
    element_count: 2,
    body: null,
    pages: [
        {
            id: 5,
            template_id: 4,
            sequence: 1,
            width: 297,
            height: 210,
            orientation: 'landscape',
            margin_top: 10,
            margin_right: 10,
            margin_bottom: 10,
            margin_left: 10,
            background: null,
            elements: [ELEMENT, ORPHAN],
        },
    ],
    findings: [
        {
            severity: 'error',
            key: 'certificate.template.verification_text_missing',
            message: 'A QR code may not be the only way to reach the verification page.',
        },
    ],
};

const SUMMARY: CertificateTemplate = {
    id: 4,
    template_key: 'standard',
    name: 'Standard',
    renderer: 'elements',
    format: 'elements',
    achievement_type: 'CertificateOfCompletion',
    revision: 3,
    schema_version: 1,
    locale: null,
    state: 'published',
    is_default: true,
    expiry_mode: 'never',
    expiry_days: null,
    expiry_at: null,
    locked_at: '2026-07-01 10:00:00',
    page_count: 1,
    element_count: 2,
};

const SECOND_PAGE: CertificateTemplatePage = {
    ...DETAIL.pages[0]!,
    id: 6,
    sequence: 2,
    elements: [],
};

const TYPES: CertificateElementType[] = [
    {
        key: 'learner_name',
        label: 'Learner name',
        group: 'identity',
        order: 1,
        edition: 'free',
        available: true,
        current_version: 2,
        capabilities: ['ResolvesToText', 'ContributesToCredential'],
        contributes_to: 'credentialSubject.achievement.name',
        groups: [
            {
                key: 'type',
                label: 'Type',
                fields: [{ key: 'font_size', label: 'Font size', type: 'integer', default: 16, minimum: 6 }],
            },
        ],
    },
    {
        key: 'credit_statement',
        label: 'Credit statement',
        group: 'compliance',
        order: 2,
        edition: 'free',
        available: false,
        unavailable_reason: 'This course carries no credit scheme.',
        current_version: 1,
        capabilities: ['ResolvesToText'],
        groups: [],
    },
];

interface World {
    reissueAvailable?: boolean;
    reissueStatus?: 'idle' | 'queued';
    failFirstCreate?: boolean;
    failFirstTemplateUpdate?: boolean;
    failFirstPageAdd?: boolean;
    failFirstPageUpdate?: boolean;
    failElementUpdate?: boolean;
    enforceTemplateCas?: boolean;
    enforceElementCas?: boolean;
    staleTemplateReplay?: boolean;
    /** The detail the server answers, so the lock can be varied without a second fixture. */
    template?: Partial<CertificateTemplateDetail>;
}

/**
 * A response with a status of its own. The 202 of a queued run and the 200
 * of a build that registers no bulk reissue are different answers on the
 * same route, so the fixture has to be able to say which one it is.
 */
function statusResponse(payload: unknown, status: number): Response {
    return new Response(JSON.stringify(payload), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function renderTemplates(world: World = {}) {
    window.history.replaceState(null, '', window.location.pathname);
    const config = makeConfig({ screen: 'certificates' });
    let currentRevision = world.template?.revision ?? DETAIL.revision;
    let currentPages = world.template?.pages ?? DETAIL.pages;
    let createAttempts = 0;
    let templateUpdateAttempts = 0;
    let pageAddAttempts = 0;
    let pageUpdateAttempts = 0;
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (request.method === 'GET' && url.pathname === '/v1/manage/certificate-blueprints') {
            return jsonResponse({
                items: [],
                renderer_options: [
                    { key: 'elements', label: 'HTML page design', formats: ['elements'] },
                    { key: 'html', label: 'HTML body', formats: ['html'] },
                ],
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/certificate-templates') {
            return jsonResponse({
                items: [{ ...SUMMARY, thumbnail: world.template?.thumbnail }],
                total: 1,
                page: 1,
                per_page: 20,
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/certificate-templates/4') {
            return jsonResponse({
                ...DETAIL,
                ...(world.template ?? {}),
                pages: currentPages,
                revision: currentRevision,
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/certificate-templates/4/reissue') {
            return jsonResponse({
                template_id: 4,
                revision: world.reissueStatus === 'queued' ? 2 : null,
                state: world.reissueStatus ?? 'idle',
                available: true,
                retention_days: 30,
                counts: { queued: 8, running: 1, complete: 3, failed: 0, cancelled: 0, total: 12 },
                selection_complete: false,
                retry_safe: true,
                percent: null,
                audit_url: null,
            });
        }
        if (request.method === 'GET' && url.pathname === '/v1/manage/certificate-templates/4/element-types') {
            return jsonResponse({
                items: TYPES,
                tokens: [
                    {
                        key: 'user',
                        label: 'Learner',
                        edition: 'free',
                        available: true,
                        fields: [{ key: 'full_name', label: 'Full name' }],
                    },
                ],
            });
        }
        if (request.method === 'POST' && url.pathname === '/v1/manage/certificate-templates') {
            createAttempts += 1;
            if (world.failFirstCreate === true && createAttempts === 1) {
                return problemResponse(503, 'Temporarily unavailable');
            }
            return statusResponse({ ...SUMMARY, id: 8, name: 'Leadership certificate', state: 'draft' }, 201);
        }
        if (request.method === 'PATCH' && url.pathname === '/v1/manage/certificate-templates/4') {
            templateUpdateAttempts += 1;
            if (world.failFirstTemplateUpdate === true && templateUpdateAttempts === 1) {
                currentRevision += 1;
                return problemResponse(503, 'Response lost after commit');
            }
            if (world.staleTemplateReplay === true && templateUpdateAttempts === 2) {
                const replayRevision = currentRevision;
                currentRevision += 1;
                return jsonResponse({ ...SUMMARY, revision: replayRevision });
            }
            const body = request.body as { expected_revision?: number };
            if (world.enforceTemplateCas === true && body.expected_revision !== currentRevision) {
                return problemResponse(409, 'Certificate template changed');
            }
            currentRevision += 1;
            return jsonResponse({ ...SUMMARY, revision: currentRevision });
        }
        if (request.method === 'DELETE' && url.pathname === '/v1/manage/certificate-templates/4') {
            currentRevision += 1;
            return jsonResponse({ ...SUMMARY, revision: currentRevision, state: 'archived' });
        }
        if (request.method === 'POST' && url.pathname === '/v1/manage/certificate-templates/4/preview') {
            return jsonResponse({
                media_type: 'text/html',
                html: '<p>Awarded to Ana Costa</p>',
                findings: [],
                unresolved_tokens: ['{cohort.title}'],
            });
        }
        if (request.method === 'POST' && url.pathname === '/v1/manage/certificate-templates/4/reissue') {
            const available = world.reissueAvailable ?? true;
            return statusResponse(
                {
                    available,
                    job_id: available ? 77 : null,
                    dedupe_key: available ? 'reissue:4:3' : null,
                    revision: 3,
                    selected: available ? 12 : 0,
                    completed: 0,
                    status: available ? 'queued' : 'unavailable',
                },
                available ? 202 : 200,
            );
        }
        if (request.method === 'POST' && url.pathname === '/v1/manage/certificate-templates/4/pages') {
            pageAddAttempts += 1;
            if (!currentPages.some((page) => page.id === SECOND_PAGE.id)) {
                currentPages = [...currentPages, SECOND_PAGE];
                currentRevision += 1;
            }
            if (world.failFirstPageAdd === true && pageAddAttempts === 1) {
                return problemResponse(503, 'Response lost after commit');
            }
            return statusResponse({ template_revision: currentRevision, item: SECOND_PAGE }, 201);
        }
        if (request.method === 'PATCH' && url.pathname === '/v1/manage/certificate-template-pages/5') {
            pageUpdateAttempts += 1;
            const body = request.body as Partial<CertificateTemplatePage> & { expected_revision?: number };
            const currentPage = currentPages.find((page) => page.id === 5) ?? DETAIL.pages[0]!;
            const item: CertificateTemplatePage = {
                ...currentPage,
                width: body.width ?? currentPage.width,
                height: body.height ?? currentPage.height,
                orientation: body.orientation ?? currentPage.orientation,
                margin_top: body.margin_top ?? currentPage.margin_top,
                margin_right: body.margin_right ?? currentPage.margin_right,
                margin_bottom: body.margin_bottom ?? currentPage.margin_bottom,
                margin_left: body.margin_left ?? currentPage.margin_left,
                background: body.background === undefined ? currentPage.background : body.background,
            };
            if (pageUpdateAttempts === 1 || world.failFirstPageUpdate !== true) {
                currentPages = currentPages.map((page) => (page.id === item.id ? item : page));
                currentRevision += 1;
            }
            if (world.failFirstPageUpdate === true && pageUpdateAttempts === 1) {
                return problemResponse(503, 'Response lost after commit');
            }
            return jsonResponse({ template_revision: currentRevision, item });
        }
        if (
            request.method === 'PUT' &&
            url.pathname === '/v1/manage/certificate-template-pages/5/elements/order'
        ) {
            currentRevision += 1;
            return jsonResponse({ template_revision: currentRevision, items: [ORPHAN, ELEMENT] });
        }
        if (
            request.method === 'POST' &&
            url.pathname === '/v1/manage/certificate-template-pages/5/elements'
        ) {
            currentRevision += 1;
            return statusResponse(
                { template_revision: currentRevision, item: { ...ELEMENT, id: 13, element: 'learner_name' } },
                201,
            );
        }
        if (request.method === 'PATCH' && url.pathname === '/v1/manage/certificate-template-elements/11') {
            if (world.failElementUpdate === true) {
                return problemResponse(409, 'Designer placement refused');
            }
            const body = request.body as Partial<CertificateTemplateElement> & { expected_revision?: number };
            if (world.enforceElementCas === true && body.expected_revision !== currentRevision) {
                return problemResponse(409, 'Certificate design changed');
            }
            const currentElement =
                currentPages.flatMap((page) => page.elements).find((element) => element.id === ELEMENT.id) ??
                ELEMENT;
            const item: CertificateTemplateElement = {
                ...currentElement,
                name: body.name === undefined ? currentElement.name : body.name,
                data: body.data === undefined ? currentElement.data : body.data,
                data_version: body.data_version ?? currentElement.data_version,
                posx: body.posx ?? currentElement.posx,
                posy: body.posy ?? currentElement.posy,
                width: body.width === undefined ? currentElement.width : body.width,
                height: body.height === undefined ? currentElement.height : body.height,
                refpoint: body.refpoint ?? currentElement.refpoint,
                alignment: body.alignment ?? currentElement.alignment,
            };
            currentPages = currentPages.map((page) => ({
                ...page,
                elements: page.elements.map((element) => (element.id === item.id ? item : element)),
            }));
            currentRevision += 1;
            return jsonResponse({ template_revision: currentRevision, item });
        }
        return null;
    }, config);
    render(<CertificatesScreen enableTemplateStudio />, { wrapper: harness.wrapper });
    return {
        ...harness,
        setServerRevision(revision: number) {
            currentRevision = revision;
        },
    };
}

async function openTemplate(section = 'Design') {
    await userEvent.click(await screen.findByRole('tab', { name: 'Templates & customization' }));
    await userEvent.click(await screen.findByRole('button', { name: 'Edit the template Standard' }));
    await screen.findByRole('heading', { name: 'Standard' });
    if (section !== 'Design') await userEvent.click(screen.getByRole('tab', { name: section }));
}

describe('the certificate template screens', () => {
    it('shows current artwork without requesting a PDF until preview is opened', async () => {
        const harness = renderTemplates({ template: { thumbnail: {
            html: '<section style="background:#202c3b">Current draft artwork</section>', width: 297, height: 210,
        } } });
        await userEvent.click(await screen.findByRole('tab', { name: 'Templates & customization' }));
        expect(await screen.findByRole('button', { name: 'Edit the template Standard' })).toBeInTheDocument();
        expect(harness.requests.some((request) => request.path.endsWith('/preview'))).toBe(false);
        const artwork = screen.getByTitle('Sample artwork: Standard');
        expect(artwork).toHaveAttribute('sandbox', '');
        expect(artwork.getAttribute('srcdoc')).toContain('Current draft artwork');
        await userEvent.click(screen.getByRole('button', { name: 'Preview Standard' }));
        const dialog = await screen.findByRole('dialog', { name: 'Preview Standard' });
        expect(await within(dialog).findByTitle('Certificate preview')).toHaveAttribute('sandbox', '');
        expect(
            harness.requests
                .filter((request) => request.method !== 'GET')
                .every((request) => request.path.endsWith('/preview')),
        ).toBe(true);
    });

    it('opens HTML templates directly in their content editor', async () => {
        renderTemplates({ template: { format: 'html', body: '<h1>{user.full_name}</h1>' } });
        await userEvent.click(await screen.findByRole('tab', { name: 'Templates & customization' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Edit the template Standard' }));
        expect(await screen.findByRole('tab', { name: 'Content' })).toHaveAttribute('aria-selected', 'true');
        expect(screen.getByRole('textbox', { name: 'Body' })).toHaveValue('<h1>{user.full_name}</h1>');
        expect(screen.queryByRole('button', { name: 'Add a page' })).not.toBeInTheDocument();
    });

    it('retains an unsaved settings draft across studio sections and prevents publication', async () => {
        renderTemplates();
        await openTemplate('Settings & validity');
        const name = screen.getByLabelText('Name');
        await userEvent.clear(name);
        await userEvent.type(name, 'A draft worth keeping');
        await userEvent.click(screen.getByRole('tab', { name: 'Preview' }));
        expect(screen.getByRole('button', { name: 'Publish design' })).toBeDisabled();
        await userEvent.click(screen.getByRole('tab', { name: 'Settings & validity' }));
        expect(name).toHaveValue('A draft worth keeping');
    });

    it('keeps an unsaved root draft when leaving is cancelled and guards browser history', async () => {
        renderTemplates();
        await openTemplate('Settings & validity');
        const name = screen.getByLabelText('Name');
        await userEvent.clear(name);
        await userEvent.type(name, 'My unsaved certificate');
        await userEvent.click(screen.getByRole('button', { name: 'Back to templates' }));
        expect(
            await screen.findByRole('dialog', { name: 'Keep your unsaved certificate edits?' }),
        ).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(name).toHaveValue('My unsaved certificate');
        await act(async () => {
            window.location.hash = '/credits';
            window.dispatchEvent(new HashChangeEvent('hashchange'));
        });
        expect(
            await screen.findByRole('dialog', { name: 'Keep your unsaved certificate edits?' }),
        ).toBeInTheDocument();
        expect(window.location.hash).toBe('#/templates/4/edit');
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(name).toHaveValue('My unsaved certificate');
    });

    it('requires a decision before replacing dirty element fields with another layer', async () => {
        renderTemplates();
        await openTemplate();
        const label = screen.getByLabelText('Label');
        await userEvent.clear(label);
        await userEvent.type(label, 'Uncommitted label');
        await userEvent.click(screen.getByRole('button', { name: 'Issuer seal' }));
        expect(
            await screen.findByRole('dialog', { name: 'Keep your unsaved certificate edits?' }),
        ).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(label).toHaveValue('Uncommitted label');
    });

    it('reloads a saved design only after explicit discard confirmation', async () => {
        renderTemplates();
        await openTemplate('Settings & validity');
        const name = screen.getByLabelText('Name');
        await userEvent.clear(name);
        await userEvent.type(name, 'Local edit');
        await userEvent.click(screen.getByRole('button', { name: 'Reload saved design' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Discard changes and continue' }));
        await waitFor(() => expect(screen.getByLabelText('Name')).toHaveValue('Standard'));
        expect(screen.queryByText('Unsaved changes')).not.toBeInTheDocument();
    });
    it('lists templates with the default and the lock stated', async () => {
        renderTemplates();
        await userEvent.click(await screen.findByRole('tab', { name: 'Templates & customization' }));
        await userEvent.click(await screen.findByRole('button', { name: 'Template table' }));
        const table = await screen.findByRole('table', { name: 'Certificate templates' });
        expect(within(table).getByText('Standard')).toBeInTheDocument();
        expect(within(table).getByText('Default')).toBeInTheDocument();
        expect(within(table).getByText('Locked')).toBeInTheDocument();
    });

    it('says what the criteria lock means rather than hiding the controls', async () => {
        renderTemplates();
        await openTemplate();
        expect(screen.getByText(/its criteria and expiry are frozen/)).toBeInTheDocument();
    });

    it('disables every field the lock froze, and names the reason on the control itself', async () => {
        renderTemplates({ template: { expiry_mode: 'days_after_issue', expiry_days: 365 } });
        await openTemplate('Settings & validity');

        const mode = screen.getByLabelText('Expiry');
        const days = screen.getByLabelText('Days after issue');

        expect(mode).toBeDisabled();
        expect(days).toBeDisabled();
        expect(mode).toHaveAccessibleDescription(/Frozen by the lock/);
        expect(days).toHaveAccessibleDescription(/Frozen by the lock/);
        /* Only the frozen set: everything else stays editable, which is what
           makes a correction to a name or a body possible after issuance. */
        expect(screen.getByLabelText('Name')).toBeEnabled();
    });

    it('takes the frozen set from the server rather than deciding for itself what a lock covers', async () => {
        renderTemplates({
            template: {
                expiry_mode: 'fixed',
                expiry_at: '2027-01-01 23:59:59',
                locked_fields: ['expiry_at'],
            },
        });
        await openTemplate('Settings & validity');

        expect(screen.getByLabelText('Expires on')).toBeDisabled();
        expect(screen.getByLabelText('Expiry')).toBeEnabled();
    });

    it('leaves the expiry editable on a template nothing has been issued from', async () => {
        renderTemplates({
            template: {
                locked_at: null,
                locked_fields: [],
                expiry_mode: 'days_after_issue',
                expiry_days: 365,
            },
        });
        await openTemplate('Settings & validity');

        expect(screen.getByLabelText('Expiry')).toBeEnabled();
        expect(screen.getByLabelText('Days after issue')).toBeEnabled();
        expect(screen.queryByText(/Frozen by the lock/)).toBeNull();
    });

    it('creates the aggregate with a replay key and no invented revision precondition', async () => {
        const harness = renderTemplates();
        await userEvent.click(await screen.findByRole('tab', { name: 'Templates & customization' }));
        await userEvent.click(screen.getByRole('button', { name: 'New template' }));
        const dialog = screen.getByRole('dialog');
        await userEvent.type(within(dialog).getByLabelText('Name'), 'Leadership certificate');
        await userEvent.click(within(dialog).getByRole('button', { name: 'New template' }));

        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'POST' && request.path === '/v1/manage/certificate-templates',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) => request.method === 'POST' && request.path === '/v1/manage/certificate-templates',
        );
        expect(write?.headers['idempotency-key']).toBeTruthy();
        expect(write?.body).not.toHaveProperty('expected_revision');
        expect(write?.body).not.toHaveProperty('revision');
    });

    it('replays a failed root create with the same key', async () => {
        const harness = renderTemplates({ failFirstCreate: true });
        await userEvent.click(await screen.findByRole('tab', { name: 'Templates & customization' }));
        await userEvent.click(screen.getByRole('button', { name: 'New template' }));
        const dialog = screen.getByRole('dialog');
        await userEvent.type(within(dialog).getByLabelText('Name'), 'Leadership certificate');
        const submit = within(dialog).getByRole('button', { name: 'New template' });

        await userEvent.click(submit);
        await waitFor(() => expect(submit).toBeEnabled());
        await userEvent.click(submit);
        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'POST' && request.path === '/v1/manage/certificate-templates',
                ),
            ).toHaveLength(2),
        );

        const writes = harness.requests.filter(
            (request) => request.method === 'POST' && request.path === '/v1/manage/certificate-templates',
        );
        expect(writes[0]?.headers['idempotency-key']).toBeTruthy();
        expect(writes[1]?.headers['idempotency-key']).toBe(writes[0]?.headers['idempotency-key']);
    });

    it('saves template settings with the revision read and a replay key', async () => {
        const harness = renderTemplates();
        await openTemplate('Settings & validity');
        await userEvent.click(screen.getByRole('button', { name: 'Save template' }));

        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) => request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
        );
        expect(write?.body).toMatchObject({ expected_revision: 3 });
        expect(write?.body).not.toHaveProperty('revision');
        expect(write?.headers['idempotency-key']).toBeTruthy();
    });

    it('replays an ambiguous template save with its captured body, revision, and key after a refetch', async () => {
        const harness = renderTemplates({ failFirstTemplateUpdate: true });
        await openTemplate('Settings & validity');
        const save = screen.getByRole('button', { name: 'Save template' });

        await userEvent.click(save);
        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
                ),
            ).toHaveLength(1),
        );
        await waitFor(() => expect(save).toBeEnabled());

        act(() => {
            harness.queryClient.setQueryData(credentialKeys.template(4), { ...DETAIL, revision: 4 });
        });
        await userEvent.click(save);
        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
                ),
            ).toHaveLength(2),
        );

        const writes = harness.requests.filter(
            (request) => request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
        );
        expect(writes[0]?.body).toMatchObject({ expected_revision: 3 });
        expect(writes[1]?.body).toEqual(writes[0]?.body);
        expect(writes[1]?.headers['idempotency-key']).toBe(writes[0]?.headers['idempotency-key']);
    });

    it('does not let a late replay response roll back the cache or silently rebase its draft', async () => {
        const harness = renderTemplates({
            failFirstTemplateUpdate: true,
            staleTemplateReplay: true,
            enforceTemplateCas: true,
        });
        await openTemplate('Settings & validity');
        const save = screen.getByRole('button', { name: 'Save template' });

        await userEvent.click(save);
        await waitFor(() => expect(save).toBeEnabled());
        act(() => {
            harness.queryClient.setQueryData(credentialKeys.template(4), { ...DETAIL, revision: 5 });
        });
        const observedRevisions: number[] = [];
        const unsubscribe = harness.queryClient.getQueryCache().subscribe(() => {
            const detail = harness.queryClient.getQueryData<CertificateTemplateDetail>(
                credentialKeys.template(4),
            );
            if (detail !== undefined) {
                observedRevisions.push(detail.revision);
            }
        });

        await userEvent.click(save);
        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
                ),
            ).toHaveLength(2),
        );
        await waitFor(() =>
            expect(
                harness.queryClient.getQueryData<CertificateTemplateDetail>(credentialKeys.template(4))
                    ?.revision,
            ).toBe(5),
        );
        unsubscribe();

        expect(observedRevisions).not.toContain(4);
        const name = screen.getByLabelText('Name');
        await userEvent.clear(name);
        await userEvent.type(name, 'Still based on revision three');
        await userEvent.click(save);
        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
                ),
            ).toHaveLength(3),
        );
        const writes = harness.requests.filter(
            (request) => request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
        );
        expect(writes[2]?.body).toMatchObject({
            name: 'Still based on revision three',
            expected_revision: 3,
        });
        expect(
            await screen.findByText('This conflicts with the current state. Reload and try again.'),
        ).toBeInTheDocument();
    });

    it('rotates the save key for a changed retry payload without adopting a passive revision', async () => {
        const harness = renderTemplates({ failFirstTemplateUpdate: true, enforceTemplateCas: true });
        await openTemplate('Settings & validity');
        const save = screen.getByRole('button', { name: 'Save template' });

        await userEvent.click(save);
        await waitFor(() => expect(save).toBeEnabled());
        act(() => {
            harness.queryClient.setQueryData(credentialKeys.template(4), { ...DETAIL, revision: 4 });
        });
        const name = screen.getByLabelText('Name');
        await userEvent.clear(name);
        await userEvent.type(name, 'Leadership certificate');
        await userEvent.click(save);

        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
                ),
            ).toHaveLength(2),
        );
        const writes = harness.requests.filter(
            (request) => request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
        );
        expect(writes[1]?.headers['idempotency-key']).not.toBe(writes[0]?.headers['idempotency-key']);
        expect(writes[1]?.body).toMatchObject({ name: 'Leadership certificate', expected_revision: 3 });
        expect(
            await screen.findByText('This conflicts with the current state. Reload and try again.'),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Name')).toHaveValue('Leadership certificate');
    });

    it('pins a root draft to the revision observed at its first edit', async () => {
        const harness = renderTemplates({ enforceTemplateCas: true });
        await openTemplate('Settings & validity');
        const name = screen.getByLabelText('Name');
        await userEvent.clear(name);
        await userEvent.type(name, 'Unsaved local name');

        act(() => {
            harness.setServerRevision(4);
            harness.queryClient.setQueryData(credentialKeys.template(4), { ...DETAIL, revision: 4 });
        });
        await userEvent.click(screen.getByRole('button', { name: 'Save template' }));

        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) => request.method === 'PATCH' && request.path === '/v1/manage/certificate-templates/4',
        );
        expect(write?.body).toMatchObject({ name: 'Unsaved local name', expected_revision: 3 });
        expect(
            await screen.findByText('This conflicts with the current state. Reload and try again.'),
        ).toBeInTheDocument();
        expect(name).toHaveValue('Unsaved local name');
    });

    it('replays an ambiguously committed page add without changing its key or duplicating the cache row', async () => {
        const harness = renderTemplates({ failFirstPageAdd: true });
        await openTemplate();
        const add = screen.getByRole('button', { name: 'Add a page' });

        await userEvent.click(add);
        await waitFor(() => expect(add).toBeEnabled());
        act(() => {
            harness.queryClient.setQueryData(credentialKeys.template(4), {
                ...DETAIL,
                revision: 4,
                pages: [...DETAIL.pages, SECOND_PAGE],
            });
        });
        await userEvent.click(add);

        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'POST' &&
                        request.path === '/v1/manage/certificate-templates/4/pages',
                ),
            ).toHaveLength(2),
        );
        const writes = harness.requests.filter(
            (request) =>
                request.method === 'POST' && request.path === '/v1/manage/certificate-templates/4/pages',
        );
        expect(writes[0]?.body).toEqual({ expected_revision: 3 });
        expect(writes[1]?.body).toEqual(writes[0]?.body);
        expect(writes[1]?.headers['idempotency-key']).toBe(writes[0]?.headers['idempotency-key']);

        await waitFor(() => {
            const detail = harness.queryClient.getQueryData<CertificateTemplateDetail>(
                credentialKeys.template(4),
            );
            expect(detail?.pages.filter((page) => page.id === SECOND_PAGE.id)).toHaveLength(1);
        });
    });

    it('merges a passive page refresh without replacing dirty page fields or their source revision', async () => {
        const harness = renderTemplates();
        await openTemplate();
        const width = screen.getAllByLabelText('Width (mm)')[0]!;
        const height = screen.getAllByLabelText('Height (mm)')[0]!;
        await userEvent.clear(width);
        await userEvent.type(width, '301');

        act(() => {
            harness.queryClient.setQueryData(credentialKeys.template(4), {
                ...DETAIL,
                revision: 4,
                pages: [{ ...DETAIL.pages[0]!, width: 333, height: 211 }],
            });
        });

        await waitFor(() => {
            expect(width).toHaveValue(301);
            expect(height).toHaveValue(211);
        });
        await userEvent.click(screen.getByRole('button', { name: 'Save page' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'PATCH' &&
                        request.path === '/v1/manage/certificate-template-pages/5',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) =>
                request.method === 'PATCH' && request.path === '/v1/manage/certificate-template-pages/5',
        );
        expect(write?.body).toMatchObject({ width: 301, height: 211, expected_revision: 3 });
    });

    it('retries an ambiguous page save with exact captured bytes and key after a passive merge', async () => {
        const harness = renderTemplates({ failFirstPageUpdate: true });
        await openTemplate();
        const width = screen.getAllByLabelText('Width (mm)')[0]!;
        await userEvent.clear(width);
        await userEvent.type(width, '301');
        const save = screen.getByRole('button', { name: 'Save page' });
        await userEvent.click(save);
        await waitFor(() => expect(save).toBeEnabled());

        act(() => {
            harness.queryClient.setQueryData(credentialKeys.template(4), {
                ...DETAIL,
                revision: 4,
                pages: [{ ...DETAIL.pages[0]!, width: 301, height: 219 }],
            });
        });
        await waitFor(() => expect(screen.getAllByLabelText('Height (mm)')[0]).toHaveValue(219));
        await userEvent.click(save);
        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'PATCH' &&
                        request.path === '/v1/manage/certificate-template-pages/5',
                ),
            ).toHaveLength(2),
        );

        const writes = harness.requests.filter(
            (request) =>
                request.method === 'PATCH' && request.path === '/v1/manage/certificate-template-pages/5',
        );
        expect(writes[1]?.body).toEqual(writes[0]?.body);
        expect(writes[1]?.headers['idempotency-key']).toBe(writes[0]?.headers['idempotency-key']);
        expect(writes[1]?.body).toMatchObject({ width: 301, height: 210, expected_revision: 3 });
    });

    it('archives with a query precondition and a replay key', async () => {
        const harness = renderTemplates();
        await userEvent.click(await screen.findByRole('tab', { name: 'Templates & customization' }));
        await userEvent.click(screen.getByRole('button', { name: 'Archive template Standard' }));
        await userEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', { name: 'Archive template' }),
        );

        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) =>
                        request.method === 'DELETE' &&
                        request.path === '/v1/manage/certificate-templates/4?expected_revision=3',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find((request) => request.method === 'DELETE');
        expect(write?.headers['idempotency-key']).toBeTruthy();
        expect(write?.body).toBeNull();
    });

    it('draws the palette from the registry, refusing what the template cannot use', async () => {
        renderTemplates();
        await openTemplate();
        expect(screen.getByRole('button', { name: 'Add Learner name' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Add Credit statement' })).toBeDisabled();
    });

    it('keeps an element whose provider is missing, and says what happened', async () => {
        renderTemplates();
        await openTemplate();
        await userEvent.click(screen.getByRole('button', { name: 'Issuer seal' }));
        expect(screen.getByText(/No package on this build registers this element/)).toBeInTheDocument();
        await userEvent.click(screen.getByText('Position, size & alignment'));
        expect(screen.getByRole('textbox', { name: 'X (mm)' })).toBeInTheDocument();
    });

    it('writes draw order as the whole list rather than as a swap', async () => {
        const harness = renderTemplates();
        await openTemplate();
        await userEvent.click(screen.getByRole('button', { name: 'Draw Issuer seal earlier' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => request.path === '/v1/manage/certificate-template-pages/5/elements/order',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) => request.path === '/v1/manage/certificate-template-pages/5/elements/order',
        );
        expect(write?.body).toEqual({ ids: [12, 11], expected_revision: 3 });
        expect(write?.headers['idempotency-key']).toBeTruthy();
    });

    it('uses the revision returned by one child write for the next write', async () => {
        const harness = renderTemplates();
        await openTemplate();
        await userEvent.click(screen.getByRole('button', { name: 'Draw Issuer seal earlier' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => request.path === '/v1/manage/certificate-template-pages/5/elements/order',
                ),
            ).toBe(true),
        );

        await userEvent.click(screen.getByRole('button', { name: 'Save element' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => request.path === '/v1/manage/certificate-template-elements/11',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) => request.path === '/v1/manage/certificate-template-elements/11',
        );
        expect(write?.body).toMatchObject({ expected_revision: 4 });
    });

    it('stores an edited payload at the version the provider declares now', async () => {
        const harness = renderTemplates();
        await openTemplate();
        await userEvent.clear(screen.getByLabelText('Font size'));
        await userEvent.type(screen.getByLabelText('Font size'), '24');
        await userEvent.click(screen.getByRole('button', { name: 'Save element' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => request.path === '/v1/manage/certificate-template-elements/11',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) => request.path === '/v1/manage/certificate-template-elements/11',
        );
        expect(write?.body).toMatchObject({
            expected_revision: 3,
            data_version: 2,
            data: { font_size: 24 },
        });
        expect(write?.headers['idempotency-key']).toBeTruthy();
    });

    it('saves placement without resubmitting unfinished content or upgrading its version', async () => {
        const harness = renderTemplates({ template: {
            pages: [{ ...DETAIL.pages[0]!, elements: [{ ...ELEMENT, data: {} }] }],
        } });
        await openTemplate();
        await userEvent.click(screen.getByText('Position, size & alignment', { exact: true }));
        await userEvent.clear(screen.getByLabelText('X (mm)'));
        await userEvent.type(screen.getByLabelText('X (mm)'), '35');
        await userEvent.selectOptions(screen.getByLabelText('Anchored at'), 'bottom_left');
        await userEvent.selectOptions(screen.getByLabelText('Alignment'), 'justify');
        await userEvent.click(screen.getByRole('button', { name: 'Save element' }));
        await waitFor(() => expect(harness.requests.some(
            (request) => request.method === 'PATCH' && request.path.endsWith('/certificate-template-elements/11'),
        )).toBe(true));
        const write = harness.requests.find(
            (request) => request.method === 'PATCH' && request.path.endsWith('/certificate-template-elements/11'),
        );
        expect(write?.body).toMatchObject({ expected_revision: 3, posx: 35, refpoint: 'bottom_left', alignment: 'justify', width: null, height: null });
        expect(write?.body).not.toHaveProperty('data');
        expect(write?.body).not.toHaveProperty('data_version');
    });

    it('explains invalid placement before sending it to the server', async () => {
        renderTemplates();
        await openTemplate();
        await userEvent.click(screen.getByText('Position, size & alignment', { exact: true }));
        const x = screen.getByLabelText('X (mm)');
        await userEvent.clear(x);
        await userEvent.type(x, '12.5');
        expect(x).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByText('Enter a whole number of 0 or more.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save element' })).toBeDisabled();
        await userEvent.clear(x);
        await userEvent.type(x, '13');
        const width = screen.getByRole('textbox', { name: 'Width (mm)' });
        await userEvent.type(width, '0');
        expect(width).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('button', { name: 'Save element' })).toBeDisabled();
        await userEvent.clear(width);
        expect(screen.getByRole('button', { name: 'Save element' })).toBeEnabled();
    });

    it('shows the template-level publication finding a per-element check cannot see', async () => {
        renderTemplates();
        await openTemplate();
        expect(
            screen.getByText('A QR code may not be the only way to reach the verification page.'),
        ).toBeInTheDocument();
    });

    it('previews without writing, and names the placeholders nothing resolved', async () => {
        const harness = renderTemplates();
        await openTemplate('Preview');
        await userEvent.click(screen.getByRole('button', { name: 'Preview' }));
        await waitFor(() => expect(screen.getByTitle('Certificate preview')).toBeInTheDocument());
        expect(screen.getByText(/\{cohort\.title\}/)).toBeInTheDocument();
        const preview = harness.requests.find(
            (request) => request.path === '/v1/manage/certificate-templates/4/preview',
        );
        expect(preview?.method).toBe('POST');
    });

    it('queues a bulk reissue and reports the fan-out key and the selection', async () => {
        const harness = renderTemplates();
        await openTemplate('Existing holders');
        await userEvent.click(screen.getByRole('button', { name: 'Reissue every certificate' }));
        await userEvent.click(screen.getByRole('button', { name: 'Queue reissue' }));
        expect(await screen.findByText('12 certificates selected.')).toBeInTheDocument();
        expect(screen.getByText('Fan-out key reissue:4:3')).toBeInTheDocument();
        expect(
            harness.requests.find((request) => request.method === 'POST' && request.path.endsWith('/reissue'))
                ?.body,
        ).toEqual({ revision: 3 });
    });

    it('says nothing was queued when no package registers bulk reissue', async () => {
        renderTemplates({ reissueAvailable: false });
        await openTemplate('Existing holders');
        await userEvent.click(screen.getByRole('button', { name: 'Reissue every certificate' }));
        await userEvent.click(screen.getByRole('button', { name: 'Queue reissue' }));
        expect(
            await screen.findByText(/No package on this build registers bulk reissue/),
        ).toBeInTheDocument();
    });

    it('recovers a retained reissue after refresh and cancels its original publication revision', async () => {
        const harness = renderTemplates({ reissueStatus: 'queued' });
        await openTemplate('Existing holders');
        const status = await screen.findByLabelText('Latest retained reissue run');
        expect(within(status).getByText('Published revision 2')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reissue every certificate' })).toBeDisabled();
        await userEvent.click(screen.getByRole('button', { name: 'Cancel the run' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => request.method === 'DELETE' && request.path.endsWith('/reissue?revision=2'),
                ),
            ).toBe(true),
        );
        expect(
            harness.requests.find((request) => request.method === 'DELETE')?.headers['idempotency-key'],
        ).toBeTruthy();
    });

    it('falls back to the form editor when no designer is registered, and says so', async () => {
        renderTemplates();
        await openTemplate();
        expect(screen.getByText(/The drag-and-drop designer is registered by a package/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Designer' })).toBeNull();
    });
});

/*
 * Registered last on purpose. There is no unregister() in any registry in
 * this codebase, and the published global lives for the life of the page, so
 * the suite that proves the fallback has to run before the one that installs
 * a designer. Vitest gives each test FILE its own window, so nothing here
 * reaches another file.
 */
describe('a registered page designer', () => {
    beforeAll(() => {
        certificateDesignerRegistry().register('page_designer', {
            version: CERTIFICATE_DESIGNER_CONTRACT,
            label: 'Canvas',
            Designer: (props: CertificatePageDesignerProps) => (
                <button type="button" onClick={() => props.onPlace(11, { posx: 30, posy: 50 })}>
                    Move learner name
                </button>
            ),
        });
    });

    it('renders in its own tab, over the same elements, without owning a write', async () => {
        const harness = renderTemplates();
        await openTemplate();
        await userEvent.click(screen.getByRole('button', { name: 'Canvas' }));
        await userEvent.click(screen.getByRole('button', { name: 'Move learner name' }));
        await waitFor(() =>
            expect(
                harness.requests.some(
                    (request) => request.path === '/v1/manage/certificate-template-elements/11',
                ),
            ).toBe(true),
        );
        const write = harness.requests.find(
            (request) => request.path === '/v1/manage/certificate-template-elements/11',
        );
        expect(write?.body).toEqual({ expected_revision: 3, posx: 30, posy: 50 });
        expect(write?.headers['idempotency-key']).toBeTruthy();
    });

    it('merges a designer move into clean placement fields while preserving dirty element content', async () => {
        const harness = renderTemplates({ enforceElementCas: true });
        await openTemplate();
        const label = screen.getByLabelText('Label');
        await userEvent.type(label, 'Unsaved label');
        await userEvent.click(screen.getByRole('button', { name: 'Canvas' }));
        await userEvent.click(screen.getByRole('button', { name: 'Move learner name' }));

        await waitFor(() => expect(screen.getByRole('textbox', { name: 'X (mm)' })).toHaveValue('30'));
        expect(screen.getByRole('textbox', { name: 'Y (mm)' })).toHaveValue('50');
        expect(label).toHaveValue('Unsaved label');
        await userEvent.click(screen.getByRole('button', { name: 'Save element' }));
        await waitFor(() =>
            expect(
                harness.requests.filter(
                    (request) =>
                        request.method === 'PATCH' &&
                        request.path === '/v1/manage/certificate-template-elements/11',
                ),
            ).toHaveLength(2),
        );

        const writes = harness.requests.filter(
            (request) =>
                request.method === 'PATCH' && request.path === '/v1/manage/certificate-template-elements/11',
        );
        expect(writes[1]?.body).toMatchObject({
            name: 'Unsaved label',
            posx: 30,
            posy: 50,
            expected_revision: 3,
        });
        expect(
            await screen.findByText('This conflicts with the current state. Reload and try again.'),
        ).toBeInTheDocument();
        expect(label).toHaveValue('Unsaved label');
    });

    it('surfaces a designer placement refusal instead of swallowing it', async () => {
        renderTemplates({ failElementUpdate: true });
        await openTemplate();
        await userEvent.click(screen.getByRole('button', { name: 'Canvas' }));
        await userEvent.click(screen.getByRole('button', { name: 'Move learner name' }));
        expect(
            await screen.findByText('This conflicts with the current state. Reload and try again.'),
        ).toBeInTheDocument();
    });
});
