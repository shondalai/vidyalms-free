import { translate as uiText } from '@/shared/i18n';
import { localizedStrings } from '@/shared/i18n';

/*
 * The availability copy and the two reason-parameter readers, in a module
 * that imports nothing but i18n.
 *
 * They used to live in availability.ts, which imports the free reason
 * renderers (availability-reasons/free.ts), while free.ts imported these
 * three back: a module cycle inside the shared chunk. Nothing read across it
 * at module scope, so it held only by luck, and one eager read of this table
 * from free.ts made every entry die on a temporal dead zone error (review
 * probe, 2026-09-30). availability.ts re-exports all three, so no importer
 * changes, and vite.config.ts now fails the build on any source module cycle.
 *
 * The copy lives beside the logic rather than in one app's strings module
 * because three apps render the same reason from the same key, and a second
 * copy of these sentences is how two surfaces start disagreeing about what a
 * blocked item says. It is built through localizedStrings because the shared
 * chunk is evaluated on pages that carry no catalogue; see src/shared/i18n.ts.
 */

type Parameters = Record<string, unknown>;

export function text(parameters: Parameters, key: string): string | null {
    const value = parameters[key];
    if (typeof value === 'string' && value !== '') {
        return value;
    }
    if (typeof value === 'number') {
        return String(value);
    }
    return null;
}

export function list(parameters: Parameters, key: string): string | null {
    const value = parameters[key];
    if (Array.isArray(value)) {
        const parts = value.filter((entry): entry is string => typeof entry === 'string' && entry !== '');
        return parts.length === 0 ? null : parts.join(', ');
    }
    return text(parameters, key);
}

export const availabilityStrings = localizedStrings(() => ({
    notYet: uiText("vidyalms.ui.787406ddea2a10a4", "Not available yet"),
    availableOn: (when: string) => uiText("vidyalms.ui.8c645723009114ba", "Available on {p0}", { p0: when }),
    authoritative: (when: string, zone: string) => uiText("vidyalms.ui.a17b712fdd735a7b", "({p0} {p1})", { p0: when, p1: zone }),
    closed: uiText("vidyalms.ui.4bc7f76d36ade5ee", "No longer available"),
    completion: (title: string) => uiText("vidyalms.ui.8d88b5942035c08d", "Available once you complete {p0}", { p0: title }),
    completionPass: (title: string) => uiText("vidyalms.ui.59ed3341a7c85161", "Available once you pass {p0}", { p0: title }),
    completionUnnamed: uiText("vidyalms.ui.ae4107d2567c019e", "Available once an earlier item is complete"),
    targetMissing: uiText("vidyalms.ui.ecf8164cf2d90f45", "Not available: this restriction points at something that is no longer here."),
    score: (title: string, min: string) => uiText("vidyalms.ui.ad81a458cb3bba85", "Available once you score {p0}% or more on {p1}", { p0: min, p1: title }),
    scoreUnnamed: uiText("vidyalms.ui.38433ec524813b6f", "Available once you reach the required score"),
    role: uiText("vidyalms.ui.cb06255556705a09", "Not available with your current role"),
    enrolmentSource: (sources: string) => uiText("vidyalms.ui.dea59d3ebfbd2dbf", "Available to learners enrolled through: {p0}", { p0: sources }),
    enrolmentSourceUnnamed: uiText("vidyalms.ui.cfd3785621f694c7", "Available to learners enrolled another way"),
    providerMissing: uiText("vidyalms.ui.59ce39d0a34ec880", "Not available: the restriction on this item needs a package the site no longer has."),
    stateLabel: uiText("vidyalms.ui.787406ddea2a10a4", "Not available yet"),
    announceUnlocked: (title: string) => uiText("vidyalms.ui.50df8c54c882287b", "{p0} is now available.", { p0: title }),
} as const));
