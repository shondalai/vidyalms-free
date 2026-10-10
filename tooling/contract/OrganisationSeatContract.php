<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatPoolState;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

/**
 * The free seat subsystem, driven through the shipped contract against a real
 * site and a real database.
 *
 * One body of assertions, two callers: each adapter's build/smoke.php runs it
 * against its own ports, exactly as DatabasePortContract and
 * AssessmentFoundationContract are run. That matters more here than for a
 * port contract, because almost everything this wave added is arithmetic over
 * rows and the two hosts count rows with different drivers: MySQL through
 * Joomla's query builder on one side and through wpdb on the other, with a
 * signed sum, a state count and a hash chain riding on every one of them.
 *
 * What it proves that a unit test cannot:
 *
 *   - the six pool states and their guarded edges survive a real transaction,
 *     and each transition writes the designed pool_* ledger row rather than a
 *     generic one;
 *   - a seat reserved at issue is a real row holding a real seat, so the
 *     forty first invitation into a forty seat pool is refused at issue by
 *     the same arithmetic the console shows;
 *   - a reclaim closes the holder's open attempt under submit_and_grade
 *     inside the revoking transaction, and the attempt names the seat event
 *     that closed it;
 *   - a transfer writes the acquiring event before the releasing one, in one
 *     transaction, on a real autoincrement;
 *   - an exhausting pool raises its alert to the administrators of its own
 *     organisation and to nobody else;
 *   - the reconciliation read agrees with the ledger on a pool nobody has
 *     touched.
 *
 * **It seeds its own organisation and removes every row it wrote**, including
 * its ledger rows: the ledger is append-only for the site's own history, and
 * a throwaway organisation this contract created has no history worth
 * keeping. The removal runs in a finally, so a failed assertion still leaves
 * the site as it was found. It creates no table and drops none.
 *
 * The caller supplies the CMS accounts, because only the host can make one:
 * two administrators in different organisations and two learners.
 */
final class OrganisationSeatContract
{
    /** Marks every organisation and pool this contract writes. */
    public const MARKER = 'vidyalms-seat-contract';

    /** @var list<string> */
    private array $failures = [];

    /** @var list<string> */
    private array $facts = [];

    /** @var array<string, int> organisation slug => id */
    private array $organisations = [];

    private int $courseId = 0;

    private int $quizId = 0;

    private int $questionId = 0;

    private int $queueWatermark = 0;

    /** @var list<int> the CMS accounts the caller handed this run */
    private array $accountIds = [];

    private function __construct(
        private readonly CoreKernel $kernel,
        private readonly Ports $ports,
        private readonly Principal $actor,
    ) {
    }

    /**
     * @param array{admin_a: int, admin_b: int, learner_one: int, learner_two: int} $accounts
     * @param int $courseId a published course already released to both organisations
     *
     * @return array{failures: list<string>, facts: list<string>}
     */
    public static function run(
        CoreKernel $kernel,
        Ports $ports,
        Principal $actor,
        array $accounts,
        int $courseId,
        ?int $quizId = null
    ): array {
        $contract = new self($kernel, $ports, $actor);

        try {
            $contract->courseId = $courseId;
            $contract->accountIds = array_values(array_map(intval(...), $accounts));
            // The high-water mark of the notification queue before anything is
            // seeded. Nothing at or below it can be this run's, so it is the
            // floor of the cleanup rather than its whole predicate: a site
            // this contract runs against has its own traffic, and a row
            // written above the mark by somebody else is not this run's to
            // delete. The mark has to be taken once, at the start, rather
            // than beside the section that means to raise an alert, because a
            // reservation exhausting a pool of one raises the same alert the
            // threshold section raises deliberately.
            $contract->queueWatermark = $contract->highestQueueId();
            $contract->seed($accounts, $courseId);
            $contract->checkPoolLifecycle($courseId);
            $contract->checkReserveAtIssue($courseId, $accounts);
            $contract->checkReclaimClosesAnAttempt($courseId, $accounts, $quizId);
            $contract->checkTransfer($courseId, $accounts);
            $contract->checkThresholdAlertAudience($courseId, $accounts);
            $contract->checkReconciliation($courseId);
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw ' . $error::class . ': ' . $error->getMessage();
        } finally {
            $contract->removeEverythingItWrote();
        }

        return ['failures' => $contract->failures, 'facts' => $contract->facts];
    }

    // -----------------------------------------------------------------------
    // The contract
    // -----------------------------------------------------------------------

