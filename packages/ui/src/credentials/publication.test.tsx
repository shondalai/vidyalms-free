import { beforeEach, describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';
import { CredentialsApp } from './app';
import { credentialKeys } from './api';
import { jsonResponse, makeConfig, makeHarness, problemResponse } from './testing/harness';

function setup({ siteAllows = false, holderAllows = true, readStatus = 200, writeStatus = 200 } = {}) {
    let stored = holderAllows;
    const harness = makeHarness((request) => {
        if (request.path.startsWith('/v1/me/certificates?')) {
            return jsonResponse({ items: [], total: 0, page: 1, per_page: 20 });
        }
        if (request.path !== '/v1/me/credentials/publication') return null;
        if (request.method === 'GET' && readStatus !== 200) return problemResponse(readStatus, 'Preference unavailable');
        if (request.method === 'PUT') {
            if (writeStatus !== 200) return problemResponse(writeStatus, 'Preference refused');
            stored = (request.body as { publish_learner_name: boolean }).publish_learner_name;
        }
        return jsonResponse({ publish_learner_name: stored, effective_publish_learner_name: siteAllows && stored });
    }, makeConfig({
        screen: 'credentials', user: { id: 482, name: 'Ana Costa' },
        routes: ['/me/certificates', '/me/credentials/publication'],
    }));
    render(<CredentialsApp />, { wrapper: harness.wrapper });
    return harness;
}

const label = 'Allow my name on public verification pages';
const saveLabel = 'Save privacy preference';

describe('credential publication preference in Achievements', () => {
    beforeEach(() => window.history.replaceState({}, '', window.location.pathname));

    it('explains default site hiding separately from the saved holder preference without writing on load', async () => {
        const harness = setup();
        const control = await screen.findByRole('checkbox', { name: label });
        expect(control).toBeChecked();
        expect(control).toHaveAccessibleDescription(/certificate and badge verification.*This site currently hides learner names/);
        expect(screen.getByRole('button', { name: saveLabel })).toBeDisabled();
        expect(harness.requests.filter((request) => request.method === 'PUT')).toHaveLength(0);
        expect(screen.queryByText(/Your saved preference allows your name to appear/)).not.toBeInTheDocument();
    });

    it('lets a holder opt out while the site allows names, and keeps the saved choice after a reload', async () => {
        const harness = setup({ siteAllows: true });
        const control = await screen.findByRole('checkbox', { name: label });
        expect(screen.getByRole('status')).toHaveTextContent('Your saved preference allows your name to appear');
        await userEvent.click(control);
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        await userEvent.click(screen.getByRole('button', { name: saveLabel }));
        await screen.findByText('Privacy preference saved.');
        expect(screen.getByText('Your saved preference keeps your name hidden on public verification pages.')).toBeInTheDocument();
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({ publish_learner_name: false });
        await harness.queryClient.invalidateQueries({ queryKey: credentialKeys.publication });
        expect(control).not.toBeChecked();
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });

    it.each([false, true])('uses the server visibility result when opting in with site publication %s', async (siteAllows) => {
        const harness = setup({ siteAllows, holderAllows: false });
        const control = await screen.findByRole('checkbox', { name: label });
        expect(control).not.toBeChecked();
        await userEvent.click(control);
        await userEvent.click(screen.getByRole('button', { name: saveLabel }));
        await screen.findByText('Privacy preference saved.');
        expect(harness.requests.find((request) => request.method === 'PUT')?.body).toEqual({ publish_learner_name: true });
        expect(await screen.findByText(siteAllows
            ? 'Your saved preference allows your name to appear on public verification pages.'
            : 'This site currently hides learner names. Your saved preference will apply if the site enables name publication.')).toBeInTheDocument();
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });

    it('preserves the opt-out draft on a failed save and does not claim that names are hidden', async () => {
        setup({ siteAllows: true, writeStatus: 403 });
        const control = await screen.findByRole('checkbox', { name: label });
        await userEvent.click(control);
        await userEvent.click(screen.getByRole('button', { name: saveLabel }));
        await screen.findByText('Could not save your privacy preference. Please try again.');
        expect(control).not.toBeChecked();
        expect(screen.getByRole('status')).toHaveTextContent('Your saved preference allows your name to appear');
        expect(screen.queryByText('Privacy preference saved.')).not.toBeInTheDocument();
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        expect(screen.getByRole('button', { name: saveLabel })).toBeEnabled();
    });

    it.each([401, 403])('does not offer a guessed preference after a %i read refusal', async (readStatus) => {
        const harness = setup({ readStatus });
        await screen.findByText('Could not load your privacy preference.');
        expect(screen.queryByRole('checkbox', { name: label })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: saveLabel })).not.toBeInTheDocument();
        expect(harness.requests.filter((request) => request.method === 'PUT')).toHaveLength(0);
    });
});
