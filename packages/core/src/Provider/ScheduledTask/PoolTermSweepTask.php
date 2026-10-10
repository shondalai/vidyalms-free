<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle;
use Shondalai\VidyaLMS\Core\Domain\Organisation\AttemptDisposition;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatExpiryOutcome;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatPoolState;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;
use Shondalai\VidyaLMS\Core\Registry\SeatExpiryDisposition\SeatExpiryDispositionRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Ends the term of every pool whose end date has passed, and disposes of the
 * seats that were still held.
 *
 * `seat_pools.ends_at` was validated on save, refused a change while seats
 * were held, and then read by nothing at all: a pool that ended in March was
 * still active in November, still assignable, and still holding its learners'
 * access open. This is the writer that was missing, and it is a scheduled
 * task rather than a check inside the assignment path because a term ends
 * whether or not anybody visits the pool.
 *
 * **The disposition is a registered provider and the miss is a substitution,
 * not a refusal.** The free answer lapses access and keeps progress, because
 * a learner's own record is never a licensing artefact. When a pool names a
 * disposition whose package has been removed the sweep applies the free one
 * and records the substitution on the pool event, so a pool behaving like a
 * different pool says so on its own ledger row instead of quietly doing it.
 *
 * **In-flight attempts are closed under the run's disposition.** Expiring a
 * pool at minute forty of a sixty minute attempt would otherwise leave the
 * learner unable to save or submit, with answers recorded and no grade. The
 * closure runs inside the same transaction as the revocation and records the
 * `seat_events` row that caused it, which is the ceremony the seat lifecycle
 * owns; this task hands each held assignment to it rather than writing a
 * second copy of it.
 *
 * A reserved seat that was never claimed is forfeited rather than returned:
 * the term it was reserved against has ended, so there is no pool left to
 * return it to, which is Canvas Catalog's settled rule for unused seats.
 */
