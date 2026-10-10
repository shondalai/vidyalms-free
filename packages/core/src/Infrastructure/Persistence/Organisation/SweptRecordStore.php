<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * What the organisation sweeps read before they have a tenant.
 *
 * The four free organisation sweeps have a problem the request paths do not:
 * a cron has no caller, so there is nobody to authorise a scope from, and a
 * sweep that invented a system actor would be exactly the hole in the access
 * spine that BackgroundActorResolver refuses to open. What every row they act
 * on does have is the person who authorised it: `seat_pools.created_by`
 * funded the pool, `seat_assignments.assigned_by` spent the seat and
 * `invitations.issued_by` sent the mail. That is the same shape
 * `org_access_termination_jobs` records and the same shape
 * OrgScope::fromTerminationJob already mints from.
 *
 * So the reads that find the due rows and the read that recovers each row's
 * recorded authority both live here, in the persistence home the tenancy gate
 * treats as the place these tables belong. They deliberately carry no tenant
 * predicate: they are the reads that MINT the tenant rather than consume it,
 * which is the first of the two witnessed categories, and anchoring them on a
 * tenant the caller supplied would be asking the answer to supply the
 * question. Every read after them is narrowed by the scope they produced.
 */
final class SweptRecordStore
{
    /** How many rows one sweep run touches, so a neglected site drains over several runs. */
    public const BATCH = 100;

    public function __construct(public readonly DatabasePort $database, private readonly ClockPort $clock)
    {
    }

    public function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * The organisation and the recorded actor behind one row, or null.
     *
     * Null rather than an exception, because a sweep that finds a row and
     * then cannot recover its authority has found a row somebody deleted
     * between the two statements, which is a race and not a failure.
     *
     * @return array{0: int, 1: int}|null
     */
    /**
     * The organisation and the person who created it, for a sweep acting on a
     * row that records no actor of its own.
     *
     * `org_memberships` has no `created_by`: a membership records who it is
     * about and who manages them, and neither is the authority a sweep acts
     * under. The organisation own creator is, and it is the same rule one step
     * out: the sweep acts as the person whose organisation the row belongs to,
     * on that person own tenant, under BASIS_SYSTEM.
     *
     * @return array{0: int, 1: int}|null
     */
    public function organisationAuthority(int $organisationId): array|null
    {
        $row = $this->database->selectOne(
            Query::from('organisations')->select(['id', 'created_by'])->where('id', '=', $organisationId)
        );

        if ($row === null) {
            return null;
        }

        $actorId = (int) $row['created_by'];

        return $organisationId > 0 && $actorId > 0 ? [$organisationId, $actorId] : null;
    }

    /**
     * The memberships whose agreed end date has passed and which are still
     * active, oldest first, across every organisation.
     *
     * `org_memberships.ends_at` is written by every roster load that carries a
     * OneRoster endDate and by the console, and it had no reader anywhere: no
     * sweep, filter or access check consulted it, so an organisation-sourced
     * entitlement followed a membership that ended two years ago. This is the
     * read that gives it one.
     *
     * @return list<array<string, mixed>>
     */
    public function dueTermMemberships(string $state, int $limit = self::BATCH): array
    {
        return $this->database->selectAll(
            Query::from('org_memberships')
                ->select(['id', 'organisation_id', 'user_id', 'ends_at'])
                ->where('state', '=', $state)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<=', $this->now())
                ->orderBy('id')
                ->limit(max(1, $limit))
        );
    }

    /**
     * The organisation and the recorded actor behind one row, or null.
     *
     * Null rather than an exception, because a sweep that finds a row and
     * then cannot recover its authority has found a row somebody deleted
     * between the two statements, which is a race and not a failure.
     *
     * @return array{0: int, 1: int}|null
     */
    public function authority(string $table, string $actorColumn, int $id): array|null
    {
        $row = $this->database->selectOne(Query::from($table)
            ->select(['organisation_id', $actorColumn])->where('id', '=', $id));

        if ($row === null) {
            return null;
        }

        $organisationId = (int) $row['organisation_id'];
        $actorId = (int) $row[$actorColumn];

        return $organisationId > 0 && $actorId > 0 ? [$organisationId, $actorId] : null;
    }

    /**
     * Reservations whose window has run out, oldest first.
     *
     * Served by the (reserved_until) index, which exists for this one query:
     * it asks across every organisation at once and so has no organisation to
     * lead with, exactly as the pool term sweep's (state, ends_at) does.
     *
     * @return list<array<string, mixed>>
     */
    public function dueReservations(string $state, int $limit = self::BATCH): array
    {
        return $this->database->selectAll(Query::from('seat_assignments')
            ->select(['id', 'organisation_id', 'pool_id'])
            ->where('state', '=', $state)
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<=', $this->now())
            ->orderBy('reserved_until')->limit($limit));
    }

