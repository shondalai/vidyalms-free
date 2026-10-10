import createClient, { type Client } from 'openapi-fetch';
import type { paths } from './generated/schema.js';
import { isProblem, ProblemError } from './problem.js';

/**
 * The "api" object of the mount contract (data-vy-config), verbatim.
 *
 * - mode "prefix": url = base + path. The WordPress mount and the Joomla
 *   API application work this way.
 * - mode "query": url = base + encodeURIComponent(path), where base already
 *   ends with its query key. The Joomla SITE app proxies the API through a
 *   component task URL, so the whole contract path (its own query string
 *   included) travels as one encoded parameter value.
 * - headers ride every request: X-CSRF-Token on Joomla, X-WP-Nonce on
 *   WordPress, or whatever else the host adapter put there.
 */
export interface ApiConfig {
    base: string;
    mode: 'prefix' | 'query';
    headers?: Record<string, string>;
}

/** Injection point for tests and exotic hosts; defaults to global fetch. */
export type FetchLike = (request: Request) => Promise<Response>;

export type VidyaLmsClient = Client<paths>;

/** Both bases resolve against a sentinel origin that never leaves this module. */
const SENTINEL_ORIGIN = 'https://vidyalms.invalid';

/** Mutations are replay-safe by contract; give every one a key by default. */
const IDEMPOTENT_METHODS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

/**
 * Builds the real request URL for a contract path (which may carry its own
 * query string, e.g. "/me/progress?course_id=3").
 */
export function buildApiUrl(config: Pick<ApiConfig, 'base' | 'mode'>, pathWithQuery: string): string {
    if (config.mode === 'query') {
        return config.base + encodeURIComponent(pathWithQuery);
    }
    return config.base + pathWithQuery;
}

async function toProblemError(response: Response): Promise<ProblemError> {
    const contentType = response.headers.get('content-type') ?? '';
    if (contentType.includes('json')) {
        try {
            const payload: unknown = await response.json();
            if (isProblem(payload)) {
                return new ProblemError(payload);
            }
        } catch {
            /* fall through to the synthetic problem */
        }
    }
    return ProblemError.from(undefined, response.status, response.statusText);
}

/**
 * The one HTTP door of the apps, typed by the generated contract.
 *
 * Behaviour on top of openapi-fetch:
 * - every request is rewritten through the config's URL mode;
 * - the host's auth headers ride every request, cookies included
 *   (credentials: same-origin);
 * - POST and PUT get a generated Idempotency-Key unless the caller set one;
 * - a non-2xx answer REJECTS with a typed ProblemError instead of returning
 *   an error field, so call sites and query hooks share one failure path.
 */
export function createVidyaLmsClient(config: ApiConfig, fetchLike?: FetchLike): VidyaLmsClient {
    const doFetch: FetchLike = fetchLike ?? ((request) => globalThis.fetch(request));
    return createClient<paths>({
        baseUrl: SENTINEL_ORIGIN,
        headers: config.headers,
        fetch: async (request: Request): Promise<Response> => {
            const parsed = new URL(request.url);
            const url = buildApiUrl(config, parsed.pathname + parsed.search);
            const method = request.method.toUpperCase();
            const headers = new Headers(request.headers);
            if (IDEMPOTENT_METHODS.has(method) && !headers.has('Idempotency-Key')) {
                headers.set('Idempotency-Key', crypto.randomUUID());
            }
            const init: RequestInit = {
                method,
                headers,
                credentials: 'same-origin',
                redirect: 'follow',
                signal: request.signal,
            };
            if (method !== 'GET' && method !== 'HEAD') {
                init.body = await request.arrayBuffer();
            }
            const response = await doFetch(new Request(url, init));
            if (!response.ok) {
                throw await toProblemError(response);
            }
            return response;
        },
    });
}
