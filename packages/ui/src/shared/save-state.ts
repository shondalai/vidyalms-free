import { useCallback, useRef, useState } from 'react';
import type { SaveState } from './components';

/*
 * Explicit save state for every write the workbench makes, in the shape the
 * shared SaveIndicator renders (Foundations piece 4): saving, saved with a
 * timestamp, or not saved with a retry that replays the SAME logical write.
 *
 * The idempotency key is the point. A retry reuses the key of the attempt
 * that failed, so a write that actually landed before the connection
 * dropped replays instead of executing twice, which is what the contract's
 * Idempotency-Key promises.
 */

export interface SaveTracker {
    state: SaveState;
    savedAt: number | null;
    error: unknown;
    /** Runs one logical write; the key it hands the callback is stable across retries. */
    run: (write: (idempotencyKey: string) => Promise<unknown>) => Promise<boolean>;
    /** Retries the last failed write with its original key. */
    retry: () => void;
    reset: () => void;
}

export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `vy-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export function useSaveTracker(): SaveTracker {
    const [state, setState] = useState<SaveState>('idle');
    const [savedAt, setSavedAt] = useState<number | null>(null);
    const [error, setError] = useState<unknown>(null);
    const lastRef = useRef<{ write: (key: string) => Promise<unknown>; key: string } | null>(null);

    const perform = useCallback(async (write: (key: string) => Promise<unknown>, key: string) => {
        lastRef.current = { write, key };
        setState('saving');
        setError(null);
        try {
            await write(key);
            lastRef.current = null;
            setSavedAt(Date.now());
            setState('saved');
            return true;
        } catch (cause) {
            setError(cause);
            setState('failed');
            return false;
        }
    }, []);

    const run = useCallback(
        (write: (idempotencyKey: string) => Promise<unknown>) => perform(write, newIdempotencyKey()),
        [perform],
    );

    const retry = useCallback(() => {
        const last = lastRef.current;
        if (last !== null) {
            void perform(last.write, last.key);
        }
    }, [perform]);

    const reset = useCallback(() => {
        lastRef.current = null;
        setState('idle');
        setSavedAt(null);
        setError(null);
    }, []);

    return { state, savedAt, error, run, retry, reset };
}
