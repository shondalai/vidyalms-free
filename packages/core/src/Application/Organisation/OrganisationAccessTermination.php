<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Domain\Organisation\AttemptDisposition;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/** The boundary loses only its own access; unrelated grants and learning records survive. */
final class OrganisationAccessTermination
{
    public const MAX_SYNCHRONOUS_GRANTS = 500;

    public function __construct(
        private readonly OrganisationLearningStore $store,
        private readonly SeatLifecycle $seats,
        private readonly RevokeEntitlement $revoke,
        private readonly OrganisationLearningEvents $events,
        /**
         * The attempt half of the revocation ceremony, for the grants that
         * never had a seat behind them.
         *
         * A direct organisation grant strands an in-flight attempt exactly as
         * a seat reclaim does, and RequireCurrentOrganisationAccess refuses
         * both identically, so closing only the seat-backed half would have
         * left the more common case unfixed. Optional so a harness with no
         * assessment engine still builds; never absent in the kernel.
         */
        private readonly ?CloseAttemptsOnAccessLoss $attempts = null,
    ) {
    }

    /**
     * Closes the attempts a revoked grant leaves open.
     *
     * The disposition is submit_and_grade and is not a parameter here,
     * because a termination has no request behind it to carry one: it is a
     * membership change, a course withdrawal or a durable job draining. Work
     * already done is the learner's, and voiding it by default would be the
     * product deciding that on their behalf.
     *
     * @param array<string, mixed> $grant
     */
    private function closeAttempts(array $grant, string $reason): void
    {
        if ($this->attempts === null || !$this->revoke->isLastEffectiveGrant(
            (int) $grant['id'], (int) $grant['user_id'], (int) $grant['course_id'],
        )) {
            return;
        }

        $reasonKey = SeatEventReason::normalise($reason);
        // No seat event to point at: this branch is the grants that never had
        // a seat behind them, so the attempt records why it ended and nothing
        // more, which is honest rather than a number no balance depends on.
        $this->attempts->record(
            $this->attempts->close((int) $grant['user_id'], (int) $grant['course_id'], AttemptDisposition::SUBMIT_AND_GRADE, $reasonKey),
            $reasonKey,
            null,
        );
    }

    /** @return array{job_id: int, state: string}|null */
    public function execute(OrgScope $scope, int $revision, string $reason, ?int $userId = null, ?int $courseId = null): ?array
    {
        $query = Query::from('entitlements')->where('organisation_id', '=', $scope->organisationId)->where('status', '=', 'active');
        if ($userId !== null) {
            $query->where('user_id', '=', $userId);
        }
        if ($courseId !== null) {
            $query->where('course_id', '=', $courseId);
        }
        $pending = Query::from('seat_assignments', 'a')->select(['a.id'])
            ->join('seat_pools', 'p', 'a.pool_id', 'p.id', [['a.organisation_id', 'p.organisation_id']])
            ->where('a.organisation_id', '=', $scope->organisationId)->where('a.state', '=', 'pending');
        if ($userId !== null) {
            $pending->where('a.user_id', '=', $userId);
        }
        if ($courseId !== null) {
            $pending->where('p.course_id', '=', $courseId);
        }
        if ($this->store->database->count(clone $pending) + $this->store->database->count(clone $query) > self::MAX_SYNCHRONOUS_GRANTS) {
            $maxGrant = $this->store->database->selectOne((clone $query)->select(['id'])->orderBy('id', 'DESC')->limit(1));
            $maxAssignment = $this->store->database->selectOne((clone $pending)->orderBy('a.id', 'DESC')->limit(1));
            $jobId = $this->store->insert('org_access_termination_jobs', $scope, [
                'user_id' => $userId, 'course_id' => $courseId, 'actor_id' => $scope->actorId, 'reason' => $reason,
                'max_entitlement_id' => (int) ($maxGrant['id'] ?? 0), 'max_assignment_id' => (int) ($maxAssignment['id'] ?? 0),
            ]);

            return ['job_id' => $jobId, 'state' => 'queued'];
        }
        $grants = $this->store->database->selectAll($query->orderBy('id')->limit(self::MAX_SYNCHRONOUS_GRANTS));
        foreach ($this->store->database->selectAll($pending->orderBy('a.id')->limit(self::MAX_SYNCHRONOUS_GRANTS)) as $row) {
            $this->seats->release($scope, $revision, (int) $row['id'], $reason, true);
        }
        foreach ($grants as $grant) {
            $assignment = $this->store->database->selectOne(Query::from('seat_assignments')
                ->where('organisation_id', '=', $scope->organisationId)->where('entitlement_id', '=', (int) $grant['id'])
                ->whereIn('state', OrganisationLearningStore::ACTIVE));
            if ($assignment !== null) {
                $this->seats->release($scope, $revision, (int) $assignment['id'], $reason, true);
            } else {
                $this->closeAttempts($grant, $reason);
                $this->events->stage($this->revoke->executeForOrganisation($scope, $this->store->database, (int) $grant['id'], $reason));
            }
        }

        return null;
    }

