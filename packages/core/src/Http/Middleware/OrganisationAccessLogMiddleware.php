<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Middleware;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessRecord;

/**
 * Records every organisation-scoped read in the admin access log.
 *
 * ISO/IEC 27001:2022 A.8.15 names access attempts among the events a system
 * logs, and in an organisations feature the threat is an administrator
 * **reading**: the mutation ledger org_membership_events already answers what
 * was changed, and answered nothing at all about who looked at a roster.
 *
 * It is middleware rather than a line in each handler for the reason the
 * standards already give about cross-cutting concerns, and for one specific
 * to this rule. **The site-manager path bypasses the organisation role
 * predicate**, which is the one place a compensating record was missing: a
 * vidyalms.manage holder reads an organisation's members without holding a
 * role in it, and a writer placed inside the delegated code path would have
 * recorded everybody except the person the manage waiver exists to make
 * visible. Here there is one writer, above dispatch, and it cannot tell the
 * two callers apart, which is the point.
 *
 * Refusals are recorded too, with no actor organisation and a record count of
 * zero, because an attempt on an organisation the caller has no claim on is
 * exactly the event the standard names.
 *
 * The write never changes the answer. A log that fails takes the response
 * with it only if the failure is allowed to escape, and an audit record that
 * can 500 a working read is an availability defect wearing a compliance
 * badge; so a failure is logged through the application logger and the
 * response is returned. The chain then shows a gap rather than a forgery,
 * which is the honest outcome.
 */
final class OrganisationAccessLogMiddleware implements Middleware
{
    /** Every recorded event key is prefixed and suffixed, so the vocabulary reads as one. */
    public const EVENT_PREFIX = 'org.';
    public const EVENT_SUFFIX = '.read';

    /** event_type is a string:40 column, and a truncated key is a key nothing can group by. */
    public const EVENT_MAX = 40;

    public function __construct(
        private readonly ActiveOrganisation $active,
        private readonly AdminAccessLogStore $log,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        $organisationId = OrgScopeMiddleware::organisationInPath($request->path);
        if ($request->method !== 'GET' || $request->principal->isGuest() || $organisationId === null) {
            return $next($request);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $refusal) {
            // A handler that throws is answered by the kernel's problem
            // mapping above this middleware, so the response never comes back
            // through here. The attempt is exactly the event A.8.15 names, so
            // it is recorded before the throw is allowed to continue.
            $this->write($request, null);

            throw $refusal;
        }

        // An unmatched route returns a 404 without touching any organisation.
        if ($response->status !== 404 && $response->status !== 405) {
            $this->write($request, $response);
        }

        return $response;
    }

    private function write(ApiRequest $request, ?ApiResponse $response): void
    {
        try {
            $organisationId = OrgScopeMiddleware::organisationInPath($request->path);
            if ($organisationId === null || ($this->active->resolvedScope() === null
                && !$this->log->organisationExists($organisationId))) {
                return;
            }
            $allowed = $response !== null && $response->status >= 200 && $response->status < 300;
            if (!$allowed && $this->log->hasRefusedAttemptToday(
                $request->principal->userId,
                OrgScopeMiddleware::organisationInPath($request->path),
            )) {
                return;
            }

            $this->log->record($this->record($request, $response));
        } catch (\Throwable $failure) {
            $this->logger->error('VidyaLMS could not record an organisation access-log row.', [
                'path' => $request->path,
                'exception' => $failure,
            ]);
        }
    }

    /**
     * Whether an anonymous attempt on this organisation is already on record
     * for today.
     *
     * A guest never reaches an organisation route, so the row their attempt
     * produces carries actor 0, no address hash and no request reference: it
     * is a row with no audit value that anybody on the internet can create at
     * will, in a table with no delete use case and a 400-day floor. Walking
     * /manage/organisations/1..n therefore grew the log without bound.
     *
     * The attempt is still recorded, which is what A.8.15 asks for, and it is
     * collapsed to one row per organisation per day, which is what makes it
     * evidence rather than a write primitive. The extra read runs only for a
     * guest, so an authenticated console read pays nothing for it.
     */

