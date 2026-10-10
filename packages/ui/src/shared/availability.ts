import { useEffect, useMemo, useRef } from 'react';
import type { components } from '@vidyalms/api-sdk';
import { registerFreeReasonRenderers } from '@/availability-reasons/free';
import { reasonRendererRegistry } from '@/availability-reasons/bridge';
import { availabilityStrings } from './availability-text';

/* The copy and the parameter readers live in a leaf module that free.ts can import without a cycle. */
export { availabilityStrings, list, text } from './availability-text';

/*
 * The learner side of availability, shared by the player, the quiz runner
 * and the workbench preview.
 *
 * Two rules from 13-learning-flow-and-credentials.md shape everything here.
 *
 * A reason is a KEY PLUS PARAMETERS and never a rendered sentence: the core
 * owns no locale, so the sentence is assembled on this side. An unknown key
 * therefore has to read as something honest rather than as a blank, which is
 * what reasonText's fallback is for. A Pro condition that ships after this
 * bundle is exactly that case.
 *
 * An instant arrives as a UTC datetime PLUS the zone it was authored in,
 * because a single pre-localised string is not enough: the learner and the
 * run can disagree and both readings are true. So openingText renders the
 * viewer's local reading and names the authoritative one beside it when the
 * two differ, rather than picking one and hiding the other.
 */

export type AvailabilityAccess = components['schemas']['AvailabilityAccess'];

/** The contract's "nothing is stored" access object, which is always present. */
export const OPEN_ACCESS: AvailabilityAccess = {
    state: 'available',
    reason: null,
    reason_parameters: {},
    opens_at: null,
    opens_at_timezone: null,
};

export function isBlocked(access: AvailabilityAccess | null | undefined): boolean {
    return access?.state === 'blocked';
}

const API_DATE = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/;

/** Milliseconds since the epoch of a contract datetime, or null when malformed. */
export function parseInstant(value: string | null | undefined): number | null {
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

function formatIn(ms: number, locale: string, timeZone?: string): string | null {
    try {
        return new Intl.DateTimeFormat(locale, {
            day: 'numeric',
            month: 'long',
            hour: '2-digit',
            minute: '2-digit',
            ...(timeZone === undefined ? {} : { timeZone }),
        }).format(new Date(ms));
    } catch {
        return null;
    }
}

export interface OpeningReading {
    /** The viewer's own local reading, which is what the line leads with. */
    local: string;
    /**
     * The same instant in the zone it was authored in, present only when it
     * reads differently. Null covers three cases at once: no zone was sent,
     * the zone is the viewer's own, and a zone this browser cannot resolve.
     */
    authoritative: string | null;
    /** The authored zone, for the "09:00 Europe/London" half of the line. */
    timezone: string | null;
}

/**
 * How an opening instant is put in front of a learner.
 *
 * Both readings are true and the design refuses to collapse them, so this
 * returns both and lets the caller decide the layout. The authoritative half
 * is omitted when it would say the same thing twice.
 */
export function openingReading(
    opensAt: string | null | undefined,
    timezone: string | null | undefined,
    locale: string,
): OpeningReading | null {
    const ms = parseInstant(opensAt);
    if (ms === null) {
        return null;
    }
    const local = formatIn(ms, locale);
    if (local === null) {
        return null;
    }
    const zone = typeof timezone === 'string' && timezone !== '' ? timezone : null;
    const authored = zone === null ? null : formatIn(ms, locale, zone);
    return {
        local,
        authoritative: authored !== null && authored !== local ? authored : null,
        timezone: zone,
    };
}

/**
 * The learner-facing sentence for one reason key, from the registry.
 *
 * The eight free keys are registrations rather than a switch, so a paid or
 * third-party condition contributes the sentence its own describe() key was
 * designed to carry, on the day it installs, the same way it already
 * contributes an authoring form. Before the registry the two halves of one
 * extension point were treated differently: seventeen conditions ship reason
 * keys and this surface could render five of them.
 *
 * A key nothing claims still degrades to the honest general line rather than
 * to a blank or to the raw key. A blocked item always says something.
 */
export function reasonText(access: AvailabilityAccess, locale: string): string {
    const key = access.reason;
    const opening = openingReading(access.opens_at, access.opens_at_timezone, locale);

    if (typeof key === 'string' && key !== '') {
        const registry = reasonRendererRegistry();
        registerFreeReasonRenderers(registry);

        const rendered = registry.get(key)?.render({
            access,
            locale,
            opensAtLocal: opening === null ? null : opening.local,
        });

        if (typeof rendered === 'string' && rendered !== '') {
            return rendered;
        }
    }

    if (opening !== null) {
        return availabilityStrings.availableOn(opening.local);
    }
    return availabilityStrings.notYet;
}

/**
 * The whole line, reason first and the authoritative reading appended when
 * the viewer's zone disagrees with the one the date was authored in.
 */
export function accessLine(access: AvailabilityAccess, locale: string): string {
    const sentence = reasonText(access, locale);
    const opening = openingReading(access.opens_at, access.opens_at_timezone, locale);
    if (opening === null || opening.authoritative === null || opening.timezone === null) {
        return sentence;
    }
    return `${sentence} ${availabilityStrings.authoritative(opening.authoritative, opening.timezone)}`;
}

export interface UnlockCandidate {
    id: number;
    title: string;
    access: AvailabilityAccess;
}

/**
 * Watches the earliest opening instant in view and calls back when it
 * passes, so a learner sitting on the outline hears about an unlock.
 *
 * It NEVER navigates. The design bans surprise navigation outright, and the
 * announcement is polite rather than assertive because nothing the learner
 * is doing is at risk. A passed instant is a hint that the server's answer
 * changed, not the answer itself: the caller refetches and the server
 * decides, because a leaf can name an instant while the node it sits under
 * names none.
 */
export function useUnlockWatch(
    items: readonly UnlockCandidate[],
    onUnlocked: (item: UnlockCandidate) => void,
): void {
    const callback = useRef(onUnlocked);
    callback.current = onUnlocked;

    const next = useMemo(() => {
        let soonest: { at: number; item: UnlockCandidate } | null = null;
        for (const item of items) {
            if (!isBlocked(item.access)) {
                continue;
            }
            const at = parseInstant(item.access.opens_at);
            if (at === null) {
                continue;
            }
            if (soonest === null || at < soonest.at) {
                soonest = { at, item };
            }
        }
        return soonest;
    }, [items]);

    useEffect(() => {
        if (next === null) {
            return;
        }
        const delay = next.at - Date.now();
        /* A moment already past is not rescheduled: the payload in hand is
           the server's answer and re-announcing it on every render would be
           a loop. The next refetch carries the change. */
        if (delay <= 0) {
            return;
        }
        /* setTimeout clamps above 2^31-1 ms and fires immediately instead,
           which would announce an unlock three weeks early. */
        const timer = window.setTimeout(() => callback.current(next.item), Math.min(delay + 1000, 2_147_483_000));
        return () => window.clearTimeout(timer);
    }, [next]);
}