    /**
     * Pools whose term has ended and which are still in a state that can end.
     *
     * @param list<string> $states
     *
     * @return list<array<string, mixed>>
     */
    public function endedPools(array $states, int $limit = self::BATCH): array
    {
        return $this->database->selectAll(Query::from('seat_pools')
            ->select(['id', 'organisation_id'])
            ->whereIn('state', $states)
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $this->now())
            ->orderBy('ends_at')->limit($limit));
    }

    /**
     * Pending invitations past their expiry, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function expiredInvitations(int $limit = self::BATCH): array
    {
        return $this->database->selectAll(Query::from('invitations')
            ->select(['id', 'organisation_id', 'seat_assignment_id', 'email'])
            ->where('state', '=', 'pending')
            ->where('expires_at', '<=', $this->now())
            ->orderBy('expires_at')->limit($limit));
    }

    /**
     * Reservations still held by an invitation that has already finished.
     *
     * A hard bounce, and any other path that ends an invitation without going
     * through the expiry, leaves the seat it reserved held by a promise
     * nobody can accept. The reservation sweep eventually returns it when its
     * window runs out, but that is a seat held for up to a week for an
     * address the mail server has already told us does not exist.
     *
     * The join is what keeps this pass cheap and terminating: a link that has
     * already been released matches nothing, so the same rows are not
     * rescanned every hour for ever.
     *
     * @param list<string> $terminalStates
     *
     * @return list<array<string, mixed>>
     */
    public function strandedReservations(array $terminalStates, string $reserved, int $limit = self::BATCH): array
    {
        return $this->database->selectAll(Query::from('invitations', 'i')
            ->select(['i.id', 'i.organisation_id', 'i.seat_assignment_id'])
            ->join('seat_assignments', 'a', 'i.seat_assignment_id', 'a.id', [['i.organisation_id', 'a.organisation_id']])
            ->whereIn('i.state', $terminalStates)
            ->where('a.state', '=', $reserved)
            ->orderBy('i.id')->limit($limit));
    }

    /**
     * Every pool a reconciliation pass should look at, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function poolsToReconcile(int $limit, int $after = 0): array
    {
        return $this->database->selectAll(Query::from('seat_pools')
            ->select(['id', 'organisation_id'])
            ->where('id', '>', $after)
            ->orderBy('id')->limit($limit));
    }

    /**
     * Every catalogue entry that grants by itself, oldest first.
     *
     * **`grant_mode` had a writer, an index and a reader, and nothing ever
     * acted on `auto_grant`.** An organisation could publish a course to its
     * people and mark it as granted automatically, and the column was stored,
     * indexed and returned by the console with no sweep anywhere that turned it
     * into an entitlement. The read has to live here, in the persistence home,
     * for the reason every sweep read does: it asks the whole site a question
     * and recovers the tenant per row, and a read narrowed on a tenant would be
     * a sweep that only ever ran for one organisation.
     *
     * It is here rather than in the package that acts on it because **no seam
     * exists for a package to add a site-wide read of a free organisation
     * table.** Every such read is enumerated in this file and witnessed in the
     * CI gate, which is the guarantee working, and it is also the reason this
     * one line is a core edit: the tenancy rules make an unanchored read a
     * signed decision, and only the core can sign one.
     *
     * The window is bounded by `starts_at` and `ends_at` in the caller rather
     * than here, because those two are compared against the caller's clock and
     * a store that read a clock would be a store with a policy in it.
     *
     * @return list<array<string, mixed>>
     */
    public function autoGrantCatalogueEntries(string $grantMode, int $limit, int $after = 0): array
    {
        return $this->database->selectAll(Query::from('org_catalogue_entries')
            ->select([
                'id',
                'organisation_id',
                'course_id',
                'source_key',
                'scope_type',
                'scope_id',
                'applies_to_descendants',
                'starts_at',
                'ends_at',
            ])
            ->where('grant_mode', '=', $grantMode)
            ->where('state', '=', 'active')
            ->whereNotNull('course_id')
            ->where('id', '>', $after)
            ->orderBy('id')->limit($limit));
    }

    /**
     * Expired claim rows an invitation sweep may prune, oldest first.
     *
     * @return list<int>
     */
    public function prunableClaims(int $limit = self::BATCH): array
    {
        $rows = $this->database->selectAll(Query::from('org_invitation_claims')
            ->select(['id'])->where('expires_at', '<=', $this->now())
            ->orderBy('id')->limit($limit));

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /** Removes one claim row by id. It is only ever called for a row this store already found expired. */
    public function pruneClaim(int $id): void
    {
        $this->database->delete('org_invitation_claims', ['id' => $id]);
    }
}
