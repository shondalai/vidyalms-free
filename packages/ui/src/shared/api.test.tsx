import { describe, expect, it } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createVidyaLmsClient, ProblemError, type FetchLike } from '@vidyalms/api-sdk';
import type { ReactNode } from 'react';
import { ApiClientContext } from './context';
import { queryKeys, useCourse, useHeartbeat, useSaveAnswers } from './api';

const courseDetail = {
    id: 7,
    title: 'Intro to PHP',
    slug: 'intro-to-php',
    summary: null,
    image: null,
    category_id: null,
    level: null,
    language: null,
    price_mode: 'free',
    featured: false,
    published_at: '2026-01-15 10:00:00',
    description: null,
    visibility: 'public',
    state: 'published',
    completion_rule: 'all_lessons',
    completion_threshold: null,
    price: null,
    purchase_url: null,
};

function harness(handler: (request: Request) => Response | Promise<Response>) {
    const calls: Request[] = [];
    const fetchLike: FetchLike = async (request) => {
        calls.push(request);
        return handler(request);
    };
    const client = createVidyaLmsClient({ base: '/v1', mode: 'prefix', headers: {} }, fetchLike);
    const queryClient = new QueryClient({
        defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const wrapper = ({ children }: { children: ReactNode }) => (
        <ApiClientContext.Provider value={client}>
            <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
        </ApiClientContext.Provider>
    );
    return { calls, wrapper };
}

describe('queryKeys', () => {
    it('nests every key under the one root so a single invalidate reaches all', () => {
        expect(queryKeys.course('a')[0]).toBe(queryKeys.root[0]);
        expect(queryKeys.curriculum(1)[0]).toBe(queryKeys.root[0]);
        expect(queryKeys.lesson(2)[0]).toBe(queryKeys.root[0]);
        expect(queryKeys.myProgress(1)[0]).toBe(queryKeys.root[0]);
        expect(queryKeys.attempt(3)[0]).toBe(queryKeys.root[0]);
    });
});

describe('useCourse', () => {
    it('fetches through the SDK transport and returns typed data', async () => {
        const { calls, wrapper } = harness(() => Response.json(courseDetail));
        const { result } = renderHook(() => useCourse('intro-to-php'), { wrapper });
        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(result.current.data?.title).toBe('Intro to PHP');
        expect(calls[0]?.url).toContain('/v1/courses/intro-to-php');
    });

    it('surfaces a ProblemError the UI can branch on', async () => {
        const { wrapper } = harness(
            () =>
                new Response(
                    JSON.stringify({ type: 'about:blank', title: 'Not found', status: 404 }),
                    { status: 404, headers: { 'Content-Type': 'application/problem+json' } },
                ),
        );
        const { result } = renderHook(() => useCourse('missing'), { wrapper });
        await waitFor(() => expect(result.current.isError).toBe(true));
        expect(result.current.error).toBeInstanceOf(ProblemError);
        expect((result.current.error as ProblemError).status).toBe(404);
    });
});

describe('mutations', () => {
    it('saves answers as an upsert PUT and resolves on 204', async () => {
        const { calls, wrapper } = harness(() => new Response(null, { status: 204 }));
        const { result } = renderHook(() => useSaveAnswers(), { wrapper });
        result.current.mutate({ attemptId: 9, answers: { '12': 'b' } });
        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(calls[0]?.method).toBe('PUT');
        expect(calls[0]?.url).toContain('/v1/attempts/9/answers');
    });

    it('posts a heartbeat and stays quiet on success', async () => {
        const { calls, wrapper } = harness(() => new Response(null, { status: 204 }));
        const { result } = renderHook(() => useHeartbeat(), { wrapper });
        result.current.mutate({
            lessonId: 42,
            body: { enrolment_id: 3, progress_percent: 50, seconds_delta: 15 },
        });
        await waitFor(() => expect(result.current.isSuccess).toBe(true));
        expect(calls[0]?.method).toBe('POST');
        expect(calls[0]?.url).toContain('/v1/lessons/42/progress');
    });

    it('rejects a submitted-attempt save with the 409 problem', async () => {
        const { wrapper } = harness(
            () =>
                new Response(
                    JSON.stringify({ type: 'about:blank', title: 'Already submitted', status: 409 }),
                    { status: 409, headers: { 'Content-Type': 'application/problem+json' } },
                ),
        );
        const { result } = renderHook(() => useSaveAnswers(), { wrapper });
        result.current.mutate({ attemptId: 9, answers: { '12': 'b' } });
        await waitFor(() => expect(result.current.isError).toBe(true));
        expect((result.current.error as ProblemError).status).toBe(409);
    });
});
