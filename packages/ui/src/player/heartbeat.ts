import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createVidyaLmsClient, type HeartbeatRequest } from '@vidyalms/api-sdk';
import { conflictKind, useApiClient } from '@/shared/api';
import { useMountConfig } from '@/shared/context';
import { clampPercent } from './lib';

/*
 * THE HEARTBEAT ENGINE. A 15 second cadence while the lesson is visibly
 * open, sending seconds_delta (capped exactly as the API caps it, 120s per
 * beat), progress_percent where the lesson type warrants it, and
 * position_seconds for resumable media.
 *
 * Honesty rules from the artboards:
 * - time accrues only while the page is visible AND the window is focused;
 *   visibilitychange-hidden and window blur both pause the clock;
 * - pagehide flushes whatever is pending through a keepalive request;
 * - the SaveIndicator reflects genuine state, never nags;
 * - three consecutive failures (or navigator.onLine false) escalate to the
 *   offline banner; seconds keep accruing locally and sync on reconnect;
 * - a 409 whose problem detail marks the enrolment suspended stops the
 *   engine for good and the app shows the re-activation state; any other
 *   409 (an idempotency replay in flight, a proxy quirk) is an ordinary
 *   failure and retries, because heartbeats on completed enrolments are
 *   valid and must never collapse the player into the suspended screen;
 * - every beat carries one Idempotency-Key per logical operation: a failed
 *   beat retries under the SAME key (the server replays instead of double
 *   counting), and only a success rotates to a fresh key;
 * - completion is only ever claimed for view-completion lesson types, and
 *   onCompleted fires only after the server acknowledged the claim.
 */

/** What a lesson pane knows about its own progress, read at each beat. */
export interface HeartbeatSnapshot {
    /** 0..100 or null when the type has no measurable progress. */
    progressPercent: number | null;
    /** Playback position for resumable media; null elsewhere. */
    positionSeconds: number | null;
    /** The view-completion claim; ignored until acknowledged once. */
    completed: boolean;
}

export type HeartbeatPhase = 'idle' | 'saving' | 'saved' | 'failed';

export interface HeartbeatEngineOptions {
    /** Inactive, visible tabs stop accruing time after this interval. */
    idleTimeoutMs?: number;
    lessonId: number | null;
    enrolmentId: number | null;
    /** Gate: lesson loaded and the enrolment allows recording. */
    enabled: boolean;
    /** True when the server already stored this lesson as completed. */
    alreadyCompleted?: boolean;
    getSnapshot: () => HeartbeatSnapshot;
    /** True only when the server confirms that the lesson is completed. */
    send: (lessonId: number, body: HeartbeatRequest, idempotencyKey: string) => Promise<boolean>;
    /** Fire-and-forget flush for pagehide; should ride a keepalive fetch. */
    sendOnHide?: (lessonId: number, body: HeartbeatRequest, idempotencyKey: string) => void;
    /** The server acknowledged a completed=true beat for this lesson. */
    onCompleted?: (lessonId: number) => void;
    /** A heartbeat answered 409: the enrolment is suspended. */
    onSuspended?: () => void;
    intervalMs?: number;
    maxSecondsPerBeat?: number;
}

export interface HeartbeatEngine {
    phase: HeartbeatPhase;
    savedAt: number | null;
    /** Escalated: the browser reports offline, or 3 beats failed in a row. */
    offline: boolean;
    /** A 409 arrived; the engine will not send again for this enrolment. */
    suspended: boolean;
    /** Manual beat, out of cadence: the SaveIndicator's "retry now". */
    retryNow: () => void;
    /** Immediate beat for a completion event (download click, 90% watched). */
    flushNow: () => void;
}

export const HEARTBEAT_INTERVAL_MS = 15_000;
/** RecordLessonProgress::MAX_SECONDS_PER_HEARTBEAT on the server. */
export const HEARTBEAT_MAX_SECONDS = 120;
const OFFLINE_AFTER_FAILURES = 3;

