<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgTenant;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The one way to start a query over an organisation-owned table.
 *
 * The tenancy rule used to be a convention: every organisation query happened
 * to carry an organisation_id predicate because whoever wrote it remembered
 * to. A convention is not a guarantee, and the query that forgets is not a
 * bug that shows up in a test, it is one tenant's roster in another tenant's
 * screen.
 *
 * So the predicate is injected here rather than typed at each call site, and
 * the injection is not optional: the only entry point takes an OrgScope,
 * which has a private constructor and three witnessed factories, so a query
 * over one of these tables cannot exist without an authorisation having
 * produced the scope that narrows it.
 *
 * **It is deliberately not a repository.** It mints a Query and hands it
 * back, so a caller keeps the fluent API, its own joins and its own
 * projection; wrapping every read would have meant one method per read and a
 * god object inside a wave that spends its whole length refusing them. The
 * CI check named beside it is the other half: a scoped table named anywhere
 * but here is a build failure, so the guarantee is enforced rather than
 * documented.
 */
final class OrgScopedReader
{
    /**
     * Every table whose rows belong to exactly one organisation, and the
     * column that says which.
     *
     * organisations is here with its own primary key as the predicate,
     * because a read of the organisation row itself is as much a tenancy
     * question as a read of its members.
     *
     * admin_access_log is listed on its subject anchor so the CI check
     * watches the table, but it is never actually read through here: an audit
     * query matches on **either** anchor, because "what did my administrators
     * read" and "what did anybody read about my organisation" are one
     * question asked from two ends. Its anchored reader is
     * {@see AdminAccessLogStore::page()}, which takes an
     * {@see AdminAccessLogQuery} rather than a bare id.
     *
     * org_notification_secrets is here because it holds a sealed invitation
     * payload carrying a recipient address and an invitation secret, which is
     * the most sensitive organisation-owned row in the schema. It was on
     * neither list, so the CI check did not watch its unanchored lookup at
     * all and routing it correctly would have raised a LogicException: an
     * omission rather than a recorded exclusion, which is the difference this
     * list exists to make visible.
     *
     * Four organisation tables are deliberately absent, and each for a stated
     * reason. org_invitation_limits is a rate-limit bucket keyed on a hash and
     * carries no organisation at all. org_learning_locks is a lock over a
     * (user, course) pair held across organisations, which is the point of it.
     * org_unit_closure is reached only through a join from a table that is
     * here, and is never the leading table of a read. entitlements carries an
     * organisation_id that is nullable by design, because a self-purchased
     * entitlement belongs to nobody but the learner, so an unconditional
     * tenant predicate over it would hide exactly the rows a learner owns;
     * the commerce boundary owns it and OrgEntitlementBoundary is where the
     * organisation-side reads live. report_runs is the same shape one wave
     * later: its organisation_id is null for every site-scoped run, so an
     * unconditional predicate would hide every artefact a site manager
     * produced. Its own tenancy is carried by scope_digest, which is
     * OrgScope::digest() verbatim, and an organisation-scoped run is found by
     * the digest and the actor together rather than by the anchor alone.
     * import_jobs is the third of that shape and the reason is the same one
     * stated a third time: its organisation_id is null for every migration an
     * operator starts from the site console and for every job both development
     * sites already carry, so an unconditional tenant predicate would empty
     * the operations screen. The organisation side of it is narrowed
     * explicitly, in {@see OrgImportStore}'s four organisation reads, each of
     * which takes a non-defaultable OrgScope and compares organisation_id
     * to it. import_row_problems carries no organisation at
     * all: it is reached only through its job, and its tenancy is the job's.
     */
    public const SCOPED_TABLES = [
        'organisations' => 'id',
        'org_memberships' => 'organisation_id',
        'org_membership_events' => 'organisation_id',
        'org_role_assignments' => 'organisation_id',
        'org_units' => 'organisation_id',
        'org_course_releases' => 'organisation_id',
        // The organisation's own selection from the releases above. Scoped for
        // the same reason the releases are: an index of which courses one
        // customer bought their people is a commercial fact about that
        // customer, and the reverse read (which organisations reach this
        // course) is a site owner's question answered from an unscoped path.
        'org_catalogue_entries' => 'organisation_id',
        'org_access_termination_jobs' => 'organisation_id',
        'invitations' => 'organisation_id',
        'org_invitation_claims' => 'organisation_id',
        'seat_pools' => 'organisation_id',
        // The funding ledger, scoped like the pool it belongs to: an index of
        // what one organisation paid for is a commercial fact about that
        // organisation, and a read of it from outside would price its contract.
        'seat_assignments' => 'organisation_id',
        'seat_events' => 'organisation_id',
        'org_notification_secrets' => 'organisation_id',
        'admin_access_log' => 'subject_org_id',
        // The four identity tables. org_identity_links carries a nullable
        // organisation_id, which is not an exception to the rule: a link is
        // minted before any tenant is known, so a link with no organisation is
        // a link no organisation's console may list, and the predicate
        // excluding it is the correct answer rather than a gap.
        'org_connections' => 'organisation_id',
        'org_identity_links' => 'organisation_id',
        'org_domains' => 'organisation_id',
        'org_join_rules' => 'organisation_id',
        // The three compliance tables that name a tenant. compliance_cycles is
        // on the exclusion list below, because it hangs off an assignment that
        // carries the organisation and a second copy of the tenant would be a
        // second thing to keep in step.
        'compliance_requirements' => 'organisation_id',
        'compliance_assignments' => 'organisation_id',
        'assignment_exceptions' => 'organisation_id',
        // The erasure receipt. It holds no user id, and it is scoped all the
        // same: an index of which ledger rows one organisation redacted is a
        // fact about that organisation's people, and a read of it from outside
        // would count them.
        'org_ledger_redactions' => 'organisation_id',
    ];

