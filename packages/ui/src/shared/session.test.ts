import { describe, expect, it, vi } from 'vitest';
import { createVidyaLmsClient, type ApiConfig, type FetchLike } from '@vidyalms/api-sdk';
import { hostSessionProbe, SessionTracker, withSessionTracking } from './session';
import {
    coreLessonRefusal,
    joomlaApiUnusableToken,
    joomlaStaleCsrfToken,
    noEnrolments,
    wordpressStaleNonce,
} from './testing/host-session-answers';

/*
 * The session tracker's rule: a 401/403 AFTER an authenticated success may
 * be an expired host token; the same status on first contact is a plain
 * permission problem and stays with the apps' permission states. A 401 is
 * an expiry outright. A 403 is one only when the host also refuses the
 * session check, because the core refuses a signed-in caller with the same
 * status.
 */

describe('SessionTracker', () => {
    it('does not treat a first-contact 401 as an expired session', () => {
        const tracker = new SessionTracker();
        tracker.observe(401);
        expect(tracker.snapshot()).toBe('fresh');
    });

    it('flips to expired only when a 401 or 403 follows a success', () => {
        const tracker = new SessionTracker();
        tracker.observe(200);
        expect(tracker.snapshot()).toBe('established');
        tracker.observe(403);
        expect(tracker.snapshot()).toBe('expired');
    });

    it('notifies subscribers exactly on state changes', () => {
        const tracker = new SessionTracker();
        const listener = vi.fn();
        tracker.subscribe(listener);
        tracker.observe(200);
        expect(listener).toHaveBeenCalledTimes(1);
        tracker.observe(204);
        expect(listener).toHaveBeenCalledTimes(1);
        tracker.observe(401);
        expect(listener).toHaveBeenCalledTimes(2);
    });

    it('ignores ordinary failures: 404s and 500s never expire the session', () => {
        const tracker = new SessionTracker();
        tracker.observe(200);
        tracker.observe(404);
        tracker.observe(500);
        expect(tracker.snapshot()).toBe('established');
    });

    it('withSessionTracking feeds every response status through the tracker', async () => {
        const tracker = new SessionTracker();
        const responses = [new Response(null, { status: 204 }), new Response(null, { status: 401 })];
        const inner = vi.fn(async () => responses.shift() as Response);
        const tracked = withSessionTracking(tracker, inner);
        await tracked(new Request('https://example.test/v1/courses'));
        expect(tracker.snapshot()).toBe('established');
        await tracked(new Request('https://example.test/v1/lessons/1'));
        expect(tracker.snapshot()).toBe('expired');
    });
});

describe('guest mounts', () => {
    it('never flips to expired when the mount is unauthenticated', () => {
        const tracker = new SessionTracker(false);

        // A guest journey: public reads succeed, then an authenticated-only
        // endpoint answers 401 by design. There is no session to expire.
        tracker.observe(200);
        tracker.observe(401);
        expect(tracker.snapshot()).toBe('established');

        tracker.observe(403);
        expect(tracker.snapshot()).toBe('established');
    });

    it('still expires for an authenticated mount after a working session', () => {
        const tracker = new SessionTracker(true);
        tracker.observe(200);
        tracker.observe(401);
        expect(tracker.snapshot()).toBe('expired');
    });
});

/** A session check the test answers by hand; the tracker is waiting on it meanwhile. */
function heldProbe() {
    const answers: Array<(status: number) => void> = [];
    const probe = vi.fn(() => new Promise<number>((resolve) => answers.push(resolve)));
    return { probe, answer: (status: number) => answers.shift()?.(status) };
}

