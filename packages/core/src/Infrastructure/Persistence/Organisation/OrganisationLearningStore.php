<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/** All learning and ledger queries retain the tenant even for site managers. */
final class OrganisationLearningStore
{
    /**
     * The six seat-holding states, from the one place that declares them.
     *
     * It stays a constant here because eleven call sites read it, and it is
     * now derived rather than repeated: the reconciliation invariant is
     * `purchased + granted - |HOLDING|` equals the signed sum of the ledger,
     * so a second copy of this list that drifted by one state would break the
     * arithmetic on every pool with rows and break it silently.
     *
     * @var list<string>
     */
    public const HOLDING = SeatAssignmentState::HOLDING;

    /** @var list<string> */
    public const ACTIVE = SeatAssignmentState::ACTIVE;

    /**
     * The shape seat_events.payload is written in, stated on the row rather
     * than inside the JSON so the question "which rows can this reader
     * understand" is answerable without decoding every payload. It is hashed
     * with the rest of the row, so a shape change is provable. A row at
     * StoredShape::UNVERSIONED was written before the column existed and is
     * hashed as though the column were absent, which is what keeps a chain
     * written before the column arrived verifiable after it did.
     */
    public const PAYLOAD_VERSION = 1;

    /**
     * The four actor kinds the ledger distinguishes.
     *
     * A person, a scheduled sweep, a roster import and a payment gateway all
     * move seats, and telling them apart is what makes "who did this" a
     * question the ledger can answer rather than a user id that is sometimes a
     * service account. `principal` is the default because it is what every
     * shipped writer except the sweeps is.
     */
    public const ACTOR_PRINCIPAL = 'principal';
    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_IMPORTER = 'importer';
    public const ACTOR_GATEWAY = 'gateway';

    /** @var list<string> */
    public const ACTOR_TYPES = [self::ACTOR_SYSTEM, self::ACTOR_PRINCIPAL, self::ACTOR_IMPORTER, self::ACTOR_GATEWAY];

    public function __construct(
        public readonly DatabasePort $database,
        private readonly ClockPort $clock,
        /**
         * The CSPRNG an assignment reference is minted from.
         *
         * Trailing and defaulted so a harness that builds this store with two
         * arguments still builds; absent, the reference is minted from the
         * platform's own CSPRNG, which is what the port wraps on both hosts.
         * It is never absent in the kernel.
         */
        private readonly ?RandomPort $random = null,
    ) {
    }

    /**
     * An opaque reference for one assignment, minted before its insert.
     *
     * Public because a caller promoting a row written before the column
     * existed needs the same mint, and there may only be one: uniqueness
     * across a revoke and a reissue is structural here, and two minting rules
     * would be two answers to how long and from which alphabet.
     */
    public function mintAssignmentRef(): string
    {
        return $this->random?->verificationCode(32) ?? strtoupper(bin2hex(random_bytes(16)));
    }

    public function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * The same instant as {@see now()}, unformatted.
     *
     * The seat decision contexts carry a clock reading rather than a string,
     * because a policy comparing dates should not be parsing the storage
     * format to do it. It is the store's clock and not a second one, so a
     * frozen test clock freezes both.
     */
    public function instant(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, OrgScope $scope, array $row): int
    {
        // Every assignment gets its reference here, before the insert, and
        // from one place. The row's own id does not exist until the insert
        // has happened, so a reference derived from it would need a
        // placeholder and an update, which reopens the double-assign race
        // holder_key closes; and a mint at each of the three writers would be
        // three chances for one of them to forget.
        if ($table === 'seat_assignments' && !\array_key_exists('assignment_ref', $row)) {
            $row['assignment_ref'] = $this->mintAssignmentRef();
        }

        // The moment this row starts occupying a seat, stamped by the one
        // place every assignment is written. A row created in a seat-holding
        // state holds from now; an over-allocation row created in `pending`
        // holds nothing and stays null until a capacity rise promotes it.
        if (
            $table === 'seat_assignments' && !\array_key_exists('held_from', $row)
            && SeatAssignmentState::holds((string) ($row['state'] ?? ''))
        ) {
            $row['held_from'] = $this->now();
        }

        return $this->database->insert($table, ['organisation_id' => $scope->organisationId] + $row
            + ['created_at' => $this->now(), 'updated_at' => $this->now()]);
    }

