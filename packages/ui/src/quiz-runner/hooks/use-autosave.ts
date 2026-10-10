import { useCallback, useEffect, useRef, useState } from 'react';
import { ProblemError } from '@vidyalms/api-sdk';
import { useSaveAnswers } from '@/shared/api';
import type { SaveState } from '@/shared/components';
import type { AnswerMap } from '../lib/answers';
import { useNavigationGuard } from '@/shared/navigation-guard';

/**
 * How many times one logical save is retried automatically before the
 * engine stops and waits to be told.
 *
 * Without a cap the runner re-posts an identical rejected payload every few
 * seconds for the rest of the attempt, and because the WHOLE answer map
 * travels on every save, nothing else the learner writes is ever stored
 * either: one refused answer wedges the attempt.
 */
export const MAX_SAVE_RETRIES = 5;

export interface UseAutosaveOptions {
    attemptId: number;
    /** Debounce after the last change before the PUT fires. */
    debounceMs?: number;
    /** Delay before an automatic retry of a failed save. */
    retryMs?: number;
    /**
     * A 409 stops saving for good; the caller reads the problem detail
     * (already submitted, deadline passed, something else) and routes the
     * recovery. Saving never resumes: the server refused this attempt.
     */
    onConflict?: (error: ProblemError) => void;
    /**
     * The server decided against this body: a 4xx that is not a 409. It is
     * never true on a second attempt, so the engine stops rather than
     * retrying, and hands the problem over to be rendered.
     */
    onRejected?: (error: ProblemError) => void;
}

export interface Autosave {
    /** Record a change: the full answer map plus the key that changed. */
    markDirty: (answers: AnswerMap, changedKey: string) => void;
    /** Save now (navigation, submit). Resolves when nothing is left dirty. */
    flush: () => Promise<void>;
    saveState: SaveState;
    savedAt: number | null;
    /** Questions changed since the last successful save (the offline chip). */
    pendingCount: number;
    retryNow: () => void;
    /** True once the engine has given up on its own; retryNow still works. */
    retriesExhausted: boolean;
}

/**
 * The autosave engine of the runner: debounced on change, immediate on
 * flush, buffered while offline (the flush happens on reconnect), retried
 * quietly on failure. The full answer map travels on every save; the
 * server upserts per question, so replays never duplicate rows. Each
 * logical save carries ONE Idempotency-Key, kept across retries of that
 * save and rotated only after a success, so a retry replays the stored
 * response instead of executing twice.
 */
