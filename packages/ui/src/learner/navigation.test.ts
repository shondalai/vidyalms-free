// @vitest-environment jsdom
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { waitFor } from '@testing-library/react';
import type Swup from 'swup';
import type { Visit } from 'swup';
import { isLearnerLink, startLearnerNavigation } from './navigation';

let app: Swup | undefined;
const shell = (content: string, layout = 'top') => `<div data-vy-learner-shell data-vy-site-key="academy" data-vy-navigation="${layout}" data-vy-leave-confirm="Leave your draft?"><nav><a href="/learning">My learning</a><a href="/catalogue">Explore courses</a></nav><div class="vy-learning-content" tabindex="-1">${content}</div></div>`;
const page = (content: string, layout = 'top') => `<!doctype html><html><head><title>Learning page</title></head><body>${shell(content, layout)}</body></html>`;
const fetchPage = vi.fn(async (url: string) => {
    const response = new Response(page(`<h1>${url}</h1>`), { status: 200 });
    Object.defineProperty(response, 'url', { value: new URL(url, location.href).href });
    return response;
});
async function go(url: string) {
    const ended = new Promise<void>((resolve) => app!.hooks.once('visit:end', () => resolve()));
    app!.navigate(url);
    await ended;
}
beforeEach(() => {
    history.replaceState(null, '', '/catalogue');
    document.body.innerHTML = '<header id="host-header">Host website</header>' + shell('<h1>Catalogue</h1>');
    vi.stubGlobal('fetch', fetchPage);
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    Element.prototype.scrollTo = vi.fn();
    Object.defineProperty(HTMLElement.prototype, 'innerText', { configurable: true, get() {
        return this.textContent;
    } });
    fetchPage.mockClear();
});
afterEach(async () => {
    await app?.destroy();
    app = undefined;
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});
describe('CMS learner SPA navigation', () => {
    it('shows a skeleton through a delayed response, preserving drafts and leaving navigation available', async () => {
        document.querySelector('.vy-learning-content')!.innerHTML = '<input value="Draft answer">';
        document.querySelector('a')!.dataset.vyPage = 'mylearning';
        const draft = document.querySelector('input')!;
        let resolvePage!: (value: Response) => void;
        fetchPage.mockImplementationOnce(() => new Promise<Response>((resolve) => {
            resolvePage = resolve;
        }));
        app = startLearnerNavigation();
        const finished = go('/learning');
        await waitFor(() => expect(document.querySelector('[data-vy-skeleton=cards]')).toBeInTheDocument());
        expect(document.querySelector('.vy-learning-content')).toHaveAttribute('inert');
        expect(document.querySelector('.vy-learning-content')).toHaveAttribute('aria-busy', 'true');
        expect(draft.isConnected).toBe(true);
        expect(draft.value).toBe('Draft answer');
        expect(document.querySelector('nav')!.closest('[inert]')).toBeNull();
        expect(document.querySelector('[role=status]')).toHaveTextContent('Loading page');
        const response = new Response(page('<h1>Loaded learning</h1>'));
        Object.defineProperty(response, 'url', { value: new URL('/learning', location.href).href });
        resolvePage(response);
        await finished;
        expect(document.querySelector('[data-vy-skeleton]')).toBeNull();
        expect(document.querySelector('[inert]')).toBeNull();
        expect(document.querySelector('[role=status]')).toBeNull();
        expect(document.activeElement).toHaveClass('vy-learning-content');
        expect(document.querySelector('h1')).toHaveTextContent('Loaded learning');
    });
    it('replaces learner content and titles without replacing the host document, and fetches fresh data on return', async () => {
        const host = document.getElementById('host-header');
        const documentElement = document.documentElement;
        const unmount = vi.fn();
        const mount = vi.fn();
        window.addEventListener('vidyalms:learner:unmount', unmount, { once: true });
        window.addEventListener('vidyalms:learner:mount', mount, { once: true });
        app = startLearnerNavigation();
        await go('/learning');
        expect(location.pathname).toBe('/learning');
        expect(document.querySelector('h1')).toHaveTextContent('/learning');
        expect(document.title).toBe('Learning page');
        expect(document.getElementById('host-header')).toBe(host);
        expect(document.documentElement).toBe(documentElement);
        expect(document.activeElement).toHaveClass('vy-learning-content');
        expect(unmount).toHaveBeenCalledOnce();
        expect(mount).toHaveBeenCalledOnce();
        await go('/catalogue');
        await go('/learning');
        expect(fetchPage).toHaveBeenCalledTimes(3);
        expect(fetchPage.mock.calls[0]).toEqual(['/learning', expect.objectContaining({ cache: 'no-store', credentials: 'same-origin' })]);
        expect(app!.cache.size).toBe(0);
        expect(document.querySelector('[aria-busy=true]')).toBeNull();
    });
    it('respects the admin-selected layout on each fetched page regardless of old device preferences', async () => {
        localStorage.setItem('vidyalms:learner-navigation:academy', 'sidebar');
        app = startLearnerNavigation();
        await go('/learning');
        expect(document.querySelector('[data-vy-navigation]')).toHaveAttribute('data-vy-navigation', 'top');
        expect(document.querySelector('[data-vy-layout-picker]')).toBeNull();
    });
    it('submits catalogue GET filters through navigation, preserving all routing and search parameters', async () => {
        document.body.innerHTML = shell('<form method="get" action="/catalogue" data-vy-page-form><input name="q" value="design"><input name="option" value="com_vidyalms"><button>Search</button></form>');
        app = startLearnerNavigation();
        document.querySelector('form')!.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent('/catalogue?q=design&option=com_vidyalms'));
        expect(location.search).toBe('?q=design&option=com_vidyalms');
    });
    it('leaves mutations to native forms, without fetching or replaying a POST', () => {
        document.body.innerHTML = shell('<form method="post" data-vy-page-form><input name="token" value="csrf"><button>Enrol</button></form>');
        app = startLearnerNavigation();
        const event = new Event('submit', { bubbles: true, cancelable: true });
        document.querySelector('form')!.dispatchEvent(event);
        expect(event.defaultPrevented).toBe(false);
        expect(fetchPage).not.toHaveBeenCalled();
    });
    it('cancels navigation before history or data changes when a learner keeps unsaved work', async () => {
        document.body.innerHTML = shell('<form data-vy-draft-form method="post"><input name="preference" value="daily"></form>');
        app = startLearnerNavigation();
        document.querySelector('input')!.value = 'weekly';
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        document.querySelector('a')!.click();
        expect(confirm).toHaveBeenCalledWith('Leave your draft?');
        expect(location.pathname).toBe('/catalogue');
        expect(fetchPage).not.toHaveBeenCalled();
        confirm.mockReturnValue(true);
        document.querySelector('a')!.click();
        await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent('/learning'));
    });
    it('handles browser back and forward without a full document navigation', async () => {
        app = startLearnerNavigation();
        await go('/learning');
        await go('/catalogue?q=video');
        history.back();
        await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent('/learning'));
        history.forward();
        await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent('/catalogue?q=video'));
    });
    it('restores the history entry when browser Back is cancelled because of unsaved answers', async () => {
        app = startLearnerNavigation();
        await go('/learning');
        const block = (event: Event) => event.preventDefault();
        window.addEventListener('vidyalms:check-drafts', block);
        const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false);
        history.back();
        await waitFor(() => expect(confirm).toHaveBeenCalledOnce());
        await waitFor(() => expect(location.pathname).toBe('/learning'));
        expect(document.querySelector('h1')).toHaveTextContent('/learning');
        expect(fetchPage).toHaveBeenCalledTimes(1);
        window.removeEventListener('vidyalms:check-drafts', block);
        history.back();
        await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent('/catalogue'));
    });
    it('falls back to native navigation for a login or other page outside the learning shell, before unmounting', async () => {
        app = startLearnerNavigation();
        const failed = vi.fn();
        const unmount = vi.fn();
        const logged = vi.spyOn(console, 'error');
        // performNativeNavigation unwinds the entry swup pushed, then lets the browser load the final URL.
        const unwound = vi.spyOn(history, 'back');
        const visits: Visit[] = [];
        window.addEventListener('vidyalms:learner:unmount', unmount);
        app!.hooks.on('visit:start', (visit) => {
            visits.push(visit);
        });
        app!.hooks.on('visit:fail', failed);
        fetchPage.mockImplementationOnce(async () => {
            const response = new Response('<html><body>Sign in</body></html>');
            Object.defineProperty(response, 'url', { value: new URL('/login', location.href).href });
            return response;
        });
        try {
            app!.navigate('/private-learning');
            await waitFor(() => expect(unwound).toHaveBeenCalledOnce());
            expect(visits).toHaveLength(1);
            expect(visits[0]!.ignored, 'an answer outside the shell is a native load, not a failed visit').toBe(true);
            expect(visits[0]!.to.url).toBe('/login');
            expect(failed).not.toHaveBeenCalled();
            // jsdom reports its own unimplemented navigation through console.error; the navigation layer logs nothing.
            const reported = logged.mock.calls.flat().map((part) => (part instanceof Error ? part.message : String(part))).join('\n');
            expect(reported).not.toContain('Native page navigation required.');
            expect(unmount).not.toHaveBeenCalled();
            expect(document.querySelector('h1')).toHaveTextContent('Catalogue');
            expect(document.querySelector('[data-vy-skeleton]')).toBeNull();
            expect(document.querySelector('[inert]')).toBeNull();
        } finally {
            window.removeEventListener('vidyalms:learner:unmount', unmount);
        }
    });
    it('still reports a learner page it cannot load for any other reason as a failed visit', async () => {
        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = '/media/vidyalms/player.css?v=1';
        document.head.append(stylesheet);
        app = startLearnerNavigation();
        const failed = vi.fn();
        app!.hooks.replace('visit:fail', failed);
        // A deploy changed a fixed entry between the two pages: a real reason, worth the report swup makes.
        const updated = '<link data-vy-spa-asset rel="stylesheet" href="/media/vidyalms/player.css?v=2">';
        fetchPage.mockImplementationOnce(async () => {
            const response = new Response(page(`${updated}<h1>Player</h1>`));
            Object.defineProperty(response, 'url', { value: new URL('/learning', location.href).href });
            return response;
        });
        try {
            app!.navigate('/learning');
            await waitFor(() => expect(failed).toHaveBeenCalledOnce());
            expect(String((failed.mock.calls[0]![1] as { error: unknown }).error)).toContain('The learning application was updated.');
            expect(document.querySelector('[data-vy-skeleton]')).toBeNull();
        } finally {
            stylesheet.remove();
        }
    });
    it('keeps downloads, external links, hashes, CMS actions and new-tab links native', () => {
        for (const attrs of ['href="https://elsewhere.example/"', 'href="/certificate.pdf"', 'href="/file" download', 'href="/learning" target="_blank"', 'href="#lesson"', 'href="/index.php?task=logout"', 'href="/wp-json/file"']) {
            document.querySelector('nav')!.innerHTML = `<a ${attrs}>Link</a>`;
            expect(isLearnerLink(document.querySelector('a')!)).toBe(false);
        }
    });
    it('opens the sign-in gate host account links natively and Explore courses inside the shell', () => {
        document.querySelector('.vy-learning-content')!.innerHTML = '<section class="vy-signin">'
            + '<div class="vy-signin-actions" data-no-swup>'
            + '<a data-vy-signin-action="sign-in" href="/index.php?option=com_users&amp;view=login&amp;return=aHR0cDovL2E">Sign in</a></div>'
            + '<ul class="vy-signin-help" data-no-swup><li>'
            + '<a data-vy-signin-action="reset" href="/index.php?option=com_users&amp;view=reset">Forgot your password?</a></li></ul>'
            + '<p class="vy-signin-explore"><a data-vy-signin-action="explore" href="/catalogue">Explore courses</a></p></section>';
        const link = (action: string) => document.querySelector<HTMLAnchorElement>(`[data-vy-signin-action="${action}"]`)!;
        expect(isLearnerLink(link('sign-in'))).toBe(false);
        expect(isLearnerLink(link('reset'))).toBe(false);
        expect(isLearnerLink(link('explore'))).toBe(true);
        link('sign-in').parentElement!.removeAttribute('data-no-swup');
        // A Joomla account address is now native on its own too, so the marker is proved on a learner address instead.
        expect(isLearnerLink(link('sign-in')), 'a com_users address stays native without the marker').toBe(false);
        link('explore').parentElement!.setAttribute('data-no-swup', '');
        expect(isLearnerLink(link('explore')), 'data-no-swup, not the address, keeps it native').toBe(false);
    });
    it('keeps every Joomla account page native, by its component query or its SEF path, without fetching it first', async () => {
        const accountLinks = [
            '/joomla/component/users/login?return=aHR0cDovL2xvY2FsaG9zdA==&Itemid=101',
            '/component/users/?view=registration',
            '/component/users',
            '/index.php?option=com_users&view=reset',
            '/index.php?Itemid=5&option=com_users&view=profile',
        ];
        for (const href of accountLinks) {
            document.querySelector('nav')!.innerHTML = `<a href="${href}">Account</a>`;
            expect(isLearnerLink(document.querySelector('a')!), href).toBe(false);
        }
        for (const href of ['/learning', '/index.php?option=com_vidyalms&view=mylearning', '/courses/users-guide', '/component/vidyalms/users']) {
            document.querySelector('nav')!.innerHTML = `<a href="${href}">Learning</a>`;
            expect(isLearnerLink(document.querySelector('a')!), href).toBe(true);
        }
        app = startLearnerNavigation();
        document.querySelector('nav')!.innerHTML = '<a href="/joomla/component/users/login?return=x">Sign in</a>';
        let leftToBrowser: boolean | null = null;
        // Last in the bubble phase: note whether anything claimed the click, then stop jsdom's unimplemented navigation.
        const observe = (event: Event) => {
            leftToBrowser = !event.defaultPrevented;
            event.preventDefault();
        };
        window.addEventListener('click', observe);
        try {
            document.querySelector('a')!.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
            await Promise.resolve();
        } finally {
            window.removeEventListener('click', observe);
        }
        expect(fetchPage).not.toHaveBeenCalled();
        expect(leftToBrowser, 'the browser follows the link itself').toBe(true);
    });
    it('carries the WordPress interactivity id into every entry it adds, so a block theme never reloads on Back or Forward', async () => {
        // What @wordpress/interactivity does on load and on every popstate (wp-includes/js/dist/script-modules/interactivity).
        history.replaceState({ ...history.state, wpInteractivityId: 'wp-session-7' }, '');
        const reloads: unknown[] = [];
        const interactivity = (event: PopStateEvent) => {
            if (event.state !== null && event.state?.wpInteractivityId !== 'wp-session-7') reloads.push(event.state);
        };
        window.addEventListener('popstate', interactivity);
        try {
            app = startLearnerNavigation();
            await go('/learning');
            expect(history.state).toMatchObject({ source: 'swup', wpInteractivityId: 'wp-session-7' });
            await go('/catalogue?q=video');
            expect(history.state).toMatchObject({ source: 'swup', wpInteractivityId: 'wp-session-7' });
            history.back();
            await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent('/learning'));
            history.forward();
            await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent('/catalogue?q=video'));
            history.go(-2);
            await waitFor(() => expect(location.pathname).toBe('/catalogue'));
            await waitFor(() => expect(document.querySelector('h1')).toHaveTextContent(/^\/catalogue$/));
            expect(reloads).toEqual([]);
            expect(fetchPage).toHaveBeenCalledTimes(5);
        } finally {
            window.removeEventListener('popstate', interactivity);
        }
    });
    it('adds no key to history on a host without one', async () => {
        app = startLearnerNavigation();
        await go('/learning');
        expect(Object.keys(history.state as object).sort()).toEqual(['index', 'random', 'source', 'url']);
    });
});