    /**
     * Two organisations: the subject, and a second one whose administrator
     * must never hear about the first one's pool.
     *
     * @param array{admin_a: int, admin_b: int, learner_one: int, learner_two: int} $accounts
     */
    private function seed(array $accounts, int $courseId): void
    {
        foreach (['a' => 'admin_a', 'b' => 'admin_b'] as $suffix => $adminKey) {
            $slug = self::MARKER . '-' . $suffix;
            $created = $this->call('POST', '/manage/organisations', ['name' => 'Seat contract ' . $suffix, 'slug' => $slug], 201);
            $id = (int) ($created['id'] ?? 0);
            $this->organisations[$slug] = $id;

            $this->call('POST', '/manage/organisations/' . $id . '/releases', [
                'expected_revision' => $this->revision($id),
                'course_id' => $courseId,
            ], 200);

            $this->call('POST', '/manage/organisations/' . $id . '/members', [
                'expected_revision' => $this->revision($id),
                'user_id' => $accounts[$adminKey],
                'role_key' => 'org_admin',
            ], 201);
        }

        foreach (['learner_one', 'learner_two'] as $learnerKey) {
            $this->call('POST', '/manage/organisations/' . $this->orgA() . '/members', [
                'expected_revision' => $this->revision($this->orgA()),
                'user_id' => $accounts[$learnerKey],
                'role_key' => 'org_member',
            ], 201);
        }

        $this->facts[] = 'organisations ' . implode(', ', array_values($this->organisations))
            . ' released course ' . $courseId;
    }

    /**
     * Every one of the six pool states, the edges the machine draws, and the
     * ledger row each entry writes.
     */
    private function checkPoolLifecycle(int $courseId): void
    {
        $orgId = $this->orgA();
        $pool = $this->call('POST', '/manage/organisations/' . $orgId . '/seat-pools', [
            'expected_revision' => $this->revision($orgId),
            'name' => self::MARKER . ' lifecycle',
            'course_id' => $courseId,
            'seats_granted' => 2,
            'state' => SeatPoolState::DRAFT,
        ], 200);
        $poolId = (int) ($pool['id'] ?? 0);

        $this->expect(
            ($pool['state'] ?? null) === SeatPoolState::DRAFT,
            'a pool can be created in draft',
            'state ' . (string) ($pool['state'] ?? 'null')
        );

        // The edge the machine does not draw, refused before any of the ones
        // it does are walked, so a refusal cannot be mistaken for an ordering
        // accident.
        $this->expectStatus(
            'PATCH',
            '/manage/organisations/' . $orgId . '/seat-pools/' . $poolId,
            ['expected_revision' => $this->revision($orgId), 'state' => SeatPoolState::EXPIRED],
            409,
            'a draft pool cannot move straight to expired'
        );

        $walk = [
            SeatPoolState::ACTIVE,
            SeatPoolState::SUSPENDED,
            SeatPoolState::ACTIVE,
            SeatPoolState::EXPIRED,
            SeatPoolState::CLOSED,
        ];

        foreach ($walk as $state) {
            $moved = $this->call('PATCH', '/manage/organisations/' . $orgId . '/seat-pools/' . $poolId, [
                'expected_revision' => $this->revision($orgId),
                'state' => $state,
            ], 200);

            $this->expect(
                ($moved['state'] ?? null) === $state,
                'a pool moves to ' . $state,
                'state ' . (string) ($moved['state'] ?? 'null')
            );
        }

        $events = array_map(
            static fn (array $row): string => (string) $row['event_key'],
            $this->ports->database->selectAll(
                Query::from('seat_events')->select(['event_key'])->where('pool_id', '=', $poolId)->orderBy('id')
            )
        );

        $this->expect(
            $events === ['pool_created', 'pool_funded', 'pool_suspended', 'pool_resumed', 'pool_expired', 'pool_closed'],
            'the lifecycle writes the designed pool events and no generic pool_updated',
            implode(', ', $events)
        );

        $this->facts[] = 'pool ' . $poolId . ' walked ' . \count($events) . ' states';
    }

