<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Organisation\AttemptDisposition;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationOverviewReader;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgVisibilityContext;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

final class ManageOrganisationLearning
{
    /** Why a direct organisation grant ended, on the entitlement and on every attempt it closed. */
    public const REVOKED_REASON = 'org_grant_revoked';

    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $organisations,
        private readonly OrganisationLearningStore $store,
        /**
         * The organisation rule and the release rule as one object.
         *
         * It replaced a CourseReleasePolicy held here beside the scope
         * resolver, which is the pairing three use cases were inlining
         * separately. Holding the composition rather than its halves is what
         * stops the fourth site running them in the other order.
         */
        private readonly ComposedAccess $composed,
        private readonly GrantEntitlement $grant,
        private readonly RevokeEntitlement $revoke,
        private readonly OrganisationLearningEvents $events,
        private readonly OrganisationAccessTermination $termination,
        private readonly UsersPort $users,
        /**
         * The attempt half of the revocation ceremony, on the free grant
         * route as well as the seat ones.
         *
         * This route had none, so an organisation grant revoked at minute
         * forty of an attempt reached RevokeEntitlement's last-access branch
         * and abandoned the attempt outright: answers kept, no grade, and
         * nothing on the row saying who ended it. That is the stranded
         * ungraded attempt the ceremony exists to remove, surviving on the one
         * revoking route the seat work did not cover.
         *
         * Optional so a harness with no assessment engine still builds a
         * learning use case, on the same terms SeatLifecycle states.
         */
        private readonly ?CloseAttemptsOnAccessLoss $attempts = null,
        /**
         * The one place the organisation overview is computed.
         *
         * `GET /manage/organisations/{id}/summary` is on the published contract
         * and in the generated SDK, and amendment C7 rules that it stays
         * permanently as an alias while the report itself becomes the registered
         * `org-overview` definition. Both now read this reader, so there is one
         * set of queries rather than two that can drift; the one that drifts is
         * always the one nobody is looking at.
         *
         * Trailing and defaulted, so a harness built before it existed keeps
         * constructing this use case and keeps the queries it already had.
         */
        private readonly ?OrganisationOverviewReader $overview = null,
        /**
         * The organisation visibility seam, which this list is the reader for.
         *
         * Trailing and defaulted on the standing rule; absent, the list shows
         * the organisation's own rows and nothing else, which is the free
         * provider's own answer and the published fail-closed direction.
         */
        private readonly ?OrgPolicyResolution $policies = null,
    ) {
    }

    public function requireTarget(Principal $actor, int $orgId, ?int $userId = null, ?int $entitlementId = null): void
    {
        $scope = $this->access->require($actor, $orgId, 'learning');
        if ($userId !== null && $this->organisations->activeMemberId($scope, $userId) === null) {
            throw new EntityNotFoundException('No active organisation member here.');
        }
        if ($scope->unitId !== null && $entitlementId !== null) {
            $row = $this->store->database->selectOne(Query::from('entitlements')->where('organisation_id', '=', $orgId)
                ->where('id', '=', $entitlementId)) ?? throw new EntityNotFoundException('No organisation entitlement here.');
            $this->organisations->member($scope, (int) $row['org_membership_id']);
        }
    }

    /** @return array<string, mixed> */
    public function releases(Principal $actor, int $orgId, int $page = 1, int $perPage = 25): array
    {
        $scope = $this->access->require($actor, $orgId);
        OrganisationInput::page($page, $perPage);

        return $this->store->page(Query::from('org_course_releases', 'r')->select([
            'r.id', 'r.organisation_id', 'r.course_id', 'r.state', 'r.starts_at', 'r.ends_at', 'c.title AS course_title',
        ])->join('courses', 'c', 'r.course_id', 'c.id')->where('r.organisation_id', '=', $scope->organisationId), $page, $perPage, 'r.id');
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function releaseCourse(Principal $actor, int $orgId, array $input): array
    {
        $this->access->requireSiteManager($actor);
        OrganisationInput::only($input, ['expected_revision', 'course_id', 'state', 'starts_at', 'ends_at']);
        $revision = OrganisationInput::integer($input, 'expected_revision');
        $courseId = OrganisationInput::integer($input, 'course_id');
        $state = $input['state'] ?? 'active';
        if (!\in_array($state, ['active', 'withdrawn'], true)) {
            throw ValidationException::forField('state', 'Expected active or withdrawn.');
        }
        $starts = OrganisationInput::datetime($input['starts_at'] ?? null, 'starts_at');
        $ends = OrganisationInput::datetime($input['ends_at'] ?? null, 'ends_at');
        if ($starts !== null && $ends !== null && $starts >= $ends) {
            throw ValidationException::forField('ends_at', 'The release must end after it starts.');
        }

        return $this->store->database->transactional(function () use ($actor, $orgId, $revision, $courseId, $state, $starts, $ends): array {
            $scope = $this->access->require($actor, $orgId, 'learning');
            if ($state === 'active') {
                $this->termination->requireSettled($scope, courseId: $courseId);
            }
            $course = $this->store->database->selectOne(Query::from('courses')->where('id', '=', $courseId));
            if ($course === null || $course['state'] !== 'published') {
                throw new ConflictException('Only a published course may be released to an organisation.');
            }
            $next = $this->organisations->claim($scope, $revision);
            $existing = $this->store->database->selectOne(Query::from('org_course_releases')
                ->where('organisation_id', '=', $orgId)->where('course_id', '=', $courseId));
            $values = ['course_id' => $courseId, 'state' => $state, 'starts_at' => $starts, 'ends_at' => $ends,
                'released_by' => $actor->userId];
            if ($existing === null) {
                $id = $this->store->insert('org_course_releases', $scope, $values);
            } else {
                $id = (int) $existing['id'];
                $this->store->update('org_course_releases', $scope, $id, $values);
            }
            if ($state === 'withdrawn') {
                $cleanup = $this->termination->execute($scope, $next, 'org_course_withdrawn', courseId: $courseId);
            }
            $this->organisations->event($scope, $next, 'course.release_updated', null, ['course_id' => $courseId, 'state' => $state]);

            return ['organisation_revision' => $next, 'item' => ['id' => $id, 'organisation_id' => $orgId] + $values]
                + (isset($cleanup) ? ['access_state' => 'disabled', 'cleanup' => $cleanup] : []);
        });
    }

    /** @return array<string, mixed> */
    public function list(Principal $actor, int $orgId, int $page = 1, int $perPage = 25, string $status = ''): array
    {
        $scope = $this->access->require($actor, $orgId);
        OrganisationInput::page($page, $perPage);
        if (!\in_array($status, ['', 'active', 'revoked', 'expired'], true)) {
            throw ValidationException::forField('status', 'Unsupported entitlement status.');
        }
        $query = Query::from('entitlements', 'e')->select([
            'e.id', 'e.organisation_id', 'e.user_id', 'e.course_id', 'e.source', 'e.status', 'e.starts_at', 'e.expires_at',
            'c.title AS course_title', 'n.id AS enrolment_id', 'n.status AS enrolment_status', 'n.progress_percent',
        ])->join('courses', 'c', 'e.course_id', 'c.id')
            ->leftJoin('enrolments', 'n', 'e.user_id', 'n.user_id', [['e.course_id', 'n.course_id']])
            ->leftJoin('org_memberships', 'm', 'e.org_membership_id', 'm.id', [['e.organisation_id', 'm.organisation_id']]);
        $this->applyVisibility($scope, $query);
        $scope->constrainUnit($query, 'm.org_unit_id');
        if ($status !== '') {
            $query->where('e.status', '=', $status);
        }
        $result = $this->store->page($query, $page, $perPage, 'e.id');
        $names = $this->users->namesFor(array_values(array_map(static fn (array $row): int => (int) $row['user_id'], $result['items'])));
        foreach ($result['items'] as &$row) {
            $row['user_name'] = $names[(int) $row['user_id']] ?? null;
            $row['enrolment_id'] = $row['enrolment_id'] === null ? null : (int) $row['enrolment_id'];
            $row['progress_percent'] = $row['progress_percent'] === null ? null : (float) $row['progress_percent'];
        }

        return $result;
    }

    /**
     * Narrows the learning list by what the organisation is allowed to see.
     *
     * The `OrgVisibility` seam was seated, published in the edition table with
     * a fail-closed miss direction, and read by nothing at all: a Business
     * policy widening an organisation's view to rows the learner declared had
     * nowhere to register that anything consulted. This is the reader.
     *
     * The organisation's own rows are outside the decision and are always
     * shown, because they are not a disclosure question: an organisation may
     * see what it granted, and a policy able to remove them would be a policy
     * able to blind an organisation to its own grants. What the policy answers
     * is what else may be shown, and it is paired here with the organisation's
     * own membership so a widened source can never reach a person who is not a
     * member of it.
     *
     * The miss direction is fail closed: with no policy resolvable, nothing
     * beyond the organisation's own rows is disclosed, which is the free
     * answer and the safe one.
     */
    private function applyVisibility(OrgScope $scope, Query $query): void
    {
        $own = Predicate::compare('e.organisation_id', '=', $scope->organisationId);
        $policy = $this->policies?->visibilityFor($scope->organisationId);
        $extra = $policy === null ? [] : $policy->visibleSources(OrgVisibilityContext::of(
            $scope->organisationId,
            $scope->actorId,
            $scope->roles,
            $this->store->instant(),
        ));

        if ($extra === []) {
            $query->where('e.organisation_id', '=', $scope->organisationId);

            return;
        }

        // The membership list is read rather than joined because the join
        // available here hangs off `e.org_membership_id`, which only an
        // organisation-sourced row carries; a widened source has no such
        // column and would join to nothing. It is bounded by the
        // organisation's own membership, which is what a seat count already
        // bounds.
        $members = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['user_id'],
            $this->store->database->selectAll(
                Query::from('org_memberships')
                    ->select(['user_id'])
                    ->where('organisation_id', '=', $scope->organisationId)
            ),
        )));

        if ($members === []) {
            $query->where('e.organisation_id', '=', $scope->organisationId);

            return;
        }

        $query->whereAnyOf(
            $own,
            Predicate::allOf(
                Predicate::in('e.source', array_values($extra)),
                Predicate::in('e.user_id', $members),
            ),
        );
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function grant(Principal $actor, int $orgId, array $input): array
    {
        OrganisationInput::only($input, ['expected_revision', 'user_id', 'course_id', 'expires_at']);
        $expected = OrganisationInput::integer($input, 'expected_revision');
        $userId = OrganisationInput::integer($input, 'user_id');
        $courseId = OrganisationInput::integer($input, 'course_id');
        $expires = OrganisationInput::datetime($input['expires_at'] ?? null, 'expires_at');

        return $this->store->database->transactional(function () use ($actor, $orgId, $expected, $userId, $courseId, $expires): array {
            $scope = $this->access->require($actor, $orgId, 'learning');
            $next = $this->organisations->claim($scope, $expected);
            $context = OrgEntitlementGrantContext::authorise(
                $this->composed,
                $this->organisations,
                $actor,
                $orgId,
                $userId,
                $courseId,
                $next,
                expiresAt: $expires
            );
            $existing = $this->store->database->selectOne(Query::from('entitlements')
                ->where('organisation_id', '=', $orgId)->where('user_id', '=', $userId)->where('course_id', '=', $courseId)
                ->where('source', '=', Entitlement::SOURCE_ORG)->where('status', '=', 'active'));
            if ($existing !== null && ($existing['expires_at'] === null || (string) $existing['expires_at'] > $this->store->now())) {
                throw new ConflictException('This member already has an active direct organisation grant.');
            }
            [$grant, $events] = $this->grant->executeForOrganisation($context);
            $this->events->stage($events);
            $this->organisations->event(
                $scope,
                $next,
                'learning.granted',
                $userId,
                ['course_id' => $courseId, 'entitlement_id' => $grant->id()]
            );

            return ['organisation_revision' => $next, 'entitlement_id' => $grant->id(), 'state' => 'active'];
        });
    }

    /**
     * Revokes one direct organisation grant, with the same attempt ceremony
     * the seat routes run.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function revoke(Principal $actor, int $orgId, int $entitlementId, int $expected, array $input = []): array
    {
        // Validated before the transaction opens, so a misspelt disposition is
        // a bad request rather than a rolled back revocation.
        $disposition = AttemptDisposition::of($input['attempt_disposition'] ?? null);

        return $this->store->database->transactional(function () use (
            $actor,
            $orgId,
            $entitlementId,
            $expected,
            $disposition
        ): array {
            $scope = $this->access->require($actor, $orgId, 'learning');
            $row = $this->store->database->selectOne(Query::from('entitlements')->where('organisation_id', '=', $orgId)
                ->where('id', '=', $entitlementId)) ?? throw new EntityNotFoundException('No organisation entitlement here.');
            if ($row['source'] === Entitlement::SOURCE_SEAT) {
                throw new ConflictException('Return this access through its seat assignment and reclaim policy.');
            }
            if ($scope->unitId !== null) {
                $this->organisations->member($scope, (int) $row['org_membership_id']);
            }
            $next = $this->organisations->claim($scope, $expected);
            // Before the revoke and only when this grant is the last one that
            // confers access, which is exactly what SeatLifecycle does and
            // what RevokeEntitlement's own last-access branch asks. Afterwards
            // there would be nothing open left to grade, because that branch
            // abandons every resumable attempt on the enrolment.
            $closed = $this->attempts !== null
                && $this->revoke->isLastEffectiveGrant($entitlementId, (int) $row['user_id'], (int) $row['course_id'])
                ? $this->attempts->close(
                    (int) $row['user_id'],
                    (int) $row['course_id'],
                    $disposition,
                    self::REVOKED_REASON
                ) : [];
            $this->events->stage($this->revoke->executeForOrganisation(
                $scope,
                $this->store->database,
                $entitlementId,
                self::REVOKED_REASON
            ));
            // A null seat event id, because a direct organisation grant spends
            // no seat and writes no ledger row: inventing one would put a
            // number in the ledger that no balance depends on, which is the
            // rule OrganisationAccessTermination already follows.
            $this->attempts?->record($closed, self::REVOKED_REASON, null);
            $this->organisations->event($scope, $next, 'learning.revoked', (int) $row['user_id'], [
                'entitlement_id' => $entitlementId, 'attempt_disposition' => $disposition,
            ]);

            return ['organisation_revision' => $next, 'attempt_disposition' => $disposition];
        });
    }

    /** @return array<string, mixed> */
    public function summary(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId);

        if ($this->overview !== null) {
            // The registered org-overview definition answers from exactly this,
            // so the alias route and the report cannot report different numbers.
            return $this->overview->facts($scope);
        }

        $learningQuery = Query::from('entitlements', 'e')->select([])
            ->aggregate(Aggregate::countDistinctOf('n.id', 'enrolments'))
            ->join('enrolments', 'n', 'e.user_id', 'n.user_id', [['e.course_id', 'n.course_id']])
            ->join('org_memberships', 'm', 'e.org_membership_id', 'm.id', [['e.organisation_id', 'm.organisation_id']])
            ->where('e.organisation_id', '=', $scope->organisationId);
        $scope->constrainUnit($learningQuery, 'm.org_unit_id');
        $learning = $this->store->database->selectOne($learningQuery);
        $members = Query::from('org_memberships', 'm')->where('m.organisation_id', '=', $orgId)->where('m.state', '=', 'active');
        $scope->constrainUnit($members, 'm.org_unit_id');
        $pending = Query::from('seat_assignments', 'a')->join(
            'org_memberships',
            'm',
            'a.membership_id',
            'm.id',
            [['a.organisation_id', 'm.organisation_id']]
        )->where('a.organisation_id', '=', $orgId)->where('a.state', '=', 'pending');
        $scope->constrainUnit($pending, 'm.org_unit_id');

        return ['organisation_id' => $orgId,
            'active_members' => $this->store->database->count($members),
            'released_courses' => $this->store->database->count(Query::from('org_course_releases')
                ->where('organisation_id', '=', $orgId)->where('state', '=', 'active')
                ->whereAnyOf(
                    \Shondalai\VidyaLMS\Core\Port\Database\Predicate::isNull('starts_at'),
                    \Shondalai\VidyaLMS\Core\Port\Database\Predicate::compare('starts_at', '<=', $this->store->now())
                )
                ->whereAnyOf(
                    \Shondalai\VidyaLMS\Core\Port\Database\Predicate::isNull('ends_at'),
                    \Shondalai\VidyaLMS\Core\Port\Database\Predicate::compare('ends_at', '>', $this->store->now())
                )),
            'enrolments' => (int) ($learning['enrolments'] ?? 0),
            'seat_pools' => $this->store->database->count(Query::from('seat_pools')->where('organisation_id', '=', $orgId)),
            'pending_seats' => $this->store->database->count($pending), 'unit_id' => $scope->unitId];
    }

    /** @return array<string, mixed> */
    public function cleanupJobs(Principal $actor, int $orgId, int $page = 1, int $perPage = 25): array
    {
        // org_access_termination_jobs names the organisation and never a unit,
        // so a unit-scoped scope has nothing to narrow on and is refused rather
        // than handed the whole tenant's queue.
        $scope = $this->access->requireOrganisationWide($actor, $orgId, 'events');
        OrganisationInput::page($page, $perPage);

        return $this->store->page(Query::from('org_access_termination_jobs')
            ->where('organisation_id', '=', $scope->organisationId), $page, $perPage);
    }

    /** @return array<string, mixed> */
    public function retryCleanup(Principal $actor, int $orgId, int $jobId, int $expected): array
    {
        return $this->store->database->transactional(function () use ($actor, $orgId, $jobId, $expected): array {
            $scope = $this->access->require($actor, $orgId, 'learning');
            $job = $this->store->database->selectOne(Query::from('org_access_termination_jobs')
                ->where('organisation_id', '=', $orgId)->where('id', '=', $jobId))
                ?? throw new EntityNotFoundException('No organisation cleanup here.');
            if ($job['state'] !== 'failed') {
                throw new ConflictException('Only a failed cleanup needs a manual retry.');
            }
            $next = $this->organisations->claim($scope, $expected);
            $this->store->update('org_access_termination_jobs', $scope, $jobId, ['state' => 'queued', 'failure_code' => null]);
            $this->organisations->event($scope, $next, 'access.cleanup_retried', null, ['job_id' => $jobId]);

            return ['organisation_revision' => $next, 'cleanup' => ['job_id' => $jobId, 'state' => 'queued']];
        });
    }
}
