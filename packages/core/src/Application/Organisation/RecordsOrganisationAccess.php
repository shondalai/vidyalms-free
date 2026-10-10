<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

/**
 * What an organisation-scoped read tells the request about itself.
 *
 * The access log is written above dispatch, which is the only place that can
 * see a site manager's read and a delegated administrator's read as the same
 * event. That position costs it two facts it cannot work out for itself, and
 * both were wrong before this interface existed.
 *
 * **The scope the route actually used.** The tenancy middleware resolved its
 * own scope with the default "read" right, while a route resolves the right it
 * needs: a role permitting only "events" served the organisation's event
 * history with a 200 and was recorded as a refusal that disclosed nothing.
 * Re-resolving in two places is two answers to one question, so the resolver
 * publishes the scope it minted and the log records that.
 *
 * **Which person a read was about.** Resolving a membership id to a user id is
 * a query, and a middleware above dispatch has neither a store nor any
 * business running one. The handler that already loaded the row has both.
 *
 * It is an interface in the application layer rather than a dependency on the
 * middleware class, because the application layer may not import the HTTP
 * layer and because a use case that is handed a null here behaves exactly as
 * it did before: the publication is a record, never a control.
 */
interface RecordsOrganisationAccess
{
    /**
     * A scope a verified path minted, offered to whatever is recording.
     *
     * The first one wins. A write that resolves twice is one request about one
     * organisation, and the resolution that decided the caller may be here at
     * all is the one an auditor is asking about.
     */
    public function noteScope(OrgScope $scope): void;

    /** The person a read turned out to be about, when the route resolved one. */
    public function noteSubject(int $userId): void;
}
