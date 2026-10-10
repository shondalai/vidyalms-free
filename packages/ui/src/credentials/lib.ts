/*
 * Formatting for the credential pages.
 *
 * Datetimes arrive from the contract as UTC strings "YYYY-MM-DD hh:mm:ss"
 * and are parsed as UTC. Rendering happens in the reader's locale but on UTC
 * calendar days, so "expired 12 July" means the same thing to the holder and
 * to the relying party checking the code from another continent.
 *
 * A machine format on a human artefact is a defect the design names by
 * itself, which is why nothing here ever prints Y-m-d.
 */

export function parseApiDate(value: string | null | undefined): Date | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const parsed = new Date(`${value.replace(' ', 'T')}Z`);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
}

/** "12 July 2026", localised. Null for null and for anything malformed. */
export function formatCredentialDate(value: string | null | undefined, locale: string): string | null {
    const date = parseApiDate(value);
    if (date === null) {
        return null;
    }
    return new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(date);
}

/** "1 Sep 2026, 14:02 UTC", the stamp the verification card carries. */
export function formatCredentialDateTime(date: Date, locale: string): string {
    const formatted = new Intl.DateTimeFormat(locale, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        timeZone: 'UTC',
    }).format(date);
    return `${formatted} UTC`;
}
