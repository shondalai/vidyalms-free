import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { ProblemError, type HeartbeatRequest } from '@vidyalms/api-sdk';
import { useHeartbeatEngine, type HeartbeatEngineOptions, type HeartbeatSnapshot } from './heartbeat';

/*
 * The heartbeat engine, tested at the send boundary with fake timers:
 * cadence, the server's 120s cap, visibility and blur pauses, pagehide
 * flush, failure and retry transitions, idempotency key reuse, offline
 * escalation, the detail-gated 409 suspension, and the completion claim
 * lifecycle.
 */

type SentBody = HeartbeatRequest;

function setVisibility(state: 'visible' | 'hidden'): void {
    Object.defineProperty(document, 'visibilityState', {
        configurable: true,
        get: () => state,
    });
    document.dispatchEvent(new Event('visibilitychange'));
}

function setOnLine(value: boolean): void {
    Object.defineProperty(window.navigator, 'onLine', {
        configurable: true,
        get: () => value,
    });
}

function problem409(detail = 'The enrolment is suspended; progress cannot be recorded.'): ProblemError {
    return new ProblemError({ type: 'about:blank', title: 'Conflict', status: 409, detail });
}

interface Harness {
    snapshot: HeartbeatSnapshot;
    send: ReturnType<typeof vi.fn>;
    sendOnHide: ReturnType<typeof vi.fn>;
    onCompleted: ReturnType<typeof vi.fn>;
    onSuspended: ReturnType<typeof vi.fn>;
    sentBodies: () => SentBody[];
    result: { current: ReturnType<typeof useHeartbeatEngine> };
    rerender: (props?: HeartbeatEngineOptions) => void;
    unmount: () => void;
    props: HeartbeatEngineOptions;
}

async function setup(overrides: Partial<HeartbeatEngineOptions> = {}, keepInitialBeat = false): Promise<Harness> {
    const snapshot: HeartbeatSnapshot = { progressPercent: null, positionSeconds: null, completed: false };
    const send = vi.fn<(lessonId: number, body: HeartbeatRequest, idempotencyKey: string) => Promise<boolean>>(
        async () => true,
    );
    const sendOnHide = vi.fn();
    const onCompleted = vi.fn();
    const onSuspended = vi.fn();
    const props: HeartbeatEngineOptions = {
        lessonId: 5,
        enrolmentId: 3,
        enabled: true,
        getSnapshot: () => ({ ...snapshot }),
        send,
        sendOnHide,
        onCompleted,
        onSuspended,
        ...overrides,
    };
    const { result, rerender, unmount } = renderHook((p: HeartbeatEngineOptions) => useHeartbeatEngine(p), {
        initialProps: props,
    });
    await tick(0);
    if (!keepInitialBeat) send.mockClear();
    return {
        snapshot,
        send,
        sendOnHide,
        onCompleted,
        onSuspended,
        sentBodies: () => send.mock.calls.map((call) => call[1] as SentBody),
        result,
        rerender,
        unmount,
        props,
    };
}

async function tick(ms: number): Promise<void> {
    await act(async () => {
        await vi.advanceTimersByTimeAsync(ms);
    });
}