    /** @return array{int, int} */
    public function jobAuthority(int $jobId): array
    {
        $job = $this->store->database->selectOne(Query::from('org_access_termination_jobs')->where('id', '=', $jobId)
            ->where('state', '=', 'queued')) ?? throw new EntityNotFoundException('No queued organisation cleanup here.');

        return [(int) $job['organisation_id'], (int) $job['actor_id']];
    }

    /** One worker transaction, at most 50 grants and 50 pending seats; never reconstructs a target from a user payload. */
    public function runChunk(int $jobId, OrganisationStore $organisations): int
    {
        try {
            $processed = $this->store->database->transactional(function () use ($jobId, $organisations): int {
                $scope = OrgScope::fromTerminationJob($this, $jobId);
                $next = $organisations->claim($scope, (int) $organisations->organisation($scope)['revision']);
                $job = $this->store->database->selectOne(Query::from('org_access_termination_jobs')
                    ->where('organisation_id', '=', $scope->organisationId)->where('id', '=', $jobId)
                    ->where('state', '=', 'queued')) ?? throw new ConflictException('Cleanup already changed.');
                $grants = Query::from('entitlements')->where('organisation_id', '=', $scope->organisationId)
                    ->where('status', '=', 'active')->where('id', '<=', (int) $job['max_entitlement_id']);
                $pending = Query::from('seat_assignments', 'a')->select(['a.id'])
                    ->join('seat_pools', 'p', 'a.pool_id', 'p.id', [['a.organisation_id', 'p.organisation_id']])
                    ->where('a.organisation_id', '=', $scope->organisationId)->where('a.state', '=', 'pending')
                    ->where('a.id', '<=', (int) $job['max_assignment_id']);
                if ($job['user_id'] !== null) {
                    $grants->where('user_id', '=', (int) $job['user_id']);
                    $pending->where('a.user_id', '=', (int) $job['user_id']);
                }
                if ($job['course_id'] !== null) {
                    $grants->where('course_id', '=', (int) $job['course_id']);
                    $pending->where('p.course_id', '=', (int) $job['course_id']);
                }
                $count = 0;
                $chunk = $this->store->database->selectAll((clone $grants)
                    ->orderBy('user_id')->orderBy('course_id')->orderBy('id')->limit(50));
                foreach ($chunk as $grant) {
                    $assignment = $this->store->database->selectOne(Query::from('seat_assignments')
                        ->where('organisation_id', '=', $scope->organisationId)->where('entitlement_id', '=', (int) $grant['id'])
                        ->whereIn('state', OrganisationLearningStore::ACTIVE));
                    if ($assignment === null) {
                        $this->closeAttempts($grant, (string) $job['reason']);
                        $this->events->stage($this->revoke->executeForOrganisation(
                            $scope,
                            $this->store->database,
                            (int) $grant['id'],
                            (string) $job['reason']
                        ));
                    } else {
                        $this->seats->release($scope, $next, (int) $assignment['id'], (string) $job['reason'], true);
                    }
                    $count++;
                }
                foreach ($this->store->database->selectAll((clone $pending)->orderBy('a.id')->limit(50)) as $assignment) {
                    $this->seats->release($scope, $next, (int) $assignment['id'], (string) $job['reason'], true);
                    $count++;
                }
                $done = $this->store->database->count($grants) === 0 && $this->store->database->count($pending) === 0;
                $this->store->update(
                    'org_access_termination_jobs',
                    $scope,
                    $jobId,
                    ['processed_count' => (int) $job['processed_count'] + $count, 'state' => $done ? 'completed' : 'queued']
                );
                $organisations->event(
                    $scope,
                    $next,
                    'access.cleanup_chunk',
                    null,
                    ['job_id' => $jobId, 'processed' => $count, 'complete' => $done],
                    MembershipEventContext::system((string) $jobId),
                );

                return $count;
            });
        } catch (\Throwable $failure) {
            $this->events->discard();
            throw $failure;
        }
        $this->events->afterCommit();

        return $processed;
    }

    /** Restoring a boundary must not revive rows whose destructive cleanup is still queued. */
    public function requireSettled(OrgScope $scope, ?int $userId = null, ?int $courseId = null): void
    {
        $jobs = Query::from('org_access_termination_jobs')->where('organisation_id', '=', $scope->organisationId)
            ->where('state', '!=', 'completed');
        if ($userId !== null) {
            $jobs->whereAnyOf(
                \Shondalai\VidyaLMS\Core\Port\Database\Predicate::isNull('user_id'),
                \Shondalai\VidyaLMS\Core\Port\Database\Predicate::compare('user_id', '=', $userId)
            );
        }
        if ($courseId !== null) {
            $jobs->whereAnyOf(
                \Shondalai\VidyaLMS\Core\Port\Database\Predicate::isNull('course_id'),
                \Shondalai\VidyaLMS\Core\Port\Database\Predicate::compare('course_id', '=', $courseId)
            );
        }
        if ($this->store->database->count($jobs) > 0) {
            throw new ConflictException('Wait for the previous access cleanup to finish before restoring this boundary.');
        }
    }
}
