import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { ProblemError } from '@vidyalms/api-sdk';
import { MAX_SAVE_RETRIES, useAutosave } from './use-autosave';
import { hasUnsavedWorkspaceChanges } from '@/shared/navigation-guard';

const mocks = vi.hoisted(() => ({
    save: vi.fn<(vars: { attemptId: number; answers: Record<string, unknown> }) => Promise<void>>(),
}));

vi.mock('@/shared/api', () => ({
    useSaveAnswers: () => ({ mutateAsync: mocks.save }),
}));

describe('useAutosave', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        mocks.save.mockReset();
        mocks.save.mockResolvedValue(undefined);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('debounces: one PUT with the latest answers after the quiet period', async () => {
        const { result } = renderHook(() => useAutosave({ attemptId: 71 }));
        act(() => {
            result.current.markDirty({ '11': 'a' }, '11');
        });
        expect(hasUnsavedWorkspaceChanges()).toBe(true);
        act(() => {
            vi.advanceTimersByTime(500);
        });
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(799);
        });
        expect(mocks.save).not.toHaveBeenCalled();
        await act(async () => {
            vi.advanceTimersByTime(1);
        });
        expect(mocks.save).toHaveBeenCalledTimes(1);
        expect(mocks.save).toHaveBeenCalledWith({
            attemptId: 71,
            answers: { '11': 'aa' },
            idempotencyKey: expect.any(String) as string,
        });
        expect(result.current.saveState).toBe('saved');
        expect(result.current.pendingCount).toBe(0);
        expect(hasUnsavedWorkspaceChanges()).toBe(false);
    });

    it('flush saves immediately without waiting for the debounce', async () => {
        const { result } = renderHook(() => useAutosave({ attemptId: 71 }));
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            await result.current.flush();
        });
        expect(mocks.save).toHaveBeenCalledTimes(1);
        expect(result.current.saveState).toBe('saved');
        /* The cancelled debounce timer must not fire a second save. */
        await act(async () => {
            vi.advanceTimersByTime(2000);
        });
        expect(mocks.save).toHaveBeenCalledTimes(1);
    });

    it('keeps the failed state honest and retries on its own', async () => {
        mocks.save.mockRejectedValueOnce(new Error('boom')).mockResolvedValueOnce(undefined);
        const { result } = renderHook(() => useAutosave({ attemptId: 71 }));
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        expect(result.current.saveState).toBe('failed');
        expect(result.current.pendingCount).toBe(1);
        await act(async () => {
            vi.advanceTimersByTime(4000);
        });
        expect(mocks.save).toHaveBeenCalledTimes(2);
        expect(result.current.saveState).toBe('saved');
        expect(result.current.pendingCount).toBe(0);
    });

    it('retries a failed save under the same idempotency key and rotates after a success', async () => {
        mocks.save.mockRejectedValueOnce(new Error('boom')).mockResolvedValue(undefined);
        const { result } = renderHook(() => useAutosave({ attemptId: 71 }));
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        /* The automatic retry replays the SAME logical save. */
        await act(async () => {
            vi.advanceTimersByTime(4000);
        });
        expect(mocks.save).toHaveBeenCalledTimes(2);
        const keyOf = (call: number) =>
            (mocks.save.mock.calls[call]?.[0] as { idempotencyKey?: string }).idempotencyKey;
        expect(keyOf(1)).toBe(keyOf(0));
        /* A new edit after the success is a new operation: a new key. */
        act(() => {
            result.current.markDirty({ '11': 'aaa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        expect(mocks.save).toHaveBeenCalledTimes(3);
        expect(keyOf(2)).not.toBe(keyOf(0));
    });

    it('treats a 409 as terminal: reports the conflict with its problem and stops saving', async () => {
        mocks.save.mockRejectedValue(
            new ProblemError({ type: 'about:blank', title: 'Already submitted', status: 409 }),
        );
        const onConflict = vi.fn();
        const { result } = renderHook(() => useAutosave({ attemptId: 71, onConflict }));
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        expect(onConflict).toHaveBeenCalledTimes(1);
        expect(onConflict).toHaveBeenCalledWith(expect.any(ProblemError));
        expect(mocks.save).toHaveBeenCalledTimes(1);
        /* No retry loop and later edits are ignored: the attempt is closed. */
        await act(async () => {
            vi.advanceTimersByTime(10_000);
        });
        act(() => {
            result.current.markDirty({ '11': 'aaa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(2000);
        });
        expect(mocks.save).toHaveBeenCalledTimes(1);
    });
});

/*
 * The whole answer map travels on every save, so a payload the server keeps
 * refusing does not just fail: it blocks every later answer too. The engine
 * therefore gives up on its own rather than re-posting an identical rejected
 * body every few seconds for the rest of the attempt.
 */
describe('useAutosave gives up rather than wedging the attempt', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        mocks.save.mockReset();
        mocks.save.mockResolvedValue(undefined);
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('stops the automatic retry after the cap, and leaves the manual retry working', async () => {
        mocks.save.mockRejectedValue(new Error('boom'));
        const { result } = renderHook(() => useAutosave({ attemptId: 71 }));
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        for (let attempt = 1; attempt < MAX_SAVE_RETRIES; attempt += 1) {
            await act(async () => {
                vi.advanceTimersByTime(4000);
            });
        }
        expect(mocks.save).toHaveBeenCalledTimes(MAX_SAVE_RETRIES);
        expect(result.current.retriesExhausted).toBe(true);

        /* No further automatic attempt, however long the attempt runs. */
        await act(async () => {
            vi.advanceTimersByTime(60_000);
        });
        expect(mocks.save).toHaveBeenCalledTimes(MAX_SAVE_RETRIES);

        mocks.save.mockResolvedValue(undefined);
        await act(async () => {
            result.current.retryNow();
        });
        expect(mocks.save).toHaveBeenCalledTimes(MAX_SAVE_RETRIES + 1);
        expect(result.current.saveState).toBe('saved');
    });

    it('never retries a body the server decided against, and reports the problem', async () => {
        const rejected: ProblemError[] = [];
        mocks.save.mockRejectedValue(
            new ProblemError({ type: 'about:blank', title: 'Unprocessable', status: 422, detail: 'bad answer' }),
        );
        const { result } = renderHook(() =>
            useAutosave({ attemptId: 71, onRejected: (error) => rejected.push(error) }),
        );
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        expect(mocks.save).toHaveBeenCalledTimes(1);
        expect(result.current.retriesExhausted).toBe(true);
        expect(rejected).toHaveLength(1);
        await act(async () => {
            vi.advanceTimersByTime(60_000);
        });
        expect(mocks.save).toHaveBeenCalledTimes(1);
    });

    it('gives a changed answer a fresh budget, so one refusal does not silence the rest', async () => {
        mocks.save.mockRejectedValue(
            new ProblemError({ type: 'about:blank', title: 'Unprocessable', status: 422 }),
        );
        const { result } = renderHook(() => useAutosave({ attemptId: 71 }));
        act(() => {
            result.current.markDirty({ '11': 'aa' }, '11');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        expect(result.current.retriesExhausted).toBe(true);

        mocks.save.mockResolvedValue(undefined);
        act(() => {
            result.current.markDirty({ '11': 'aa', '12': true }, '12');
        });
        await act(async () => {
            vi.advanceTimersByTime(800);
        });
        expect(mocks.save).toHaveBeenCalledTimes(2);
        expect(result.current.saveState).toBe('saved');
        expect(result.current.retriesExhausted).toBe(false);
    });
});
