/*
 * Test-only: what each host answers when the credential an app mount holds
 * has gone stale, next to what the core answers when it refuses a caller it
 * recognised. The stale answers were recorded on the Joomla and WordPress
 * development sites on 1 October 2026 by sending a stale credential as a
 * guest, which takes the same server branch as a signed-in visitor whose
 * session expired. Both hosts refuse a stale credential on every path, the
 * public ones included; a refusal by the core is about one resource. The
 * session tracker's tests and the player's app test feed these verbatim, so a
 * host that changes its answer is a fixture to record again, not a guess to
 * adjust.
 */

function json(status: number, contentType: string, body: unknown): Response {
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': contentType } });
}

/**
 * Joomla's site and administrator passthrough (task=api.route, the query URL
 * mode every Joomla app mount uses) on a stale X-CSRF-Token. The adapter
 * builds it with the core's own problem class, so it has the shape of a core
 * refusal and only the detail differs.
 */
export function joomlaStaleCsrfToken(): Response {
    return json(403, 'application/problem+json', {
        type: 'about:blank',
        title: 'Forbidden',
        status: 403,
        detail: 'The CSRF token is missing or invalid.',
    });
}

/** WordPress on a stale wp_rest nonce, answered by WordPress itself before any route runs. */
export function wordpressStaleNonce(): Response {
    return json(403, 'application/json; charset=UTF-8', {
        code: 'rest_cookie_invalid_nonce',
        message: 'Cookie check failed',
        data: { status: 403 },
    });
}

/**
 * The Joomla API application on a token it cannot use: RestController falls
 * back to a guest principal and the core asks for authentication.
 */
export function joomlaApiUnusableToken(): Response {
    return json(401, 'application/problem+json', { type: 'about:blank', title: 'Authentication required', status: 401 });
}

/**
 * The core refusing a signed-in caller one resource: ApiKernel maps
 * NotAuthorizedException to 403 for a caller it recognised. This is the lesson
 * outside the free preview, opened without an enrolment.
 */
export function coreLessonRefusal(): Response {
    return json(403, 'application/problem+json', {
        type: 'about:blank',
        title: 'Forbidden',
        status: 403,
        detail: 'An enrolment in the course is required to open this lesson.',
    });
}

/** What GET /me/enrolments?per_page=1 answers a signed-in caller with no enrolment at all. */
export function noEnrolments(): Response {
    return Response.json({ items: [], total: 0, page: 1, per_page: 1 });
}
