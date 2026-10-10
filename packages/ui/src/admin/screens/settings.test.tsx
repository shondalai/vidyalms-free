import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SettingsScreen } from './settings';
import { adminKeys } from '../api';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import {
    jsonResponse,
    makeConfig,
    makeHarness,
    makeSettings,
    problemResponse,
    type RecordedRequest,
} from '../testing/harness';
import type { ConfigurationValues } from '../settings/fields';

const values: ConfigurationValues = {
    academy_name: 'Vidya Academy',
    learner_navigation: 'top',
    learning_idle_timeout_seconds: 300,
    learner_header_display: 'full', learner_header_title: '', learner_header_tagline: '',
    learner_header_show_tagline: true, learner_header_show_logo: true, learner_header_logo_url: '',
    learner_header_logo_size: 42, learner_header_density: 'comfortable', learner_header_divider: true,
    site_base_url: 'https://academy.example',
    site_logo_url: '',
    verification_base_url: '',
    credential_base_url: '',
    status_list_base_url: '',
    unsubscribe_base_url: '',
    envelope_domain: '',
    catalogue_per_page: 12,
    self_enrolment: false,
    import_max_upload_mb: 64,
    upload_max_resource_mb: 64,
    upload_max_image_mb: 8,
    private_storage_path: '',
    private_storage_dir: 'vidyalms-private',
    import_source_retention_days: 30,
    integrations_http_allowlist: '',
    integrations_http_timeout: 15,
};
function setup(handler?: (request: RecordedRequest) => Response | null, config = makeConfig({ screen: 'settings' })) {
    let stored = { revision: 4, values: { ...values } };
    let publishNames = false;
    const harness = makeHarness(
        (request) => {
            const custom = handler?.(request);
            if (custom) return custom;
            const url = new URL(request.path, 'http://localhost');
            if (url.pathname === '/v1/admin/settings') {
                if (request.method === 'PUT') {
                    const body = request.body as {
                        configuration?: ConfigurationValues;
                        expected_revision?: number;
                        publish_learner_name?: boolean;
                    };
                    if (body.configuration)
                        stored = { revision: stored.revision + 1, values: { ...stored.values, ...body.configuration } };
                    if (body.publish_learner_name !== undefined) publishNames = body.publish_learner_name;
                }
                return jsonResponse(makeSettings({ configuration: stored, publish_learner_name: publishNames }));
            }
            return null;
        },
        config,
    );
    const view = render(<SettingsScreen />, { wrapper: harness.wrapper });
    return { ...harness, view };
}