export function useHeartbeatEngine(options: HeartbeatEngineOptions): HeartbeatEngine {
    const { lessonId, enrolmentId, enabled } = options;
    const intervalMs = options.intervalMs ?? HEARTBEAT_INTERVAL_MS;
    const cap = options.maxSecondsPerBeat ?? HEARTBEAT_MAX_SECONDS;

    const [phase, setPhase] = useState<HeartbeatPhase>('idle');
    const [savedAt, setSavedAt] = useState<number | null>(null);
    const [failures, setFailures] = useState(0);
    const [netOffline, setNetOffline] = useState(() => typeof navigator !== 'undefined' && !navigator.onLine);
    const [suspended, setSuspended] = useState(false);

    /* Latest callbacks and identifiers, so beat() never closes over stale
       props and the main effect only re-runs on real identity changes. */
    const optionsRef = useRef(options);
    optionsRef.current = options;
    const lessonRef = useRef(lessonId);
    lessonRef.current = lessonId;
    const enrolRef = useRef(enrolmentId);
    enrolRef.current = enrolmentId;

    /* Visible-time accounting. carried holds whole and fractional seconds
       accrued across pauses and failed beats; runningSince marks the start
       of the current visible-and-focused stretch. */
    const carriedRef = useRef(0);
    const runningSinceRef = useRef<number | null>(null);
    const lastActivityRef = useRef(Date.now());
    const focusedRef = useRef(true);
    const lastMediaPositionRef = useRef<number | null>(null);
    const completedAckedRef = useRef(false);
    const inFlightRef = useRef(false);
    const failuresRef = useRef(0);
    const suspendedRef = useRef(false);
    const activeRef = useRef(false);
    const generationRef = useRef(0);
    /* One key per logical beat: kept across retries, rotated on success. */
    const beatKeyRef = useRef<string | null>(null);

    const pauseClock = useCallback(() => {
        if (runningSinceRef.current !== null) {
            const until = Math.min(Date.now(), lastActivityRef.current + (optionsRef.current.idleTimeoutMs ?? 300_000));
            carriedRef.current += Math.max(0, until - runningSinceRef.current) / 1000;
            runningSinceRef.current = null;
        }
    }, []);

    const resumeClock = useCallback(() => {
        if (runningSinceRef.current === null && activeRef.current && focusedRef.current
            && Date.now() < lastActivityRef.current + (optionsRef.current.idleTimeoutMs ?? 300_000)) {
            runningSinceRef.current = Date.now();
        }
    }, []);

    /** Takes up to cap whole seconds out of the accumulator. */
    const takeSeconds = useCallback(() => {
        let total = carriedRef.current;
        if (runningSinceRef.current !== null) {
            const now = Date.now();
            const until = Math.min(now, lastActivityRef.current + (optionsRef.current.idleTimeoutMs ?? 300_000));
            total += Math.max(0, until - runningSinceRef.current) / 1000;
            runningSinceRef.current = now;
        }
        const whole = Math.floor(total);
        const sent = Math.min(whole, cap);
        carriedRef.current = total - sent;
        return sent;
    }, [cap]);

    const beat = useCallback(
        async (manual: boolean, initialize = false) => {
            const lesson = lessonRef.current;
            const enrolment = enrolRef.current;
            if (!activeRef.current || lesson === null || enrolment === null) {
                return;
            }
            if (suspendedRef.current || inFlightRef.current) {
                return;
            }
            if (typeof navigator !== 'undefined' && !navigator.onLine) {
                /* Keep accruing locally; the online listener resumes syncing. */
                setNetOffline(true);
                return;
            }
            const generation = generationRef.current;
            const snapshot = initialize
                ? { progressPercent: null, positionSeconds: null, completed: false }
                : optionsRef.current.getSnapshot();
            const claimCompleted = snapshot.completed && !completedAckedRef.current;
            const seconds = takeSeconds();
            if (!manual && seconds === 0 && !claimCompleted && runningSinceRef.current === null) {
                /* Paused with nothing pending: stay quiet. */
                return;
            }
            const body: HeartbeatRequest = { enrolment_id: enrolment };
            if (seconds > 0) {
                body.seconds_delta = seconds;
            }
            if (snapshot.progressPercent !== null) {
                body.progress_percent = clampPercent(snapshot.progressPercent);
            }
            if (snapshot.positionSeconds !== null) {
                body.position_seconds = Math.max(0, Math.floor(snapshot.positionSeconds));
            }
            if (claimCompleted) {
                body.completed = true;
            }
            const key = beatKeyRef.current ?? crypto.randomUUID();
            beatKeyRef.current = key;
            inFlightRef.current = true;
            setPhase('saving');
            try {
                const completed = await optionsRef.current.send(lesson, body, key);
                if (generation !== generationRef.current) return;
                inFlightRef.current = false;
                beatKeyRef.current = null;
                failuresRef.current = 0;
                setFailures(0);
                setNetOffline(false);
                setPhase('saved');
                setSavedAt(Date.now());
                if (claimCompleted && completed) {
                    completedAckedRef.current = true;
                    optionsRef.current.onCompleted?.(lesson);
                }
            } catch (error) {
                if (generation !== generationRef.current) return;
                inFlightRef.current = false;
                /* The seconds were not recorded; put them back for the retry
                   (which reuses the key, so a replay cannot double count). */
                carriedRef.current += seconds;
                if (conflictKind(error) === 'suspended') {
                    suspendedRef.current = true;
                    setSuspended(true);
                    setPhase('failed');
                    optionsRef.current.onSuspended?.();
                    return;
                }
                failuresRef.current += 1;
                setFailures(failuresRef.current);
                setPhase('failed');
            }
        },
        [takeSeconds],
    );

    /** Pending work flushed through the keepalive path on pagehide. */
    const flushOnHide = useCallback(() => {
        const lesson = lessonRef.current;
        const enrolment = enrolRef.current;
        const sendOnHide = optionsRef.current.sendOnHide;
        if (lesson === null || enrolment === null || suspendedRef.current || sendOnHide === undefined) {
            return;
        }
        const snapshot = optionsRef.current.getSnapshot();
        const claimCompleted = snapshot.completed && !completedAckedRef.current;
        const seconds = takeSeconds();
        if (seconds === 0 && !claimCompleted) {
            return;
        }
        const body: HeartbeatRequest = { enrolment_id: enrolment };
        if (seconds > 0) {
            body.seconds_delta = seconds;
        }
        if (snapshot.progressPercent !== null) {
            body.progress_percent = clampPercent(snapshot.progressPercent);
        }
        if (snapshot.positionSeconds !== null) {
            body.position_seconds = Math.max(0, Math.floor(snapshot.positionSeconds));
        }
        if (claimCompleted) {
            body.completed = true;
        }
        /* Reuse a pending retry key so a beat that actually landed cannot
           be double counted by the flush. */
        sendOnHide(lesson, body, beatKeyRef.current ?? crypto.randomUUID());
    }, [takeSeconds]);

    useEffect(() => {
        if (!enabled || lessonId === null || enrolmentId === null) {
            return;
        }
        activeRef.current = true;
        generationRef.current += 1;
        inFlightRef.current = false;
        lastActivityRef.current = Date.now();
        focusedRef.current = document.hasFocus();
        lastMediaPositionRef.current = null;
        suspendedRef.current = false;
        completedAckedRef.current = optionsRef.current.alreadyCompleted === true;
        carriedRef.current = 0;
        runningSinceRef.current = null;
        beatKeyRef.current = null;
        failuresRef.current = 0;
        setSuspended(false);
        setFailures(0);
        setPhase('idle');
        setSavedAt(null);

        let timer: number | null = null;
        const startTimer = () => {
            if (timer === null) {
                timer = window.setInterval(() => {
                    const position = optionsRef.current.getSnapshot().positionSeconds;
                    if (focusedRef.current && document.visibilityState === 'visible' && position !== null
                        && lastMediaPositionRef.current !== null && position > lastMediaPositionRef.current) {
                        onActivity();
                    }
                    lastMediaPositionRef.current = position;
                    void beat(false);
                }, intervalMs);
            }
        };
        const stopTimer = () => {
            if (timer !== null) {
                window.clearInterval(timer);
                timer = null;
            }
        };

        const onVisibility = () => {
            if (document.visibilityState === 'hidden') {
                pauseClock();
                stopTimer();
            } else {
                resumeClock();
                startTimer();
            }
        };
        const onBlur = () => {
            pauseClock();
            focusedRef.current = false;
        };
        const onFocus = () => {
            focusedRef.current = true;
            lastActivityRef.current = Date.now();
            if (document.visibilityState === 'visible') {
                resumeClock();
            }
        };
        const onPageHide = () => {
            pauseClock();
            flushOnHide();
        };
        const onOnline = () => {
            setNetOffline(false);
            void beat(true);
        };
        const onOffline = () => {
            setNetOffline(true);
        };
        const onActivity = () => {
            if (!focusedRef.current || document.visibilityState !== 'visible') return;
            pauseClock();
            lastActivityRef.current = Date.now();
            resumeClock();
        };
        const activityEvents = ['pointerdown', 'keydown', 'wheel', 'touchstart', 'scroll'] as const;
        activityEvents.forEach(event => window.addEventListener(event, onActivity, { passive: true, capture: true }));

        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
        window.addEventListener('pagehide', onPageHide);
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);

        if (document.visibilityState === 'visible') {
            resumeClock();
            startTimer();
        }
        // Establish the server's clock before the first interval accrues time.
        void beat(true, true);

        return () => {
            activityEvents.forEach(event => window.removeEventListener(event, onActivity, true));
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('focus', onFocus);
            window.removeEventListener('pagehide', onPageHide);
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
            stopTimer();
            pauseClock();
            /* Leaving the lesson (switch or unmount): flush what remains so
               the last stretch of viewing is not lost. The server caps each
               heartbeat at `cap` seconds, so a long accumulation (retries
               while offline, a background stint) is CHUNKED into cap-sized
               deltas instead of silently truncated to one beat. Fire and
               forget; the next lesson's engine state starts clean. The
               closured lessonId/enrolmentId are used on purpose: the refs
               already point at the next lesson by the time cleanup runs. */
            const lesson = lessonId;
            const enrolment = enrolmentId;
            if (!suspendedRef.current) {
                const snapshot = optionsRef.current.getSnapshot();
                const claimCompleted = snapshot.completed && !completedAckedRef.current;
                let remaining = Math.floor(carriedRef.current);
                let first = true;
                while (remaining > 0 || (first && claimCompleted)) {
                    const seconds = Math.min(remaining, cap);
                    const body: HeartbeatRequest = { enrolment_id: enrolment };
                    if (seconds > 0) {
                        body.seconds_delta = seconds;
                    }
                    if (first) {
                        if (snapshot.progressPercent !== null) {
                            body.progress_percent = clampPercent(snapshot.progressPercent);
                        }
                        if (snapshot.positionSeconds !== null) {
                            body.position_seconds = Math.max(0, Math.floor(snapshot.positionSeconds));
                        }
                        if (claimCompleted) {
                            body.completed = true;
                        }
                    }
                    const key = first ? (beatKeyRef.current ?? crypto.randomUUID()) : crypto.randomUUID();
                    void optionsRef.current.send(lesson, body, key).catch(() => undefined);
                    remaining -= seconds;
                    first = false;
                }
            }
            carriedRef.current = 0;
            runningSinceRef.current = null;
            beatKeyRef.current = null;
            activeRef.current = false;
            generationRef.current += 1;
        };
    }, [enabled, lessonId, enrolmentId, intervalMs, cap, beat, flushOnHide, pauseClock, resumeClock]);

    const retryNow = useCallback(() => {
        void beat(true);
    }, [beat]);

    /* Stable identity while nothing changed, so consumers can lift the
       engine into state without render loops. */
    return useMemo(
        () => ({
            phase,
            savedAt,
            offline: netOffline || failures >= OFFLINE_AFTER_FAILURES,
            suspended,
            retryNow,
            flushNow: retryNow,
        }),
        [phase, savedAt, netOffline, failures, suspended, retryNow],
    );
}

