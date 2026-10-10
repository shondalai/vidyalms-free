<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;

/**
 * The tenant a sweep acts under, recovered from the row it is acting on.
 *
 * **A cron has no caller, and inventing one is not on the table.** OrgScope's
 * constructor refuses anything that is not one positive organisation and one
 * positive actor, and that refusal is the control: a synthetic "system"
 * principal would be a hole in the access spine with a name every later
 * feature would reach for. What the four organisation sweeps have instead is
 * the same thing `org_access_termination_jobs` has and that
 * OrgScope::fromTerminationJob already mints from: a stored row that records
 * who authorised the thing being swept. A pool records the administrator who
 * funded it, a reservation records the administrator who spent the seat, and
 * an invitation records the administrator who sent it.
 *
 * So the sweep acts as that person, on that person's own tenant, under
 * BASIS_SYSTEM, and the ledger row it writes carries their id rather than a
 * zero. An auditor reading a reservation expiry sees the administrator whose
 * invitation reserved the seat, which is the honest answer to "on whose
 * authority did this seat come back".
 *
 * **This is the fifth witness and the one file that mints a swept scope.**
 * The tasks themselves never name OrgScope, so adding a sweep does not add a
 * way to reach organisation data; it adds a caller of this one.
 *
 * A row whose recorded actor is gone answers null and the sweep skips it,
 * reporting the skip. It does not fall back to another actor: an expiry
 * attributed to somebody who did not authorise it is worse than an expiry
 * that waits for a person to look at it.
 */
final class OrganisationSweepAuthority
{
    public function __construct(private readonly SweptRecordStore $records)
    {
    }

    /** The administrator who funded a pool, as that pool's tenant. */
    public function forPool(int $poolId): ?OrgScope
    {
        return $this->scope('seat_pools', 'created_by', $poolId);
    }

    /** The administrator who spent the seat a reservation is holding. */
    public function forReservation(int $assignmentId): ?OrgScope
    {
        return $this->scope('seat_assignments', 'assigned_by', $assignmentId);
    }

    /** The administrator who sent an invitation. */
    public function forInvitation(int $invitationId): ?OrgScope
    {
        return $this->scope('invitations', 'issued_by', $invitationId);
    }

    /**
     * The administrator who assigned a seat, for the listener that writes
     * the seat's progress transitions.
     *
     * The same witness and the same rule as the sweeps beside it: a domain
     * event has no caller either, and the assignment row records who spent
     * the seat. It is a separate method from {@see forReservation()} only so
     * the call site reads as what it is; both recover the tenant from
     * seat_assignments.assigned_by.
     */
    public function forAssignment(int $assignmentId): ?OrgScope
    {
        return $this->scope('seat_assignments', 'assigned_by', $assignmentId);
    }

    /**
     * The administrator who authored an organisation-anchored report schedule.
     *
     * The reports clock has no caller either, and the schedule row records
     * both halves the scope needs: the organisation it is anchored to and the
     * person who saved it. Without this the scheduled organisation reports ran
     * with a null scope, which made the provider throw and made the field
     * disclosure stage return every column unchanged.
     */
    public function forReportSchedule(int $scheduleId): ?OrgScope
    {
        return $this->scope('report_schedules', 'created_by', $scheduleId);
    }

    /**
     * The administrator who claimed a connection, as that connection tenant.
     *
     * The host-assertion path has no caller either: a person signs in, the host
     * says what it knows about them, and nothing about that is a request made by
     * an administrator. The connection row is what records who claimed the
     * issuer, so the same rule the four sweeps use applies unchanged, and a
     * membership the host assertion writes carries that administrator id rather
     * than a zero.
     */
    public function forConnection(int $connectionId): ?OrgScope
    {
        return $this->scope(OrgIdentityStore::CONNECTIONS, 'created_by', $connectionId);
    }

    /**
     * The person who created an organisation, as that organisation tenant.
     *
     * For a sweep acting on a row that records no actor of its own, which is
     * every membership row: a membership records who it is about and who
     * manages them, and neither authorised the sweep.
     */
    public function forOrganisation(int $organisationId): ?OrgScope
    {
        $authority = $this->records->organisationAuthority($organisationId);

        return $authority === null ? null : OrgScope::fromSweptRecord($authority[0], $authority[1]);
    }

    private function scope(string $table, string $actorColumn, int $id): ?OrgScope
    {
        $authority = $this->records->authority($table, $actorColumn, $id);

        return $authority === null ? null : OrgScope::fromSweptRecord($authority[0], $authority[1]);
    }
}