    /**
     * An invitation reserves the seat it promises, and the pool refuses the
     * one invitation it has no seat for.
     *
     * @param array{admin_a: int, admin_b: int, learner_one: int, learner_two: int} $accounts
     */
    private function checkReserveAtIssue(int $courseId, array $accounts): void
    {
        $orgId = $this->orgA();
        $poolId = $this->pool($orgId, $courseId, 'reserve', 1);

        $before = $this->balance($orgId, $poolId);
        $revision = $this->revision($orgId);
        $email = self::MARKER . '-invitee@example.invalid';
        $receipt = $this->call('POST', '/manage/organisations/' . $orgId . '/invitations', [
            'expected_revision' => $revision,
            'email' => $email,
            'role_key' => 'org_member',
            'pool_id' => $poolId,
        ], 201);

        $this->expect(
            $receipt === ['organisation_revision' => $revision + 1, 'outcome' => 'queued'],
            'issuing an invitation returns only the private queued receipt',
            (string) json_encode($receipt)
        );

        // The receipt deliberately reveals no invitation or reservation row.
        // Inspect this run's stored invitation to verify the promised seat.
        $invitation = $this->ports->database->selectOne(
            Query::from('invitations')->where('organisation_id', '=', $orgId)
                ->where('email', '=', $email)->where('state', '=', 'pending')
        ) ?? [];
        $assignmentId = (int) ($invitation['seat_assignment_id'] ?? 0);
        $after = $this->balance($orgId, $poolId);

        $this->expect(
            $assignmentId > 0,
            'issuing an invitation against a pool reserves a seat',
            'seat_assignment_id ' . $assignmentId
        );

        $this->expect(
            $after['available'] === $before['available'] - 1 && $after['reconciled'] === true,
            'the reservation takes the seat and the pool still reconciles',
            'available ' . $before['available'] . ' -> ' . $after['available']
                . ' reconciled=' . var_export($after['reconciled'], true)
        );

        $reserved = $this->ports->database->selectOne(
            Query::from('seat_assignments')->where('organisation_id', '=', $orgId)
                ->where('pool_id', '=', $poolId)->where('id', '=', $assignmentId)
        ) ?? [];

        $this->expect(
            (string) ($reserved['state'] ?? '') === SeatAssignmentState::RESERVED
                && $reserved['reserved_until'] !== null
                && $reserved['membership_id'] === null,
            'the reserved row has a window and no membership, which is what the widening is for',
            'state ' . (string) ($reserved['state'] ?? 'null')
                . ' reserved_until ' . (string) ($reserved['reserved_until'] ?? 'null')
                . ' membership_id ' . var_export($reserved['membership_id'] ?? null, true)
        );

        $this->expect(
            \is_string($reserved['assignment_ref'] ?? null)
                && preg_match('/^[A-Z0-9]{32}$/', (string) $reserved['assignment_ref']) === 1,
            'the reservation is minted with an opaque reference',
            (string) ($reserved['assignment_ref'] ?? 'null')
        );

        // The pool holds one seat and it is spoken for, so the next invitation
        // is refused at issue rather than a week later in somebody else's
        // browser.
        $this->expectStatus(
            'POST',
            '/manage/organisations/' . $orgId . '/invitations',
            [
                'expected_revision' => $this->revision($orgId),
                'email' => self::MARKER . '-one-too-many@example.invalid',
                'role_key' => 'org_member',
                'pool_id' => $poolId,
            ],
            409,
            'one more invitation than the pool has seats is refused at issue'
        );

        $pending = $this->ports->database->count(
            Query::from('invitations')->where('organisation_id', '=', $orgId)->where('state', '=', 'pending')
        );

        $this->expect(
            $pending === 1,
            'the refused invitation wrote no row',
            'pending invitations ' . $pending
        );

        $this->facts[] = 'reservation ' . $assignmentId . ' holds the only seat in pool ' . $poolId;
    }

    /**
     * The ceremony: a reclaim closes the holder's open attempt under
     * submit_and_grade, inside the revoking transaction, and the attempt says
     * which ledger row closed it.
     *
     * @param array{admin_a: int, admin_b: int, learner_one: int, learner_two: int} $accounts
     */
    private function checkReclaimClosesAnAttempt(int $courseId, array $accounts, ?int $quizId): void
    {
        $orgId = $this->orgA();
        $poolId = $this->pool($orgId, $courseId, 'reclaim', 2);
        $assignmentId = $this->assign($orgId, $poolId, $accounts['learner_one']);
        $learner = Principal::user($accounts['learner_one']);

        $quizId ??= $this->quiz($courseId);

        if ($quizId === 0) {
            // Without an attempt there is still a reclaim to prove, and the
            // disposition still has to be accepted and recorded.
            $this->release($orgId, $assignmentId, 'submit_and_grade');
            $state = $this->ports->database->selectOne(Query::from('seat_assignments')->where('id', '=', $assignmentId));

            $this->expect(
                (string) ($state['state'] ?? '') === SeatAssignmentState::RECLAIMED,
                'a reclaim with no open attempt still closes the seat',
                'state ' . (string) ($state['state'] ?? 'null')
            );

            return;
        }

        $started = $this->kernel->apiKernel()->handle(new ApiRequest('POST', '/quizzes/' . $quizId . '/attempts', $learner));
        $attemptId = (int) ($started->data['id'] ?? 0);

        $this->expect(
            $started->status < 300 && $attemptId > 0,
            'the seat holder can start an attempt on the course their seat funds',
            'status ' . $started->status . ' ' . json_encode($started->data)
        );

        if ($attemptId === 0) {
            return;
        }

        if ($this->questionId > 0) {
            $saved = $this->kernel->apiKernel()->handle(new ApiRequest(
                'PUT',
                '/attempts/' . $attemptId . '/answers',
                $learner,
                [],
                ['answers' => [(string) $this->questionId => 'An answer written before the seat was taken away.']],
                self::MARKER . '-answers-' . bin2hex(random_bytes(6))
            ));

            $this->expect($saved->status < 300, 'the learner saves an answer mid-attempt', 'status ' . $saved->status);
        }

        $this->reclaimAndCheckTheAttempt($orgId, $assignmentId, $attemptId);
    }

