import { ProblemError } from '@/shared/api';

/*
 * Reading an RFC 9457 problem the way the contract tells clients to: branch
 * on status (and on the 422 errors member), never on the type or on prose.
 *
 * The order of checks on a course-scoped route is fixed by the contract:
 * 401 for a guest, 403 for a caller who may not use the surface or is not
 * an instructor of the course, 404 for a course that is not there. So a 403
 * from any of these routes is exactly the "you don't manage this course"
 * state of artboard 4b, and needs no guessing.
 */

export function statusOf(error: unknown): number {
    return error instanceof ProblemError ? error.status : 0;
}

export function deniedFor(error: unknown): boolean {
    const status = statusOf(error);
    return status === 401 || status === 403;
}

export function notFoundFor(error: unknown): boolean {
    return statusOf(error) === 404;
}

/**
 * The field messages of a 422, keyed by the field the server named. The
 * server is the authority on validation; the client's own schema only
 * spares an obvious round trip.
 */
export function validationErrors(error: unknown): Record<string, string> {
    if (!(error instanceof ProblemError) || error.status !== 422) {
        return {};
    }
    const raw = (error.problem as { errors?: unknown }).errors;
    if (typeof raw !== 'object' || raw === null) {
        return {};
    }
    const out: Record<string, string> = {};
    for (const [field, message] of Object.entries(raw as Record<string, unknown>)) {
        if (typeof message === 'string') {
            out[field] = message;
        } else if (Array.isArray(message)) {
            const first = message.find((entry): entry is string => typeof entry === 'string');
            if (first !== undefined) {
                out[field] = first;
            }
        }
    }
    return out;
}
