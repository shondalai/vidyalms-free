import { adminStrings } from './strings';

/*
 * Formatting and small pure helpers of the admin app. Everything here is a
 * function of its arguments: "now" is always passed in, so the tests state
 * the clock instead of racing it.
 *
 * Datetimes arrive from the contract as UTC strings "YYYY-MM-DD hh:mm:ss"
 * and are parsed as UTC. Rendering happens in the reader's locale but on
 * UTC calendar days, so "expires in 3 days" means the same thing to the
 * administrator and to the expiry sweep.
 */

const DAY_MS = 86_400_000;

/** Parses a contract datetime; null for null, and null for anything malformed. */
export function parseApiDate(value: string | null | undefined): Date | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const normalized = value.replace(' ', 'T');
    const utc = /^\d{4}-\d{2}-\d{2}$/.test(normalized) ? `${normalized}T00:00:00Z` : `${normalized}Z`;
    const parsed = new Date(/(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized) ? normalized : utc);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/** Whole UTC days since the epoch; the unit every "in N days" is counted in. */
function utcDayIndex(date: Date): number {
    return Math.floor(date.getTime() / DAY_MS);
}

/** UTC calendar days from a to b, negative when b is in the past. */
export function dayDifference(from: Date, to: Date): number {
    return utcDayIndex(to) - utcDayIndex(from);
}

/** "12 Jul", and "12 Jul 2025" once the year is not the reader's current one. */
export function formatDate(value: string | null | undefined, locale: string, now = new Date()): string | null {
    const date = parseApiDate(value);
    if (date === null) {
        return null;
    }
    const sameYear = date.getUTCFullYear() === now.getUTCFullYear();
    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: sameYear ? undefined : 'numeric',
        timeZone: 'UTC',
    }).format(date);
}

const AGO_UNITS: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 1000],
    ['minute', 60_000],
    ['hour', 3_600_000],
    ['day', DAY_MS],
];

/** "6 minutes ago", localised. Anything older than 30 days becomes a date. */
export function formatAgo(value: string | null | undefined, locale: string, now = new Date()): string | null {
    const date = parseApiDate(value);
    if (date === null) {
        return null;
    }
    const elapsed = now.getTime() - date.getTime();
    if (elapsed > 30 * DAY_MS) {
        return formatDate(value, locale, now);
    }
    const relative = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    let unit: Intl.RelativeTimeFormatUnit = 'second';
    let size = 1000;
    for (const [candidate, ms] of AGO_UNITS) {
        if (Math.abs(elapsed) >= ms) {
            unit = candidate;
            size = ms;
        }
    }
    return relative.format(-Math.round(elapsed / size), unit);
}

/**
 * The Expires column and the learner record's entitlement line. Within a
 * month it counts days, because that is what an administrator acts on;
 * beyond it the date says more than "in 214 days".
 */
export function expiryText(
    expiresAt: string | null | undefined,
    locale: string,
    now = new Date(),
): { text: string; expired: boolean; soon: boolean } {
    const date = parseApiDate(expiresAt);
    if (date === null) {
        return { text: adminStrings.expiry.never, expired: false, soon: false };
    }
    const days = dayDifference(now, date);
    if (days < 0) {
        const ago = Math.abs(days);
        return { text: adminStrings.expiry.expiredDaysAgo(ago), expired: true, soon: false };
    }
    if (days === 0) {
        return { text: adminStrings.expiry.today, expired: false, soon: true };
    }
    if (days <= 30) {
        return { text: adminStrings.expiry.inDays(days), expired: false, soon: days <= 14 };
    }
    return {
        text: adminStrings.expiry.on(formatDate(expiresAt, locale, now) ?? ''),
        expired: false,
        soon: false,
    };
}

/** The entitlement source, named where the contract names it, else verbatim. */
export function sourceLabel(source: string): string {
    return adminStrings.enrolments.sources[source] ?? source;
}

/** Two letters for the learner record avatar; one for a single-word name. */
export function initials(name: string): string {
    const words = name.trim().split(/\s+/).filter((word) => word !== '');
    if (words.length === 0) {
        return '?';
    }
    const first = words[0] as string;
    const last = words.length > 1 ? (words[words.length - 1] as string) : '';
    return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
}

/**
 * A date from an <input type="date"> as a contract datetime. The end of
 * that UTC day, so "expires 30 September" includes the thirtieth.
 */
export function endOfDayUtc(isoDate: string): string {
    return `${isoDate} 23:59:59`;
}

/** Today as an <input type="date"> value, for the min attribute. */
export function todayIsoDate(now = new Date()): string {
    return now.toISOString().slice(0, 10);
}

/** Thousands separated in the reader's locale: 1412 reads as "1,412". */
export function formatNumber(value: number, locale: string): string {
    return new Intl.NumberFormat(locale).format(value);
}

/** Rounds a contract percentage for display; nulls stay null. */
export function roundPercent(value: number | null | undefined): number | null {
    return value === null || value === undefined ? null : Math.round(value);
}

/** "R. Okafor, M. Chen", the course meta line of the design's course list. */
export function instructorNames(instructors: { name: string | null }[]): string {
    return instructors
        .map((instructor) => instructor.name)
        .filter((name): name is string => name !== null && name !== '')
        .join(', ');
}

/** The rows of the page currently shown, as "1 to 20 of 173". */
export function pageRange(page: number, perPage: number, total: number, shown: number): string {
    if (total === 0 || shown === 0) {
        return adminStrings.list.total(total);
    }
    const from = (page - 1) * perPage + 1;
    return adminStrings.list.range(from, from + shown - 1, total);
}