    /** @param array<string, mixed> $row */
    public function update(string $table, OrgScope $scope, int $id, array $row): void
    {
        if ($table === 'seat_assignments' && \array_key_exists('state', $row)) {
            $row = $this->guardAssignmentState($scope, $id, $row);
        }

        $this->database->update(
            $table,
            $row + ['updated_at' => $this->now()],
            ['organisation_id' => $scope->organisationId, 'id' => $id]
        );
    }

    /**
     * The assignment state machine, consulted by the one writer every state
     * change goes through.
     *
     * {@see SeatAssignmentState::permits()} had zero call sites anywhere in
     * the repository, so the published table was documentation: a listener
     * moved a seat straight from `assigned` to `completed`, an edge the table
     * forbade, and nothing noticed. It is asked here rather than at each of
     * the seven writers because a rule enforced in seven places is a rule six
     * of them can forget.
     *
     * It also stamps `held_from` on the one transition that starts holding a
     * seat without creating a row: an over-allocation promoted by a capacity
     * rise. A no-op move is permitted and stamps nothing, because writing the
     * same state twice is what an idempotent writer does.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function guardAssignmentState(OrgScope $scope, int $id, array $row): array
    {
        $current = $this->database->selectOne(Query::from('seat_assignments')->select(['state', 'held_from'])
            ->where('organisation_id', '=', $scope->organisationId)->where('id', '=', $id));

        if ($current === null) {
            return $row;
        }

        $from = (string) $current['state'];
        $to = (string) $row['state'];

        if ($from !== $to && !SeatAssignmentState::permits($from, $to)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('A %s seat assignment cannot move to %s.', $from, $to));
        }

        if (
            $from !== $to && $current['held_from'] === null && !\array_key_exists('held_from', $row)
            && SeatAssignmentState::holds($to)
        ) {
            $row['held_from'] = $this->now();
        }

        return $row;
    }

    /** @return array<string, mixed> */
    public function pool(OrgScope $scope, int $id): array
    {
        $row = $this->database->selectOne(Query::from('seat_pools')->where('organisation_id', '=', $scope->organisationId)
            ->where('id', '=', $id)) ?? throw new EntityNotFoundException('No seat pool here.');

        return self::numbers($row);
    }

    /** @return array<string, mixed> */
    public function assignment(OrgScope $scope, int $id): array
    {
        $query = Query::from('seat_assignments', 'a')->select(['a.*'])
            ->join('org_memberships', 'm', 'a.membership_id', 'm.id', [['a.organisation_id', 'm.organisation_id']])
            ->where('a.organisation_id', '=', $scope->organisationId)->where('a.id', '=', $id);
        $scope->constrainUnit($query, 'm.org_unit_id');
        $row = $this->database->selectOne($query) ?? throw new EntityNotFoundException('No seat assignment here.');

        return self::numbers($row);
    }

    /**
     * One assignment, including a reservation that has no membership yet.
     *
     * {@see assignment()} inner-joins org_memberships, which is right for a
     * live seat and drops a reserved row on the floor: a reservation taken at
     * issue has no membership and may have no account, so the join matches
     * nothing and the read answers "no seat assignment here". Every path that
     * disposes of a whole pool has to reach both kinds, and the term end did
     * not: the sweep caught the refusal and counted the seat as untouched,
     * while the administrator's term end let it out as a 404 on a pool that
     * simply had an outstanding invitation.
     *
     * The unit narrowing still applies through the left join, so a unit
     * administrator does not reach a reservation that belongs to no unit.
     *
     * @return array<string, mixed>
     */
    public function openAssignment(OrgScope $scope, int $id): array
    {
        $query = Query::from('seat_assignments', 'a')->select(['a.*'])
            ->leftJoin('org_memberships', 'm', 'a.membership_id', 'm.id', [['a.organisation_id', 'm.organisation_id']])
            ->where('a.organisation_id', '=', $scope->organisationId)->where('a.id', '=', $id);
        $scope->constrainUnit($query, 'm.org_unit_id');
        $row = $this->database->selectOne($query) ?? throw new EntityNotFoundException('No seat assignment here.');

        return self::numbers($row);
    }