    private function reclaimAndCheckTheAttempt(int $orgId, int $assignmentId, int $attemptId): void
    {
        $answersBefore = $this->ports->database->count(
            Query::from('attempt_answers')->where('attempt_id', '=', $attemptId)
        );
        $eventsBefore = $this->ports->database->count(
            Query::from('attempt_answer_events')->where('attempt_id', '=', $attemptId)
        );

        $this->release($orgId, $assignmentId, 'submit_and_grade');

        $attempt = $this->ports->database->selectOne(Query::from('attempts')->where('id', '=', $attemptId)) ?? [];
        $status = (string) ($attempt['status'] ?? '');

        $this->expect(
            \in_array($status, ['graded', 'submitted'], true),
            'the reclaim left the attempt graded rather than stranded',
            'status ' . $status
        );

        $this->expect(
            (string) ($attempt['closure_reason'] ?? '') !== '',
            'the attempt records why it was closed',
            'closure_reason ' . (string) ($attempt['closure_reason'] ?? 'null')
        );

        $seatEventId = (int) ($attempt['closed_by_seat_event_id'] ?? 0);
        $causing = $seatEventId === 0
            ? null
            : $this->ports->database->selectOne(Query::from('seat_events')->where('id', '=', $seatEventId));

        $this->expect(
            $causing !== null && (int) $causing['assignment_id'] === $assignmentId,
            'the attempt names the seat event that closed it, and it is this seat',
            'closed_by_seat_event_id ' . $seatEventId
                . ' assignment ' . (string) ($causing['assignment_id'] ?? 'null')
        );

        $answersAfter = $this->ports->database->count(
            Query::from('attempt_answers')->where('attempt_id', '=', $attemptId)
        );
        $eventsAfter = $this->ports->database->count(
            Query::from('attempt_answer_events')->where('attempt_id', '=', $attemptId)
        );

        $this->expect(
            $answersAfter === $answersBefore && $eventsAfter === $eventsBefore,
            'no answer was stranded or discarded by the closure',
            'answers ' . $answersBefore . ' -> ' . $answersAfter
                . ', answer events ' . $eventsBefore . ' -> ' . $eventsAfter
        );

        $this->facts[] = 'attempt ' . $attemptId . ' closed by seat event ' . $seatEventId;
    }

    /**
     * A transfer writes the acquiring event before the releasing one, in one
     * transaction.
     *
     * @param array{admin_a: int, admin_b: int, learner_one: int, learner_two: int} $accounts
     */
    private function checkTransfer(int $courseId, array $accounts): void
    {
        $orgId = $this->orgA();
        $source = $this->pool($orgId, $courseId, 'transfer-source', 1);
        $target = $this->pool($orgId, $courseId, 'transfer-target', 1);
        $assignmentId = $this->assign($orgId, $source, $accounts['learner_two']);

        $moved = $this->call('POST', '/manage/organisations/' . $orgId . '/seat-assignments/' . $assignmentId . '/transfer', [
            'expected_revision' => $this->revision($orgId),
            'target_pool_id' => $target,
        ], 200);

        $this->expect(
            (int) ($moved['pool_id'] ?? 0) === $target,
            'the seat now belongs to the target pool',
            'pool_id ' . (string) ($moved['pool_id'] ?? 'null')
        );

        // Both pools, not one assignment: the acquiring half is a fresh
        // assignment row in the target pool, deliberately, so a transferred
        // seat keeps its seat provenance rather than resurrecting a closed
        // row. The ordering claim is therefore about the two pools' ledgers.
        $rows = $this->ports->database->selectAll(
            Query::from('seat_events')->select(['id', 'pool_id', 'seat_delta'])
                ->whereIn('pool_id', [$source, $target])
                ->orderBy('id')
        );
        $tail = \array_slice($rows, -2);

        $this->expect(
            \count($tail) === 2
                && (int) $tail[0]['pool_id'] === $target && (int) $tail[0]['seat_delta'] === -1
                && (int) $tail[1]['pool_id'] === $source && (int) $tail[1]['seat_delta'] === 1,
            'the acquiring event is written before the releasing event',
            implode(' then ', array_map(
                static fn (array $row): string => 'pool ' . $row['pool_id'] . ' delta ' . $row['seat_delta'],
                $tail
            ))
        );

        foreach ([$source, $target] as $poolId) {
            $balance = $this->balance($orgId, $poolId);
            $this->expect(
                $balance['reconciled'] === true,
                'pool ' . $poolId . ' still reconciles after the transfer',
                'ledger ' . $balance['ledger_balance'] . ' available ' . $balance['available']
            );
        }
    }

