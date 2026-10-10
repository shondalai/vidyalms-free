import type { components } from './generated/schema.js';

/** RFC 9457 problem details, exactly as the contract defines them. */
export type ApiProblem = components['schemas']['Problem'];

export function isProblem(value: unknown): value is ApiProblem {
    return (
        typeof value === 'object' &&
        value !== null &&
        'title' in value &&
        'status' in value &&
        typeof (value as { title: unknown }).title === 'string' &&
        typeof (value as { status: unknown }).status === 'number'
    );
}

/**
 * A non-2xx answer from the API, carrying the problem document the server
 * sent. Every client call rejects with this type, so UI code can branch on
 * `status` and show `problem.detail` verbatim.
 */
export class ProblemError extends Error {
    readonly problem: ApiProblem;
    readonly status: number;

    constructor(problem: ApiProblem) {
        super(problem.detail ?? problem.title ?? `HTTP ${problem.status}`);
        this.name = 'ProblemError';
        this.problem = problem;
        this.status = problem.status ?? 0;
    }

    /** Field messages of a 422 problem, or an empty object. */
    get fieldErrors(): Record<string, string> {
        return this.problem.errors ?? {};
    }

    /**
     * Coerces whatever an error payload turned out to be into a ProblemError.
     * Servers behind proxies sometimes answer with HTML or plain text; those
     * become a synthetic problem so callers still get one error vocabulary.
     */
    static from(payload: unknown, status: number, statusText = ''): ProblemError {
        if (payload instanceof ProblemError) {
            return payload;
        }
        if (isProblem(payload)) {
            return new ProblemError(payload);
        }
        return new ProblemError({
            type: 'about:blank',
            title: statusText !== '' ? statusText : `HTTP ${status}`,
            status,
        });
    }
}
