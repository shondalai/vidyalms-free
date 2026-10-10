import { translate as uiText } from '@/shared/i18n';
import Swup from 'swup';
import SwupFormsPlugin from '@swup/forms-plugin';
import SwupScrollPlugin from '@swup/scroll-plugin';
import { createLearnerAssetLoader } from './assets';
import { showPageSkeleton } from './loading';
import { observeLearnerColorScheme } from '../shared/learner-color-scheme';

const SHELL = '[data-vy-learner-shell]';
const GET_FORM = `${SHELL} form[data-vy-page-form][method=get]`;
/*
 * History keys a host script stamps on the entries of this document. A
 * WordPress block theme loads @wordpress/interactivity, which reloads the
 * page on popstate whenever the entry's wpInteractivityId is not the one it
 * stamped on load, and swup's pushState entries carried none, so browser Back
 * and Forward to any entry swup had created reloaded the whole document. The
 * value names the document, not the entry, so each entry swup adds carries
 * the value of the entry it leaves.
 */
const DOCUMENT_HISTORY_KEYS = ['wpInteractivityId'] as const;

/**
 * Joomla's account pages: sign-in, registration, password reset, profile and
 * the MFA steps, reached through the component query or its SEF path. They
 * render outside the learner shell, so fetching one only to fall back to a
 * native load costs a request and logs a failed visit. A sign-in link on a
 * menu alias is still caught by that fallback.
 */
function isHostAccountPage(url: URL): boolean {
    return url.searchParams.get('option') === 'com_users' || /\/component\/users(?:\/|$)/.test(url.pathname);
}

export function isLearnerLink(link: HTMLAnchorElement): boolean {
    if (!link.closest(SHELL) || link.closest('[data-no-swup]') || link.hasAttribute('download')) return false;
    if (link.target && link.target !== '_self') return false;
    const url = new URL(link.href, location.href);
    if (url.origin !== location.origin || !['https:', 'http:'].includes(url.protocol)) return false;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return false;
    // Downloads, API operations and authentication retain their native browser behavior.
    return !url.searchParams.has('task') && !/\.(?:pdf|zip|csv|docx?|xlsx?|mp[34])$/i.test(url.pathname)
        && !/\/(?:wp-json|wp-admin|administrator|wp-login\.php)(?:\/|$)/.test(url.pathname)
        && !isHostAccountPage(url);
}