    /**
     * An exhausting pool alerts the administrators of its own organisation
     * and nobody else.
     *
     * @param array{admin_a: int, admin_b: int, learner_one: int, learner_two: int} $accounts
     */
    private function checkThresholdAlertAudience(int $courseId, array $accounts): void
    {
        $orgId = $this->orgA();
        $poolId = $this->pool($orgId, $courseId, 'threshold', 1);

        $before = $this->queuedFor([$accounts['admin_a'], $accounts['admin_b']]);

        // A reservation is the shipped writer that raises the alert, and it
        // exhausts a pool of one on its own.
        $this->call('POST', '/manage/organisations/' . $orgId . '/invitations', [
            'expected_revision' => $this->revision($orgId),
            'email' => self::MARKER . '-threshold@example.invalid',
            'role_key' => 'org_member',
            'pool_id' => $poolId,
        ], 201);

        // The occurrence is written to the outbox; the fan-out is what turns
        // it into recipients, and running it here is the only way to ask who
        // it reached.
        $this->kernel->registries->scheduledTasks
            ->get(\Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationFanoutTask::TYPE)
            ->run(new \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun(0, ['limit' => 50], Principal::guest()));

        $after = $this->queuedFor([$accounts['admin_a'], $accounts['admin_b']]);
        $reachedA = $after[$accounts['admin_a']] - $before[$accounts['admin_a']];
        $reachedB = $after[$accounts['admin_b']] - $before[$accounts['admin_b']];

        $this->expect(
            $reachedA >= 1,
            'an exhausting pool reaches an administrator of its own organisation',
            'queued rows for the owning administrator +' . $reachedA
        );

        $this->expect(
            $reachedB === 0,
            'and reaches the administrator of another organisation not at all',
            'queued rows for the other organisation +' . $reachedB
        );
    }

    /** The two derivations agree on a pool nobody has touched, now and in the past. */
    private function checkReconciliation(int $courseId): void
    {
        $orgId = $this->orgA();
        $poolId = $this->pool($orgId, $courseId, 'reconciliation', 3);

        $now = $this->call(
            'GET',
            '/manage/organisations/' . $orgId . '/seat-pools/' . $poolId . '/reconciliation',
            [],
            200
        );

        $this->expect(
            ($now['reconciled'] ?? null) === true && (int) ($now['divergence'] ?? -1) === 0,
            'the reconciliation read reports no divergence on an untouched pool',
            'reconciled=' . var_export($now['reconciled'] ?? null, true)
                . ' divergence=' . (string) ($now['divergence'] ?? 'null')
        );

        $past = $this->call(
            'GET',
            '/manage/organisations/' . $orgId . '/seat-pools/' . $poolId . '/reconciliation',
            [],
            200,
            ['as_at' => '2020-01-01 00:00:00']
        );

        $this->expect(
            (int) ($past['held'] ?? -1) === 0 && (int) ($past['ledger_balance'] ?? -1) === 0,
            'the as-at read answers about the past rather than about today',
            'held ' . (string) ($past['held'] ?? 'null')
                . ' ledger_balance ' . (string) ($past['ledger_balance'] ?? 'null')
        );

        $this->expectStatus(
            'GET',
            '/manage/organisations/' . $orgId . '/seat-pools/' . $poolId . '/reconciliation',
            [],
            422,
            'a malformed as_at is refused rather than quietly meaning now',
            ['as_at' => 'the year end']
        );
    }

    // -----------------------------------------------------------------------
    // Fixture plumbing
    // -----------------------------------------------------------------------

    private function orgA(): int
    {
        return $this->organisations[self::MARKER . '-a'] ?? 0;
    }

    /**
     * A quiz with one question on the released course, so the attempt half of
     * the ceremony has something to close.
     *
     * Built here rather than by each host, because the two harnesses would
     * otherwise be two fixtures and the ceremony would be proved twice in two
     * shapes. Returns zero when this build registers no authoring route for
     * it, which the caller reports rather than failing on.
     */
    private function quiz(int $courseId): int
    {
        $created = $this->call('POST', '/manage/courses/' . $courseId . '/quizzes', [
            'title' => self::MARKER . ' quiz',
            'pass_mark' => 50,
            'policy' => ['opens_at' => $this->kernel->ports()->clock->now()->format(DATE_ATOM)],
        ], 201);
        $quizId = (int) ($created['id'] ?? 0);

        if ($quizId === 0) {
            return 0;
        }

        $this->quizId = $quizId;
        $question = $this->call('POST', '/manage/quizzes/' . $quizId . '/questions', [
            'type' => 'short_answer',
            'definition' => ['prompt' => 'What does this seat fund?', 'accepted' => []],
            'points' => 5,
        ], 201);
        $this->questionId = (int) ($question['id'] ?? 0);

        return $quizId;
    }