export function useAutosave({
    attemptId,
    debounceMs = 800,
    retryMs = 4000,
    onConflict,
    onRejected,
}: UseAutosaveOptions): Autosave {
    const save = useSaveAnswers();

    const [saveState, setSaveState] = useState<SaveState>('idle');
    const [savedAt, setSavedAt] = useState<number | null>(null);
    const [pendingCount, setPendingCount] = useState(0);
    useNavigationGuard(pendingCount > 0 || saveState === 'saving');
    const [retriesExhausted, setRetriesExhausted] = useState(false);

    const latestRef = useRef<AnswerMap>({});
    const dirtyRef = useRef(false);
    const dirtyKeysRef = useRef<Set<string>>(new Set());
    /* The Idempotency-Key of the save in flight or awaiting retry. */
    const saveKeyRef = useRef<string | null>(null);
    const versionRef = useRef(0);
    const conflictRef = useRef(false);
    const inFlightRef = useRef<Promise<void> | null>(null);
    const debounceTimerRef = useRef<number | null>(null);
    const retryTimerRef = useRef<number | null>(null);
    const mountedRef = useRef(true);

    const failuresRef = useRef(0);
    const onConflictRef = useRef(onConflict);
    onConflictRef.current = onConflict;
    const onRejectedRef = useRef(onRejected);
    onRejectedRef.current = onRejected;
    const mutateRef = useRef(save.mutateAsync);
    mutateRef.current = save.mutateAsync;

    const clearTimer = (ref: { current: number | null }) => {
        if (ref.current !== null) {
            window.clearTimeout(ref.current);
            ref.current = null;
        }
    };

    const doSave = useCallback(async (): Promise<void> => {
        if (inFlightRef.current !== null) {
            return inFlightRef.current;
        }
        if (!dirtyRef.current || conflictRef.current) {
            return;
        }
        const run = (async () => {
            /* Loop until a save lands on the latest version or fails. */
            for (;;) {
                const version = versionRef.current;
                const keys = [...dirtyKeysRef.current];
                if (mountedRef.current) {
                    setSaveState('saving');
                }
                const idempotencyKey = saveKeyRef.current ?? crypto.randomUUID();
                saveKeyRef.current = idempotencyKey;
                try {
                    await mutateRef.current({ attemptId, answers: latestRef.current, idempotencyKey });
                } catch (error) {
                    if (error instanceof ProblemError && error.status === 409) {
                        conflictRef.current = true;
                        dirtyRef.current = false;
                        onConflictRef.current?.(error);
                        return;
                    }
                    failuresRef.current += 1;
                    /* A 4xx that is not a 409 is a decided answer: the body
                       was refused and posting it again changes nothing. */
                    const decided =
                        error instanceof ProblemError && error.status >= 400 && error.status < 500;
                    const giveUp = decided || failuresRef.current >= MAX_SAVE_RETRIES;
                    if (decided) {
                        onRejectedRef.current?.(error);
                    }
                    if (mountedRef.current) {
                        setSaveState('failed');
                        setRetriesExhausted(giveUp);
                        clearTimer(retryTimerRef);
                        if (!giveUp) {
                            retryTimerRef.current = window.setTimeout(() => {
                                retryTimerRef.current = null;
                                /* Every caller of doSave() swallows the
                                   rejection: the failure is already carried
                                   in saveState and the retry budget. A bare
                                   `void` here would leave the automatic
                                   retry's rejection unhandled, which the
                                   browser reports as an uncaught error on
                                   every retry of a failing save. */
                                doSave().catch(() => undefined);
                            }, retryMs);
                        }
                    }
                    throw error;
                }
                /* The save landed: this logical operation is complete and
                   the next save (even the loop's re-save of newer edits)
                   is a new one with a fresh key. */
                saveKeyRef.current = null;
                failuresRef.current = 0;
                if (versionRef.current === version) {
                    dirtyRef.current = false;
                    for (const key of keys) {
                        dirtyKeysRef.current.delete(key);
                    }
                    if (mountedRef.current) {
                        setPendingCount(dirtyKeysRef.current.size);
                        setSaveState('saved');
                        setSavedAt(Date.now());
                        setRetriesExhausted(false);
                    }
                    return;
                }
                /* Newer edits arrived during the request: save again. */
            }
        })();
        inFlightRef.current = run;
        try {
            await run;
        } finally {
            inFlightRef.current = null;
        }
    }, [attemptId, retryMs]);

    const markDirty = useCallback(
        (answers: AnswerMap, changedKey: string) => {
            if (conflictRef.current) {
                return;
            }
            latestRef.current = answers;
            dirtyRef.current = true;
            versionRef.current += 1;
            /* A changed payload is a different save, so it starts with a
               fresh budget rather than inheriting the refused one's. */
            failuresRef.current = 0;
            setRetriesExhausted(false);
            dirtyKeysRef.current.add(changedKey);
            setPendingCount(dirtyKeysRef.current.size);
            if (!navigator.onLine) {
                /* Buffer locally; the reconnect listener flushes. */
                return;
            }
            clearTimer(debounceTimerRef);
            debounceTimerRef.current = window.setTimeout(() => {
                debounceTimerRef.current = null;
                doSave().catch(() => undefined);
            }, debounceMs);
        },
        [debounceMs, doSave],
    );

    const flush = useCallback(async (): Promise<void> => {
        clearTimer(debounceTimerRef);
        if (!dirtyRef.current && inFlightRef.current === null) {
            return;
        }
        await doSave();
    }, [doSave]);

    const retryNow = useCallback(() => {
        clearTimer(retryTimerRef);
        failuresRef.current = 0;
        setRetriesExhausted(false);
        doSave().catch(() => undefined);
    }, [doSave]);

    /* Reconnect: send whatever is buffered. */
    useEffect(() => {
        const onOnline = () => {
            if (dirtyRef.current) {
                doSave().catch(() => undefined);
            }
        };
        window.addEventListener('online', onOnline);
        return () => window.removeEventListener('online', onOnline);
    }, [doSave]);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
            clearTimer(debounceTimerRef);
            clearTimer(retryTimerRef);
        };
    }, []);

    return { markDirty, flush, saveState, savedAt, pendingCount, retryNow, retriesExhausted };
}
