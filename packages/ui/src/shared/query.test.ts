import { describe, expect, it } from 'vitest';
import { ProblemError } from '@vidyalms/api-sdk';
import { MAX_QUERY_RETRIES, shouldRetry } from './query';

/*
 * The retry policy every mount and both harnesses share. It is tested on its
 * own because it is the difference between a screen that renders its
 * permission state and one that keeps a skeleton up while a refusal is
 * retried, and because a harness that configured its own policy could not
 * have shown that difference.
 */

function problem(status: number): ProblemError {
    return new ProblemError({ type: 'about:blank', title: 'Problem', status });
}

describe('query retry policy', () => {
    it.each([401, 403, 404, 409, 422])('never retries a %d, which is a decided answer', (status) => {
        expect(shouldRetry(0, problem(status))).toBe(false);
    });

    it('retries a server error once and then stops', () => {
        expect(shouldRetry(0, problem(500))).toBe(true);
        expect(shouldRetry(MAX_QUERY_RETRIES, problem(500))).toBe(false);
    });

    it('retries a dropped connection once, which is not a problem document at all', () => {
        expect(shouldRetry(0, new TypeError('Failed to fetch'))).toBe(true);
        expect(shouldRetry(MAX_QUERY_RETRIES, new TypeError('Failed to fetch'))).toBe(false);
    });
});