    private function pool(int $orgId, int $courseId, string $name, int $seats): int
    {
        $pool = $this->call('POST', '/manage/organisations/' . $orgId . '/seat-pools', [
            'expected_revision' => $this->revision($orgId),
            'name' => self::MARKER . ' ' . $name,
            'course_id' => $courseId,
            'seats_granted' => $seats,
            'state' => SeatPoolState::ACTIVE,
        ], 200);

        return (int) ($pool['id'] ?? 0);
    }

    private function assign(int $orgId, int $poolId, int $userId): int
    {
        $assigned = $this->call('POST', '/manage/organisations/' . $orgId . '/seat-pools/' . $poolId . '/assignments', [
            'expected_revision' => $this->revision($orgId),
            'user_id' => $userId,
        ], 200);

        return (int) ($assigned['id'] ?? 0);
    }

    private function release(int $orgId, int $assignmentId, string $disposition): void
    {
        $this->call('POST', '/manage/organisations/' . $orgId . '/seat-assignments/' . $assignmentId . '/release', [
            'expected_revision' => $this->revision($orgId),
            'attempt_disposition' => $disposition,
        ], 200);
    }

    /** @return array{capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool} */
    private function balance(int $orgId, int $poolId): array
    {
        $read = $this->call('GET', '/manage/organisations/' . $orgId . '/seat-pools/' . $poolId, [], 200);
        $balance = \is_array($read['balance'] ?? null) ? $read['balance'] : [];

        return [
            'capacity' => (int) ($balance['capacity'] ?? 0),
            'held' => (int) ($balance['held'] ?? 0),
            'available' => (int) ($balance['available'] ?? 0),
            'ledger_balance' => (int) ($balance['ledger_balance'] ?? 0),
            'reconciled' => (bool) ($balance['reconciled'] ?? false),
        ];
    }

    private function revision(int $orgId): int
    {
        $row = $this->ports->database->selectOne(Query::from('organisations')->select(['revision'])->where('id', '=', $orgId));

        return (int) ($row['revision'] ?? 1);
    }

    private function highestQueueId(): int
    {
        $row = $this->ports->database->selectOne(
            Query::from('notification_queue')->select(['id'])->orderBy('id', 'DESC')->limit(1)
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, int>
     */
    private function queuedFor(array $userIds): array
    {
        $counts = [];

        foreach ($userIds as $userId) {
            $counts[$userId] = $this->ports->database->count(
                Query::from('notification_queue')->where('recipient_user_id', '=', $userId)
            );
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $body, int $expected, array $query = []): array
    {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            $query,
            $body,
            self::MARKER . '-' . bin2hex(random_bytes(8))
        ));

        if ($response->status !== $expected) {
            $this->failures[] = sprintf(
                '%s %s answered %d, expected %d: %s',
                $method,
                $path,
                $response->status,
                $expected,
                json_encode($response->data)
            );

            return [];
        }

        $payload = \is_array($response->data) ? $response->data : [];

        // The organisation writes answer in an envelope that carries the
        // organisation revision beside the row; the reads answer with the row
        // itself. Unwrapping here rather than at every call site means an
        // assertion never has to know which of the two it is looking at.
        return \is_array($payload['item'] ?? null) ? $payload['item'] : $payload;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $query
     */
    private function expectStatus(
        string $method,
        string $path,
        array $body,
        int $expected,
        string $what,
        array $query = []
    ): void {
        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            $this->actor,
            $query,
            $body,
            self::MARKER . '-' . bin2hex(random_bytes(8))
        ));

        $this->expect($response->status === $expected, $what, 'status ' . $response->status);
    }

    private function expect(bool $condition, string $what, string $evidence): void
    {
        if ($condition) {
            $this->facts[] = 'ok: ' . $what;

            return;
        }

        $this->failures[] = $what . ': ' . $evidence;
    }

