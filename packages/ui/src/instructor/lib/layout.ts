import { useCallback, useEffect, useState, type RefObject } from 'react';

/*
 * The workbench is embedded in administrator chrome that owns the page
 * width: a Joomla sidebar, a WordPress admin menu, either of them
 * collapsible. So every layout decision is made from the MOUNT width, never
 * from the viewport, exactly as the player does.
 */

/** Below this mount width the workbench stacks (artboard 1a). */
export const NARROW_BELOW_PX = 900;

export function useContainerWidth(ref: RefObject<HTMLElement | null>, ready = true): number {
    const [width, setWidth] = useState(Number.POSITIVE_INFINITY);
    useEffect(() => {
        const el = ref.current;
        if (!ready || el === null || typeof ResizeObserver === 'undefined') {
            return;
        }
        const observer = new ResizeObserver((entries) => {
            const rect = entries[0]?.contentRect;
            if (rect !== undefined && rect.width > 0) {
                setWidth(rect.width);
            }
        });
        observer.observe(el);
        return () => observer.disconnect();
    }, [ref, ready]);
    return width;
}

/**
 * The current tab, kept in the location hash so the host's submenu can deep
 * link into one and a reload lands back where the person was. Hash routing,
 * not path routing, because the host's own query string owns the URL of an
 * administrator page on both platforms.
 *
 * The hash is namespaced (#vy/<tab>): a hash that belongs to the host page
 * is left alone and simply reads as "no tab chosen".
 */
const HASH_PREFIX = '#vy/';

export function tabFromHash<T extends string>(hash: string, allowed: readonly T[], fallback: T): T {
    hash = hash.replace(/^#\/course\/\d+\/vy\//, '#vy/');
    if (!hash.startsWith(HASH_PREFIX)) {
        return fallback;
    }
    const candidate = hash.slice(HASH_PREFIX.length).split('/')[0] ?? '';
    return allowed.find((tab) => tab === candidate) ?? fallback;
}

/** `allowed` must be a stable array (a module constant), not a fresh literal per render. */
export function useHashTab<T extends string>(allowed: readonly T[], fallback: T): [T, (tab: T) => void] {
    const [tab, setTab] = useState<T>(() => tabFromHash(window.location.hash, allowed, fallback));

    useEffect(() => {
        const onHashChange = () => setTab(tabFromHash(window.location.hash, allowed, fallback));
        window.addEventListener('hashchange', onHashChange);
        return () => window.removeEventListener('hashchange', onHashChange);
    }, [allowed, fallback]);

    const select = useCallback((next: T) => {
        setTab(next);
        const course = /^#\/course\/(\d+)/.exec(window.location.hash);
        const prefix = course === null ? HASH_PREFIX : `#/course/${course[1]}/vy/`;
        if (window.location.hash !== `${prefix}${next}`) {
            window.history.replaceState(window.history.state, '', `${prefix}${next}`);
        }
    }, []);

    return [tab, select];
}
