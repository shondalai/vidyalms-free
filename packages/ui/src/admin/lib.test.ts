import { describe, expect, it } from 'vitest';
import {
    dayDifference,
    endOfDayUtc,
    expiryText,
    formatAgo,
    formatDate,
    formatNumber,
    initials,
    instructorNames,
    pageRange,
    parseApiDate,
    roundPercent,
    sourceLabel,
    todayIsoDate,
} from './lib';

const NOW = new Date('2026-09-01T10:00:00Z');

describe('parseApiDate', () => {
    it('preserves timeline offsets and accepts ISO dates without appending a second zone', () => {
        expect(parseApiDate('2026-09-01T10:00:00Z')?.toISOString()).toBe('2026-09-01T10:00:00.000Z');
        expect(parseApiDate('2026-09-01T01:00:00+05:30')?.toISOString()).toBe('2026-08-31T19:30:00.000Z');
        expect(parseApiDate('2026-09-01')?.toISOString()).toBe('2026-09-01T00:00:00.000Z');
    });
    it('reads the contract format as UTC', () => {
        expect(parseApiDate('2026-09-01 10:00:00')?.toISOString()).toBe('2026-09-01T10:00:00.000Z');
    });

    it('is null for null, empty and malformed values', () => {
        expect(parseApiDate(null)).toBeNull();
        expect(parseApiDate('')).toBeNull();
        expect(parseApiDate('not a date')).toBeNull();
    });
});

describe('formatDate', () => {
    it('drops the year inside the reader\'s current year', () => {
        expect(formatDate('2026-07-12 10:00:00', 'en-GB', NOW)).toBe('12 Jul');
    });

    it('keeps the year once it differs', () => {
        expect(formatDate('2025-07-12 10:00:00', 'en-GB', NOW)).toBe('12 Jul 2025');
    });
});

describe('formatAgo', () => {
    it('counts in the largest unit that fits', () => {
        expect(formatAgo('2026-09-01 09:54:00', 'en-GB', NOW)).toBe('6 minutes ago');
        expect(formatAgo('2026-09-01 08:00:00', 'en-GB', NOW)).toBe('2 hours ago');
    });

    it('falls back to a date beyond a month', () => {
        expect(formatAgo('2026-06-01 10:00:00', 'en-GB', NOW)).toBe('1 Jun');
    });

    it('is null when nothing ran', () => {
        expect(formatAgo(null, 'en-GB', NOW)).toBeNull();
    });
});

describe('expiryText', () => {
    it('says never when there is no expiry', () => {
        expect(expiryText(null, 'en-GB', NOW)).toEqual({ text: 'never', expired: false, soon: false });
    });

    it('counts whole UTC days ahead', () => {
        expect(expiryText('2026-09-04 23:59:59', 'en-GB', NOW).text).toBe('in 3 days');
        expect(expiryText('2026-09-02 00:30:00', 'en-GB', NOW).text).toBe('in 1 day');
    });

    it('marks the design\'s 14 day window as soon, and later dates as not', () => {
        expect(expiryText('2026-09-10 10:00:00', 'en-GB', NOW).soon).toBe(true);
        expect(expiryText('2026-09-20 10:00:00', 'en-GB', NOW).soon).toBe(false);
    });

    it('counts days back once it has passed', () => {
        const past = expiryText('2026-08-14 23:59:59', 'en-GB', NOW);
        expect(past).toEqual({ text: 'expired 18 days ago', expired: true, soon: false });
    });

    it('shows a date rather than a day count beyond a month', () => {
        expect(expiryText('2027-04-01 10:00:00', 'en-GB', NOW).text).toBe('on 1 Apr 2027');
    });

    it('counts today as today', () => {
        expect(expiryText('2026-09-01 23:59:59', 'en-GB', NOW).text).toBe('today');
    });
});

describe('dayDifference', () => {
    it('is whole UTC days, not elapsed hours', () => {
        expect(dayDifference(new Date('2026-09-01T23:00:00Z'), new Date('2026-09-02T01:00:00Z'))).toBe(1);
    });
});

describe('sourceLabel', () => {
    it('names the sources the contract names', () => {
        expect(sourceLabel('purchase')).toBe('Shop');
        expect(sourceLabel('manual')).toBe('Manual');
    });

    it('passes an unknown source through rather than hiding it', () => {
        expect(sourceLabel('seat-pool')).toBe('seat-pool');
    });
});

describe('initials', () => {
    it('takes the first and last word', () => {
        expect(initials('Tom Weiss')).toBe('TW');
        expect(initials('ana maria costa')).toBe('AC');
    });

    it('copes with one word and with nothing', () => {
        expect(initials('Prince')).toBe('P');
        expect(initials('   ')).toBe('?');
    });
});

describe('endOfDayUtc and todayIsoDate', () => {
    it('turns a date input into the end of that UTC day', () => {
        expect(endOfDayUtc('2026-10-01')).toBe('2026-10-01 23:59:59');
    });

    it('gives today in the shape a date input wants', () => {
        expect(todayIsoDate(NOW)).toBe('2026-09-01');
    });
});

describe('small formatters', () => {
    it('separates thousands in the reader\'s locale', () => {
        expect(formatNumber(1412, 'en-GB')).toBe('1,412');
    });

    it('rounds percentages and keeps null null', () => {
        expect(roundPercent(24.4)).toBe(24);
        expect(roundPercent(null)).toBeNull();
    });

    it('joins instructor names and skips removed accounts', () => {
        expect(instructorNames([{ name: 'R. Okafor' }, { name: null }, { name: 'M. Chen' }])).toBe(
            'R. Okafor, M. Chen',
        );
    });

    it('reports the rows on screen as a range', () => {
        expect(pageRange(1, 20, 17, 17)).toBe('1 to 17 of 17');
        expect(pageRange(2, 20, 45, 20)).toBe('21 to 40 of 45');
        expect(pageRange(1, 20, 0, 0)).toBe('0 total');
    });
});
