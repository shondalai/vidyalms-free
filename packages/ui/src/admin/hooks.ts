import { useCallback, useEffect, useRef, useState, useSyncExternalStore } from 'react';
import type { DataTableSort } from '@/shared/components';

/*
 * The two pieces of state every admin screen has: where a list is (filter,
 * search, sort, page) and where the screen is (the hash sub-route).
 *
 * Hash routing, not path routing: on both hosts the administrator page URL
 * belongs to the CMS, so the hash is the only part this app may own
 * (05-frontend-strategy.md). The host's menu decides WHICH screen renders;
 * the hash decides where inside it the reader is.
 */

const hashListeners = new Set<() => void>();

function currentHash(): string {
    return typeof window === 'undefined' ? '' : window.location.hash.replace(/^#/, '');
}

function subscribeHash(listener: () => void): () => void {
    hashListeners.add(listener);
    window.addEventListener('hashchange', listener);
    /* Browsers fire popstate while travelling through session history. Most
       also fire hashchange for a changed fragment, but listening to both
       keeps list state correct in embedded CMS webviews too. */
    window.addEventListener('popstate', listener);
    return () => {
        hashListeners.delete(listener);
        window.removeEventListener('hashchange', listener);
        window.removeEventListener('popstate', listener);
    };
}

function notifyHashListeners(): void {
    for (const listener of [...hashListeners]) {
        listener();
    }
}

/**
 * The hash without its "#", and a setter. The setter notifies subscribers
 * itself rather than trusting the environment to fire hashchange for a
 * scripted assignment, which is also what makes it deterministic in tests.
 */
export function useHashRoute(): [string, (next: string) => void] {
    const hash = useSyncExternalStore(subscribeHash, currentHash, () => '');
    const navigate = useCallback((next: string) => {
        window.location.hash = next;
        notifyHashListeners();
    }, []);
    return [hash, navigate];
}

/** "/learner/482" gives 482; anything else gives null. */
export function learnerIdFromHash(hash: string): number | null {
    const match = /^\/learner\/(\d+)(?:\?[^#]*)?$/.exec(hash);
    if (match === null) {
        return null;
    }
    const id = Number(match[1]);
    return Number.isSafeInteger(id) && id > 0 ? id : null;
}

/** Delays a value so typing in a search box is not one request per key. */
export function useDebounced<T>(value: T, delay = 300): T {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delay);
        return () => window.clearTimeout(timer);
    }, [value, delay]);
    return debounced;
}

export interface ListState {
    filter: string;
    search: string;
    sort: DataTableSort;
    page: number;
    setFilter: (filter: string) => void;
    setSearch: (search: string) => void;
    setSort: (sort: DataTableSort) => void;
    setPage: (page: number) => void;
}

/**
 * The CMS owns the real query string, so persisted list controls live after
 * `?` in the hash (`#/?filter=draft&page=2`). Callers provide their finite
 * filter and sort vocabularies: bookmarked values from another/newer screen
 * are then ignored instead of reaching an API unchecked.
 */
export type ListStateOptions =
    | {
          persistence: 'hash-query';
          allowedFilters: readonly string[];
          allowedSortKeys: readonly string[];
      }
    | {
          persistence?: undefined;
          allowedFilters?: never;
          allowedSortKeys?: never;
      };

interface ListStateValues {
    filter: string;
    search: string;
    sort: DataTableSort;
    page: number;
}

interface ListStateSettings {
    persist: boolean;
    defaults: ListStateValues;
    allowedFilters: ReadonlySet<string>;
    allowedSortKeys: ReadonlySet<string>;
}

function listStateSettings(
    initialFilter: string,
    initialSort: DataTableSort,
    options: ListStateOptions,
): ListStateSettings {
    const persist = options.persistence === 'hash-query';
    return {
        persist,
        defaults: {
            filter: initialFilter,
            search: '',
            sort: initialSort,
            page: 1,
        },
        allowedFilters: new Set(persist ? [initialFilter, ...options.allowedFilters] : [initialFilter]),
        allowedSortKeys: new Set(persist ? [initialSort.key, ...options.allowedSortKeys] : [initialSort.key]),
    };
}

/** Empty and `#/` are list routes; every other hash path belongs to a nested resource. */
function listHashQuery(hash: string): URLSearchParams | null {
    if (hash === '' || hash === '/') {
        return new URLSearchParams();
    }
    if (!hash.startsWith('/?')) {
        return null;
    }
    return new URLSearchParams(hash.slice(2));
}

function safePage(raw: string | null): number {
    if (raw === null || !/^[1-9]\d*$/.test(raw)) {
        return 1;
    }
    const page = Number(raw);
    return Number.isSafeInteger(page) ? page : 1;
}

