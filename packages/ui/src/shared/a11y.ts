import { useCallback } from 'react';
import { useMountEl } from './context';

/*
 * Focus and the live region channel registry.
 *
 * Three regions, three roles, no overlap (SC 4.1.3), plus the timer:
 *
 *   status  save confirmations, selection counts, progress, drag messages
 *   alert   validation failure and connection loss that puts an attempt at
 *           risk, and nothing else: using it for routine saves interrupts
 *   log     reorder announcements, which are incremental, not atomic
 *   timer   the ticking countdown value, aria-live off; the threshold
 *           announcements come from status instead
 *
 * The four are a registry owned by the shell. A question type never creates
 * a region of its own; it reaches these through ctx.announce(channel, msg).
 *
 * Messages are queued and serialised rather than overwriting each other:
 * "Picked up boils", "boils moved over gap 1" and "boils dropped into gap 1"
 * are three announcements, not one surviving message. Each container is
 * created before any content is inserted, because an insertion into a region
 * that was not already in the DOM is frequently not announced. Focus is
 * never moved to one.
 */

export type Politeness = 'polite' | 'assertive';

export type AnnounceChannel = 'status' | 'alert' | 'log' | 'timer';

export const ANNOUNCE_CHANNELS: readonly AnnounceChannel[] = ['status', 'alert', 'log', 'timer'];

/**
 * Moves keyboard focus to an element, making it programmatically focusable
 * first when needed. Use on route and step changes: focus the new step's
 * heading so screen reader and keyboard users land where the change is.
 */
export function moveFocus(target: HTMLElement | null | undefined): void {
    if (!target) {
        return;
    }
    if (target.tabIndex < 0 && !target.hasAttribute('tabindex')) {
        target.setAttribute('tabindex', '-1');
    }
    target.focus();
}

/** How long one message holds the channel before the next is written. */
const DRAIN_MS = 50;

/** How many messages a log channel keeps before the oldest are pruned. */
const LOG_HISTORY = 6;

const CHANNEL_ATTR = 'data-vy-channel';
const LEGACY_ATTR = 'data-vy-live';

const VISUALLY_HIDDEN =
    'position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;border:0';

interface ChannelSpec {
    role: string;
    live: 'polite' | 'assertive' | 'off';
    /** Log appends; the others replace, so the region reads as the one message. */
    append: boolean;
}

const CHANNEL_SPECS: Record<AnnounceChannel, ChannelSpec> = {
    status: { role: 'status', live: 'polite', append: false },
    alert: { role: 'alert', live: 'assertive', append: false },
    log: { role: 'log', live: 'polite', append: true },
    timer: { role: 'timer', live: 'off', append: false },
};

function createRegion(container: HTMLElement, channel: AnnounceChannel): HTMLElement {
    const spec = CHANNEL_SPECS[channel];
    const doc = container.ownerDocument;
    const region = doc.createElement('div');
    region.setAttribute(CHANNEL_ATTR, channel);
    region.setAttribute('role', spec.role);
    region.setAttribute('aria-live', spec.live);
    region.setAttribute('aria-atomic', 'false');
    if (spec.append) {
        region.setAttribute('aria-relevant', 'additions');
    }
    region.style.cssText = VISUALLY_HIDDEN;
    container.appendChild(region);
    return region;
}

class ChannelQueue {
    private readonly pending: string[] = [];

    private timer: number | null = null;

    constructor(
        private readonly region: HTMLElement,
        private readonly append: boolean,
    ) {}

    push(message: string): void {
        if (message === '') {
            return;
        }
        this.pending.push(message);
        if (this.timer === null) {
            this.write();
        }
    }

    private write(): void {
        const next = this.pending.shift();
        if (next === undefined) {
            this.timer = null;
            return;
        }
        const doc = this.region.ownerDocument;
        const line = doc.createElement('div');
        line.textContent = next;
        if (this.append) {
            this.region.appendChild(line);
            while (this.region.childElementCount > LOG_HISTORY) {
                this.region.firstElementChild?.remove();
            }
        } else {
            this.region.replaceChildren(line);
        }
        const view = doc.defaultView ?? window;
        this.timer = view.setTimeout(() => {
            this.timer = null;
            if (this.pending.length > 0) {
                this.write();
            }
        }, DRAIN_MS);
    }
}

