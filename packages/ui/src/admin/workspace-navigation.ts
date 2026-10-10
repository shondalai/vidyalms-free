import type { AdminConfig } from './config';

/** Match only destinations published by this CMS mount; never infer admin paths. */
export function screenAtUrl(config: AdminConfig, href: string): string | null {
    const target = new URL(href, window.location.href);
    for (const [key, value] of Object.entries(config.urls.screens)) {
        const candidate = new URL(value, window.location.href);
        const query = new URLSearchParams(target.search);
        const expected = new URLSearchParams(candidate.search);
        query.sort();
        expected.sort();
        if (target.origin === candidate.origin && target.pathname === candidate.pathname && query.toString() === expected.toString()) return key;
    }
    return null;
}

export function courseIdAtHash(hash: string): number | null {
    const match = /^\/course\/([1-9]\d*)(?:\/vy\/[a-z-]+)?$/.exec(hash);
    const id = match === null ? 0 : Number(match[1]);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

export const WORKSPACE_NAVIGATION = 'vidyalms:workspace-navigation';

export function commitWorkspaceUrl(href: string): void {
    window.history.pushState(window.history.state, '', href);
    window.dispatchEvent(new Event(WORKSPACE_NAVIGATION));
    window.dispatchEvent(new HashChangeEvent('hashchange'));
}

export function subscribeWorkspaceUrl(listener: () => void): () => void {
    window.addEventListener('popstate', listener);
    window.addEventListener('hashchange', listener);
    window.addEventListener(WORKSPACE_NAVIGATION, listener);
    return () => {
        window.removeEventListener('popstate', listener);
        window.removeEventListener('hashchange', listener);
        window.removeEventListener(WORKSPACE_NAVIGATION, listener);
    };
}