    private function record(ApiRequest $request, ?ApiResponse $response): AdminAccessRecord
    {
        $event = self::eventType($request->path);
        // The scope the route actually used, not a second resolution made
        // here. The middleware above dispatch can only ask for the "read"
        // right, so a role permitting "events" and not "read" served the event
        // history with a 200 and was recorded as a refusal that disclosed
        // nothing, which understates a real disclosure in the record that
        // exists to show it.
        $scope = $this->active->resolvedScope();
        $allowed = $response !== null && $response->status >= 200 && $response->status < 300;

        if ($scope === null || !$allowed) {
            return AdminAccessRecord::refused(
                $event,
                $request->principal->userId,
                OrgScopeMiddleware::organisationInPath($request->path),
                $request->path,
                $this->active->requestRef(),
                $this->active->ipHash(),
            );
        }

        return AdminAccessRecord::allowed(
            $event,
            $scope,
            $request->path,
            self::recordCount($response),
            AdminAccessLogStore::scopeDigest($scope),
            $this->subjectUserId($request),
            $this->active->requestRef(),
            $this->active->ipHash(),
        );
    }

    /**
     * How many rows the caller was shown.
     *
     * The paged shape every organisation read returns carries its own total,
     * which is the honest number: a caller who read page one of four hundred
     * learners learned that four hundred exist. A single-object read
     * discloses one row, and a response with no document discloses none.
     */
    public static function recordCount(?ApiResponse $response): int
    {
        $data = $response?->data;

        if ($data === null) {
            return 0;
        }

        if (isset($data['total']) && (\is_int($data['total']) || \is_string($data['total']))) {
            return max(0, (int) $data['total']);
        }

        if (isset($data['items']) && \is_array($data['items'])) {
            return \count($data['items']);
        }

        return $data === [] ? 0 : 1;
    }

    /**
     * The person a read was about, when the route resolved one.
     *
     * It is published by the handler rather than matched out of the path.
     * The first draft answered only for a path carrying `/users/{id}` or
     * `/learners/{id}` and no registered organisation route has either
     * segment, so the column was always null, the shipped
     * admin_access_log_subject_user_id index was over a column nothing wrote,
     * and AdminAccessLogQuery::forSubject(), the Canvas-derived anchor the
     * whole rule is built on, could never match a row.
     *
     * A member route names a membership rather than a user, and resolving one
     * to the other is a query this middleware has no store to run. The member
     * read already has the row, so it says who it was about; the path shape
     * is kept as a fallback for a route that does name a person directly.
     */
    private function subjectUserId(ApiRequest $request): ?int
    {
        $published = $this->active->subjectUserId();

        if ($published !== null) {
            return $published;
        }

        if (preg_match('#/(?:users|learners)/([1-9][0-9]*)(?:/|$)#', $request->path, $found) !== 1) {
            return null;
        }

        return (int) $found[1];
    }

    /**
     * The event key for a path: "org.members.read", "org.seats.pools.read".
     *
     * Numeric segments are dropped rather than kept, because an event key
     * naming a row id is a vocabulary with one term per row and nothing can
     * group by it. The organisation and the row are already on the record's
     * own columns.
     */
    public static function eventType(string $path): string
    {
        $path = rawurldecode($path);
        $segments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== '' && preg_match('/^[0-9]+$/', $segment) !== 1,
        ));

        // "manage" and "organisations" are what the prefix already says.
        $tail = array_slice($segments, 2);
        $key = self::EVENT_PREFIX . ($tail === [] ? 'detail' : implode('.', $tail)) . self::EVENT_SUFFIX;

        return \strlen($key) <= self::EVENT_MAX ? $key : substr($key, 0, self::EVENT_MAX);
    }
}
