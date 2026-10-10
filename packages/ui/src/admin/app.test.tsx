import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ReactNode } from 'react';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MountElContext } from '@/shared/context';
import { AdminApp } from './app';
import type { AdminScreen } from './config';
import { learnerIdFromHash } from './hooks';
import { registerFreeAdminScreens } from './screens';
import { registerAdminScreen, resetAdminScreens } from './screens/registry';
import {
    jsonResponse,
    makeConfig,
    makeCourse,
    makeDashboard,
    makeEnrolment,
    makeHarness,
    makeLearnerRecord,
    makeSettings,
    problemResponse,
    type RecordedRequest,
} from './testing/harness';

/*
 * The app shell: the host's menu decides the screen, the hash decides where
 * inside it, and an expired administrator session outranks both.
 */

function renderApp(screenName: AdminScreen, options: { unauthorized?: boolean } = {}) {
    const config = makeConfig({ screen: screenName });
    const harness = makeHarness((request: RecordedRequest) => {
        const url = new URL(request.path, 'http://localhost');
        if (options.unauthorized === true && url.pathname !== '/v1/admin/dashboard') {
            return problemResponse(401, 'Unauthorized');
        }
        if (url.pathname === '/v1/admin/dashboard') {
            return jsonResponse(makeDashboard());
        }
        if (url.pathname === '/v1/manage/courses') {
            return jsonResponse({
                items: [makeCourse({ id: 1, title: 'Foundations of Web Accessibility' })],
                total: 1,
                page: 1,
                per_page: 20,
            });
        }
        if (url.pathname === '/v1/admin/enrolments') {
            return jsonResponse({
                items: [makeEnrolment({ id: 1 })],
                total: 1,
                page: 1,
                per_page: 20,
                facets: { active: 1, expiring: 0, suspended: 0, expiring_within_days: 14 },
            });
        }
        if (url.pathname === '/v1/admin/certificates') {
            return jsonResponse({ items: [], total: 0, page: 1, per_page: 20 });
        }
        if (url.pathname === '/v1/admin/settings') {
            return jsonResponse(makeSettings());
        }
        if (/^\/v1\/admin\/learners\/\d+$/.test(url.pathname)) {
            return jsonResponse(makeLearnerRecord());
        }
        return null;
    }, config);
    const view = render(<AdminApp />, { wrapper: harness.wrapper });
    return { ...harness, view };
}

beforeEach(() => {
    window.location.hash = '';
    window.localStorage.clear();
});

describe('learnerIdFromHash', () => {
    it('reads the learner sub-route and nothing else', () => {
        expect(learnerIdFromHash('/learner/482')).toBe(482);
        expect(learnerIdFromHash('/learner/0')).toBeNull();
        expect(learnerIdFromHash('/learner/abc')).toBeNull();
        expect(learnerIdFromHash('/courses')).toBeNull();
        expect(learnerIdFromHash('')).toBeNull();
    });
});

