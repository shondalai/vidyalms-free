import { useCallback, useEffect, useRef, useState } from 'react';
import { useAnnounce } from '@/shared/a11y';
import { quizRunnerStrings as strings } from '../strings';
import { parseUtc } from '../lib/time';

export interface UseQuizTimerOptions {
    /** The attempt's server started_at; with expires_at it gives the total. */
    startedAt: string | null;
    /**
     * The SERVER deadline (attempt expires_at): started_at plus the time
     * limit, computed server-side. Null means the quiz is untimed and the
     * hook stays inert. Remaining time derives from this on every tick, so
     * a reload or a resume shows the same deadline the server enforces.
     */
    expiresAt: string | null;
    /** False once the attempt is submitted or being submitted. */
    running: boolean;
    onExpire: () => void;
}

export interface QuizTimer {
    /** Whole seconds remaining, or null when untimed or not started. */
    remaining: number | null;
    /** The full window (expires_at minus started_at), in seconds. */
    total: number | null;
    /** Inside the final two minutes. */
    warn: boolean;
    expired: boolean;
    /** The two-minute warning is showing. */
    warningOpen: boolean;
    dismissWarning: () => void;
}

const WARN_AT = 120;
const ANNOUNCE_AT = 300;

/**
 * The countdown for timed quizzes, an advisory display of the server
 * deadline. WCAG 2.2 shape: a visible timer, polite announcements on
 * thresholds (never every second), a role=alert warning at two minutes,
 * and expiry that the caller turns into an auto-submit with clear
 * messaging, never silent loss. There is NO extension affordance: a timed
 * exam is an essential exception under WCAG 2.2.1, the deadline is the
 * server's, and the warning copy says the limit is fixed.
 */
export function useQuizTimer({ startedAt, expiresAt, running, onExpire }: UseQuizTimerOptions): QuizTimer {
    const announce = useAnnounce();
    const [now, setNow] = useState(() => Date.now());
    const [warningOpen, setWarningOpen] = useState(false);

    const startMs = parseUtc(startedAt);
    const deadline = parseUtc(expiresAt);
    const active = deadline !== null;
    const total = active && startMs !== null ? Math.max(0, Math.round((deadline - startMs) / 1000)) : null;
    const remaining = deadline === null ? null : Math.max(0, Math.ceil((deadline - now) / 1000));
    const expired = remaining !== null && remaining <= 0;

    const expireFiredRef = useRef(false);
    const announcedRef = useRef<Set<number>>(new Set());
    const onExpireRef = useRef(onExpire);
    onExpireRef.current = onExpire;

    /* Tick once a second while there is time to count down. */
    useEffect(() => {
        if (!active || !running || expired) {
            return;
        }
        const timer = window.setInterval(() => setNow(Date.now()), 1000);
        return () => window.clearInterval(timer);
    }, [active, running, expired]);

    /* Threshold crossings: one polite announce at 5:00, the alert at 2:00. */
    useEffect(() => {
        if (remaining === null || !running) {
            return;
        }
        if (remaining <= ANNOUNCE_AT && remaining > WARN_AT && !announcedRef.current.has(ANNOUNCE_AT)) {
            announcedRef.current.add(ANNOUNCE_AT);
            announce(strings.timer.fiveMinutes);
        }
        if (remaining <= WARN_AT && remaining > 0 && !announcedRef.current.has(WARN_AT)) {
            announcedRef.current.add(WARN_AT);
            setWarningOpen(true);
        }
    }, [remaining, running, announce]);

    /* Expiry fires exactly once, including when a resume lands past it. */
    useEffect(() => {
        if (expired && running && !expireFiredRef.current) {
            expireFiredRef.current = true;
            setWarningOpen(false);
            onExpireRef.current();
        }
    }, [expired, running]);

    const dismissWarning = useCallback(() => setWarningOpen(false), []);

    return {
        remaining,
        total,
        warn: remaining !== null && remaining <= WARN_AT,
        expired,
        warningOpen,
        dismissWarning,
    };
}