    /**
     * The organisation tables that are deliberately not scoped here, each
     * with the reason above.
     *
     * It is a constant rather than prose because the completeness rule is
     * asserted against the schema: a table whose name starts "org_" or that
     * carries an organisation_id column and is on neither list fails the
     * build, so the next one cannot be added by forgetting.
     *
     * @var list<string>
     */
    public const UNSCOPED_ORGANISATION_TABLES = [
        'org_invitation_limits',
        'org_learning_locks',
        'org_unit_closure',
        'entitlements',
        'report_runs',
        // The saved schedule, on report_runs' own terms and for the same
        // reason. Its organisation_id says which organisation a schedule is
        // about; the read that matters is the scheduler's, which claims every
        // due schedule across the whole site in one ordered pass on
        // (state, next_run_at), and narrowing that to one tenant would mean a
        // site with two organisations needed two schedulers. The
        // per-organisation reads that do exist narrow on the column explicitly,
        // exactly as the run manifest's do.
        'report_schedules',
        'import_jobs',
        // The automation rule. Its organisation_id is a scope pointer that
        // says which organisation a rule is about, not a tenancy boundary
        // on the read: the fan-out asks "which enabled rules are on this
        // trigger" site-wide and in run order, and narrowing that read to
        // one organisation would make a site-wide rule invisible to it. The
        // rule builder is a site MANAGE surface in this stage, and the
        // per-organisation authoring surface arrives with the organisation
        // scoping of the rest of the engine rather than by narrowing the
        // fan-out.
        'automation_rules',
        // The AI ledger and the AI job. Both carry a NULLABLE organisation_id
        // stamped from the scope that raised the run, on exactly the terms
        // import_jobs above carries one: null is the site rather than a missing
        // tenant, and the reads that matter are site-wide by design. The usage
        // report groups spend by organisation ACROSS organisations, which is the
        // number a site owner sets a ceiling from; the operations screen lists
        // every job whatever tenant raised it; and the retention sweep purges by
        // retain_until across the whole table. Narrowing those to one
        // organisation would answer a different question in each case.
        //
        // The per-organisation reads that DO exist narrow on the column
        // explicitly and are named: the windowed spend ceiling passes
        // organisation_id as its scope column, and the run rows a build writes
        // carry the tenant the job recorded.
        'ai_runs',
        'ai_jobs',
    ];

