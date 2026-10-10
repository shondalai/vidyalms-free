import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook } from '@testing-library/react';
import { useQuizTimer } from './use-quiz-timer';

/*
 * The timer displays the SERVER deadline: remaining derives from the
 * attempt's expires_at on every tick, so a reload or resume shows exactly
 * what the server enforces, and no extension mechanism exists (a timed
 * exam is an essential exception under WCAG 2.2.1).
 */

const STARTED_AT = '2026-09-01 10:00:00';
const EXPIRES_20M = '2026-09-01 10:20:00';

describe('useQuizTimer', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-09-01T10:00:00Z'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('stays inert without a server deadline (untimed quiz)', () => {
        const onExpire = vi.fn();
        const { result } = renderHook(() =>
            useQuizTimer({ startedAt: STARTED_AT, expiresAt: null, running: true, onExpire }),
        );
        expect(result.current.remaining).toBeNull();
        act(() => {
            vi.advanceTimersByTime(60_000);
        });
        expect(onExpire).not.toHaveBeenCalled();
    });

    it('derives remaining from expires_at minus now, so a reload or resume stays honest', () => {
        const onExpire = vi.fn();
        vi.setSystemTime(new Date('2026-09-01T10:07:19Z'));
        const { result } = renderHook(() =>
            useQuizTimer({ startedAt: STARTED_AT, expiresAt: EXPIRES_20M, running: true, onExpire }),
        );
        expect(result.current.remaining).toBe(761);
        expect(result.current.total).toBe(1200);
        act(() => {
            vi.advanceTimersByTime(1000);
        });
        expect(result.current.remaining).toBe(760);
    });

    it('offers no extension affordance in its api', () => {
        const onExpire = vi.fn();
        const { result } = renderHook(() =>
            useQuizTimer({ startedAt: STARTED_AT, expiresAt: EXPIRES_20M, running: true, onExpire }),
        );
        expect('extend' in result.current).toBe(false);
    });

    it('opens the warning at the two minute threshold', () => {
        const onExpire = vi.fn();
        const { result } = renderHook(() =>
            useQuizTimer({
                startedAt: STARTED_AT,
                expiresAt: '2026-09-01 10:02:05',
                running: true,
                onExpire,
            }),
        );
        expect(result.current.warningOpen).toBe(false);
        act(() => {
            vi.advanceTimersByTime(6000);
        });
        expect(result.current.warn).toBe(true);
        expect(result.current.warningOpen).toBe(true);
    });

    it('fires onExpire exactly once at the server deadline', () => {
        const onExpire = vi.fn();
        const { result } = renderHook(() =>
            useQuizTimer({
                startedAt: STARTED_AT,
                expiresAt: '2026-09-01 10:00:05',
                running: true,
                onExpire,
            }),
        );
        act(() => {
            vi.advanceTimersByTime(6000);
        });
        expect(onExpire).toHaveBeenCalledTimes(1);
        expect(result.current.expired).toBe(true);
        act(() => {
            vi.advanceTimersByTime(10_000);
        });
        expect(onExpire).toHaveBeenCalledTimes(1);
    });

    it('fires immediately when a resume lands past the deadline', () => {
        const onExpire = vi.fn();
        vi.setSystemTime(new Date('2026-09-01T11:00:00Z'));
        renderHook(() =>
            useQuizTimer({
                startedAt: STARTED_AT,
                expiresAt: '2026-09-01 10:01:00',
                running: true,
                onExpire,
            }),
        );
        expect(onExpire).toHaveBeenCalledTimes(1);
    });
});