    /**
     * Every row this contract wrote, including its ledger rows.
     *
     * The ledger is append-only for a site's own history and this is not one:
     * the organisations here were created by this run and exist for the length
     * of it, so leaving their rows behind would grow an audit trail nobody can
     * read and would make the next run's arithmetic start from somewhere else.
     * Rows are addressed by the organisation ids this run created and never by
     * a pattern, so nothing a site owner wrote can go with them.
     */
    private function removeEverythingItWrote(): void
    {
        $database = $this->ports->database;
        $removed = 0;

        // What a queued notification has to name to be this run's, collected
        // before the rows that prove it are deleted below. The map is read at
        // the end, in removeTheNotificationsItRaised.
        $owned = $this->subjectsItOwns();

        // The quiz this contract authored, and every attempt taken on it. The
        // course itself belongs to the caller and is left alone.
        if ($this->quizId > 0) {
            $attempts = $database->selectAll(Query::from('attempts')->select(['id'])->where('quiz_id', '=', $this->quizId));

            foreach ($attempts as $attempt) {
                $attemptId = (int) $attempt['id'];

                foreach (['attempt_answer_events', 'attempt_answers', 'attempt_grades', 'attempt_questions'] as $child) {
                    $removed += $database->delete($child, ['attempt_id' => $attemptId]);
                }

                $removed += $database->delete('attempts', ['id' => $attemptId]);
            }

            try {
                $removed += $database->delete('quiz_slots', ['quiz_id' => $this->quizId]);
            } catch (\Throwable) {
                // A table this build does not carry is not a leak. quiz_slots is
                // declared by pro-core, so a free-only site has no such table and
                // this contract wrote no row into it.
            }

            $removed += $database->delete('questions', ['quiz_id' => $this->quizId]);
            $removed += $database->delete('quizzes', ['id' => $this->quizId]);
        }

        foreach ($this->organisations as $orgId) {
            if ($orgId <= 0) {
                continue;
            }

            // An attempt closed by one of these seat events outlives them: it
            // belongs to the learner and to the course, not to this fixture.
            // Its drill-through is unpicked rather than left pointing at a row
            // that is about to go, which is the one thing a delete here could
            // otherwise leave behind.
            $ledgerRows = $database->selectAll(
                Query::from('seat_events')->select(['id'])->where('organisation_id', '=', $orgId)
            );

            foreach ($ledgerRows as $event) {
                $database->update(
                    'attempts',
                    ['closure_reason' => null, 'closed_by_seat_event_id' => null],
                    ['closed_by_seat_event_id' => (int) $event['id']]
                );
            }

            // The entitlements the assignments granted, addressed by the
            // organisation family reference they carry.
            $granted = $database->selectAll(
                Query::from('entitlements')->select(['id', 'source_ref'])
                    ->whereContains('source_ref', 'org' . $orgId . ':')
            );

            foreach ($granted as $entitlement) {
                if (str_starts_with((string) $entitlement['source_ref'], 'org' . $orgId . ':')) {
                    $database->delete('enrolments', ['entitlement_id' => (int) $entitlement['id']]);
                    $removed += $database->delete('entitlements', ['id' => (int) $entitlement['id']]);
                }
            }

            // Every organisation-owned table this run could have written,
            // children before parents.
            //
            // admin_access_log is deliberately NOT on this list. It is a
            // hash-chained append-only audit over the whole site, so deleting
            // the rows this run caused would break the link of every row
            // written after them. Its growth is the log doing its job and is
            // reported rather than undone.
            foreach (
                [
                    'org_notification_secrets', 'org_invitation_claims', 'invitations',
                    'seat_events', 'seat_assignments', 'seat_pools', 'org_role_assignments', 'org_membership_events',
                    'org_memberships', 'org_course_releases', 'org_access_termination_jobs',
                ] as $table
            ) {
                try {
                    $removed += $database->delete($table, ['organisation_id' => $orgId]);
                } catch (\Throwable) {
                    // A table this build does not carry is not a leak.
                }
            }

            $removed += $database->delete('organisations', ['id' => $orgId]);
        }

        // The global compare-and-set rows the grants claimed. They are keyed
        // on the learner and the course and carry no organisation, which is
        // deliberate and is why they cannot go with the organisation above.
        if ($this->courseId > 0) {
            $removed += $database->delete('org_learning_locks', ['course_id' => $this->courseId]);
        }

        $removed += $this->removeTheNotificationsItRaised($owned);

        // The replay receipts every call in this contract minted.
        $receipts = $database->selectAll(
            Query::from('idempotency_keys')->select(['scope', 'idem_key'])->whereContains('idem_key', self::MARKER)
        );

        foreach ($receipts as $receipt) {
            if (str_starts_with((string) $receipt['idem_key'], self::MARKER)) {
                $removed += $database->delete('idempotency_keys', [
                    'scope' => (string) $receipt['scope'],
                    'idem_key' => (string) $receipt['idem_key'],
                ]);
            }
        }

        $this->facts[] = 'removed ' . $removed . ' seeded row(s)';
    }