function valuesFromHash(hash: string, settings: ListStateSettings): ListStateValues | null {
    const query = listHashQuery(hash);
    if (query === null) {
        return null;
    }

    const filter = query.get('filter');
    const sortKey = query.get('sort');
    const order = query.get('order');
    return {
        filter: filter !== null && settings.allowedFilters.has(filter) ? filter : settings.defaults.filter,
        search: query.get('search') ?? settings.defaults.search,
        sort: {
            key:
                sortKey !== null && settings.allowedSortKeys.has(sortKey)
                    ? sortKey
                    : settings.defaults.sort.key,
            order: order === 'asc' || order === 'desc' ? order : settings.defaults.sort.order,
        },
        page: safePage(query.get('page')),
    };
}

function valuesEqual(left: ListStateValues, right: ListStateValues): boolean {
    return (
        left.filter === right.filter &&
        left.search === right.search &&
        left.sort.key === right.sort.key &&
        left.sort.order === right.sort.order &&
        left.page === right.page
    );
}

function hashForValues(values: ListStateValues, defaults: ListStateValues): string {
    const query = new URLSearchParams();
    if (values.search !== defaults.search) {
        query.set('search', values.search);
    }
    if (values.filter !== defaults.filter) {
        query.set('filter', values.filter);
    }
    if (values.sort.key !== defaults.sort.key) {
        query.set('sort', values.sort.key);
    }
    if (values.sort.order !== defaults.sort.order) {
        query.set('order', values.sort.order);
    }
    if (values.page !== defaults.page) {
        query.set('page', String(values.page));
    }
    const encoded = query.toString();
    return encoded === '' ? '/' : `/?${encoded}`;
}

function writeListHash(values: ListStateValues, settings: ListStateSettings, replace = false): void {
    if (typeof window === 'undefined') {
        return;
    }

    const current = valuesFromHash(currentHash(), settings);
    /* A resource route such as #/learner/482 belongs to the screen router.
       A mounted list must never erase it, even if one of its setters runs. */
    if (current === null || valuesEqual(current, values)) {
        return;
    }

    const hash = hashForValues(values, settings.defaults);
    if (replace) {
        const url = new URL(window.location.href);
        url.hash = hash;
        window.history.replaceState(window.history.state, '', url);
    } else {
        window.location.hash = hash;
    }
    /* A scripted fragment assignment is not guaranteed to synchronously
       emit hashchange in every host or test environment. */
    notifyHashListeners();
}

/**
 * Filter, search, sort and page for one list. Every change but paging
 * returns to page one, because page 4 of a different result set is a
 * different thing and usually an empty one.
 */
export function useListState(
    initialFilter: string,
    initialSort: DataTableSort,
    options: ListStateOptions = {},
): ListState {
    const settings = listStateSettings(initialFilter, initialSort, options);
    const settingsRef = useRef(settings);
    settingsRef.current = settings;

    const [values, setValues] = useState<ListStateValues>(() => {
        if (!settings.persist) {
            return settings.defaults;
        }
        return valuesFromHash(currentHash(), settings) ?? settings.defaults;
    });
    const valuesRef = useRef(values);
    valuesRef.current = values;

    /* Back, forward and an adjacent hash router all arrive through the same
       subscription. Nested routes deliberately leave the remembered list
       state alone until history returns to a list URL. */
    useEffect(() => {
        if (!settings.persist) {
            return undefined;
        }
        const syncFromHash = () => {
            const next = valuesFromHash(currentHash(), settingsRef.current);
            if (next === null) {
                return;
            }
            valuesRef.current = next;
            setValues((current) => (valuesEqual(current, next) ? current : next));
        };
        const unsubscribe = subscribeHash(syncFromHash);
        syncFromHash();
        return unsubscribe;
    }, [settings.persist]);

    const commit = useCallback((next: ListStateValues, replace = false) => {
        valuesRef.current = next;
        setValues(next);
        if (settingsRef.current.persist) {
            writeListHash(next, settingsRef.current, replace);
        }
    }, []);

    const setFilter = useCallback((next: string) => {
        const currentSettings = settingsRef.current;
        const filter =
            !currentSettings.persist || currentSettings.allowedFilters.has(next)
                ? next
                : currentSettings.defaults.filter;
        commit({ ...valuesRef.current, filter, page: 1 });
    }, [commit]);
    const setSearch = useCallback((next: string) => {
        /* Typing may produce many intermediate values. Keep the address
           bookmarkable without turning every keystroke into a Back stop. */
        commit({ ...valuesRef.current, search: next, page: 1 }, true);
    }, [commit]);
    const setSort = useCallback((next: DataTableSort) => {
        const currentSettings = settingsRef.current;
        const validOrder = next.order === 'asc' || next.order === 'desc';
        const sort =
            !currentSettings.persist || (currentSettings.allowedSortKeys.has(next.key) && validOrder)
                ? next
                : currentSettings.defaults.sort;
        commit({ ...valuesRef.current, sort, page: 1 });
    }, [commit]);
    const setPage = useCallback((next: number) => {
        const page = settingsRef.current.persist && (!Number.isSafeInteger(next) || next < 1) ? 1 : next;
        commit({ ...valuesRef.current, page });
    }, [commit]);

    return { ...values, setFilter, setSearch, setSort, setPage };
}