    /**
     * The seat a learner holds on a course, found before any scope exists.
     *
     * **This is a witness lookup and not a tenancy escape.** The progress
     * listener has no caller and therefore no scope: a domain event says a
     * learner touched a course, and the assignment row is what says which
     * organisation that concerns. So the row is found here, in the
     * persistence layer that owns these tables, and the tenant it names is
     * then recovered through {@see OrganisationSweepAuthority} before
     * anything is written. It is the same shape as the sweep authority's own
     * row lookup and it exists for the same reason.
     *
     * It answers one row and orders by id, so a learner holding seats in two
     * organisations for one course moves the oldest. That combination is
     * already refused upstream by org_learning_locks, which is a global
     * compare-and-set on (user, course).
     *
     * @param list<string> $states
     *
     * @return array<string, mixed>|null
     */
    public function assignmentOnCourse(int $userId, int $courseId, array $states): ?array
    {
        $row = $this->database->selectOne(Query::from('seat_assignments', 'a')
            ->select(['a.id', 'a.organisation_id', 'a.pool_id', 'a.entitlement_id', 'a.state'])
            ->join('seat_pools', 'p', 'a.pool_id', 'p.id', [['a.organisation_id', 'p.organisation_id']])
            ->where('a.user_id', '=', $userId)
            ->where('p.course_id', '=', $courseId)
            ->whereIn('a.state', $states)
            ->orderBy('a.id')
            ->limit(1));

        return $row === null ? null : self::numbers($row);
    }

