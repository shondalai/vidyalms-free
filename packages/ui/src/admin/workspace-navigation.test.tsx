import { beforeEach, describe, expect, it } from 'vitest';
import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminApp } from './app';
import { makeConfig, makeCourse, makeDashboard, makeHarness, jsonResponse } from './testing/harness';
import { courseIdAtHash, screenAtUrl } from './workspace-navigation';
import { useNavigationGuard } from '@/shared/navigation-guard';
import { adminScreen, registerAdminScreen } from './screens/registry';

beforeEach(() => window.history.replaceState(null, '', '/admin/dashboard'));

const config = makeConfig({ urls: {
    workbench: '/admin/course/{course_id}', preview: '/course/{slug}', settings: '/native/settings',
    screens: { dashboard: '/admin/dashboard', courses: '/admin/courses', enrolments: '/admin/enrolments', settings: '/admin/settings' },
} });

function setup(dirty = false) {
    if (dirty && adminScreen('draft-test') === undefined) registerAdminScreen({
        key: 'draft-test', label: 'Draft editor', render: () => <DirtyEditor />,
    });
    const mounted = dirty ? makeConfig({ ...config, screen: 'draft-test', urls: {
        ...config.urls, screens: { ...config.urls.screens, 'draft-test': '/admin/draft' },
    } }) : config;
    if (dirty) window.history.replaceState(null, '', '/admin/draft');
    const harness = makeHarness((request) => {
        if (request.path === '/v1/admin/dashboard') return jsonResponse(makeDashboard());
        if (request.path.startsWith('/v1/manage/courses?')) return jsonResponse({ items: [makeCourse({ id: 1, title: 'Foundations of practice' })], total: 1, page: 1, per_page: 20 });
        if (request.path === '/v1/manage/courses/1') return jsonResponse({ ...makeCourse({ id: 1, title: 'Foundations of practice' }), description: '', prerequisites: [], pending_grading_count: 0 });
        return null;
    }, mounted);
    render(<AdminApp />, { wrapper: harness.wrapper });
    return harness;
}

function DirtyEditor() {
    useNavigationGuard(true);
    return <input aria-label="Draft text" defaultValue="My unsaved lesson" />;
}

describe('connected administration workspace', () => {
    it('keeps icon navigation accessible and remembers the minimized sidebar', async () => {
        localStorage.removeItem('vidyalms-admin-sidebar-collapsed');
        setup();
        await userEvent.click(screen.getByRole('button', { name: 'Minimize sidebar' }));
        const expand = screen.getByRole('button', { name: 'Expand sidebar' });
        expect(expand).toHaveAttribute('aria-expanded', 'false');
        expect(localStorage.getItem('vidyalms-admin-sidebar-collapsed')).toBe('true');
        const nav = screen.getByRole('navigation', { name: 'VidyaLMS sections' });
        await userEvent.click(within(nav).getByRole('link', { name: 'Courses' }));
        expect(await screen.findByRole('table', { name: 'Courses' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Expand sidebar' })).toBe(expand);
        await userEvent.click(expand);
        expect(localStorage.getItem('vidyalms-admin-sidebar-collapsed')).toBe('false');
    });

    it('changes screens without remounting the shell and retains authenticated requests', async () => {
        const harness = setup();
        const region = screen.getByRole('region', { name: 'VidyaLMS administration' });
        const nav = screen.getByRole('navigation', { name: 'VidyaLMS sections' });
        await userEvent.click(within(nav).getByRole('link', { name: 'Courses' }));
        expect(await screen.findByRole('table', { name: 'Courses' })).toBeInTheDocument();
        expect(window.location.pathname).toBe('/admin/courses');
        expect(screen.getByRole('region', { name: 'VidyaLMS administration' })).toBe(region);
        expect(within(nav).getByRole('link', { name: 'Courses' })).toHaveAttribute('aria-current', 'page');
        expect(harness.requests.some((request) => request.path.startsWith('/v1/manage/courses?') && request.headers['x-csrf-token'] === 'test')).toBe(true);
        await act(async () => {
            window.history.replaceState(null, '', '/admin/dashboard');
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        await waitFor(() => expect(within(nav).getByRole('link', { name: 'Dashboard' })).toHaveAttribute('aria-current', 'page'));
    });

    it('keeps course authoring within the shell and preserves a reloadable course address', async () => {
        setup();
        const nav = screen.getByRole('navigation', { name: 'VidyaLMS sections' });
        await userEvent.click(within(nav).getByRole('link', { name: 'Courses' }));
        await userEvent.click(await screen.findByRole('link', { name: 'Open Foundations of practice' }));
        expect(await screen.findByRole('tab', { name: 'Curriculum' })).toBeInTheDocument();
        expect(window.location.hash).toBe('#/course/1/vy/curriculum');
        await userEvent.click(screen.getByRole('tab', { name: 'Access' }));
        expect(window.location.hash).toBe('#/course/1/vy/access');
        expect(screen.queryByRole('tab', { name: 'Learners' })).not.toBeInTheDocument();
        expect(screen.queryByRole('tab', { name: 'Reports' })).not.toBeInTheDocument();
        expect(screen.getByRole('navigation', { name: 'VidyaLMS sections' })).toBe(nav);
    });

    it('keeps a draft until the user explicitly discards it before switching screens', async () => {
        setup(true);
        const nav = screen.getByRole('navigation', { name: 'VidyaLMS sections' });
        await userEvent.click(within(nav).getByRole('link', { name: 'Courses' }));
        expect(screen.getByRole('dialog', { name: 'Leave with unsaved changes?' })).toBeInTheDocument();
        expect(window.location.pathname).toBe('/admin/draft');
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Draft text')).toHaveValue('My unsaved lesson');
        await userEvent.click(within(nav).getByRole('link', { name: 'Courses' }));
        await userEvent.click(screen.getByRole('button', { name: 'Discard and leave' }));
        expect(await screen.findByRole('table', { name: 'Courses' })).toBeInTheDocument();
    });

    it('leaves modified clicks to the browser', () => {
        setup();
        const link = within(screen.getByRole('navigation', { name: 'VidyaLMS sections' })).getByRole('link', { name: 'Courses' });
        expect(fireEvent.click(link, { ctrlKey: true })).toBe(true);
    });

    it('protects a dirty editor during browser history navigation', async () => {
        setup(true);
        await act(async () => {
            window.history.replaceState(null, '', '/admin/courses');
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        expect(window.location.pathname).toBe('/admin/draft');
        expect(screen.getByRole('dialog', { name: 'Leave with unsaved changes?' })).toBeInTheDocument();
        await userEvent.click(screen.getByRole('button', { name: 'Keep editing' }));
        expect(screen.getByLabelText('Draft text')).toHaveValue('My unsaved lesson');
    });

    it('matches CMS query destinations exactly and rejects foreign or malformed routes', () => {
        const cms = { ...config, urls: { ...config.urls, screens: { courses: '/administrator/index.php?option=com_vidyalms&view=courses' } } };
        expect(screenAtUrl(cms, '/administrator/index.php?view=courses&option=com_vidyalms')).toBe('courses');
        expect(screenAtUrl(cms, '/administrator/index.php?view=courses&option=another')).toBeNull();
        expect(screenAtUrl(cms, 'https://unrelated.test/administrator/index.php?option=com_vidyalms&view=courses')).toBeNull();
        expect(courseIdAtHash('/course/0')).toBeNull();
        expect(courseIdAtHash('/course/999999999999999999')).toBeNull();
    });
});