describe('useHeartbeatEngine', () => {
    it('starts the server clock on opening a lesson without claiming time or completion', async () => {
        const h = await setup({}, true);
        expect(h.send).toHaveBeenCalledTimes(1);
        expect(h.sentBodies()[0]).toEqual({ enrolment_id: 3 });
        await tick(15_000);
        expect(h.send).toHaveBeenCalledTimes(2);
        expect(h.sentBodies()[1]?.seconds_delta).toBe(15);
        h.unmount();
    });

    it('retries a completion claim until the server reports completed progress', async () => {
        const h = await setup();
        h.snapshot.completed = true;
        h.snapshot.progressPercent = 100;
        h.send.mockResolvedValueOnce(false);
        await act(async () => {
            h.result.current.flushNow();
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(h.result.current.phase).toBe('saved');
        expect(h.onCompleted).not.toHaveBeenCalled();
        await tick(15_000);
        expect(h.sentBodies().map(body => body.completed)).toEqual([true, true]);
        expect(h.onCompleted).toHaveBeenCalledExactlyOnceWith(5);
        h.unmount();
    });

    it('ignores an old completion response after switching lessons', async () => {
        const h = await setup();
        let resolveOld!: (completed: boolean) => void;
        h.send.mockImplementationOnce(() => new Promise<boolean>(resolve => {
            resolveOld = resolve;
        }));
        h.snapshot.completed = true;
        act(() => h.result.current.flushNow());
        h.snapshot.completed = false;
        act(() => h.rerender({ ...h.props, lessonId: 6 }));
        await act(async () => {
            resolveOld(true);
        });
        expect(h.onCompleted).not.toHaveBeenCalled();
        expect(h.send).toHaveBeenLastCalledWith(6, { enrolment_id: 3 }, expect.any(String));
        h.unmount();
    });

    it('caps a three-hour untouched lesson at the configured inactivity window', async () => {
        const h = await setup({ idleTimeoutMs: 60_000 });
        await tick(3 * 60 * 60 * 1000);
        expect(h.sentBodies().reduce((sum, body) => sum + (body.seconds_delta ?? 0), 0)).toBe(60);
        act(() => window.dispatchEvent(new Event('pointerdown')));
        await tick(15_000);
        expect(h.sentBodies().reduce((sum, body) => sum + (body.seconds_delta ?? 0), 0)).toBe(75);
        h.unmount();
    });

    it('counts continuing media playback while the page is visible and focused', async () => {
        const h = await setup({ idleTimeoutMs: 30_000 });
        h.snapshot.positionSeconds = 0;
        for (let beat = 1; beat <= 8; beat++) {
            h.snapshot.positionSeconds = beat * 15;
            await tick(15_000);
        }
        expect(h.sentBodies().reduce((sum, body) => sum + (body.seconds_delta ?? 0), 0)).toBe(120);
        h.unmount();
    });

    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(document, 'hasFocus').mockReturnValue(true);
        setOnLine(true);
    });

    afterEach(() => {
        vi.useRealTimers();
        setOnLine(true);
        Object.defineProperty(document, 'visibilityState', {
            configurable: true,
            get: () => 'visible',
        });
    });

    it('beats every 15 seconds with the visible seconds as seconds_delta', async () => {
        const h = await setup();
        await tick(15_000);
        expect(h.send).toHaveBeenCalledTimes(1);
        expect(h.send).toHaveBeenLastCalledWith(5, expect.objectContaining({ enrolment_id: 3, seconds_delta: 15 }), expect.any(String));
        await tick(15_000);
        expect(h.send).toHaveBeenCalledTimes(2);
        expect(h.sentBodies()[1]?.seconds_delta).toBe(15);
        expect(h.result.current.phase).toBe('saved');
        expect(h.result.current.savedAt).not.toBeNull();
        h.unmount();
    });

    it('sends progress_percent and floored position_seconds when the pane reports them', async () => {
        const h = await setup();
        h.snapshot.progressPercent = 62;
        h.snapshot.positionSeconds = 298.7;
        await tick(15_000);
        expect(h.sentBodies()[0]).toMatchObject({ progress_percent: 62, position_seconds: 298 });
        h.unmount();
    });

    it('caps seconds_delta exactly as the API caps it (120s per beat)', async () => {
        const h = await setup();
        h.send.mockRejectedValue(new Error('boom'));
        for (let i = 0; i < 8; i += 1) {
            await tick(15_000);
        }
        h.send.mockResolvedValue(true);
        await tick(15_000);
        const last = h.sentBodies().at(-1);
        expect(last?.seconds_delta).toBe(120);
        h.unmount();
    });

    it('pauses the clock and the cadence while the document is hidden', async () => {
        const h = await setup();
        await tick(5_000);
        act(() => setVisibility('hidden'));
        await tick(60_000);
        expect(h.send).not.toHaveBeenCalled();
        act(() => setVisibility('visible'));
        await tick(15_000);
        /* 5 visible seconds before hiding plus 15 after returning. */
        expect(h.send).toHaveBeenCalledTimes(1);
        expect(h.sentBodies()[0]?.seconds_delta).toBe(20);
        h.unmount();
    });

    it('stops counting on window blur and stays quiet once drained', async () => {
        const h = await setup();
        await tick(7_000);
        act(() => {
            window.dispatchEvent(new Event('blur'));
        });
        await tick(15_000 - 7_000);
        expect(h.sentBodies()[0]?.seconds_delta).toBe(7);
        await tick(15_000);
        /* Nothing accrued while blurred: no second beat. */
        expect(h.send).toHaveBeenCalledTimes(1);
        act(() => {
            window.dispatchEvent(new Event('focus'));
        });
        await tick(15_000);
        expect(h.send).toHaveBeenCalledTimes(2);
        h.unmount();
    });

    it('flushes pending work through the keepalive sender on pagehide', async () => {
        const h = await setup();
        await tick(10_000);
        act(() => {
            window.dispatchEvent(new Event('pagehide'));
        });
        expect(h.sendOnHide).toHaveBeenCalledTimes(1);
        expect(h.sendOnHide).toHaveBeenLastCalledWith(5, expect.objectContaining({ seconds_delta: 10 }), expect.any(String));
        h.unmount();
    });

    it('marks failed on the first failure, escalates to offline after three, recovers on retry', async () => {
        const h = await setup();
        h.send.mockRejectedValue(new Error('boom'));
        await tick(15_000);
        expect(h.result.current.phase).toBe('failed');
        expect(h.result.current.offline).toBe(false);
        await tick(15_000);
        expect(h.result.current.offline).toBe(false);
        await tick(15_000);
        expect(h.result.current.offline).toBe(true);
        h.send.mockResolvedValue(true);
        await act(async () => {
            h.result.current.retryNow();
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(h.result.current.phase).toBe('saved');
        expect(h.result.current.offline).toBe(false);
        /* The failed seconds were kept and finally recorded. */
        expect(h.sentBodies().at(-1)?.seconds_delta).toBe(45);
        h.unmount();
    });

    it('treats a 409 as a suspended enrolment: stops for good and lifts it', async () => {
        const h = await setup();
        h.send.mockRejectedValue(problem409());
        await tick(15_000);
        expect(h.onSuspended).toHaveBeenCalledTimes(1);
        expect(h.result.current.suspended).toBe(true);
        await tick(45_000);
        expect(h.send).toHaveBeenCalledTimes(1);
        h.unmount();
    });

    it('does not read a 409 without the suspension detail as suspension', async () => {
        const h = await setup();
        h.send.mockRejectedValue(problem409('A request with this idempotency key is already in flight.'));
        await tick(15_000);
        expect(h.onSuspended).not.toHaveBeenCalled();
        expect(h.result.current.suspended).toBe(false);
        expect(h.result.current.phase).toBe('failed');
        /* An ordinary failure: the engine keeps beating and recovers. */
        h.send.mockResolvedValue(true);
        await tick(15_000);
        expect(h.result.current.phase).toBe('saved');
        h.unmount();
    });

    it('retries a failed beat under the same idempotency key and rotates only on success', async () => {
        const h = await setup();
        h.send.mockRejectedValueOnce(new Error('boom'));
        await tick(15_000);
        await tick(15_000);
        const keys = h.send.mock.calls.map((call) => call[2] as string);
        expect(keys).toHaveLength(2);
        /* The retry of the failed logical beat replays the same key. */
        expect(keys[1]).toBe(keys[0]);
        await tick(15_000);
        /* After a success the next beat is a new operation: a new key. */
        const nextKey = h.send.mock.calls[2]?.[2] as string;
        expect(nextKey).not.toBe(keys[0]);
        h.unmount();
    });

    it('chunks a long accumulation into cap-sized deltas instead of losing time on lesson switch', async () => {
        const h = await setup();
        h.send.mockRejectedValue(new Error('boom'));
        for (let i = 0; i < 10; i += 1) {
            await tick(15_000);
        }
        /* 150 visible seconds accrued and none recorded yet. */
        h.send.mockResolvedValue(true);
        const before = h.send.mock.calls.length;
        act(() => {
            h.rerender({ ...h.props, lessonId: 6 });
        });
        const flushed = h.send.mock.calls.slice(before).filter(call => call[0] === 5);
        expect(flushed.map((call) => (call[1] as SentBody).seconds_delta)).toEqual([120, 30]);
        expect(flushed.every((call) => call[0] === 5)).toBe(true);
        /* Each chunk is its own logical operation with its own key. */
        expect(flushed[0]?.[2]).not.toBe(flushed[1]?.[2]);
        h.unmount();
    });

    it('claims completion immediately via flushNow and acknowledges it exactly once', async () => {
        const h = await setup();
        h.snapshot.completed = true;
        h.snapshot.progressPercent = 100;
        await act(async () => {
            h.result.current.flushNow();
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(h.sentBodies()[0]).toMatchObject({ completed: true, progress_percent: 100 });
        expect(h.onCompleted).toHaveBeenCalledTimes(1);
        expect(h.onCompleted).toHaveBeenLastCalledWith(5);
        await tick(15_000);
        expect(h.sentBodies().at(-1)?.completed).toBeUndefined();
        expect(h.onCompleted).toHaveBeenCalledTimes(1);
        h.unmount();
    });

    it('keeps the completion claim through failures until the server confirms', async () => {
        const h = await setup();
        h.snapshot.completed = true;
        h.send.mockRejectedValueOnce(new Error('boom'));
        await act(async () => {
            h.result.current.flushNow();
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(h.onCompleted).not.toHaveBeenCalled();
        await tick(15_000);
        expect(h.sentBodies().at(-1)?.completed).toBe(true);
        expect(h.onCompleted).toHaveBeenCalledTimes(1);
        h.unmount();
    });

    it('never re-claims completion for a lesson the server already stored as complete', async () => {
        const h = await setup({ alreadyCompleted: true });
        h.snapshot.completed = true;
        await tick(15_000);
        expect(h.sentBodies()[0]?.completed).toBeUndefined();
        expect(h.onCompleted).not.toHaveBeenCalled();
        h.unmount();
    });

    it('buffers while the browser reports offline and syncs on the online event', async () => {
        const h = await setup();
        setOnLine(false);
        act(() => {
            window.dispatchEvent(new Event('offline'));
        });
        await tick(30_000);
        expect(h.send).not.toHaveBeenCalled();
        expect(h.result.current.offline).toBe(true);
        setOnLine(true);
        await act(async () => {
            window.dispatchEvent(new Event('online'));
            await vi.advanceTimersByTimeAsync(0);
        });
        expect(h.send).toHaveBeenCalledTimes(1);
        expect(h.sentBodies()[0]?.seconds_delta).toBe(30);
        expect(h.result.current.offline).toBe(false);
        h.unmount();
    });

    it('flushes the remaining seconds for the old lesson when the lesson changes', async () => {
        const h = await setup();
        await tick(10_000);
        act(() => {
            h.rerender({ ...h.props, lessonId: 6 });
        });
        expect(h.send).toHaveBeenCalledTimes(2);
        expect(h.send).toHaveBeenNthCalledWith(1, 5, expect.objectContaining({ seconds_delta: 10 }), expect.any(String));
        expect(h.send).toHaveBeenLastCalledWith(6, { enrolment_id: 3 }, expect.any(String));
        /* The new lesson starts a clean accumulator. */
        await tick(15_000);
        expect(h.send).toHaveBeenLastCalledWith(6, expect.objectContaining({ seconds_delta: 15 }), expect.any(String));
        h.unmount();
    });

    it('does nothing while disabled', async () => {
        const h = await setup({ enabled: false });
        await tick(60_000);
        expect(h.send).not.toHaveBeenCalled();
        expect(h.result.current.phase).toBe('idle');
        h.unmount();
    });
});
