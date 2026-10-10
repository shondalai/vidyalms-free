/*
 * Time helpers for the runner. Contract datetimes are UTC strings
 * "YYYY-MM-DD hh:mm:ss" (see the DateTime schema); everything here parses
 * them as UTC and formats for display. The attempt deadline derives from
 * the server's started_at, so a reload or resume keeps the timer honest.
 */

/** Epoch milliseconds of a contract datetime, or null when malformed. */
export function parseUtc(value: string | null | undefined): number | null {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(value)) {
        return null;
    }
    const ms = Date.parse(value.replace(' ', 'T') + 'Z');
    return Number.isNaN(ms) ? null : ms;
}

/** "12:41", "6:02", "1:00:00": the timer pill and review meta format. */
export function formatClock(totalSeconds: number): string {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const ss = String(s).padStart(2, '0');
    if (h > 0) {
        return `${h}:${String(m).padStart(2, '0')}:${ss}`;
    }
    return `${m}:${ss}`;
}

/** "12 minutes 41 seconds", "20 minutes": for timer aria labels. */
export function formatDuration(totalSeconds: number): string {
    const seconds = Math.max(0, Math.floor(totalSeconds));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const parts: string[] = [];
    if (h > 0) {
        parts.push(h === 1 ? '1 hour' : `${h} hours`);
    }
    if (m > 0) {
        parts.push(m === 1 ? '1 minute' : `${m} minutes`);
    }
    if (s > 0 || parts.length === 0) {
        parts.push(s === 1 ? '1 second' : `${s} seconds`);
    }
    return parts.join(' ');
}

/** "20 min": the summary stat tile format. */
export function formatLimitShort(totalSeconds: number): string {
    if (totalSeconds < 60) {
        return `${totalSeconds} sec`;
    }
    return `${Math.round(totalSeconds / 60)} min`;
}

/** "26 Aug" in the mount locale; falls back to the raw string. */
export function formatShortDate(value: string | null | undefined, locale: string): string | null {
    const ms = parseUtc(value);
    if (ms === null) {
        return null;
    }
    try {
        return new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short' }).format(new Date(ms));
    } catch {
        return value ?? null;
    }
}