describe('AdminApp', () => {
    it('is one named region inside the host page', () => {
        renderApp('dashboard');
        expect(screen.getByRole('region', { name: 'VidyaLMS administration' })).toBeInTheDocument();
    });

    it('keeps one shrink boundary from the application region to the screen content', () => {
        renderApp('dashboard');

        const region = screen.getByRole('region', { name: 'VidyaLMS administration' });
        const main = screen.getByRole('main');
        expect(region).toHaveClass('w-full', 'min-w-0', 'max-w-full', 'overflow-hidden');
        expect(main).toHaveClass('vy-workspace-main', 'min-w-0', 'max-w-full');
        expect(main).not.toHaveClass('overflow-hidden');
        expect(main.firstElementChild).toHaveClass('w-full', 'min-w-0', 'max-w-full', 'overflow-hidden');
    });

    it('renders the screen the host mounted', async () => {
        renderApp('certificates');
        expect(await screen.findByRole('table', { name: 'Certificates' })).toBeInTheDocument();
        expect(screen.queryByRole('table', { name: 'Courses' })).toBeNull();
    });

    it('uses only host-provided destinations in its product navigation', () => {
        renderApp('courses');

        const courseLinks = screen.getAllByRole('link', { name: 'Courses' });
        expect(courseLinks).toHaveLength(1);
        for (const link of courseLinks) {
            expect(link).toHaveAttribute('href', '/admin/courses');
            expect(link).toHaveAttribute('aria-current', 'page');
        }
        expect(screen.queryByRole('link', { name: 'Notifications' })).toBeNull();
    });

    it('opens a keyboard-accessible compact navigation dialog', async () => {
        renderApp('courses');
        await userEvent.click(screen.getByRole('button', { name: 'Open workspace menu' }));
        const dialog = screen.getByRole('dialog', { name: 'VidyaLMS' });
        expect(within(dialog).getByRole('navigation', { name: 'VidyaLMS sections on this site' })).toBeInTheDocument();
        await userEvent.keyboard('{Escape}');
        expect(screen.queryByRole('dialog')).toBeNull();
    });

    it('switches the scoped shell theme and remembers the choice', async () => {
        const harness = makeHarness((request) =>
            request.path.startsWith('/v1/admin/dashboard') ? jsonResponse(makeDashboard()) : null,
        );
        const mountEl = document.createElement('div');
        mountEl.id = 'vidyalms-admin';
        document.body.appendChild(mountEl);
        const HarnessWrapper = harness.wrapper;
        const wrapper = ({ children }: { children: ReactNode }) => (
            <MountElContext.Provider value={mountEl}>
                <HarnessWrapper>{children}</HarnessWrapper>
            </MountElContext.Provider>
        );
        const view = render(<AdminApp />, { wrapper, container: mountEl });

        await userEvent.click(screen.getByRole('button', { name: 'Use dark theme' }));

        expect(mountEl).toHaveAttribute('data-color-scheme', 'dark');
        expect(screen.getByRole('button', { name: 'Use light theme' })).toHaveAttribute('aria-pressed', 'true');
        expect(window.localStorage.getItem('vidyalms-admin-color-scheme')).toBe('dark');

        view.unmount();
        expect(mountEl).not.toHaveAttribute('data-color-scheme');
        mountEl.remove();
    });

    it('renders each of the six screens', async () => {
        const expectations: [AdminScreen, RegExp][] = [
            ['dashboard', /Published courses/],
            ['courses', /Foundations of Web Accessibility/],
            ['enrolments', /Jonas Berg/],
            ['learners', /Know your learners/],
            ['certificates', /No certificates match this search\./],
            ['settings', /Academy name/],
        ];
        for (const [name, pattern] of expectations) {
            const { view } = renderApp(name);
            expect(await screen.findByText(pattern)).toBeInTheDocument();
            view.unmount();
        }
    });

    it('drills into a learner record from another list and restores its exact state on Back', async () => {
        window.location.hash = '#/?search=Jonas&filter=active&sort=progress&order=desc&page=2';
        renderApp('enrolments');
        await userEvent.click(await screen.findByRole('button', { name: 'Open the record of Jonas Berg' }));

        expect(await screen.findByRole('heading', { name: 'Tom Weiss' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Back to Enrolments' }));
        expect(window.location.hash).toBe(
            '#/?search=Jonas&filter=active&sort=progress&order=desc&page=2',
        );
        expect(await screen.findByRole('table', { name: 'Enrolments' })).toBeInTheDocument();
    });

    it('opens on the learner record when the hash names one', async () => {
        window.location.hash = '#/learner/482';
        renderApp('enrolments');
        expect(await screen.findByRole('heading', { name: 'Tom Weiss' })).toBeInTheDocument();
    });

    it('shows the session-expired state once a working session stops being one', async () => {
        const { sessionTracker, view } = renderApp('dashboard');
        await screen.findByText('Published courses');
        sessionTracker.observe(403);
        view.rerender(<AdminApp />);
        expect(await screen.findByTestId('session-expired')).toBeInTheDocument();
    });
});

/*
 * Which screens exist is a registry, so a package can add one without
 * editing the free bundle, the free zod enum or either adapter.
 */
describe('the screen registry', () => {
    afterEach(() => {
        resetAdminScreens();
        registerFreeAdminScreens();
    });

    it('renders a screen a package registered, named by a key the host simply passes', async () => {
        registerAdminScreen({
            key: 'seat-reports',
            label: 'Seat reports',
            render: () => <p>Seats used across the group.</p>,
        });

        const harness = makeHarness(() => null, makeConfig({ screen: 'seat-reports' }));
        render(<AdminApp />, { wrapper: harness.wrapper });

        expect(await screen.findByText('Seats used across the group.')).toBeInTheDocument();
    });

    it('refuses an explicit screen this bundle does not carry instead of showing dashboard', async () => {
        const harness = makeHarness(
            (request) =>
                request.path.startsWith('/v1/admin/dashboard') ? jsonResponse(makeDashboard()) : null,
            makeConfig({ screen: 'a-screen-from-a-newer-host' }),
        );
        render(<AdminApp />, { wrapper: harness.wrapper });

        expect(within(await screen.findByRole('alert')).getByRole('heading', { name: 'Screen unavailable' })).toBeInTheDocument();
        expect(screen.getByText(/a-screen-from-a-newer-host/)).toBeInTheDocument();
        expect(screen.queryByText(/Published courses/)).toBeNull();
        expect(harness.requests).toHaveLength(0);
    });

    it('renders a contributed screen that registers after the shell mounted', async () => {
        const harness = makeHarness(() => null, makeConfig({ screen: 'late-reports' }));
        render(<AdminApp />, { wrapper: harness.wrapper });
        expect(within(await screen.findByRole('alert')).getByRole('heading', { name: 'Screen unavailable' })).toBeInTheDocument();

        act(() => registerAdminScreen({
            key: 'late-reports',
            label: 'Late reports',
            render: () => <p>Late bundle is ready.</p>,
        }));

        expect(await screen.findByText('Late bundle is ready.')).toBeInTheDocument();
    });

    it('refuses two packages that both claim one key, at boot rather than by script order', () => {
        expect(() =>
            registerAdminScreen({ key: 'courses', label: 'Mine', render: () => <p>mine</p> }),
        ).toThrow(/already registered/);
    });
});