    /**
     * The organisation-owned tables packages outside the core have declared.
     *
     * A package declares its own tenancy beside its own schema, in
     * `packages/{package}/schema/org-tenancy.php`, and the CI gate has read
     * every one of those files into the reads rule since that file existed. The
     * reader did not: {@see SCOPED_TABLES} is a core constant that no
     * declaration feeds, so a package naming a table `scoped` got a build that
     * failed every read of it outside this directory and a reader that threw
     * when it was routed here. Declaring the tenancy correctly was the one
     * thing a package could not do, and both Pro packages routed around it by
     * declaring their organisation-owned tables `unscoped` instead.
     *
     * So the declaration is contributed here at boot, once per table, and the
     * two halves finally share one source. It is write-once by design: a second
     * contribution naming a different column is a package disagreeing with
     * whoever got there first about which column carries the tenant, and that
     * is a build failure rather than a last-writer-wins.
     *
     * @var array<string, string>
     */
    private static array $contributed = [];

    private function __construct()
    {
    }

    /**
     * Adds one package-declared organisation-owned table to the reader.
     *
     * Idempotent for the same column, so a package booted twice in one process
     * contributes twice and means it once.
     */
    public static function contribute(string $table, string $column): void
    {
        if ($table === '' || $column === '') {
            throw new \LogicException('An organisation-scoped declaration names a table and its tenant column.');
        }

        $existing = self::SCOPED_TABLES[$table] ?? self::$contributed[$table] ?? null;

        if ($existing !== null && $existing !== $column) {
            throw new \LogicException(sprintf(
                'Table "%s" is already scoped on "%s", so it cannot also be scoped on "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $table,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $existing,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $column,
            ));
        }

        self::$contributed[$table] = $column;
    }

    /**
     * Contributes a whole `schema/org-tenancy.php` declaration.
     *
     * The shape is the one the gate already reads, so a package hands this the
     * file it already ships rather than restating it.
     *
     * @param array{scoped?: array<string, string>, unscoped?: array<string, string>} $declaration
     */
    public static function contributeDeclaration(array $declaration): void
    {
        foreach ($declaration['scoped'] ?? [] as $table => $column) {
            if (\is_string($table) && \is_string($column)) {
                self::contribute($table, $column);
            }
        }
    }

    /**
     * Every table this reader owns: the core's own and the contributed.
     *
     * @return array<string, string>
     */
    public static function tables(): array
    {
        return self::SCOPED_TABLES + self::$contributed;
    }

    /**
     * A query over one organisation-owned table, already narrowed.
     *
     * The alias is applied to the predicate too, so a caller that aliases the
     * table for a join does not silently get an unqualified column that the
     * adapter then resolves against whichever joined table happens to have
     * one.
     *
     * **It takes an OrgTenant as well as an OrgScope, and the widening is
     * narrow on purpose.** A queued notification records no actor, so the
     * fan-out cannot hold a scope and amendment A16 forbids inventing one;
     * what it holds instead is the organisation the raising use case resolved
     * and stamped on the occurrence. OrgTenant has the same construction
     * guarantee this reader relies on, a private constructor and two
     * witnessed factories, so a query still cannot exist without something
     * having resolved the tenant that narrows it. What the tenant does not
     * carry is unit reach, roles or a digest, so a caller holding one can
     * narrow a read and can authorise nothing.
     */
    public static function from(OrgScope|OrgTenant $scope, string $table, ?string $alias = null): Query
    {
        $column = self::tables()[$table] ?? throw new \LogicException(sprintf(
            'Table "%s" is not organisation scoped, so it must not be read through the scoped reader.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $table
        ));

        $query = $alias === null ? Query::from($table) : Query::from($table, $alias);

        return $query->where(($alias === null ? '' : $alias . '.') . $column, '=', $scope->organisationId);
    }

    /** Whether a table name is one this reader owns. */
    public static function owns(string $table): bool
    {
        return isset(self::tables()[$table]);
    }
}