describe('a refusal is not an expiry', () => {
    it('keeps a working session when the host still accepts it after a 403', async () => {
        const probe = vi.fn(async () => 200);
        const tracker = new SessionTracker(true, probe);
        tracker.observe(200);
        await tracker.settle(403);
        expect(probe).toHaveBeenCalledTimes(1);
        expect(tracker.snapshot()).toBe('established');
    });

    it.each([
        ['the Joomla passthrough, stale X-CSRF-Token', 403],
        ['WordPress, stale nonce', 403],
        ['the Joomla API application, unusable token', 401],
    ])('expires when the host refuses the session check as well: %s', async (_host, status) => {
        const probe = vi.fn(async () => status);
        const tracker = new SessionTracker(true, probe);
        tracker.observe(200);
        await tracker.settle(403);
        expect(probe).toHaveBeenCalledTimes(1);
        expect(tracker.snapshot()).toBe('expired');
    });

    it('expires nothing when the session check cannot reach the host or answers anything else', async () => {
        for (const answer of [0, 404, 429, 500]) {
            const tracker = new SessionTracker(true, async () => answer);
            tracker.observe(200);
            await tracker.settle(403);
            expect(tracker.snapshot()).toBe('established');
        }
        const unreachable = new SessionTracker(true, async () => {
            throw new TypeError('Failed to fetch');
        });
        unreachable.observe(200);
        await unreachable.settle(403);
        expect(unreachable.snapshot()).toBe('established');
    });

    it('asks nothing on a 401, which the core sends only to a caller it took for a guest', async () => {
        const probe = vi.fn(async () => 200);
        const tracker = new SessionTracker(true, probe);
        tracker.observe(200);
        await tracker.settle(401);
        expect(probe).not.toHaveBeenCalled();
        expect(tracker.snapshot()).toBe('expired');
    });

    it('asks nothing on first contact or on a guest mount', async () => {
        const probe = vi.fn(async () => 403);
        const fresh = new SessionTracker(true, probe);
        await fresh.settle(403);
        expect(fresh.snapshot()).toBe('fresh');

        const guest = new SessionTracker(false, probe);
        guest.observe(200);
        await guest.settle(403);
        expect(guest.snapshot()).toBe('established');
        expect(probe).not.toHaveBeenCalled();
    });

    it('asks once for refusals that arrive together, and again for a later one', async () => {
        const { probe, answer } = heldProbe();
        const tracker = new SessionTracker(true, probe);
        tracker.observe(200);
        const first = tracker.settle(403);
        const second = tracker.settle(403);
        expect(probe).toHaveBeenCalledTimes(1);
        answer(200);
        await Promise.all([first, second]);
        expect(tracker.snapshot()).toBe('established');

        const later = tracker.settle(403);
        expect(probe).toHaveBeenCalledTimes(2);
        answer(403);
        await later;
        expect(tracker.snapshot()).toBe('expired');
    });

    it('hands the refusal to the app only once the host has answered the session check', async () => {
        const { probe, answer } = heldProbe();
        const tracker = new SessionTracker(true, probe);
        const responses = [new Response(null, { status: 204 }), coreLessonRefusal()];
        const tracked = withSessionTracking(tracker, async () => responses.shift() as Response);
        await tracked(new Request('https://example.test/v1/courses'));

        let delivered = false;
        const refusal = tracked(new Request('https://example.test/v1/lessons/5')).then((response) => {
            delivered = true;
            return response;
        });
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(delivered).toBe(false);

        answer(200);
        expect((await refusal).status).toBe(403);
        expect(tracker.snapshot()).toBe('established');
    });
});

describe('hostSessionProbe', () => {
    const joomlaSite: ApiConfig = {
        base: 'http://localhost/joomla/index.php?option=com_vidyalms&task=api.route&path=',
        mode: 'query',
        headers: { 'X-CSRF-Token': 'f00d' },
    };
    const wordpress: ApiConfig = {
        base: 'http://localhost/vidyalms-wp/index.php/wp-json/vidyalms/v1',
        mode: 'prefix',
        headers: { 'X-WP-Nonce': 'c0ffee' },
    };
    const joomlaApi: ApiConfig = { base: 'http://localhost/joomla/api/index.php/v1/vidyalms', mode: 'prefix' };

    it("asks for one row of the caller's own enrolments, through the mount's URL mode and credential", async () => {
        const seen: Request[] = [];
        const probe = hostSessionProbe(joomlaSite, async (request) => {
            seen.push(request);
            return noEnrolments();
        });
        expect(await probe()).toBe(200);
        expect(seen).toHaveLength(1);
        expect(seen[0]?.method).toBe('GET');
        expect(seen[0]?.url).toBe(joomlaSite.base + encodeURIComponent('/me/enrolments?per_page=1'));
        expect(seen[0]?.headers.get('X-CSRF-Token')).toBe('f00d');
    });

    it.each([
        ['the Joomla passthrough, stale X-CSRF-Token', joomlaSite, joomlaStaleCsrfToken, 403],
        ['WordPress, stale nonce', wordpress, wordpressStaleNonce, 403],
        ['the Joomla API application, unusable token', joomlaApi, joomlaApiUnusableToken, 401],
    ])('reads the recorded stale-credential answer of %s', async (_host, api, staleAnswer, status) => {
        const probe = hostSessionProbe(api, async () => staleAnswer());
        expect(await probe()).toBe(status);
    });

    it('answers 0 when the host cannot be reached', async () => {
        const probe = hostSessionProbe(wordpress, async () => {
            throw new TypeError('Failed to fetch');
        });
        expect(await probe()).toBe(0);
    });
});

describe('a signed-in mount over each host', () => {
    it.each([
        ['the Joomla passthrough', 'http://localhost/joomla/index.php?option=com_vidyalms&task=api.route&path=', 'query', joomlaStaleCsrfToken],
        ['WordPress', 'http://localhost/vidyalms-wp/index.php/wp-json/vidyalms/v1', 'prefix', wordpressStaleNonce],
    ] as const)('%s: the core refusing a lesson keeps the session, a stale credential expires it', async (_host, base, mode, staleAnswer) => {
        let stale = false;
        const host: FetchLike = async (request) => {
            if (stale) {
                return staleAnswer();
            }
            return decodeURIComponent(request.url).includes('/lessons/5') ? coreLessonRefusal() : noEnrolments();
        };
        const api: ApiConfig = { base, mode };
        const tracker = new SessionTracker(true, hostSessionProbe(api, host));
        const client = createVidyaLmsClient(api, withSessionTracking(tracker, host));
        const openLesson = () => client.GET('/lessons/{id}', { params: { path: { id: 5 } } });

        await client.GET('/me/enrolments', { params: { query: { per_page: 1 } } });
        await expect(openLesson()).rejects.toMatchObject({ status: 403 });
        expect(tracker.snapshot()).toBe('established');

        stale = true;
        await expect(openLesson()).rejects.toMatchObject({ status: 403 });
        expect(tracker.snapshot()).toBe('expired');
    });
});