describe('Settings workspace', () => {
    beforeEach(() => window.history.replaceState({}, '', window.location.pathname));

    it('keeps public names off by default and saves an explicit site choice in both directions', async () => {
        window.history.replaceState({}, '', '#/credentials');
        const harness = setup();
        const control = await screen.findByRole('switch', { name: 'Allow learner names on public verification pages' });
        expect(control).not.toBeChecked();
        expect(control).toHaveAccessibleDescription(/Off by default.*Learners can hide their own names in Achievements/);
        expect(screen.getByRole('button', { name: 'Save name publication' })).toBeDisabled();
        expect(harness.requests.filter((request) => request.method === 'PUT')).toHaveLength(0);
        await userEvent.click(control);
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        await userEvent.click(screen.getByRole('button', { name: 'Save name publication' }));
        await screen.findByText('Name publication setting saved.');
        expect(control).toBeChecked();
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
        await userEvent.click(control);
        await userEvent.click(screen.getByRole('button', { name: 'Save name publication' }));
        await waitFor(() => expect(hasUnsavedWorkspaceChanges()).toBe(false));
        expect(harness.requests.filter((request) => request.method === 'PUT').map((request) => request.body))
            .toEqual([{ publish_learner_name: true }, { publish_learner_name: false }]);
        expect(control).not.toBeChecked();
    });

    it('saves name publication without saving or discarding an issuer draft', async () => {
        window.history.replaceState({}, '', '#/credentials');
        const harness = setup();
        const issuer = await screen.findByRole('textbox', { name: 'Issuer name' });
        await userEvent.type(issuer, 'Draft issuer');
        await userEvent.click(screen.getByRole('switch', { name: 'Allow learner names on public verification pages' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save name publication' }));
        await screen.findByText('Name publication setting saved.');
        expect(issuer).toHaveValue('Draft issuer');
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({ publish_learner_name: true });
    });

    it('retains a refused name-publication draft until it is explicitly discarded', async () => {
        window.history.replaceState({}, '', '#/credentials');
        setup((request) => request.method === 'PUT' ? problemResponse(403, 'Name publication refused') : null);
        const control = await screen.findByRole('switch', { name: 'Allow learner names on public verification pages' });
        await userEvent.click(control);
        await userEvent.click(screen.getByRole('button', { name: 'Save name publication' }));
        await screen.findByText("Couldn't save changes.");
        expect(control).toBeChecked();
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        expect(screen.queryByText('Name publication setting saved.')).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
        await userEvent.click(screen.getByRole('button', { name: 'Discard and reload' }));
        await waitFor(() => expect(control).not.toBeChecked());
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });

    it('opens the shop section directly from a course setup link', async () => {
        window.history.replaceState({}, '', '#/commerce');
        setup();
        expect(await screen.findByRole('heading', { name: 'Shop setup' })).toBeInTheDocument();
        expect(screen.queryByRole('textbox', { name: 'Academy name' })).not.toBeInTheDocument();
    });
    it('retains shared outbound controls for general webhook and directory integrations', async () => {
        const harness = setup();
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Network & security' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Outbound host allowlist' }), 'hooks.example.test');
        expect(screen.getByText(/general outbound integrations, including webhooks/)).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await screen.findByText('Your settings have been saved.');
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({
            expected_revision: 4, configuration: { integrations_http_allowlist: 'hooks.example.test' },
        });
    });
    it('directs shop setup to the host and distinguishes registration from connection health', async () => {
        const harness = setup();
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Shop integrations' }));
        expect(screen.getByRole('heading', { name: 'Shop setup' })).toBeInTheDocument();
        expect(screen.getByText(/Open a course, choose Access → Visibility & pricing/)).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Open shop setup' })).toHaveAttribute('href', '/admin/settings');
        expect(screen.getByText('EasyCommerce')).toBeInTheDocument();
        expect(screen.getByText(/registration alone does not confirm/)).toBeInTheDocument();
        expect(screen.getByRole('searchbox', { name: 'Background account' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Network & security' })).toBeInTheDocument();
        expect(harness.requests.every((request) => request.path === '/v1/admin/settings')).toBe(true);
    });
    it('reports a missing shop source without inventing a setup URL or connection state', async () => {
        setup(() => jsonResponse(makeSettings({
            commerce: { source: '', label: '', sources: [] }, host_settings_url: null,
        })));
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Shop integrations' }));
        expect(screen.getByText(/No shop source is registered/)).toBeInTheDocument();
        expect(screen.queryByRole('link', { name: 'Open shop setup' })).toBeNull();
        expect(screen.queryByText('Default source')).toBeNull();
    });
    it('shows separate product, mapping and payment destinations and an inactive integration', async () => {
        setup(undefined, makeConfig({ screen: 'settings', shop: {
            name: 'EasyCommerce', installed: true, integrationEnabled: false,
            productsUrl: '/shop/products', settingsUrl: '/shop/settings', mappingUrl: '/plugins/lms-shop',
        } }));
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Shop integrations' }));
        expect(screen.getByRole('status')).toHaveTextContent('Install or enable the VidyaLMS integration');
        expect(screen.getByRole('link', { name: 'Products & prices' })).toHaveAttribute('href', '/shop/products');
        expect(screen.getByRole('link', { name: 'Connect products to courses' })).toHaveAttribute('href', '/plugins/lms-shop');
        expect(screen.getByRole('link', { name: 'Shop payment settings' })).toHaveAttribute('href', '/shop/settings');
        expect(screen.queryByRole('link', { name: 'Open shop setup' })).toBeNull();
    });
    it('explains that the shop is missing without offering inaccessible configuration links', async () => {
        setup(undefined, makeConfig({ screen: 'settings', shop: {
            name: 'WooCommerce', installed: false, integrationEnabled: false,
            productsUrl: null, settingsUrl: null, mappingUrl: null,
        } }));
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Shop integrations' }));
        expect(screen.getByRole('status')).toHaveTextContent('Install and activate the shop');
        expect(screen.queryByRole('link', { name: 'Products & prices' })).toBeNull();
        expect(screen.queryByRole('link', { name: 'Connect products to courses' })).toBeNull();
    });
    it('saves the background enrolment actor through the existing settings contract', async () => {
        const actor = { id: 42, name: 'Enrolment service', email: 'enrolment@example.test' };
        const harness = setup((request) => {
            if (request.path.startsWith('/v1/admin/users')) return jsonResponse({ items: [actor] });
            if (request.method === 'PUT') return jsonResponse(makeSettings({
                background_actor: { user_id: actor.id, name: actor.name, may_grant: true },
            }));
            return null;
        });
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Shop integrations' }));
        await userEvent.type(screen.getByRole('searchbox', { name: 'Background account' }), 'Enrolment');
        await userEvent.click(await screen.findByRole('button', { name: /Enrolment service enrolment@example.test/ }));
        await userEvent.click(screen.getByRole('button', { name: 'Save background account' }));
        expect(await screen.findByText('Background account saved.')).toBeInTheDocument();
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({ background_actor_user_id: 42 });
        expect(screen.getByText(/Enrolment service · Can grant access/)).toBeInTheDocument();
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });
    it('previews header drafts across panels, saves typed changes and restores saved values', async () => {
        const harness = setup();
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Learner experience' }));
        await userEvent.click(screen.getByRole('button', { name: 'Branding' }));
        const title = screen.getByRole('textbox', { name: 'Header title' });
        await userEvent.type(title, 'Learn together');
        expect(screen.getByLabelText('Learner header preview')).toHaveTextContent('Learn together');
        await userEvent.click(screen.getByRole('switch', { name: 'Show tagline' }));
        expect(screen.getByLabelText('Learner header preview')).not.toHaveTextContent('Your learning, your pace.');
        await userEvent.click(screen.getByRole('button', { name: 'Mobile preview' }));
        expect(screen.getByRole('button', { name: 'Mobile preview' })).toHaveAttribute('aria-pressed', 'true');
        await userEvent.click(screen.getByRole('button', { name: 'Spacing' }));
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Header spacing' }), 'compact');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(await screen.findByText('Your settings have been saved.')).toBeInTheDocument();
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({
            expected_revision: 4,
            configuration: { learner_header_title: 'Learn together', learner_header_show_tagline: false, learner_header_density: 'compact' },
        });
        await userEvent.click(screen.getByRole('button', { name: 'Branding' }));
        await userEvent.clear(screen.getByRole('textbox', { name: 'Header title' }));
        await userEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
        await userEvent.click(screen.getByRole('button', { name: 'Discard and reload' }));
        await waitFor(() => expect(screen.getByRole('textbox', { name: 'Header title' })).toHaveValue('Learn together'));
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });
    it('keeps header-only labels out of the navigation-only preview and exposes branding in search', async () => {
        setup();
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Learner experience' }));
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Header display' }), 'navigation');
        expect(screen.getByLabelText('Learner header preview')).not.toHaveTextContent('Vidya Academy');
        expect(screen.getByLabelText('Learner header preview')).toHaveTextContent('Achievements');
        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Header display' }), 'hidden');
        expect(screen.getByLabelText('Learner header preview')).toHaveTextContent('The learner header is hidden.');
        expect(screen.queryByLabelText('Preview navigation labels')).toBeNull();
        await userEvent.type(screen.getByRole('searchbox', { name: 'Find a setting…' }), 'Header logo URL');
        expect(screen.getByRole('textbox', { name: 'Header logo URL' })).toBeInTheDocument();
    });
    it('saves the administrator-selected learner layout without a learner override option', async () => {
        const harness = setup();
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Learner experience' }));
        const layout = screen.getByRole('combobox', { name: 'Learner navigation' });
        expect(layout).toHaveValue('top');
        await userEvent.selectOptions(layout, 'sidebar');
        expect(screen.queryByRole('switch', { name: 'Let learners choose their navigation' })).not.toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(await screen.findByText('Your settings have been saved.')).toBeInTheDocument();
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({
            expected_revision: 4,
            configuration: { learner_navigation: 'sidebar' },
        });
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });
    it('loads database values and keeps the page focused on one section', async () => {
        setup();
        expect(screen.getByLabelText('Settings')).toHaveAttribute('aria-busy', 'true');
        expect(await screen.findByRole('textbox', { name: 'Academy name' })).toHaveValue('Vidya Academy');
        expect(screen.queryByRole('spinbutton', { name: 'Maximum course image (MB)' })).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'Files & storage' }));
        expect(screen.getByRole('spinbutton', { name: 'Maximum course image (MB)' })).toHaveValue(8);
        await userEvent.type(screen.getByRole('searchbox', { name: 'Find a setting…' }), 'unsubscribe');
        expect(screen.getByRole('textbox', { name: 'Unsubscribe URL' })).toBeInTheDocument();
        expect(screen.queryByRole('spinbutton', { name: 'Maximum course image (MB)' })).toBeNull();
    });
    it('saves only changed keys with their original types and captured revision across sections', async () => {
        const harness = setup();
        const name = await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.clear(name);
        await userEvent.type(name, 'A new academy');
        await userEvent.click(screen.getByRole('button', { name: 'Catalogue & enrolment' }));
        expect(screen.getByRole('switch', { name: 'Allow self-enrolment' })).not.toBeChecked();
        await userEvent.click(screen.getByRole('switch', { name: 'Allow self-enrolment' }));
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(await screen.findByText('Your settings have been saved.')).toBeInTheDocument();
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({
            expected_revision: 4,
            configuration: { academy_name: 'A new academy', self_enrolment: true },
        });
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });
    it('preserves local edits and revision when a background request finds a newer version', async () => {
        let reads = 0;
        const harness = setup((request) => {
            if (request.method === 'GET') {
                reads += 1;
                return jsonResponse(
                    makeSettings({
                        configuration: {
                            revision: reads === 1 ? 4 : 9,
                            values: { ...values, academy_name: reads === 1 ? 'Initial' : 'Another administrator' },
                        },
                    }),
                );
            }
            return null;
        });
        const input = await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.clear(input);
        await userEvent.type(input, 'My local edit');
        await harness.queryClient.invalidateQueries({ queryKey: adminKeys.settings });
        expect(input).toHaveValue('My local edit');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await waitFor(() =>
            expect(harness.requests.find((request) => request.method === 'PUT')?.body).toMatchObject({
                expected_revision: 4,
            }),
        );
    });
    it('keeps edits on conflicts until the administrator confirms a reload', async () => {
        setup((request) =>
            request.method === 'PUT' ? problemResponse(409, 'A newer settings revision exists.') : null,
        );
        const input = await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.clear(input);
        await userEvent.type(input, 'Unsaved academy');
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(await screen.findByText(/Another administrator changed these settings/)).toBeInTheDocument();
        expect(input).toHaveValue('Unsaved academy');
        await userEvent.click(screen.getByRole('button', { name: 'Reload latest settings' }));
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(input).toHaveValue('Unsaved academy');
        await userEvent.click(screen.getByRole('button', { name: 'Discard changes' }));
        await userEvent.click(screen.getByRole('button', { name: 'Discard and reload' }));
        await waitFor(() => expect(input).toHaveValue('Vidya Academy'));
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });
    it('retains issuer edits when moving between sections and saves them through the existing endpoint', async () => {
        const harness = setup();
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.click(screen.getByRole('button', { name: 'Certificates & credentials' }));
        const name = screen.getByRole('textbox', { name: 'Issuer name' });
        await userEvent.clear(name);
        await userEvent.type(name, 'Learning Council');
        await userEvent.click(screen.getByRole('button', { name: 'General' }));
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        await userEvent.click(screen.getByRole('button', { name: 'Certificates & credentials' }));
        expect(screen.getByRole('textbox', { name: 'Issuer name' })).toHaveValue('Learning Council');
        await userEvent.click(screen.getByRole('button', { name: 'Save issuer' }));
        expect(await screen.findByText('Credential issuer saved.')).toBeInTheDocument();
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toMatchObject({
            issuer: { name: 'Learning Council' },
        });
    });
    it('shows no-match results and can recover', async () => {
        setup();
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.type(screen.getByRole('searchbox', { name: 'Find a setting…' }), 'no-such-field');
        expect(screen.getByRole('heading', { name: 'No settings found' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Clear search' }));
        expect(screen.getByRole('textbox', { name: 'Academy name' })).toBeInTheDocument();
    });
    it('explains server validation errors and brings the hidden field back into view', async () => {
        setup((request) =>
            request.method === 'PUT'
                ? new Response(
                      JSON.stringify({
                          title: 'Validation failed',
                          status: 422,
                          errors: { academy_name: 'Use a shorter academy name.' },
                      }),
                      { status: 422, headers: { 'Content-Type': 'application/problem+json' } },
                  )
                : null,
        );
        await screen.findByRole('textbox', { name: 'Academy name' });
        await userEvent.clear(screen.getByRole('textbox', { name: 'Academy name' }));
        await userEvent.type(screen.getByRole('textbox', { name: 'Academy name' }), 'Changed academy');
        await userEvent.click(screen.getByRole('button', { name: 'Files & storage' }));
        await userEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        await userEvent.click(await screen.findByRole('button', { name: /Academy name: Use a shorter/ }));
        expect(screen.getByRole('textbox', { name: 'Academy name' })).toHaveValue('Changed academy');
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
    });
    it.each([401, 403])('preserves the %i API permission state', async (status) => {
        setup(() => problemResponse(status, 'Access refused'));
        expect(
            await screen.findByText(status === 401 ? 'Sign in to continue.' : 'Managers see courses only.'),
        ).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save changes' })).toBeNull();
    });
});
