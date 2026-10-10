import { QueryClient } from '@tanstack/react-query';
import { ProblemError } from '@vidyalms/api-sdk';

/*
 * One TanStack Query configuration, shared by every mount and by both test
 * harnesses. It lives here rather than inline in mountApp because a harness
 * that configured itself was a harness that could not fail the way the
 * shipped bundle fails: the retry policy is the difference between a screen
 * that shows its permission state and one that keeps a spinner up while a
 * refusal is retried.
 */

/**
 * How many times a failed query is tried again.
 *
 * A 4xx from the contract is a decided answer: 401 is a signed-out caller,
 * 403 a refusal, 404 a missing row, 409 a conflict, 422 a body the server
 * rejected. None of them becomes true on a second attempt, so retrying one
 * only delays the state the screen is built to render. A 5xx or a dropped
 * connection is worth exactly one more attempt.
 */
export const MAX_QUERY_RETRIES = 1;

/**
 * How long the one retry waits.
 *
 * TanStack's default is a second, growing exponentially, which is the right
 * shape for a policy that retries several times. This one retries once, and
 * a whole second of skeleton before the single attempt is a long time to
 * hold a screen for a blip that is usually over in a few hundred
 * milliseconds. Long enough to let a restarting process answer, short
 * enough that a real outage reaches its error state promptly.
 */
export const QUERY_RETRY_DELAY_MS = 400;

export function shouldRetry(failureCount: number, error: unknown): boolean {
    if (error instanceof ProblemError && error.status >= 400 && error.status < 500) {
        return false;
    }

    return failureCount < MAX_QUERY_RETRIES;
}

export function createAppQueryClient(): QueryClient {
    return new QueryClient({
        defaultOptions: {
            queries: {
                retry: shouldRetry,
                retryDelay: QUERY_RETRY_DELAY_MS,
                staleTime: 30_000,
                refetchOnWindowFocus: false,
            },
            // A write is never retried on its own: every mutation carries an
            // idempotency key and its own retry control, so replaying one is
            // the reader's decision, not a default.
            mutations: { retry: false },
        },
    });
}
