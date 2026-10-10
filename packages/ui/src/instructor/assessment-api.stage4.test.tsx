import { act, renderHook, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import type { QuestionBank } from '@vidyalms/api-sdk';
import {
    useBulkUpdateQuestionBankEntries,
    useCreateQuestionBank,
    useCreateQuestionBankEntry,
    useCreateQuestionBankEntryVersion,
    useQuestionBankEntries,
    useQuestionBanks,
} from './assessment-api';
import { makeBankEntry, makeBankVersion } from './testing/assessment-fixtures';
import { jsonResponse, makeHarness, type RecordedRequest } from './testing/harness';

function bank(overrides: Partial<QuestionBank> = {}): QuestionBank {
    return {
        id: 11,
        owner_scope: 'course',
        owner_id: 7,
        name: 'Accessibility library',
        description: null,
        state: 'active',
        created_by: 1,
        created_at: '2026-09-01 09:00:00',
        updated_at: '2026-09-02 09:00:00',
        question_count: 100_000,
        folder_count: 8,
        ...overrides,
    };
}

function route(request: RecordedRequest): Response | null {
    const url = new URL(request.path, 'http://localhost');
    if (request.method === 'GET' && url.pathname === '/v1/manage/banks') {
        return jsonResponse({ items: [bank()], total: 100_000, page: 3, per_page: 20 });
    }
    if (request.method === 'POST' && url.pathname === '/v1/manage/banks') {
        return jsonResponse(bank({ owner_scope: 'site', owner_id: 0, name: 'New bank' }));
    }
    if (request.method === 'GET' && url.pathname === '/v1/manage/banks/11/entries') {
        return jsonResponse({ items: [makeBankEntry()], total: 100_000, page: 5, per_page: 20 });
    }
    if (request.method === 'POST' && url.pathname === '/v1/manage/banks/11/entries') {
        return jsonResponse(makeBankEntry({ id: 302 }));
    }
    if (request.method === 'PATCH' && url.pathname === '/v1/manage/banks/11/entries') {
        return jsonResponse({ items: [makeBankEntry({ status: 'archived' })], updated: 1 });
    }
    if (request.method === 'POST' && url.pathname === '/v1/manage/banks/11/entries/301/versions') {
        return jsonResponse(makeBankVersion({ version: 3 }));
    }
    return null;
}

describe('named question-bank hooks', () => {
    it('passes every bank ownership, search, sort and paging filter to the bounded list', async () => {
        const harness = makeHarness(route);
        const { result } = renderHook(() => useQuestionBanks({
            q: 'leadership',
            state: 'active',
            owner_scope: 'course',
            owner_id: 7,
            sort: 'created_at',
            order: 'asc',
            page: 3,
            per_page: 20,
        }), { wrapper: harness.wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        const request = harness.requests.find((candidate) => candidate.method === 'GET');
        const query = new URL(request?.path ?? '', 'http://localhost').searchParams;
        expect(Object.fromEntries(query)).toMatchObject({
            q: 'leadership', state: 'active', owner_scope: 'course', owner_id: '7',
            sort: 'created_at', order: 'asc', page: '3', per_page: '20',
        });
        expect(result.current.data?.total).toBe(100_000);
        expect(result.current.data?.items).toHaveLength(1);
    });

    it('keeps category, author, last-used and usage filters on the nested question page request', async () => {
        const harness = makeHarness(route);
        const { result } = renderHook(() => useQuestionBankEntries(11, {
            q: 'wcag', category_id: 41, include_subcategories: true, status: 'ready',
            type: 'single_choice', tag: 'a11y', objective_id: 9, difficulty: 'medium',
            usage: 'used', created_by: 27, last_used_before: '2026-09-01T00:00:00.000Z',
            sort: 'usage_count', order: 'desc', page: 5, per_page: 20,
        }), { wrapper: harness.wrapper });

        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        const request = harness.requests.find((candidate) => candidate.path.includes('/entries?'));
        const query = new URL(request?.path ?? '', 'http://localhost').searchParams;
        expect(Object.fromEntries(query)).toMatchObject({
            q: 'wcag', category_id: '41', include_subcategories: 'true', status: 'ready',
            type: 'single_choice', tag: 'a11y', objective_id: '9', difficulty: 'medium',
            usage: 'used', created_by: '27', last_used_before: '2026-09-01T00:00:00.000Z',
            sort: 'usage_count', order: 'desc', page: '5', per_page: '20',
        });
        expect(result.current.data?.items).toHaveLength(1);
        expect(result.current.data?.total).toBe(100_000);
    });

    it('creates a bank, question and immutable version on their nested routes', async () => {
        const harness = makeHarness(route);
        const bankHook = renderHook(() => useCreateQuestionBank(), { wrapper: harness.wrapper });
        const entryHook = renderHook(() => useCreateQuestionBankEntry(), { wrapper: harness.wrapper });
        const versionHook = renderHook(() => useCreateQuestionBankEntryVersion(), { wrapper: harness.wrapper });

        await act(async () => {
            await bankHook.result.current.mutateAsync({ body: { owner_scope: 'site', name: 'New bank' }, idempotencyKey: 'bank-key' });
            await entryHook.result.current.mutateAsync({
                bankId: 11,
                body: { type: 'single_choice', definition: { prompt: 'One?', options: [], correct: '' } },
                idempotencyKey: 'entry-key',
            });
            await versionHook.result.current.mutateAsync({
                bankId: 11, entryId: 301,
                body: { definition: { prompt: 'Two?', options: [], correct: '' }, points: 2, scored: true, status: 'ready' },
                idempotencyKey: 'version-key',
            });
        });

        expect(harness.requests.map((request) => `${request.method} ${new URL(request.path, 'http://localhost').pathname}`)).toEqual([
            'POST /v1/manage/banks',
            'POST /v1/manage/banks/11/entries',
            'POST /v1/manage/banks/11/entries/301/versions',
        ]);
        expect(harness.requests.map((request) => request.headers['idempotency-key'])).toEqual(['bank-key', 'entry-key', 'version-key']);
        expect(harness.requests[2]?.body).toMatchObject({ status: 'ready' });
    });

    it('bulk-updates only the explicit ids in the request', async () => {
        const harness = makeHarness(route);
        const { result } = renderHook(() => useBulkUpdateQuestionBankEntries(), { wrapper: harness.wrapper });

        await act(async () => {
            const answer = await result.current.mutateAsync({
                bankId: 11,
                body: { entry_ids: [301], status: 'archived', add_tags: ['reviewed'] },
                idempotencyKey: 'bulk-key',
            });
            expect(answer.updated).toBe(1);
        });

        expect(harness.requests).toHaveLength(1);
        expect(harness.requests[0]).toMatchObject({
            method: 'PATCH',
            body: { entry_ids: [301], status: 'archived', add_tags: ['reviewed'] },
        });
        expect(harness.requests[0]?.path).toBe('/v1/manage/banks/11/entries');
    });
});
