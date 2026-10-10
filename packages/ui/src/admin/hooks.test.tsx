import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useListState, type ListStateOptions } from './hooks';

const OPTIONS = {
    persistence: 'hash-query',
    allowedFilters: ['all', 'draft', 'published'],
    allowedSortKeys: ['title', 'enrolled_count'],
} as const satisfies ListStateOptions;

function queryInHash(): URLSearchParams {
    const hash = window.location.hash.replace(/^#\/?\??/, '');
    return new URLSearchParams(hash);
}

beforeEach(() => {
    window.location.hash = '';
});

describe('useListState', () => {
    it('initialises persisted state from the hash query', () => {
        window.location.hash = '#/?search=leadership&filter=draft&sort=enrolled_count&order=desc&page=3';

        const { result } = renderHook(() =>
            useListState('all', { key: 'title', order: 'asc' }, OPTIONS),
        );

        expect(result.current.search).toBe('leadership');
        expect(result.current.filter).toBe('draft');
        expect(result.current.sort).toEqual({ key: 'enrolled_count', order: 'desc' });
        expect(result.current.page).toBe(3);
    });

    it('ignores unknown or malformed bookmarked values', () => {
        window.location.hash =
            '#/?search=safe&filter=removed-state&sort=unknown-column&order=sideways&page=-4&future=value';

        const { result } = renderHook(() =>
            useListState('all', { key: 'title', order: 'asc' }, OPTIONS),
        );

        expect(result.current).toMatchObject({
            search: 'safe',
            filter: 'all',
            sort: { key: 'title', order: 'asc' },
            page: 1,
        });
    });

    it('writes control changes to the hash, omits defaults and resets the page', () => {
        const { result } = renderHook(() =>
            useListState('all', { key: 'title', order: 'asc' }, OPTIONS),
        );

        act(() => result.current.setPage(4));
        expect(queryInHash().get('page')).toBe('4');
        expect(queryInHash().has('filter')).toBe(false);
        expect(queryInHash().has('sort')).toBe(false);

        act(() => result.current.setFilter('draft'));
        expect(result.current.page).toBe(1);
        expect(queryInHash().get('filter')).toBe('draft');
        expect(queryInHash().has('page')).toBe(false);

        act(() => result.current.setSearch('inclusive teams'));
        expect(queryInHash().get('search')).toBe('inclusive teams');

        act(() => result.current.setSort({ key: 'enrolled_count', order: 'desc' }));
        expect(queryInHash().get('sort')).toBe('enrolled_count');
        expect(queryInHash().get('order')).toBe('desc');

        act(() => result.current.setSearch(''));
        act(() => result.current.setFilter('all'));
        act(() => result.current.setSort({ key: 'title', order: 'asc' }));
        expect(window.location.hash).toBe('#/');
        expect(queryInHash().toString()).toBe('');
    });

    it('replaces search edits so Back does not visit every typed character', () => {
        const replace = vi.spyOn(window.history, 'replaceState');
        const { result } = renderHook(() =>
            useListState('all', { key: 'title', order: 'asc' }, OPTIONS),
        );

        act(() => result.current.setSearch('l'));
        act(() => result.current.setSearch('le'));
        act(() => result.current.setSearch('lead'));

        expect(replace).toHaveBeenCalledTimes(3);
        expect(queryInHash().get('search')).toBe('lead');
    });

    it('follows back and forward state when browser history emits popstate', () => {
        const { result } = renderHook(() =>
            useListState('all', { key: 'title', order: 'asc' }, OPTIONS),
        );

        act(() => {
            window.history.replaceState(null, '', '#/?filter=draft&page=2');
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        expect(result.current.filter).toBe('draft');
        expect(result.current.page).toBe(2);

        act(() => {
            window.history.replaceState(null, '', '#/?search=forward&filter=published&order=desc');
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        expect(result.current).toMatchObject({
            search: 'forward',
            filter: 'published',
            sort: { key: 'title', order: 'desc' },
            page: 1,
        });
    });

    it('never reads or overwrites a nested resource hash', () => {
        window.location.hash = '#/learner/482';
        const { result } = renderHook(() =>
            useListState('all', { key: 'title', order: 'asc' }, OPTIONS),
        );

        expect(result.current).toMatchObject({ filter: 'all', search: '', page: 1 });
        act(() => result.current.setPage(4));
        act(() => result.current.setFilter('draft'));
        act(() => result.current.setSearch('Ada'));

        expect(result.current).toMatchObject({ filter: 'draft', search: 'Ada', page: 1 });
        expect(window.location.hash).toBe('#/learner/482');
    });

    it('keeps the original local-only behaviour when persistence is omitted', () => {
        window.location.hash = '#/?filter=draft&page=4';
        const { result } = renderHook(() =>
            useListState('all', { key: 'title', order: 'asc' }),
        );

        expect(result.current).toMatchObject({ filter: 'all', page: 1 });
        act(() => result.current.setPage(3));
        act(() => result.current.setFilter('anything'));
        expect(result.current).toMatchObject({ filter: 'anything', page: 1 });
        expect(window.location.hash).toBe('#/?filter=draft&page=4');
    });
});