/**
 * The SDK-bound senders of the real app. The pagehide path uses its own
 * client whose transport asks fetch for keepalive, so the flush survives
 * the page going away; everything else (URL mode, auth headers,
 * Idempotency-Key) stays identical to the main client.
 */
export function useHeartbeatSenders(): {
    send: HeartbeatEngineOptions['send'];
    sendOnHide: (lessonId: number, body: HeartbeatRequest, idempotencyKey: string) => void;
} {
    const client = useApiClient();
    const config = useMountConfig();
    const hideClient = useMemo(
        () => createVidyaLmsClient(config.api, (request) => globalThis.fetch(request, { keepalive: true })),
        [config.api],
    );
    const send = useCallback(
        async (lessonId: number, body: HeartbeatRequest, idempotencyKey: string) => {
            await client.POST('/lessons/{id}/progress', {
                params: { path: { id: lessonId } },
                body,
                headers: { 'Idempotency-Key': idempotencyKey },
            });
            // A successful write may have rejected an early completion claim.
            // Read the authoritative progress before marking the lesson complete.
            if (body.completed) {
                const { data } = await client.GET('/lessons/{id}', {
                    params: { path: { id: lessonId } },
                });
                return data?.progress?.status === 'completed';
            }
            return false;
        },
        [client],
    );
    const sendOnHide = useCallback(
        (lessonId: number, body: HeartbeatRequest, idempotencyKey: string) => {
            void hideClient
                .POST('/lessons/{id}/progress', {
                    params: { path: { id: lessonId } },
                    body,
                    headers: { 'Idempotency-Key': idempotencyKey },
                })
                .catch(() => undefined);
        },
        [hideClient],
    );
    return { send, sendOnHide };
}