    /**
     * Assignments written before assignment_ref existed, oldest first.
     *
     * Scope-free and site-wide on purpose, and it is the same kind of read as
     * {@see assignmentOnCourse}: an upgrade has no caller, so there is no
     * tenant to narrow on and inventing one would make the backfill skip
     * every organisation it did not guess. It lives here rather than in the
     * use case for the same reason that one does, so the only file naming
     * these tables outside a scope is the file that owns them.
     *
     * The predicate is "still has no reference", so a pass that writes rows
     * removes them from its own result set. That is why the caller re-reads
     * rather than pages by offset: an offset cursor over a shrinking set
     * skips exactly as many rows as the previous page wrote.
     *
     * @return list<int>
     */
    public function assignmentsWithoutReference(int $limit): array
    {
        $rows = $this->database->selectAll(Query::from('seat_assignments')
            ->select(['id'])
            ->whereNull('assignment_ref')
            ->orderBy('id')
            ->limit($limit));

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * Gives one stored assignment the reference it was written without.
     *
     * By id alone, and only where the reference is still absent, so two
     * upgrade requests racing each other cannot overwrite a reference one of
     * them has already minted: the second update matches no row and answers
     * zero. Nothing else about the row is touched, updated_at included,
     * because an upgrade did not change what the assignment says.
     */
    public function giveAssignmentReference(int $id, string $reference): int
    {
        return $this->database->update(
            'seat_assignments',
            ['assignment_ref' => $reference],
            ['id' => $id, 'assignment_ref' => null],
        );
    }

    public function requireNoOutstandingReservation(OrgScope $scope, int $poolId, ?string $email): void
    {
        if ($email === null || $email === '') {
            return;
        }

        $held = $this->database->selectOne(OrgScopedReader::from($scope, 'invitations', 'i')
            ->select(['i.id'])
            ->join('seat_assignments', 'a', 'i.seat_assignment_id', 'a.id', [['i.organisation_id', 'a.organisation_id']])
            ->where('i.email', '=', strtolower($email))
            ->where('i.state', '=', 'pending')
            ->where('a.pool_id', '=', $poolId)
            ->where('a.state', '=', SeatAssignmentState::RESERVED));

        if ($held !== null) {
            throw new ConflictException(sprintf(
                'Invitation %d is already holding a seat in this pool for that address.',
                (int) $held['id']
            ));
        }
    }

    /** @return array<string, mixed>|null */
    public function currentAssignment(OrgScope $scope, int $poolId, int $userId): ?array
    {
        $row = $this->database->selectOne(Query::from('seat_assignments')
            ->where('organisation_id', '=', $scope->organisationId)->where('pool_id', '=', $poolId)
            ->where('user_id', '=', $userId)->whereNotNull('holder_key'));

        return $row === null ? null : self::numbers($row);
    }

    /**
     * A pool's funded capacity, which is zero until the pool is funded.
     *
     * `pool_funded` used to be written at creation whatever state the pool was
     * created in, so a draft pool carrying a quantity nobody had paid for
     * already read as funded and the entry into `active` wrote no ledger row
     * at all. The funding event moved to the entry into `active`, and this is
     * what keeps the two derivations of availability agreeing while it does:
     * an unfunded pool has a capacity of zero against a ledger balance of
     * zero. The stamp and not the state, because a pool abandoned in draft can
     * be closed without ever being funded.
     *
     * @param array<string, mixed> $pool
     */
    public static function capacityOf(array $pool): int
    {
        if (($pool['funded_at'] ?? null) === null) {
            return 0;
        }

        return (int) $pool['seats_purchased'] + (int) $pool['seats_granted'];
    }

    /** @return array{capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool} */
    public function balance(OrgScope $scope, int $poolId): array
    {
        $pool = $this->pool($scope, $poolId);
        $capacity = self::capacityOf($pool);
        $held = $this->database->count(Query::from('seat_assignments')
            ->where('organisation_id', '=', $scope->organisationId)->where('pool_id', '=', $poolId)
            ->whereIn('state', self::HOLDING));
        $sum = $this->database->selectOne(Query::from('seat_events')->select([])
            ->aggregate(Aggregate::sum('seat_delta', 'balance'))->where('organisation_id', '=', $scope->organisationId)
            ->where('pool_id', '=', $poolId));
        $ledger = (int) ($sum['balance'] ?? 0);

        return ['capacity' => $capacity, 'held' => $held, 'available' => $capacity - $held,
            'ledger_balance' => $ledger, 'reconciled' => $ledger === $capacity - $held];
    }

    /** @param list<array<string, mixed>> $pools
     * @return array<int, array{capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool}>
     */
    public function balances(OrgScope $scope, array $pools): array
    {
        if ($pools === []) {
            return [];
        }
        $ids = array_map(static fn (array $pool): int => (int) $pool['id'], $pools);
        $held = [];
        foreach (
            $this->database->selectAll(Query::from('seat_assignments')->select(['pool_id'])
            ->aggregate(Aggregate::count('held'))->where('organisation_id', '=', $scope->organisationId)
            ->whereIn('pool_id', $ids)->whereIn('state', self::HOLDING)->groupBy('pool_id')) as $row
        ) {
            $held[(int) $row['pool_id']] = (int) $row['held'];
        }
        $ledger = [];
        foreach (
            $this->database->selectAll(Query::from('seat_events')->select(['pool_id'])
            ->aggregate(Aggregate::sum('seat_delta', 'balance'))->where('organisation_id', '=', $scope->organisationId)
            ->whereIn('pool_id', $ids)->groupBy('pool_id')) as $row
        ) {
            $ledger[(int) $row['pool_id']] = (int) $row['balance'];
        }
        $result = [];
        foreach ($pools as $pool) {
            $id = (int) $pool['id'];
            $capacity = self::capacityOf($pool);
            $holding = $held[$id] ?? 0;
            $sum = $ledger[$id] ?? 0;
            $result[$id] = ['capacity' => $capacity, 'held' => $holding, 'available' => $capacity - $holding,
                'ledger_balance' => $sum, 'reconciled' => $capacity - $holding === $sum];
        }

        return $result;
    }

    /**
     * The two derivations of availability at a given instant.
     *
     * The reconciliation the product had could only ask about now, because
     * both clocks on every ledger row carried the same value and the state
     * count is by definition a count of today. This one takes the business
     * clock, so `org.seat_reconciliation` can alert on a divergence, a
     * delivery document can say what a pool looked like on the day it was
     * invoiced, and an auditor asking about the year end gets the year end.
     *
     * The ledger side sums `occurred_at` rather than `recorded_at`
     * deliberately: a backdated correction belongs to the day it took effect,
     * and a tamper check is the other question, asked with the other clock.
     *
     * **Both sides are as at the instant, and neither is today's answer.** The
     * capacity comes from the pool's own funding events up to the instant, not
     * from `seats_purchased + seats_granted` as the row stands now: a pool
     * funded with one seat in January and raised to five in March used to read
     * back at January with a capacity of five, a divergence of minus four, and
     * a false alert on a pool that was perfectly healthy. Every pool whose
     * quantity ever changed reported a divergence for every date before the
     * change.
     *
     * **The holding side counts rows that were holding a seat then**, from the
     * row's own two stamps: `held_from`, which is the moment it began
     * occupying a seat, and `closed_at` where the state it closed into gives
     * the seat back. It used to add a count of rows now in HOLDING to a count
     * of rows now in TERMINAL, which double counted `forfeited` and `expired`
     * because those two are in both lists, and counted an over-allocation row
     * that was `pending` at the instant as having held a seat then. Both
     * produced a negative availability on a pool that reconciles perfectly at
     * `now`, and this is the read the reconciliation task alerts on.
     *
     * @return array{as_at: string, capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool, divergence: int}
     */
    public function reconciliationAt(OrgScope $scope, int $poolId, \DateTimeImmutable $asAt): array
    {
        $instant = $asAt->format('Y-m-d H:i:s');
        $this->pool($scope, $poolId);

        // The pool-level rows are exactly the funding history: pool_created at
        // zero, pool_funded carrying the quantity, and the two quantity
        // changes. The assignment-level rows are the seats spent and returned,
        // which is the other side and is deliberately not counted here.
        $funded = $this->database->selectOne(OrgScopedReader::from($scope, 'seat_events')->select([])
            ->aggregate(Aggregate::sum('seat_delta', 'balance'))
            ->where('pool_id', '=', $poolId)
            ->whereNull('assignment_id')
            ->where('occurred_at', '<=', $instant));
        $capacity = (int) ($funded['balance'] ?? 0);

        $holding = $this->database->count(OrgScopedReader::from($scope, 'seat_assignments')
            ->where('pool_id', '=', $poolId)
            ->whereNotNull('held_from')
            ->where('held_from', '<=', $instant)
            // A row that gave its seat back on or before the instant was not
            // holding one then, and the three states that give it back are
            // exactly the terminal ones outside HOLDING: released, reclaimed
            // and cancelled. `forfeited` and `expired` are inside HOLDING and
            // never give it back, so they stay counted whatever their closing
            // stamp says, which is what keeps a spent seat spent.
            ->whereAnyOf(
                Predicate::in('state', self::HOLDING),
                Predicate::isNull('closed_at'),
                Predicate::compare('closed_at', '>', $instant),
            ));

        $sum = $this->database->selectOne(OrgScopedReader::from($scope, 'seat_events')->select([])
            ->aggregate(Aggregate::sum('seat_delta', 'balance'))
            ->where('pool_id', '=', $poolId)
            ->where('occurred_at', '<=', $instant));
        $ledger = (int) ($sum['balance'] ?? 0);

        return ['as_at' => $instant, 'capacity' => $capacity, 'held' => $holding,
            'available' => $capacity - $holding, 'ledger_balance' => $ledger,
            'reconciled' => $ledger === $capacity - $holding, 'divergence' => $ledger - ($capacity - $holding)];
    }

    /**
     * A pool's stored provider parameters, decoded, with the shape that wrote
     * them.
     *
     * Two columns hold configuration and each names its own StoredShape
     * version, so a provider reading its parameters is told which shape it is
     * reading rather than guessing from the keys present. An undecodable
     * value answers an empty bag at version zero rather than throwing: a
     * policy is a decision and a malformed parameter is the pool's problem,
     * not a reason to refuse a read of it.
     *
     * @param array<string, mixed> $pool
     *
     * @return array{0: array<string, mixed>, 1: int}
     */
    public static function params(array $pool, string $column): array
    {
        $raw = $pool[$column] ?? null;
        $version = (int) ($pool[$column . '_version'] ?? StoredShape::UNVERSIONED);

        if (!\is_string($raw) || $raw === '') {
            return [[], $version];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], StoredShape::UNVERSIONED];
        }

