import { createContext, useContext, useSyncExternalStore } from 'react';
import { createVidyaLmsClient, ProblemError, type ApiConfig, type FetchLike } from '@vidyalms/api-sdk';

/*
 * Host session health, shared by both apps. Joomla's CSRF token and the
 * WordPress nonce expire while a lesson stays open; the API then answers
 * 401 or 403 on requests that worked minutes earlier. That must never be
 * read as "offline" or "not enrolled": the tracker watches every response
 * that travels through the SDK client, and a 401/403 AFTER at least one
 * authenticated success can flip it to expired. A 401/403 on first contact
 * stays with the apps' permission states (a signed-out visitor is not an
 * expired session).
 *
 * A 403 alone does not say which of two things happened. The core refuses a
 * signed-in caller with 403 problem+json (a lesson outside the free preview
 * opened without an enrolment, for one), and Joomla's passthrough answers a
 * stale X-CSRF-Token with a 403 problem+json of the very same shape, built
 * with the core's own problem class; WordPress answers a stale nonce with 403
 * rest_cookie_invalid_nonce before any route runs. What tells them apart on
 * both hosts is scope: a stale credential is refused on every path, a
 * refusal by the core is about one resource. So a 403 after a working
 * session asks the host once more, on a request every signed-in user may
 * make, and only a refused answer to that is an expiry. A 401 needs no
 * second question: the core answers 401 only to a caller it took for a
 * guest, so a signed-in mount that receives one has lost its credential.
 *
 * A GUEST mount (the host config carries no authenticated user) has no
 * session to expire: public reads succeed and /me reads answer 401 by
 * design, so the tracker never leaves fresh/established. Without this gate
 * a visitor on a free-preview lesson saw the session-expired screen the
 * moment the player probed an authenticated endpoint.
 */

export type SessionState = 'fresh' | 'established' | 'expired';

/**
 * Asks the host whether the mount's credential still stands: the status of
 * a request every signed-in user may make, or 0 when the host could not be
 * reached.
 */
export type SessionProbe = () => Promise<number>;

export class SessionTracker {
    private state: SessionState = 'fresh';
    private listeners = new Set<() => void>();
    private probing: Promise<void> | null = null;

    /**
     * @param authenticated  false for a guest mount, which has no session to expire
     * @param probe          the session check a 403 is confirmed with; a tracker
     *                       without one reads every 403 after a success as an expiry
     */
    constructor(
        private readonly authenticated: boolean = true,
        private readonly probe: SessionProbe | null = null,
    ) {}

    snapshot = (): SessionState => this.state;

    subscribe = (listener: () => void): (() => void) => {
        this.listeners.add(listener);
        return () => this.listeners.delete(listener);
    };

    /** Feed one HTTP outcome without waiting for a session check it starts. */
    observe(status: number): void {
        void this.settle(status);
    }

    /**
     * Feed one HTTP outcome and resolve once it is classified. The tracking
     * fetch waits on this, so the app never renders a refusal the tracker is
     * about to lift into the session-expired state.
     */
    settle(status: number): Promise<void> {
        if (status >= 200 && status < 300) {
            if (this.state === 'fresh') {
                this.setState('established');
            }
            return Promise.resolve();
        }
        if ((status !== 401 && status !== 403) || this.state !== 'established' || !this.authenticated) {
            return Promise.resolve();
        }
        if (status === 401 || this.probe === null) {
            this.setState('expired');
            return Promise.resolve();
        }
        return this.confirm(this.probe);
    }

    /** One question to the host however many refusals arrive while it is asked. */
    private confirm(probe: SessionProbe): Promise<void> {
        if (this.probing === null) {
            this.probing = probe()
                .then(
                    (status) => {
                        if (status === 401 || status === 403) {
                            this.setState('expired');
                        }
                    },
                    () => undefined,
                )
                .finally(() => {
                    this.probing = null;
                });
        }
        return this.probing;
    }

    private setState(next: SessionState): void {
        if (this.state !== next) {
            this.state = next;
            for (const listener of this.listeners) {
                listener();
            }
        }
    }
}

/** Wraps a FetchLike so every response outcome feeds the tracker. */
export function withSessionTracking(tracker: SessionTracker, inner?: FetchLike): FetchLike {
    const doFetch: FetchLike = inner ?? ((request) => globalThis.fetch(request));
    return async (request) => {
        const response = await doFetch(request);
        await tracker.settle(response.status);
        return response;
    };
}

/**
 * The session check of a mount: one row of the caller's own enrolments. Any
 * signed-in user may read it whatever they are enrolled in, and the core
 * refuses it only to a guest, with 401, so a 401 or 403 here is the host
 * refusing the credential itself. It goes through the mount's own URL mode
 * and headers, and through a client of its own: its answer must not feed the
 * tracker it is answering.
 */
export function hostSessionProbe(api: ApiConfig, fetchLike?: FetchLike): SessionProbe {
    const client = createVidyaLmsClient(api, fetchLike);
    return async () => {
        try {
            const { response } = await client.GET('/me/enrolments', { params: { query: { per_page: 1 } } });
            return response.status;
        } catch (error) {
            return error instanceof ProblemError ? error.status : 0;
        }
    };
}

export const SessionTrackerContext = createContext<SessionTracker | null>(null);

const NO_TRACKER = (): (() => void) => () => undefined;

/** True once the host session expired under an app that had worked. */
export function useSessionExpired(): boolean {
    const tracker = useContext(SessionTrackerContext);
    return useSyncExternalStore(
        tracker?.subscribe ?? NO_TRACKER,
        () => (tracker !== null ? tracker.snapshot() === 'expired' : false),
    );
}
