import { describe, expect, it } from 'vitest';
import {
    accessLine,
    isBlocked,
    OPEN_ACCESS,
    openingReading,
    parseInstant,
    reasonText,
    type AvailabilityAccess,
} from './availability';

/*
 * The learner side of availability.
 *
 * The interesting cases are the ones a screen cannot show: an unknown reason
 * key from a package that shipped after this bundle, a zone the viewer does
 * not share, and a blocked item with no instant at all. Every one of them
 * has to read as a sentence rather than as a blank, because a blocked row
 * that says nothing is the failure the whole shown-with-the-reason default
 * exists to avoid.
 */

function blocked(overrides: Partial<AvailabilityAccess> = {}): AvailabilityAccess {
    return {
        state: 'blocked',
        reason: null,
        reason_parameters: {},
        opens_at: null,
        opens_at_timezone: null,
        ...overrides,
    };
}

describe('the access object', () => {
    it('reads the contract "no restrictions" object as available', () => {
        expect(isBlocked(OPEN_ACCESS)).toBe(false);
        expect(isBlocked(blocked())).toBe(true);
        expect(isBlocked(null)).toBe(false);
    });

    it('parses a contract datetime as UTC and refuses anything else', () => {
        expect(parseInstant('2026-03-03 09:00:00')).toBe(Date.UTC(2026, 2, 3, 9, 0, 0));
        expect(parseInstant('3 March 2026')).toBeNull();
        expect(parseInstant(null)).toBeNull();
    });
});

describe('the reason sentence', () => {
    it('names the date for a window that has not opened', () => {
        const access = blocked({
            reason: 'availability.date.from',
            reason_parameters: { from: '2026-03-03 09:00:00', tz: 'Europe/London' },
            opens_at: '2026-03-03 09:00:00',
            opens_at_timezone: 'Europe/London',
        });
        expect(reasonText(access, 'en-GB')).toContain('3 March');
    });

    it('says a closed window is no longer available rather than promising a date', () => {
        const access = blocked({ reason: 'availability.date.until', reason_parameters: {} });
        expect(reasonText(access, 'en-GB')).toBe('No longer available');
    });

    it('names the item a completion condition depends on, and distinguishes passing from finishing', () => {
        expect(
            reasonText(
                blocked({
                    reason: 'availability.completion.required',
                    reason_parameters: { title: 'Module 2 check', state: 'complete' },
                }),
                'en-GB',
            ),
        ).toBe('Available once you complete Module 2 check');
        expect(
            reasonText(
                blocked({
                    reason: 'availability.completion.required',
                    reason_parameters: { title: 'Module 2 check', state: 'complete_pass' },
                }),
                'en-GB',
            ),
        ).toBe('Available once you pass Module 2 check');
    });

    it('renders the score band and the enrolment source list from their parameters', () => {
        expect(
            reasonText(
                blocked({ reason: 'availability.score.min', reason_parameters: { title: 'Final exam', min: 80 } }),
                'en-GB',
            ),
        ).toBe('Available once you score 80% or more on Final exam');
        expect(
            reasonText(
                blocked({
                    reason: 'availability.enrolment_source.required',
                    reason_parameters: { sources: ['purchase', 'seat'] },
                }),
                'en-GB',
            ),
        ).toContain('purchase, seat');
    });

    it('says a missing provider is missing rather than pretending the item is merely late', () => {
        expect(reasonText(blocked({ reason: 'availability.provider_missing' }), 'en-GB')).toContain(
            'needs a package the site no longer has',
        );
    });

    /* A key this bundle has never seen is the normal case for a condition
       that shipped in a package after it, and it must still say something. */
    it('degrades an unknown reason key to the honest general line', () => {
        expect(reasonText(blocked({ reason: 'acme.forum_posts.required' }), 'en-GB')).toBe('Not available yet');
    });

    it('uses the instant of an unknown key when it carries one, because a date is better than nothing', () => {
        const access = blocked({
            reason: 'acme.forum_posts.required',
            opens_at: '2026-03-03 09:00:00',
            opens_at_timezone: 'UTC',
        });
        expect(reasonText(access, 'en-GB')).toContain('3 March');
    });
});

/*
 * The zones are chosen relative to whatever zone the runner is in rather
 * than hardcoded, because a suite that assumed UTC would pass on CI and fail
 * on the machine of anybody east of Greenwich, which is exactly the class of
 * bug this pair of readings exists to prevent.
 */
const VIEWER_ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone;
const OTHER_ZONE = VIEWER_ZONE === 'Pacific/Kiritimati' ? 'Pacific/Niue' : 'Pacific/Kiritimati';

describe('the two readings of one instant', () => {
    it('gives the local reading and the authored one when they differ', () => {
        const reading = openingReading('2026-07-03 09:00:00', OTHER_ZONE, 'en-GB');
        expect(reading).not.toBeNull();
        expect(reading?.timezone).toBe(OTHER_ZONE);
        expect(reading?.authoritative).not.toBeNull();
        expect(reading?.authoritative).not.toBe(reading?.local);
    });

    it('omits the authored reading when it would say the same thing twice', () => {
        expect(openingReading('2026-07-03 09:00:00', VIEWER_ZONE, 'en-GB')?.authoritative).toBeNull();
        expect(openingReading('2026-07-03 09:00:00', null, 'en-GB')?.authoritative).toBeNull();
    });

    it('answers null for a malformed instant rather than rendering it raw', () => {
        expect(openingReading('soon', 'UTC', 'en-GB')).toBeNull();
    });

    it('appends the authoritative reading to the line only when the zones disagree', () => {
        const away = accessLine(
            blocked({
                reason: 'availability.date.from',
                opens_at: '2026-07-03 09:00:00',
                opens_at_timezone: OTHER_ZONE,
            }),
            'en-GB',
        );
        expect(away).toContain(OTHER_ZONE);

        const same = accessLine(
            blocked({
                reason: 'availability.date.from',
                opens_at: '2026-07-03 09:00:00',
                opens_at_timezone: VIEWER_ZONE,
            }),
            'en-GB',
        );
        expect(same).not.toContain(VIEWER_ZONE);
    });
});