        return [\is_array($decoded) ? $decoded : [], $version];
    }

    public function requireReconciled(OrgScope $scope, int $poolId): void
    {
        if (!$this->balance($scope, $poolId)['reconciled']) {
            throw new ConflictException('Seat accounting needs reconciliation before this pool can change.');
        }
    }

    /**
     * Appends one ledger row and answers its id.
     *
     * The id is answered because the revocation ceremony records it on every
     * attempt it closes, which is the drill-through an auditor asking why an
     * attempt ended at minute forty needs; before it, the row that caused a
     * closure could only be guessed at from a timestamp.
     *
     * **The two clocks are now two clocks.** occurredAt is when the movement
     * took effect and recorded_at is when the row was written, and they were
     * the same value on every row, so an as-at reconciliation returned today's
     * answer for every date and a backdated correction was indistinguishable
     * from a tamper. Defaulting to now keeps every existing caller honest: a
     * path with nothing to backdate says nothing and gets the two clocks
     * equal, which is the truth for it.
     *
     * **The idempotency key is claimed here.** R0 added the column and its
     * unique index and left the writer for this wave. A command replayed
     * under one key writes one row: the second insert loses the unique index
     * and is answered with the id of the row that won, so a retried request
     * neither doubles a delta nor forks the hash chain.
     *
     * @param array<string, mixed> $payload
     */
    public function ledger(
        OrgScope $scope,
        int $revision,
        int $poolId,
        ?int $assignmentId,
        string $event,
        int $delta,
        string $reason,
        ?int $userId = null,
        ?int $entitlementId = null,
        array $payload = [],
        ?\DateTimeImmutable $occurredAt = null,
        string $actorType = self::ACTOR_PRINCIPAL,
        ?string $externalRef = null,
        ?string $idempotencyKey = null
    ): int {
        // Every row claims a key, and a caller that has not supplied one gets
        // the row's own identity as its key: the organisation, the event, the
        // pool, the assignment and the revision that wrote it. The revision
        // is what makes it a key rather than a name, because it is claimed
        // once per mutation under a compare-and-set, so two rows of one event
        // for one assignment inside one mutation cannot exist and a replayed
        // mutation cannot write a second one.
        $idempotencyKey ??= sprintf(
            'org%d:%s:pool%d:a%d:r%d',
            $scope->organisationId,
            $event,
            $poolId,
            $assignmentId ?? 0,
            $revision,
        );

        $seen = $this->claimed($scope, $idempotencyKey);

        if ($seen !== null) {
            // The replay of a command that already landed. Answered with the
            // original row rather than refused, because the caller is a retry
            // and the outcome it wants is the outcome that happened.
            return $seen;
        }

        $last = $this->database->selectOne(Query::from('seat_events')->where('organisation_id', '=', $scope->organisationId)
            ->where('pool_id', '=', $poolId)->orderBy('id', 'DESC'));
        $balance = $last === null ? 0 : (int) $last['balance_after'];
        $row = [
            'organisation_id' => $scope->organisationId, 'pool_id' => $poolId, 'assignment_id' => $assignmentId,
            'event_key' => $event, 'seat_delta' => $delta, 'balance_after' => $balance + $delta,
            'subject_user_id' => $userId, 'entitlement_id' => $entitlementId, 'actor_id' => $scope->actorId,
            'reason_key' => $reason,
            'occurred_at' => ($occurredAt ?? $this->clock->now())->format('Y-m-d H:i:s'),
            'recorded_at' => $this->now(),
            'actor_type' => $actorType, 'external_ref' => $externalRef,
            'organisation_revision' => $revision, 'prev_hash' => $last === null ? str_repeat('0', 64) : (string) $last['hash'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'payload_version' => self::PAYLOAD_VERSION,
            'idempotency_key' => $idempotencyKey,
        ];
        $row['hash'] = self::digest($row);

        try {
            $id = $this->insert('seat_events', $scope, $row);
        } catch (UniqueConstraintViolation $race) {
            // The select above and this insert are two statements, so two
            // writers racing one key both pass the select and one of them
            // loses the index. The docblock promised the loser was answered
            // with the id of the row that won and nothing implemented it: the
            // loser raised an uncaught violation and the caller saw a 500 for
            // a command that had in fact landed. This is the same
            // catch-and-re-read the entitlement digest insert already uses.
            $winner = $this->claimed($scope, $idempotencyKey)
                ?? throw $race;

            return $winner;
        }

        $held = $this->balance($scope, $poolId)['held'];
        $this->update('seat_pools', $scope, $poolId, ['seats_assigned' => $held]);

        return $id;
    }

    /**
     * The ledger row already holding this key, or null.
     *
     * **The predicate is the index.** `seat_events_idem_uq` is unique on
     * `idempotency_key` alone, so a lookup narrowed by organisation could
     * answer "nothing here" about a key another organisation already holds and
     * the insert would then be refused by an index the lookup never consulted.
     * The index is not widened to the pair because it guards a hash-chained
     * ledger and this repository does not issue destructive ALTERs against
     * one; the global form is in any case the stronger constraint, since
     * everything it refuses the per-organisation form would also refuse except
     * a key deliberately reused across tenants.
     *
     * That one case is refused explicitly rather than answered with a foreign
     * row's id, because handing one organisation the id of another's ledger
     * row would tell the caller its command landed when it did not. Every
     * shipped writer composes a key that carries the organisation or a
     * globally unique row id, so nothing reaches this refusal by accident.
     */
    private function claimed(OrgScope $scope, string $idempotencyKey): ?int
    {
        $seen = $this->database->selectOne(Query::from('seat_events')->select(['id', 'organisation_id'])
            ->where('idempotency_key', '=', $idempotencyKey));

        if ($seen === null) {
            return null;
        }

        if ((int) $seen['organisation_id'] !== $scope->organisationId) {
            throw new ConflictException('That seat ledger idempotency key belongs to another organisation.');
        }

        return (int) $seen['id'];
    }

    /** @param array<string, mixed> $row */
    public static function digest(array $row): string
    {
        unset($row['hash'], $row['id'], $row['created_at'], $row['updated_at']);

        // A row written before either column existed hashed without them, so
        // both hash as though absent when they carry nothing: an unversioned
        // payload and an unclaimed row. A chain written before they arrived
        // therefore still verifies after they did, and a row that does carry a
        // version or a key binds it into the chain.
        if (StoredShape::UNVERSIONED === (int) ($row['payload_version'] ?? StoredShape::UNVERSIONED)) {
            unset($row['payload_version']);
        }

        if (($row['idempotency_key'] ?? null) === null) {
            unset($row['idempotency_key']);
        }

        // actor_type and external_ref join the chain on exactly the same
        // terms, and this is why neither carries a database default: a
        // migration that had written "principal" onto the rows already in the
        // ledger would have changed what those rows say without changing
        // their hash, so the chain over them would stop verifying the first
        // time anything checked it. A row carrying nothing hashes as though
        // the column were absent; a row carrying a value binds it in.
        foreach (['actor_type', 'external_ref'] as $appended) {
            if (($row[$appended] ?? null) === null) {
                unset($row[$appended]);
            }
        }

        $row = self::numbers($row);
        ksort($row, SORT_STRING);

        return hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function page(Query $query, int $page, int $perPage, string $order = 'id'): array
    {
        $total = $this->database->count(clone $query);
        $rows = $this->database->selectAll($query->orderBy($order)->limit($perPage)->offset(($page - 1) * $perPage));

        return ['items' => array_map(self::numbers(...), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function numbers(array $row): array
    {
        foreach (
            ['id', 'organisation_id', 'course_id', 'released_by', 'seats_purchased', 'seats_granted', 'seats_assigned',
            'created_by', 'pool_id', 'membership_id', 'user_id', 'entitlement_id', 'assigned_by', 'assignment_id',
            'seat_delta', 'balance_after', 'subject_user_id', 'actor_id', 'organisation_revision',
            'max_entitlement_id', 'max_assignment_id', 'processed_count', 'payload_version',
            'min_hold_days', 'access_days', 'scope_params_version', 'policy_params_version',
            'seat_pool_id', 'invitation_id'] as $key
        ) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        unset($row['holder_key']);

        return $row;
    }
}
