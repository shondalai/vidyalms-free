import { afterEach, describe, expect, it } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { OrganisationInvitationPage } from './organisations';
import { jsonResponse, makeConfig, makeHarness, problemResponse } from './testing/harness';

afterEach(() => {
    window.history.replaceState(null, '', '/');
});
const config = () => makeConfig({ screen: 'organisations', user: { id: 7, name: 'Olivia' }, urls: {
    home: '/courses', verify: '', myLearning: '/my-learning', course: '/course/__VIDYALMS_SLUG__', player: '/learn/__VIDYALMS_SLUG__',
} });
const summary = { assigned: 2, available: 1, required: 1 };
const course = { course_id: 21, title: 'Baking essentials', slug: 'baking-essentials', summary: 'Practice with confidence.', progress_percent: 40,
    enrolment_status: 'active', can_learn: true, required: true, due_at: '2026-10-20 00:00:00', expires_at: null,
    completed_at: null, has_access: true, grant_mode: null, published: true };
function navigate(hash: string) {
    act(() => {
        window.history.pushState(null, '', '/' + hash);
        window.dispatchEvent(new HashChangeEvent('hashchange'));
    });
}

describe('organization learning workspace', () => {
    it('links membership summaries to personal learning and supports back navigation', async () => {
        const harness = makeHarness((request) => jsonResponse(request.path.includes('/7/learning') ? {
            organisation: { id: 7, name: 'Northstar' }, summary, items: [course], total: 1, page: 1, per_page: 12,
        } : { items: [{ id: 7, name: 'Northstar', slug: 'northstar', state: 'active', membership_id: 9, learning: summary }], total: 1, page: 1, per_page: 20 }), config());
        render(<OrganisationInvitationPage />, { wrapper: harness.wrapper });
        expect(await screen.findByRole('link', { name: 'View learning Northstar' })).toHaveAttribute('href', '#/organisation/7/learning');
        navigate('#/organisation/7/learning');
        expect(await screen.findByRole('link', { name: 'Resume learning' })).toHaveAttribute('href', '/learn/baking-essentials');
        expect(screen.getByRole('progressbar', { name: 'Progress in Baking essentials' })).toHaveAttribute('value', '40');
        expect(screen.getByText('Required')).toBeInTheDocument();
        navigate('#/');
        expect(await screen.findByRole('link', { name: 'View learning Northstar' })).toBeInTheDocument();
        expect(harness.requests.every((request) => request.method === 'GET')).toBe(true);
    });

    it('searches and pages on the server, and never offers resume for an unallocated offering', async () => {
        window.history.replaceState(null, '', '/#/organisation/7/learning');
        const harness = makeHarness((request) => jsonResponse({ organisation: { id: 7, name: 'Northstar' }, summary,
            items: [{ ...course, can_learn: false, has_access: false, required: false, enrolment_status: null }],
            total: 50, page: request.path.includes('page=2') ? 2 : 1, per_page: 12 }), config());
        render(<OrganisationInvitationPage />, { wrapper: harness.wrapper });
        const user = userEvent.setup();
        await screen.findByRole('heading', { name: 'Baking essentials' });
        await user.click(screen.getByRole('button', { name: 'Available to me' }));
        await waitFor(() => expect(harness.requests.some((r) => r.path.includes('tab=available'))).toBe(true));
        expect(screen.queryByRole('link', { name: 'Resume learning' })).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Next ›' }));
        await waitFor(() => expect(harness.requests.some((r) => r.path.includes('page=2'))).toBe(true));
        await user.type(screen.getByRole('searchbox'), 'Baking');
        await user.click(screen.getByRole('button', { name: 'Search' }));
        await waitFor(() => expect(harness.requests.some((r) => r.path.includes('search=Baking') && r.path.includes('page=1'))).toBe(true));
        expect(await screen.findByRole('link', { name: 'View course' })).toHaveAttribute('href', '/course/baking-essentials');
    });

    it('shows a recoverable error rather than stale organization data', async () => {
        window.history.replaceState(null, '', '/#/organisation/7/learning');
        const harness = makeHarness(() => problemResponse(404, 'Unavailable', 'This membership is no longer active.'), config());
        render(<OrganisationInvitationPage />, { wrapper: harness.wrapper });
        expect(await screen.findByText('This membership is no longer active.')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'Your organizations' })).toHaveAttribute('href', '#/');
        expect(screen.queryByRole('link', { name: 'Start learning' })).not.toBeInTheDocument();
    });
});