    /**
     * Everything a queued notification can name that would make it this run's.
     *
     * Read before the rows that prove it are deleted, because the proof is the
     * rows: a threshold alert names a pool of this run's organisations, a
     * sealed invitation delivery is named by an org_notification_secrets row,
     * and an enrolment notice names the course the caller seeded for this
     * contract alone.
     *
     * @return array{queue_ids: list<int>, subjects: array<string, list<int>>}
     */
    private function subjectsItOwns(): array
    {
        $database = $this->ports->database;
        $organisationIds = array_values(array_filter(
            array_map(intval(...), array_values($this->organisations)),
            static fn (int $id): bool => $id > 0
        ));

        $queueIds = [];
        $subjects = [
            'organisation' => $organisationIds,
            'seat_pool' => [],
            'seat_assignment' => [],
            'invitation' => [],
            'org_membership' => [],
            'enrolment' => [],
            'entitlement' => [],
            'user' => $this->accountIds,
            'course' => $this->courseId > 0 ? [$this->courseId] : [],
            'quiz' => $this->quizId > 0 ? [$this->quizId] : [],
            'question' => $this->questionId > 0 ? [$this->questionId] : [],
            'attempt' => [],
        ];

        if ($this->quizId > 0) {
            $attempts = $database->selectAll(
                Query::from('attempts')->select(['id'])->where('quiz_id', '=', $this->quizId)
            );

            foreach ($attempts as $attempt) {
                $subjects['attempt'][] = (int) $attempt['id'];
            }
        }

        foreach ($organisationIds as $orgId) {
            foreach (
                ['seat_pools' => 'seat_pool', 'seat_assignments' => 'seat_assignment',
                    'invitations' => 'invitation', 'org_memberships' => 'org_membership'] as $table => $subject
            ) {
                try {
                    foreach (
                        $database->selectAll(
                            Query::from($table)->select(['id'])->where('organisation_id', '=', $orgId)
                        ) as $row
                    ) {
                        $subjects[$subject][] = (int) $row['id'];
                    }
                } catch (\Throwable) {
                    // A table this build does not carry raised no notification.
                }
            }

            try {
                foreach (
                    $database->selectAll(
                        Query::from('org_notification_secrets')->select(['queue_id'])
                            ->where('organisation_id', '=', $orgId)
                    ) as $secret
                ) {
                    $queueIds[] = (int) $secret['queue_id'];
                }
            } catch (\Throwable) {
                // Same.
            }
        }

        if ($this->courseId > 0) {
            $entitlements = $database->selectAll(
                Query::from('entitlements')->select(['id'])->where('course_id', '=', $this->courseId)
            );

            foreach ($entitlements as $entitlement) {
                $subjects['entitlement'][] = (int) $entitlement['id'];
            }

            $enrolments = $database->selectAll(
                Query::from('enrolments')->select(['id'])->where('course_id', '=', $this->courseId)
            );

            foreach ($enrolments as $enrolment) {
                $subjects['enrolment'][] = (int) $enrolment['id'];
            }
        }

        return ['queue_ids' => $queueIds, 'subjects' => $subjects];
    }

    /**
     * The notification rows this contract raised, and only those.
     *
     * Left behind they would sit at the back of a queue the drain reads
     * oldest first, which is a queue nobody notices growing until a section
     * that sends one message finds its own row unreachable. That is not
     * hypothetical: it is how a run first failed the envelope-sender section
     * of the WordPress harness.
     *
     * Deleted by the high-water mark alone, which is what this did before, it
     * would take a row somebody else wrote while it ran. This contract runs
     * against a real site by design, a real site has its own traffic, and a
     * development site the integration wave drives has ten of them at once.
     * So the mark is the floor and ownership is the predicate, and a row
     * above the mark this run cannot claim is reported rather than removed.
     *
     * @param array{queue_ids: list<int>, subjects: array<string, list<int>>} $owned
     */
    private function removeTheNotificationsItRaised(array $owned): int
    {
        // A watermark of zero is an empty queue, not a failed read, so there
        // is no guard here. The guard that used to stand at zero meant a
        // freshly installed site, whose queue is empty by definition, was the
        // one site where this contract cleaned up nothing at all.
        $database = $this->ports->database;
        $removed = 0;
        $foreign = [];

        $candidates = $database->selectAll(
            Query::from('notification_queue')
                ->select(['id', 'subject_type', 'subject_id', 'recipient_user_id', 'trigger_key', 'parameters'])
                ->where('id', '>', $this->queueWatermark)
        );

        foreach ($candidates as $row) {
            $id = (int) $row['id'];
            $recipient = (int) ($row['recipient_user_id'] ?? 0);
            $subjectType = (string) ($row['subject_type'] ?? '');
            $subjectId = (int) ($row['subject_id'] ?? 0);

            $mine = \in_array($id, $owned['queue_ids'], true)
                || ($recipient > 0 && \in_array($recipient, $this->accountIds, true))
                || (
                    $subjectId > 0
                    && \in_array($subjectId, $owned['subjects'][$subjectType] ?? [], true)
                )
                // The marker of last resort: every pool this contract funds is
                // named for it, and the pool name travels in the parameters of
                // the threshold alerts. No other wave and no site owner writes
                // this string, so it can only widen the claim onto rows this
                // contract raised.
                || str_contains((string) ($row['parameters'] ?? ''), self::MARKER);

            if (!$mine) {
                $foreign[] = (string) $row['trigger_key'];

                continue;
            }

            $removed += $database->delete('notification_queue', ['id' => $id]);
        }

        $this->facts[] = 'removed ' . $removed . ' notification row(s) it raised above queue id '
            . $this->queueWatermark
            . ($foreign === []
                ? ', and left none it did not'
                : ', and left ' . \count($foreign) . ' it did not: ' . implode(', ', array_unique($foreign)));

        return $removed;
    }
}
