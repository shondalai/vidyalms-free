import { instructorStrings as strings } from '../strings';

/*
 * Formatting the workbench does in one place. Datetimes cross the port
 * boundary as UTC "Y-m-d H:i:s" strings (the core's persistence
 * convention), which no browser parses reliably on its own, so every
 * reading here goes through parseApiDate.
 */

const API_DATE = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/;

/** Milliseconds since the epoch, or null when the value is not a contract datetime. */
export function parseApiDate(value: string | null | undefined): number | null {
    if (typeof value !== 'string') {
        return null;
    }
    const match = API_DATE.exec(value.trim());
    if (match === null) {
        return null;
    }
    const [, year, month, day, hour, minute, second] = match;
    const stamp = Date.UTC(
        Number(year),
        Number(month) - 1,
        Number(day),
        Number(hour),
        Number(minute),
        Number(second),
    );
    return Number.isNaN(stamp) ? null : stamp;
}

/** The contract's datetime shape, for the fields an author types into. */
export function isApiDate(value: string): boolean {
    return parseApiDate(value) !== null;
}

/**
 * How long something has been waiting, in the coarse words the design uses
 * ("4 days", "6 hours", "today"). Coarse on purpose: a grader needs the age
 * of the queue, not a clock.
 */
export function waitingFor(value: string | null | undefined, now: number = Date.now()): string {
    const stamp = parseApiDate(value);
    if (stamp === null) {
        return strings.time.never;
    }
    const minutes = Math.max(0, Math.floor((now - stamp) / 60_000));
    if (minutes < 1) {
        return strings.time.justNow;
    }
    if (minutes < 60) {
        return strings.time.minutes(minutes);
    }
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        return strings.time.hours(hours);
    }
    return strings.time.days(Math.floor(hours / 24));
}

/** A date the reader scans in a table: "today" for the current UTC day, else the stored day. */
export function shortDate(value: string | null | undefined, now: number = Date.now()): string {
    const stamp = parseApiDate(value);
    if (stamp === null) {
        return strings.time.never;
    }
    const day = new Date(stamp).toISOString().slice(0, 10);
    if (day === new Date(now).toISOString().slice(0, 10)) {
        return strings.time.today;
    }
    return day;
}

/** The stored datetime as the author typed it, minus the seconds noise. */
export function dateTimeText(value: string | null | undefined): string {
    const stamp = parseApiDate(value);
    return stamp === null ? strings.time.never : new Date(stamp).toISOString().slice(0, 16).replace('T', ' ');
}

/* One byte formatter for the whole bundle: the file picker states its size
   limit in the same words the gradebook reads a submission file in. */
export { formatBytes as fileSize } from '@/shared/format';

/** Percentages are shown whole; a stored 83.4 is 83, never 83.4%. */
export function wholePercent(value: number | null | undefined): number {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return 0;
    }
    return Math.round(Math.min(100, Math.max(0, value)));
}

/** Points read as "1", "1.5", never "1.00". */
export function pointsText(points: number): string {
    return Number.isInteger(points) ? String(points) : String(Math.round(points * 100) / 100);
}

export function minutesFromSeconds(seconds: number | null): number | null {
    return seconds === null ? null : Math.max(1, Math.round(seconds / 60));
}

/** Initials for the queue avatar; the name itself is always beside it. */
export function initials(name: string | null): string {
    const parts = (name ?? '').trim().split(/\s+/).filter((part) => part !== '');
    const first = parts[0]?.[0] ?? '?';
    const last = parts.length > 1 ? (parts[parts.length - 1]?.[0] ?? '') : '';
    return (first + last).toUpperCase();
}