export interface Announcer {
    (channel: AnnounceChannel, message: string): void;
}

interface AnnouncerRecord {
    announce: Announcer;
    regions: Record<AnnounceChannel, HTMLElement>;
}

const announcers = new WeakMap<HTMLElement, AnnouncerRecord>();

/**
 * The four channels of one container, created on first use and reused
 * afterwards. Creating them together is the point: a channel that only
 * exists once something is announced through it has already missed its
 * first message on several screen readers.
 */
export function channelRegistry(container: HTMLElement): AnnouncerRecord {
    const existing = announcers.get(container);
    if (existing !== undefined && container.contains(existing.regions.status)) {
        return existing;
    }
    const regions = {} as Record<AnnounceChannel, HTMLElement>;
    const queues: Partial<Record<AnnounceChannel, ChannelQueue>> = {};
    /*
     * The three ANNOUNCING channels are created together and before any
     * content: an insertion into a polite or assertive region that was not
     * already in the DOM is frequently not announced.
     *
     * The timer channel is not one of those. It is aria-live off by
     * definition, so it has nothing to miss, and the visible countdown pill
     * is itself the page's role=timer. Creating a second empty one on every
     * mount would give every screen two timers and no reader a value, so it
     * appears only if something writes to it.
     */
    for (const channel of ['status', 'alert', 'log'] as const) {
        const region = createRegion(container, channel);
        regions[channel] = region;
        queues[channel] = new ChannelQueue(region, CHANNEL_SPECS[channel].append);
    }
    const record: AnnouncerRecord = {
        regions,
        announce: (channel, message) => {
            let queue = queues[channel];
            if (queue === undefined) {
                const region = createRegion(container, channel);
                regions[channel] = region;
                queue = new ChannelQueue(region, CHANNEL_SPECS[channel].append);
                queues[channel] = queue;
            }
            queue.push(message);
        },
    };
    announcers.set(container, record);
    return record;
}

/** The channel announcer of a container, ready to hand to ctx.announce. */
export function createAnnouncer(container: HTMLElement | null): Announcer {
    const host = container ?? document.body;
    return channelRegistry(host).announce;
}

/*
 * The politeness-shaped helper the app screens outside the assessment
 * runner use (a saved course, an enrolled learner, a copied certificate
 * link). It keeps its own atomic region per politeness rather than routing
 * into the channels, and deliberately so: a role of status or alert on a
 * region that exists on every screen changes what getByRole finds for every
 * component on the page, and a course list has no drag, no reorder log and
 * no timer to justify the four. Screens migrate to a channel when they
 * acquire something a channel is for.
 */
function ensureLegacyRegion(container: HTMLElement, politeness: Politeness): HTMLElement {
    const existing = container.querySelector<HTMLElement>(`[${LEGACY_ATTR}='${politeness}']`);
    if (existing !== null) {
        return existing;
    }
    const region = container.ownerDocument.createElement('div');
    region.setAttribute(LEGACY_ATTR, politeness);
    region.setAttribute('aria-live', politeness);
    region.setAttribute('aria-atomic', 'true');
    region.style.cssText = VISUALLY_HIDDEN;
    container.appendChild(region);
    return region;
}

export function announce(container: HTMLElement | null, message: string, politeness: Politeness = 'polite'): void {
    const host = container ?? document.body;
    const region = ensureLegacyRegion(host, politeness);
    /* The clear-then-set dance makes a repeated identical message announce
       again; the channel registry gets the same effect from one node per
       message and aria-atomic false. */
    region.textContent = '';
    window.setTimeout(() => {
        region.textContent = message;
    }, DRAIN_MS);
}

/** announce() bound to the current mount element. */
export function useAnnounce(): (message: string, politeness?: Politeness) => void {
    const mountEl = useMountEl();
    return useCallback(
        (message: string, politeness: Politeness = 'polite') => {
            announce(mountEl, message, politeness);
        },
        [mountEl],
    );
}

/** The channel announcer bound to the current mount element. */
export function useAnnouncer(): Announcer {
    const mountEl = useMountEl();
    return useCallback(
        (channel: AnnounceChannel, message: string) => {
            createAnnouncer(mountEl)(channel, message);
        },
        [mountEl],
    );
}