final class PoolTermSweepTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const KEY = 'vidyalms.organisation.pool_term_sweep';

    /** How many assignments one pool's disposition handles per run. */
    public const BATCH = 200;

    /**
     * The disposition applied when a pool names none, or names one whose
     * package has been removed. It is the free provider's key, and the
     * registry's published miss direction is a substitution rather than a
     * refusal precisely so this fall-back is legitimate.
     */
    public const FREE_DISPOSITION = 'lapse_access_keep_progress';

    public function __construct(
        private readonly SweptRecordStore $records,
        private readonly OrganisationSweepAuthority $authority,
        private readonly OrganisationStore $organisations,
        private readonly OrganisationLearningStore $store,
        private readonly SeatLifecycle $lifecycle,
        private readonly SeatExpiryDispositionRegistry $dispositions,
        private readonly \Shondalai\VidyaLMS\Core\Port\Clock\ClockPort $clock,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $expired = 0;
        $seats = 0;
        $skipped = 0;
        $substituted = 0;
        $ending = [SeatPoolState::ACTIVE, SeatPoolState::SUSPENDED];

        foreach ($this->records->endedPools($ending) as $row) {
            $poolId = (int) $row['id'];
            $scope = $this->authority->forPool($poolId);

            if ($scope === null) {
                ++$skipped;

                continue;
            }

            $closed = $this->records->database->transactional(
                fn (): array => $this->expirePool($scope, $poolId, $run->string('attempt_disposition'))
            );
            $seats += $closed[0];
            $substituted += $closed[1];
            $expired += $closed[2];
        }

        return sprintf(
            'pools_expired=%d seats_disposed=%d substituted=%d skipped=%d',
            $expired,
            $seats,
            $substituted,
            $skipped
        );
    }

    /**
     * One pool's term end, bounded to a page of held seats.
     *
     * **The pool leaves `active` only when the last held seat has been dealt
     * with**, and that ordering is the whole reason this method returns three
     * numbers instead of doing the obvious thing. A pool with three hundred
     * held seats and a two hundred row page would otherwise be marked
     * `expired` on the first run, drop out of the query that finds ended
     * pools, and leave a hundred learners holding access to a course whose
     * term ended, with nothing left that would ever look at them again.
     *
     * @return array{0: int, 1: int, 2: int} seats disposed of, whether the
     *                                       disposition was substituted, and
     *                                       whether the pool finished
     */
    private function expirePool(OrgScope $scope, int $poolId, string $requestedDisposition): array
    {
        $pool = $this->store->pool($scope, $poolId);
        $next = $this->organisations->claim($scope, (int) $this->organisations->organisation($scope)['revision']);
        $key = (string) ($pool['expiry_disposition_key'] ?? self::FREE_DISPOSITION);
        $resolved = $this->dispositions->has($key);
        // OPEN and not HOLDING, and that difference is what stops the sweep
        // livelocking. HOLDING contains forfeited and expired, which are
        // terminal: dispose() can do nothing with them and returned zero, so a
        // pool whose first page was two hundred already-closed rows was
        // rescanned unchanged every day, the full-page branch below took the
        // early return, and the pool never reached `expired` while its live
        // seats kept their access. OPEN is exactly the set this task can act
        // on, and it is the same set the administrator's term end pages, which
        // is the point: one term end, one writer, one page.
        $held = $this->store->database->selectAll(OrgScopedReader::from($scope, 'seat_assignments')
            ->select(['id', 'state', 'user_id'])
            ->where('pool_id', '=', $poolId)
            ->whereIn('state', SeatAssignmentState::OPEN)
            ->orderBy('id')->limit(self::BATCH));
        $disposition = $this->dispositionFor($key, $pool, $held);
        $count = 0;
        $attemptDisposition = \in_array($requestedDisposition, AttemptDisposition::DISPOSITIONS, true)
            ? $requestedDisposition : AttemptDisposition::SUBMIT_AND_GRADE;

        foreach ($held as $assignment) {
            $count += $this->dispose($scope, $next, (int) $assignment['id'], $disposition, $attemptDisposition);
        }

        if (\count($held) >= self::BATCH) {
            // A full page means there may be more. The pool stays where it is
            // so the next run finds it again, and no pool_expired row is
            // written yet: the term has not finished ending.
            return [$count, $resolved ? 0 : 1, 0];
        }

        $this->store->update('seat_pools', $scope, $poolId, ['state' => SeatPoolState::EXPIRED]);
        $this->store->ledger(
            $scope,
            $next,
            $poolId,
            null,
            SeatEventType::POOL_EXPIRED,
            0,
            SeatEventReason::EXPIRED_WITH_TERM,
            null,
            null,
            // The substitution is recorded on the pool event exactly as the
            // registry's published miss direction says it must be, so a pool
            // that behaved like a different pool can be found later.
            ['disposition_key' => $key, 'disposition_resolved' => $resolved,
                'closes_membership' => $disposition->closesMembership],
            null,
            OrganisationLearningStore::ACTOR_SYSTEM,
            null,
            self::KEY . ':pool:' . $poolId
        );

        return [$count, $resolved ? 0 : 1, 1];
    }

    /**
     * One held seat, disposed of under the pool's term end.
     *
     * **One term end has one writer.** Every open row goes through
     * {@see SeatLifecycle::expireAtTerm()}, which is the entry point the
     * administrator's own term end already used, so the sweep and the PATCH to
     * `state=expired` produce the same ledger for the same pool. They did not:
     * this method used to hand a live seat to release() with `terminate` set,
     * whose credit comes from the pool's reclaim policy, so a pool whose
     * policy is `always` or `before_completion` credited every held seat back
     * at a term end and finished with more availability than it was sold. The
     * published delta table says a term end is `seat_expired` at zero, and
     * expireAtTerm's own docblock says no reclaim policy is consulted at a
     * term end, because bulk seat sales are final. An unclaimed reservation is
     * forfeited at zero by the same rule and through the same entry point,
     * which is what this task's own docblock has always said it did.
     *
     * Nothing here was detectable by the reconciliation, because both
     * derivations moved together; it was only visible against the other path.
     */
    private function dispose(
        OrgScope $scope,
        int $revision,
        int $assignmentId,
        SeatExpiryOutcome $outcome,
        string $attempts,
    ): int {
        try {
            $this->lifecycle->expireAtTerm($scope, $revision, $assignmentId, $outcome, $attempts);

            return 1;
        } catch (EntityNotFoundException) {
            // A row somebody closed between the page and this statement.
            return 0;
        }
    }

    /**
     * @param array<string, mixed>       $pool
     * @param list<array<string, mixed>> $held
     */
    private function dispositionFor(string $key, array $pool, array $held): SeatExpiryOutcome
    {
        if (!$this->dispositions->has($key)) {
            return SeatExpiryOutcome::lapseAccessKeepProgress();
        }

        return $this->dispositions->get($key)->disposition(SeatDecisionContext::forExpiry(
            $pool,
            \is_string($pool['policy_params'] ?? null)
                ? (array) json_decode((string) $pool['policy_params'], true, 16, JSON_THROW_ON_ERROR) : [],
            (int) ($pool['policy_params_version'] ?? 0),
            (int) $pool['course_id'],
            \count($held) === 0 ? null : (int) ($held[0]['user_id'] ?? 0),
            $this->clock->now(),
        ));
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