/** Server-backed SPA navigation: fresh access checks and data; no client page cache. */
export function startLearnerNavigation(): Swup | undefined {
    const initial = document.querySelector<HTMLElement>(SHELL);
    if (!initial) return;
    const site = initial.dataset.vySiteKey;
    let stopTheme = observeLearnerColorScheme(initial);
    const assets = createLearnerAssetLoader();
    let clearLoading = () => {};
    const loading = (url: string, trigger?: Element) => {
        clearLoading();
        const root = document.querySelector<HTMLElement>(SHELL);
        clearLoading = root ? showPageSkeleton(root, url, trigger) : () => {};
    };
    const ready = () => {
        clearLoading();
        clearLoading = () => {};
    };
    const controller = new AbortController();
    const { signal } = controller;
    const drafts = new Map<HTMLFormElement, string>();
    const valueOf = (form: HTMLFormElement) => JSON.stringify([...new FormData(form).entries()]);
    const rememberForms = () => {
        drafts.clear();
        document.querySelectorAll<HTMLFormElement>(`${SHELL} form[data-vy-draft-form]`).forEach((form) => drafts.set(form, valueOf(form)));
    };
    const dirty = () => [...drafts].some(([form, value]) => form.isConnected && valueOf(form) !== value);
    rememberForms();
    window.addEventListener('vidyalms:check-drafts', (event) => {
        if (dirty()) event.preventDefault();
    }, { signal });
    window.addEventListener('beforeunload', (event) => {
        if (dirty()) {
            event.preventDefault();
            event.returnValue = '';
        }
    }, { signal });
    const confirmLeave = () => {
        const clean = window.dispatchEvent(new Event('vidyalms:check-drafts', { cancelable: true }));
        const message = document.querySelector<HTMLElement>(SHELL)?.dataset.vyLeaveConfirm || 'Leave this page with unsaved changes?';
        return clean || window.confirm(message);
    };
    // Capture before Swup's delegated handlers; cancellation must not change the URL.
    document.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest<HTMLAnchorElement>('a[href]') : null;
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (link && isLearnerLink(link) && !confirmLeave()) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, { capture: true, signal });
    document.addEventListener('submit', (event) => {
        if (event.target instanceof HTMLFormElement && event.target.matches(GET_FORM) && !confirmLeave()) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
        // A native save is intentional; don't ask the learner to discard that very save.
        if (event.target instanceof HTMLFormElement && !event.defaultPrevented && !event.target.matches(GET_FORM)) {
            drafts.delete(event.target);
        }
    }, { capture: true, signal });
    let restoringHistory = false;
    let currentIndex = (history.state as { index?: number } | null)?.index ?? 1;
    window.addEventListener('popstate', (event) => {
        if (restoringHistory) {
            restoringHistory = false;
            event.stopImmediatePropagation();
            return;
        }
        const index = (event.state as { index?: number; source?: string } | null)?.index;
        if (event.state?.source !== 'swup' || index === undefined || index === currentIndex) return;
        if (!confirmLeave()) {
            event.stopImmediatePropagation();
            restoringHistory = true;
            history.go(currentIndex - index);
        } else {
            currentIndex = index;
        }
    }, { capture: true, signal });
    const swup = new Swup({
        containers: [SHELL],
        linkSelector: `${SHELL} a[href]`,
        cache: false,
        animationSelector: false,
        animationScope: 'containers',
        timeout: 15000,
        plugins: [
            new SwupFormsPlugin({ formSelector: GET_FORM }),
            new SwupScrollPlugin({ animateScroll: { betweenPages: false, samePageWithHash: false, samePage: false } }),
        ],
        ignoreVisit: (_url, { el } = {}) => el instanceof HTMLAnchorElement && !isLearnerLink(el),
    });
    let carried: Record<string, unknown> = {};
    const rememberDocumentKeys = () => {
        const state = history.state as Record<string, unknown> | null;
        for (const key of DOCUMENT_HISTORY_KEYS) {
            if (state !== null && typeof state === 'object' && state[key] !== undefined) carried = { ...carried, [key]: state[key] };
        }
    };
    swup.hooks.on('visit:start', (visit) => {
        if (!visit.history.popstate && visit.history.action !== 'replace' && visit.to.url !== swup.location.url) currentIndex++;
        visit.cache = { read: false, write: false };
        // Read from the entry being left, before swup pushes the next one.
        rememberDocumentKeys();
        loading(visit.to.url, visit.trigger.el);
    });
    // Swup has pushed (or replaced) the entry by now; give it the document keys it did not copy.
    swup.hooks.before('visit:transition', () => {
        const state = history.state as Record<string, unknown> | null;
        if (state === null || typeof state !== 'object' || state.source !== 'swup') return;
        const missing = Object.entries(carried).filter(([key]) => state[key] === undefined);
        if (missing.length > 0) history.replaceState({ ...state, ...Object.fromEntries(missing) }, '');
    });
    swup.hooks.before('fetch:request', (_visit, { options }) => {
        // Also bypass the browser HTTP cache for personalized HTML and fresh tokens.
        Object.assign(options, { cache: 'no-store', credentials: 'same-origin' });
    });
    swup.hooks.replace('content:replace', async (visit, args, replace) => {
        if (!replace) throw new Error(uiText("vidyalms.ui.876e70c080287a54", "The page transition handler is unavailable."));
        const page = visit.to.document;
        if (!page?.querySelector(SHELL) || page.querySelector<HTMLElement>(SHELL)?.dataset.vySiteKey !== site) {
            /*
             * Not a learner page of this site: a sign-in form on a menu alias,
             * a host page, another site's shell. That is an expected answer,
             * not a failure, so the browser loads it itself. Swup hands an
             * ignored visit to its native navigation without the console.error
             * it logs for a failed one, and nothing has unmounted yet.
             */
            ready();
            visit.ignore();
            throw new Error(uiText("vidyalms.ui.cd8c1cc9a1e10ab9", "Native page navigation required."));
        }
        await assets.styles(page, new URL(visit.to.url, location.href).href);
        window.dispatchEvent(new Event('vidyalms:learner:unmount'));
        stopTheme();
        await replace(visit, args);
        const root = document.querySelector<HTMLElement>(SHELL)!;
        stopTheme = observeLearnerColorScheme(root);
        root.dataset.vyMountPending = 'true';
        loading(visit.to.url, visit.trigger.el);
        await assets.scripts(visit.to.document!, new URL(visit.to.url, location.href).href);
        delete root.dataset.vyMountPending;
        window.dispatchEvent(new Event('vidyalms:learner:mount'));
    });
    swup.hooks.on('page:view', () => {
        ready();
        rememberForms();
        document.querySelector<HTMLElement>(`${SHELL} .vy-learning-content`)?.focus({ preventScroll: true });
    });
    swup.hooks.on('visit:end', ready);
    swup.hooks.on('visit:fail', ready);
    swup.hooks.on('visit:abort', ready);
    const destroy = swup.destroy.bind(swup);
    swup.destroy = async () => {
        ready();
        controller.abort();
        stopTheme();
        await destroy();
    };
    return swup;
}
