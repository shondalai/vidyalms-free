<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\AttemptDisposition;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatAssignmentState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventType;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatExpiryOutcome;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatOverAllocationOutcome;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatPoolState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Provider\Organisation\LapseAccessKeepProgress;
use Shondalai\VidyaLMS\Core\Registry\SeatEventReason\SeatEventReasonRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatExpiryDisposition\SeatExpiryDispositionRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation\SeatOverAllocationRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatReclaim\SeatReclaimRegistry;
use Shondalai\VidyaLMS\Core\Registry\SeatScope\SeatScopeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\DeclaresParamsShape;

/** All balance changes take the organisation CAS lock before reading capacity. */
final class ManageOrganisationSeats
{
    /**
     * A finalised funding document fixes the purchased quantity.
     *
     * Stripe states the legal reason plainly: many jurisdictions treat a
     * finalised invoice as a legal document whose monetary values cannot be
     * edited. The vocabulary is free even though only a Business package
     * writes anything but `none`, because the state a pool sits in while it
     * waits for money is a free column a purchase order attaches to.
     */
    public const FUNDING_FINALISED = 'finalised';

    /** @var list<string> */
    public const FUNDING_STATES = ['none', 'pending', self::FUNDING_FINALISED, 'cancelled'];

    /**
     * How many held seats one administrator-driven term end closes inside its
     * own transaction.
     *
     * The same order as the termination drain's synchronous bound and for the
     * same reason: past it the work belongs to a sweep, and a transaction
     * that closes ten thousand seats holds locks for as long as it takes.
     */
    public const MAX_SYNCHRONOUS_EXPIRY = 500;

    /** How deep a provider's stored configuration may nest, and how many bytes of it may be stored. */
    public const MAX_PARAM_DEPTH = 8;
    public const MAX_PARAM_BYTES = 8192;

    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $organisations,
        private readonly OrganisationLearningStore $store,
        /**
         * The organisation rule and the release rule as one object, replacing
         * the release policy this class used to hold beside the resolver and
         * pair with it by hand at three separate statements.
         */
        private readonly ComposedAccess $composed,
        private readonly GrantEntitlement $grant,
        private readonly OrganisationLearningEvents $events,
        private readonly SeatLifecycle $lifecycle,
        private readonly UsersPort $users,
        /**
         * The reclaim policies, for the one question saving a pool asks: is
         * this a policy this site actually has.
         *
         * Required rather than defaulted, because an empty default would
         * refuse every pool save with a validation error naming a field the
         * administrator filled in correctly.
         */
        private readonly SeatReclaimRegistry $reclaimPolicies,
        /**
         * The other three policy families, resolved inside the use case at
         * the moment each decision is taken rather than held as a resolved
         * provider at construction.
         *
         * That is the difference between a seam and a wall: a pool stores a
         * key, the key is looked up when the question is asked, and a package
         * registering a provider after the kernel booted is selectable on the
         * next request rather than on the next release.
         *
         * Required, on the same terms as the reclaim registry beside them: a
         * defaulted empty registry would refuse every assignment with a
         * message naming a provider the administrator never chose.
         */
        private readonly SeatScopeRegistry $scopes,
        private readonly SeatOverAllocationRegistry $overAllocation,
        private readonly SeatExpiryDispositionRegistry $expiry,
        /**
         * The reason vocabulary, asked only for labels.
         *
         * The ledger stores the key whether or not this resolves it, so an
         * uninstalled package costs a label and never a reason. That is why
         * the read path holds the registry and the write path does not.
         */
        private readonly SeatEventReasonRegistry $reasons,
        /**
         * The threshold alerts, raised the moment a seat moves.
         *
         * They had exactly one caller, the reservation path, so an
         * administrator who filled the last seat by direct assignment (the
         * primary write path) heard nothing until the next day's
         * reconciliation. The alert's own docblock says a seat moving raises
         * it at the moment it happens; this is what makes that true.
         *
         * Optional so a harness with no notification engine still builds a
         * seat use case; absent, the reconciliation pass raises the same
         * alert on its next run under the same occurrence key, so the alert
         * is late rather than lost or doubled.
         */
        private readonly ?SeatThresholdAlerts $alerts = null,
    ) {
    }

    public function requireTarget(Principal $actor, int $orgId, ?int $userId = null, ?int $assignmentId = null): void
    {
        $scope = $this->access->require($actor, $orgId, 'seats');
        if ($userId !== null && $this->organisations->activeMemberId($scope, $userId) === null) {
            throw new \Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException('No active organisation member here.');
        }
        if ($assignmentId !== null) {
            $this->store->assignment($scope, $assignmentId);
        }
    }

    /** @return array<string, mixed> */
    public function pools(Principal $actor, int $orgId, int $page = 1, int $perPage = 25): array
    {
        $scope = $this->access->require($actor, $orgId);
        OrganisationInput::page($page, $perPage);
        $result = $this->store->page(OrgScopedReader::from($scope, 'seat_pools', 'p')->select([
            'p.*', 'c.title AS course_title',
        ])->join('courses', 'c', 'p.course_id', 'c.id'), $page, $perPage, 'p.id');
        $balances = $this->store->balances($scope, array_values($result['items']));
        foreach ($result['items'] as &$row) {
            $row['balance'] = $balances[(int) $row['id']];
            $row['balance_scope'] = 'organisation_pool';
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function getPool(Principal $actor, int $orgId, int $poolId): array
    {
        $scope = $this->access->require($actor, $orgId);
        $pool = $this->store->pool($scope, $poolId);
        $course = $this->store->database->selectOne(Query::from('courses')->select(['title'])
            ->where('id', '=', (int) $pool['course_id']));

        // Asked rather than thrown, because this is a read and a pool whose
        // course the site owner has since withdrawn is a fact an administrator
        // needs to see, not a refusal to show them their own pool. It is the
        // same call the write path makes, so the two answers cannot drift.
        return $pool + ['course_title' => $course['title'] ?? null, 'balance' => $this->store->balance($scope, $poolId),
            'balance_scope' => 'organisation_pool',
            // Surfaced rather than refused, on the same terms as the withdrawn
            // course above and as an unresolved organisation type: a stored
            // policy whose package has been removed is a fact the console must
            // show, and blocking every other edit to the row until it is
            // changed is a refusal aimed at the wrong moment.
            'reclaim_policy_unresolved' => !$this->reclaimPolicies->has((string) ($pool['reclaim_policy'] ?? '')),
            // The same fact for the three families added beside it, each
            // answered by its own registry so a console can name which
            // package a site is missing rather than reporting one flag for
            // four independent decisions.
            'scope_unresolved' => !$this->scopes->has((string) ($pool['scope_key'] ?? '')),
            'over_allocation_unresolved' => !$this->overAllocation->has((string) ($pool['over_allocation_key'] ?? '')),
            'expiry_disposition_unresolved' => !$this->expiry->has((string) ($pool['expiry_disposition_key'] ?? '')),
            'course_released' => $this->composed->allows($scope, (int) $pool['course_id'])];
    }

    /**
     * The two derivations of a pool's availability at a chosen instant.
     *
     * The console can only ever ask about now, and that was the whole of what
     * the product could answer: both clocks on every ledger row carried the
     * same value, so an as-at reconciliation returned today's answer for
     * every date. This is the read the free reconciliation task and the seat
     * delivery document both take, and it is deliberately a read: a
     * divergence is reported and never silently corrected, because a silent
     * correction is how a defect becomes invisible.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(Principal $actor, int $orgId, int $poolId, ?string $asAt = null): array
    {
        // A pool belongs to the organisation and to no unit, so there is
        // nothing here for a unit-scoped assignment to narrow on and a scope
        // carrying one is refused rather than served the whole tenant.
        $scope = $this->access->requireOrganisationWide($actor, $orgId, 'events');
        // An absent or empty as_at means now. A malformed one is refused
        // rather than quietly meaning now, because a report that answered
        // about today when it was asked about the year end is the defect this
        // read exists to remove.
        $stated = $asAt === null || $asAt === '' ? null : OrganisationInput::datetime($asAt, 'as_at');
        $instant = $stated === null ? $this->store->instant() : new \DateTimeImmutable($stated, new \DateTimeZone('UTC'));

        return $this->store->reconciliationAt($scope, $poolId, $instant) + ['pool_id' => $poolId];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function savePool(Principal $actor, int $orgId, ?int $poolId, array $input): array
    {
        $this->access->requireSiteManager($actor);
        OrganisationInput::only($input, ['expected_revision', 'name', 'course_id', 'seats_granted', 'seats_purchased',
            'reclaim_policy', 'scope_key', 'over_allocation_key', 'expiry_disposition_key',
            // The two configuration bags every provider reads and nothing
            // could write. SeatDecisionContext, the free over-allocation
            // stance and the free seat scope all take their configuration from
            // these two columns, and the save allowlist did not carry either,
            // so a third-party or Business provider could not be configured
            // without a core edit or direct SQL.
            'scope_params', 'policy_params',
            'min_hold_days', 'access_days', 'funding_state', 'funding_ref',
            'state', 'starts_at', 'ends_at']);
        $expected = OrganisationInput::integer($input, 'expected_revision');

        return $this->store->database->transactional(function () use ($actor, $orgId, $poolId, $input, $expected): array {
            $scope = $this->access->require($actor, $orgId, 'seats');
            $old = $poolId === null ? null : $this->store->pool($scope, $poolId);
            $courseId = isset($input['course_id']) ? OrganisationInput::integer($input, 'course_id') : (int) ($old['course_id'] ?? 0);
            $this->composed->requireRelease($scope, $courseId);
            if ($old !== null && $courseId !== (int) $old['course_id']) {
                throw ValidationException::forField('course_id', 'A funded pool cannot change courses.');
            }
            $quantity = $input['seats_granted'] ?? $old['seats_granted'] ?? null;
            if (!\is_int($quantity) || $quantity < 0 || $quantity > 1000000) {
                throw ValidationException::forField('seats_granted', 'Expected a whole quantity from 0 to 1000000.');
            }
            $name = $input['name'] ?? $old['name'] ?? '';
            if (!\is_string($name) || trim($name) === '' || mb_strlen($name) > 190) {
                throw ValidationException::forField('name', 'Enter a pool name up to 190 characters.');
            }
            // The registry answers, so a fifth policy registered by a package
            // is selectable the moment it is registered. The literal list this
            // replaced meant the seam could be registered into and never
            // chosen, which is a seam with a wall behind it.
            // Validated only when the input names it. A pool stored under a
            // policy whose package has since been removed could not be
            // renamed: the fall-back was validated as though the caller had
            // sent it, so the refusal named a field the request did not
            // carry and could not carry, because the request schema is
            // closed. Fail-closed at reclaim time is the right direction and
            // this is a different moment.
            $chosen = \array_key_exists('reclaim_policy', $input);
            $policy = $input['reclaim_policy'] ?? $old['reclaim_policy'] ?? 'before_completion';
            if (!\is_string($policy) || ($chosen && !$this->reclaimPolicies->has($policy))) {
                throw ValidationException::forField('reclaim_policy', 'Select an installed reclaim policy.');
            }
            if (!$chosen && $old === null && !$this->reclaimPolicies->has($policy)) {
                // A create with no policy names the shipped default, and a
                // build without it is a broken build rather than a bad request.
                throw ValidationException::forField('reclaim_policy', 'Select an installed reclaim policy.');
            }
            // All six stored states, with the edges the published machine
            // draws. Four of them were unreachable: a pool a seller has put
            // on hold, a pool waiting on a purchase order, a pool whose term
            // has ended and a pool an administrator has closed all looked
            // identical to a console, and the two the code accepted were the
            // only two anything could ever be.
            $state = $input['state'] ?? $old['state'] ?? SeatPoolState::ACTIVE;
            if (!\is_string($state) || !SeatPoolState::known($state)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('state', 'Expected one of ' . implode(', ', SeatPoolState::STATES) . '.');
            }
            $wasState = (string) ($old['state'] ?? SeatPoolState::DRAFT);
            if ($old !== null && !SeatPoolState::permits($wasState, $state)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new ConflictException(sprintf('A %s pool cannot move to %s.', $wasState, $state));
            }
            // A creation was guarded by nothing at all, because the edge check
            // above is conditioned on there being a previous row. A pool could
            // be written straight into `expired`, `suspended` or `closed`,
            // three states the machine says are unreachable from `draft`, and
            // it was funded on the way in with no entry event, so a console
            // could not say when it was suspended or by whom.
            if ($old === null && !\in_array($state, SeatPoolState::CREATABLE, true)) {
                throw ValidationException::forField(
                    'state',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    'A pool is created in one of ' . implode(', ', SeatPoolState::CREATABLE) . '.'
                );
            }
            [$scopeKey, $overAllocationKey, $expiryKey] = $this->policyKeys($input, $old);
            $starts = OrganisationInput::datetime($input['starts_at'] ?? $old['starts_at'] ?? null, 'starts_at');
            $ends = OrganisationInput::datetime($input['ends_at'] ?? $old['ends_at'] ?? null, 'ends_at');
            if ($starts !== null && $ends !== null && $starts >= $ends) {
                throw ValidationException::forField('ends_at', 'The pool must end after it starts.');
            }
            $purchased = $this->purchased($input, $old);
            $next = $this->organisations->claim($scope, $expected);
            // The funding stamp, and the one place it is written. A pool
            // created in draft or awaiting_payment holds a quantity nobody has
            // paid for, so it is unfunded until it enters active; a pool
            // created active is funded now. Once stamped it is never rewritten,
            // because a pool is funded once and a suspension is not a refund.
            $funded = $old === null
                ? ($state === SeatPoolState::ACTIVE ? $this->store->now() : null)
                : ($old['funded_at'] ?? (SeatPoolState::funds($wasState, $state) ? $this->store->now() : null));
            $values = ['name' => trim($name), 'course_id' => $courseId, 'seats_granted' => $quantity,
                'seats_purchased' => $purchased,
                'reclaim_policy' => $policy, 'scope_key' => $scopeKey, 'over_allocation_key' => $overAllocationKey,
                'expiry_disposition_key' => $expiryKey, 'state' => $state, 'funded_at' => $funded,
                'starts_at' => $starts, 'ends_at' => $ends]
                + $this->params($input, $old, $scopeKey, $overAllocationKey)
                + $this->terms($input, $old);
            if ($poolId === null) {
                $poolId = $this->store->insert('seat_pools', $scope, $values + ['created_by' => $actor->userId]);
            } elseif ($old !== null) {
                $this->store->requireReconciled($scope, $poolId);
                $balance = $this->store->balance($scope, $poolId);
                if ($quantity + $purchased < $balance['held']) {
                    throw new ConflictException('Capacity cannot be reduced below seats already held.');
                }
                // Closing a pool that still holds seats stranded every one of
                // them: the assignments stayed assigned, their entitlements
                // stayed active, and `closed` is terminal, so nothing in the
                // free build would ever dispose of them again. The published
                // guards on the edges into closed said exactly this and were
                // implemented nowhere.
                // The count is of OPEN assignments and not of the held
                // balance, because forfeited and expired stay counted against
                // the pool for ever: a guard on the balance would refuse to
                // close any pool that had ever spent a seat, which is every
                // pool anybody would want to close.
                if (
                    $state === SeatPoolState::CLOSED && $wasState !== SeatPoolState::CLOSED
                    && $this->store->database->count(OrgScopedReader::from($scope, 'seat_assignments')
                        ->where('pool_id', '=', $poolId)->whereIn('state', SeatAssignmentState::OPEN)) > 0
                ) {
                    throw new ConflictException('End this pool\'s term before closing it: it still holds seats.');
                }
                if (
                    $balance['held'] > 0 && ($policy !== $old['reclaim_policy'] || $starts !== $old['starts_at']
                    || $ends !== $old['ends_at'])
                ) {
                    throw new ConflictException('A pool with held seats retains its original term and reclaim policy.');
                }
                $this->store->update('seat_pools', $scope, $poolId, $values);
            }
            // The term end is a state a pool reaches and not only a date it
            // passes, so an administrator moving it there runs the expiry
            // disposition over the seats that were still held. That is the
            // free route the SeatExpiryDisposition seam is reached through.
            // The provider is asked before the pool event is written, so the
            // event can record which disposition actually ran.
            $expiring = $state === SeatPoolState::EXPIRED && $wasState !== SeatPoolState::EXPIRED;
            $disposition = $expiring ? $this->dispositionFor($scope, $poolId) : null;
            $this->recordPoolChange(
                $scope,
                $next,
                $poolId,
                $old,
                $wasState,
                $state,
                $quantity + $purchased,
                $funded !== null,
                $disposition === null ? [] : $disposition[2]
            );
            $this->organisations->event($scope, $next, 'seats.pool_updated', null, ['pool_id' => $poolId]);

            $result = ['organisation_revision' => $next, 'item' => $this->store->pool($scope, $poolId),
                'balance' => $this->store->balance($scope, $poolId)];

            if ($disposition !== null) {
                $result['expired_assignments'] = $this->disposeOfHeldSeats($scope, $next, $poolId, $disposition[0]);
                $result['balance'] = $this->store->balance($scope, $poolId);
                $result['item'] = $this->store->pool($scope, $poolId);
            }

            return $result;
        });
    }

    /**
     * The three policy keys a save may name, each validated against its own
     * registry and only when the request carries it.
     *
     * The asymmetry is deliberate and is the same rule the reclaim key
     * already follows: a pool stored under a provider whose package has since
     * been removed must still be renamable, so a fall-back is not validated
     * as though the caller had sent it. Choosing one is validated, because a
     * key nothing resolves is a pool that fails closed at the moment somebody
     * needs a seat.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $old
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function policyKeys(array $input, ?array $old): array
    {
        $families = [
            ['scope_key', $this->scopes, 'course', 'Select an installed seat scope.'],
            ['over_allocation_key', $this->overAllocation, 'refuse_and_queue', 'Select an installed over-allocation policy.'],
            ['expiry_disposition_key', $this->expiry, 'lapse_access_keep_progress', 'Select an installed expiry disposition.'],
        ];
        $keys = [];

        foreach ($families as [$field, $registry, $fallback, $message]) {
            $chosen = \array_key_exists($field, $input);
            $value = $input[$field] ?? $old[$field] ?? $fallback;

            if (!\is_string($value) || $value === '' || (($chosen || $old === null) && !$registry->has($value))) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, $message);
            }

            $keys[] = $value;
        }

        return [$keys[0], $keys[1], $keys[2]];
    }

    /**
     * The two provider configuration bags, and the shape each was written in.
     *
     * They were read by every provider and written by nothing: the save
     * allowlist did not carry either name, so a third-party or Business
     * provider could not be configured through any free route and needed a
     * core edit or direct SQL. The columns and their version columns already
     * existed; this is the writer they were missing.
     *
     * The version is the provider's own answer where it declares one, probed
     * with instanceof through {@see DeclaresParamsShape} rather than by adding
     * a method to four interfaces that have already shipped, and 1 where it
     * does not, because a first shape is 1 and 0 means "written before a
     * version was stored".
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $old
     *
     * @return array<string, mixed>
     */
    private function params(array $input, ?array $old, string $scopeKey, string $overAllocationKey): array
    {
        $values = [];

        foreach (
            [['scope_params', $this->scopes->has($scopeKey) ? $this->scopes->get($scopeKey) : null],
                ['policy_params', $this->overAllocation->has($overAllocationKey)
                    ? $this->overAllocation->get($overAllocationKey) : null]] as [$field, $provider]
        ) {
            if (!\array_key_exists($field, $input)) {
                continue;
            }

            $value = $input[$field];

            if ($value === null) {
                $values[$field] = null;
                $values[$field . '_version'] = 0;

                continue;
            }

            $values[$field] = self::encodedParams($value, $field);
            $values[$field . '_version'] = $provider instanceof DeclaresParamsShape ? max(1, $provider->currentShape()) : 1;
        }

        return $values;
    }

    /**
     * One configuration bag, validated and encoded.
     *
     * An object and never a list, bounded in depth and in stored size. The
     * bounds are the same kind the certificate and late policy payloads carry:
     * a provider's configuration is a handful of settings, and a document
     * nested forty deep or a megabyte long is somebody storing a different
     * thing in the wrong column.
     */
    private static function encodedParams(mixed $value, string $field): string
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'An object or null is required.');
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR, self::MAX_PARAM_DEPTH);
        } catch (\JsonException) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'Expected an object at most ' . self::MAX_PARAM_DEPTH . ' levels deep.');
        }

        if (\strlen($encoded) > self::MAX_PARAM_BYTES) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'Expected at most ' . self::MAX_PARAM_BYTES . ' bytes of configuration.');
        }

        return $encoded;
    }

    /**
     * The purchased quantity, which finance reconciles and a granted quantity
     * may never silently join.
     *
     * Stripe states the legal reason a finalised invoice cannot be edited,
     * and the design makes it a domain invariant rather than an adapter
     * behaviour: once a pool's funding document is finalised the purchased
     * quantity is immutable and a change is a second document plus a second
     * pool event. The refusal lives here so a Business package cannot reach
     * around it through a route the core allows.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $old
     */
    private function purchased(array $input, ?array $old): int
    {
        $purchased = $input['seats_purchased'] ?? $old['seats_purchased'] ?? 0;

        if (!\is_int($purchased) || $purchased < 0 || $purchased > 1000000) {
            throw ValidationException::forField('seats_purchased', 'Expected a whole quantity from 0 to 1000000.');
        }

        if (
            $old !== null && (string) ($old['funding_state'] ?? 'none') === self::FUNDING_FINALISED
            && $purchased !== (int) $old['seats_purchased']
        ) {
            throw new ConflictException('A finalised funding document fixes the purchased quantity; issue a second document.');
        }

        return $purchased;
    }

    /**
     * Seat access terms and their external funding reference.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $old
     *
     * @return array<string, mixed>
     */
    private function terms(array $input, ?array $old): array
    {
        $hold = $input['min_hold_days'] ?? $old['min_hold_days'] ?? 0;
        if (!\is_int($hold) || $hold < 0 || $hold > 3650) {
            throw ValidationException::forField('min_hold_days', 'Expected a whole number of days from 0 to 3650.');
        }

        $days = $input['access_days'] ?? $old['access_days'] ?? null;
        if ($days !== null && (!\is_int($days) || $days < 1 || $days > 3650)) {
            throw ValidationException::forField('access_days', 'Expected a whole number of days from 1 to 3650.');
        }

        $funding = $input['funding_state'] ?? $old['funding_state'] ?? 'none';
        if (!\is_string($funding) || !\in_array($funding, self::FUNDING_STATES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('funding_state', 'Expected one of ' . implode(', ', self::FUNDING_STATES) . '.');
        }

        $ref = $input['funding_ref'] ?? $old['funding_ref'] ?? null;
        if ($ref !== null && (!\is_string($ref) || mb_strlen($ref) > 190)) {
            throw ValidationException::forField('funding_ref', 'Enter a funding reference up to 190 characters.');
        }

        return ['min_hold_days' => $hold, 'access_days' => $days, 'funding_state' => $funding,
            'funding_ref' => $ref];
    }

    /**
     * The ledger rows a pool save writes.
     *
     * The generic `pool_updated` row this replaces was not in the published
     * vocabulary and said nothing: one event key covered a rename, a
     * suspension, a term change and a quantity change alike, so no report
     * could group on it and the reconciliation report groups on exactly this
     * column.
     *
     * A creation writes `pool_created` at zero, and `pool_funded` carrying the
     * quantity only when the pool is funded. `pool_funded` used to be written
     * at creation whatever state the pool was created in, so a draft pool
     * carrying a quantity nobody had paid for already read as funded and the
     * entry into active wrote no ledger row at all: a settlement and an
     * administrator's grant both left no trace, and an abandoned draft carried
     * a funding row for money never taken. The funding event is now written at
     * the moment the pool is funded, which is its creation in `active` or its
     * first entry into `active`, and `seat_pools.funded_at` is the stamp that
     * keeps the two derivations of availability agreeing until then.
     *
     * @param array<string, mixed>|null $old
     * @param array<string, mixed> $entryPayload what the state entry has to say for itself, today the expiry disposition
     */
    private function recordPoolChange(
        OrgScope $scope,
        int $revision,
        int $poolId,
        ?array $old,
        string $wasState,
        string $state,
        int $capacity,
        bool $funded,
        array $entryPayload = [],
    ): void {
        if ($old === null) {
            $this->store->ledger($scope, $revision, $poolId, null, SeatEventType::POOL_CREATED, 0, SeatEventReason::GRANTED_BY_ADMIN);

            if ($funded) {
                $this->store->ledger(
                    $scope,
                    $revision,
                    $poolId,
                    null,
                    SeatEventType::POOL_FUNDED,
                    $capacity,
                    SeatEventReason::GRANTED_BY_ADMIN
                );
            }

            return;
        }

        // The pool is funded by this very save, so the funding row carries the
        // whole capacity and there is no quantity change to record beside it:
        // nothing was funded before it to change.
        if ($funded && $old['funded_at'] === null) {
            $this->store->ledger(
                $scope,
                $revision,
                $poolId,
                null,
                SeatEventType::POOL_FUNDED,
                $capacity,
                SeatEventReason::GRANTED_BY_ADMIN
            );

            return;
        }

        // An unfunded pool's quantity is a number on a form and not a seat
        // anybody has, so changing it writes nothing: the funding row that
        // eventually lands carries whatever the quantity is at that moment.
        if (!$funded) {
            return;
        }

        $before = (int) $old['seats_granted'] + (int) $old['seats_purchased'];

        if ($capacity !== $before) {
            $this->store->ledger(
                $scope,
                $revision,
                $poolId,
                null,
                $capacity > $before ? SeatEventType::POOL_QUANTITY_INCREASED : SeatEventType::POOL_QUANTITY_DECREASED,
                $capacity - $before,
                SeatEventReason::GRANTED_BY_ADMIN
            );
        }

        $entry = SeatPoolState::entryEvent($wasState, $state);

        if ($entry !== null) {
            $this->store->ledger(
                $scope,
                $revision,
                $poolId,
                null,
                $entry,
                0,
                $entry === SeatEventType::POOL_EXPIRED ? SeatEventReason::EXPIRED_WITH_TERM : SeatEventReason::GRANTED_BY_ADMIN,
                null,
                null,
                $entryPayload
            );
        }
    }

    /**
     * Runs the pool's expiry disposition over every seat still held.
     *
     * A reserved seat that nobody claimed is forfeited rather than returned,
     * which is Canvas Catalog's rule that bulk seat sales are final; a held
     * seat expires and its access lapses; an over-allocation row is
     * cancelled, because it never held a seat to lose.
     *
     * The disposition provider is asked once per pool rather than once per
     * seat: it is a property of the pool, and asking per row would let a
     * provider answer two ways inside one term end.
     */
    /**
     * The pool's expiry disposition, the outcome it answered, and what the
     * pool event has to say about the resolution.
     *
     * The published miss direction is a normal path and not a refusal:
     * access lapses and progress is kept, and the substitution is recorded on
     * the pool event rather than being silent, so a pool that behaved like a
     * different pool can be found later.
     *
     * @return array{0: SeatExpiryOutcome, 1: string, 2: array<string, mixed>}
     */
    private function dispositionFor(OrgScope $scope, int $poolId): array
    {
        $pool = $this->store->pool($scope, $poolId);
        [$params, $version] = OrganisationLearningStore::params($pool, 'policy_params');
        $key = (string) ($pool['expiry_disposition_key'] ?? '');
        $resolved = $this->expiry->has($key);
        $outcome = $resolved
            ? $this->expiry->get($key)->disposition(SeatDecisionContext::forExpiry(
                $pool,
                $params,
                $version,
                (int) $pool['course_id'],
                null,
                $this->store->instant()
            ))
            : SeatExpiryOutcome::lapseAccessKeepProgress();
        $applied = $resolved ? $key : LapseAccessKeepProgress::KEY;

        return [$outcome, $applied, ['disposition_key' => $key, 'disposition_resolved' => $resolved,
            'disposition_applied' => $applied, 'closes_membership' => $outcome->closesMembership]];
    }

    private function disposeOfHeldSeats(OrgScope $scope, int $revision, int $poolId, SeatExpiryOutcome $outcome): int
    {
        $held = $this->store->database->selectAll(OrgScopedReader::from($scope, 'seat_assignments')
            ->select(['id', 'state', 'user_id', 'entitlement_id'])
            ->where('pool_id', '=', $poolId)
            ->whereIn('state', SeatAssignmentState::OPEN)
            ->orderBy('id')
            ->limit(self::MAX_SYNCHRONOUS_EXPIRY));

        foreach ($held as $row) {
            $this->lifecycle->expireAtTerm($scope, $revision, (int) $row['id'], $outcome);
        }

        // The pool_expired row is written by the state change itself, above
        // this call, which is the order the published machine draws: the pool
        // event first, then the disposition per held assignment. The
        // substitution a missing provider forced is recorded on that row
        // rather than on a second one, so a term end is one pool event and
        // not two.
        $this->store->update('seat_pools', $scope, $poolId, ['seats_assigned' => $this->store->balance($scope, $poolId)['held']]);

        return \count($held);
    }

    /** @return array<string, mixed> */
    public function assignments(Principal $actor, int $orgId, int $poolId, int $page = 1, int $perPage = 25): array
    {
        $scope = $this->access->require($actor, $orgId);
        $this->store->pool($scope, $poolId);
        OrganisationInput::page($page, $perPage);
        // Left, not inner, since reserve-at-issue: a seat held against an
        // invitation has no membership row to join to, and an inner join
        // would have hidden exactly the committed seats the console exists to
        // show.
        $query = OrgScopedReader::from($scope, 'seat_assignments', 'a')->select(['a.*'])
            ->leftJoin('org_memberships', 'm', 'a.membership_id', 'm.id', [['a.organisation_id', 'm.organisation_id']])
            ->where('a.pool_id', '=', $poolId);
        $scope->constrainUnit($query, 'm.org_unit_id');
        $result = $this->store->page($query, $page, $perPage, 'a.id');
        // A reserved seat has no account behind it yet, so the name lookup
        // asks about the rows that do rather than about user zero.
        $names = $this->users->namesFor(array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['user_id'] ?? 0),
            $result['items']
        ))));
        foreach ($result['items'] as &$row) {
            $row['user_name'] = $row['user_id'] === null ? null : ($names[(int) $row['user_id']] ?? null);
        }

        return $result;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function assign(Principal $actor, int $orgId, int $poolId, array $input): array
    {
        OrganisationInput::only($input, ['expected_revision', 'user_id']);
        $expected = OrganisationInput::integer($input, 'expected_revision');
        $userId = OrganisationInput::integer($input, 'user_id');

        return $this->store->database->transactional(function () use ($actor, $orgId, $poolId, $expected, $userId): array {
            $scope = $this->access->require($actor, $orgId, 'seats');
            $next = $this->organisations->claim($scope, $expected);
            $pool = $this->store->pool($scope, $poolId);
            $this->composed->requireRelease($scope, (int) $pool['course_id']);
            if (
                !SeatPoolState::spendable((string) $pool['state'])
                || ($pool['starts_at'] !== null && $pool['starts_at'] > $this->store->now())
                || ($pool['ends_at'] !== null && $pool['ends_at'] <= $this->store->now())
            ) {
                throw new ConflictException('This pool is not currently available for assignment.');
            }
            $memberId = $this->organisations->activeMemberId($scope, $userId);
            if ($memberId === null) {
                throw new ConflictException('Only active members can receive seats.');
            }
            // What one seat buys, asked of the pool's registered scope rather
            // than assumed. The refusal is the published miss direction: a
            // pool whose scope provider is not installed writes no assignment
            // and names the provider, because assuming one seat per course is
            // exactly how a catalogue pool goes silently over its count.
            $this->requireScopeCovers($pool, (int) $pool['course_id']);
            $this->store->requireReconciled($scope, $poolId);
            $current = $this->store->currentAssignment($scope, $poolId, $userId);
            if ($current !== null && $current['state'] !== SeatAssignmentState::PENDING) {
                throw new ConflictException('This member already holds a seat in this pool.');
            }
            // The reservation an outstanding invitation to this person's own
            // address is already holding. currentAssignment matches on
            // user_id, and a reservation taken for somebody with no account
            // yet carries a null one, so this branch is invisible to it: the
            // person signed up, an administrator added them, and the pool paid
            // twice for one person. The acceptance then lost the unique index
            // on holder_key and answered a 500, the invitation stayed pending,
            // and the second seat was never returned.
            $this->requireNoOutstandingReservation($scope, $poolId, $userId);
            // The over-allocation stance is asked only when the pool is short,
            // so the common path takes no registry lookup that could only
            // answer one way, and the free provider's answer is Entra's:
            // refuse, and keep the person as a durable row.
            $balance = $this->store->balance($scope, $poolId);
            $outcome = $balance['available'] < $this->arityFor($pool, (int) $pool['course_id'])
                ? $this->overAllocationFor($scope, $pool, $balance, $userId)
                : SeatOverAllocationOutcome::seat();
            // An expand_pool stance was inert: expandBy was read by nothing,
            // no quantity event was written, and the stance degraded silently
            // into allow_and_flag with the pool one seat over its funded
            // count. The rise happens here, under the same compare-and-set
            // revision, and before the seat is spent, so the seat_assigned row
            // below is charged against a pool that already has the room.
            if ($outcome->decision === SeatOverAllocationOutcome::EXPAND_POOL && $outcome->expandBy > 0) {
                $pool = $this->expandPool($scope, $next, $pool, $poolId, $outcome->expandBy);
            }
            $pending = !$outcome->seats();
            $values = ['pool_id' => $poolId, 'membership_id' => $memberId, 'user_id' => $userId,
                'state' => $pending ? SeatAssignmentState::PENDING : SeatAssignmentState::ASSIGNED,
                'holder_key' => $orgId . ':' . $poolId . ':' . $userId,
                'assigned_by' => $actor->userId, 'assigned_at' => $this->store->now(),
                'problem_code' => $outcome->problemCode];
            if ($current === null) {
                $id = $this->store->insert('seat_assignments', $scope, $values);
            } else {
                $id = (int) $current['id'];
                if (($current['assignment_ref'] ?? null) === null) {
                    // A row written before the column existed, promoted from
                    // pending. It gets its reference now rather than never,
                    // so the ref is a property of every live assignment and
                    // not only of the ones written after this wave.
                    $values['assignment_ref'] = $this->store->mintAssignmentRef();
                }
                $this->store->update('seat_assignments', $scope, $id, $values);
            }
            $entitlementId = null;
            if (!$pending) {
                $context = OrgEntitlementGrantContext::authorise(
                    $this->composed,
                    $this->organisations,
                    $actor,
                    $orgId,
                    $userId,
                    (int) $pool['course_id'],
                    $next,
                    $id,
                    $pool['ends_at']
                );
                [$grant, $events] = $this->grant->executeForOrganisation($context);
                $entitlementId = $grant->id();
                $this->store->update('seat_assignments', $scope, $id, ['entitlement_id' => $entitlementId]);
                $this->events->stage($events);
            }
            $this->store->ledger(
                $scope,
                $next,
                $poolId,
                $id,
                $pending ? SeatEventType::OVERALLOCATION_RECORDED : SeatEventType::SEAT_ASSIGNED,
                // Minus one, because this path takes the seat here. The other
                // half of the published rule, a zero delta for an assignment
                // that a reservation already paid for, belongs to the
                // reservation's own conversion: a reserved row holds its
                // invitation in holder_key, so the lookup above cannot find
                // one and this branch never sees it.
                $pending ? 0 : -1,
                SeatEventReason::GRANTED_BY_ADMIN,
                $userId,
                $entitlementId
            );
            $this->organisations->event(
                $scope,
                $next,
                $pending ? 'seats.pending' : 'seats.assigned',
                $userId,
                ['pool_id' => $poolId, 'assignment_id' => $id]
            );
            $after = $this->store->balance($scope, $poolId);
            // Raised here rather than left to the nightly reconciliation, on
            // the same terms as the reservation path: the administrator who
            // just spent the last seat is the person who can do something
            // about it and they are still at their desk. Both callers share
            // the occurrence key, so a pool exhausted by an assignment and
            // then inspected by the sweep in the same period is one mail.
            $this->alerts?->afterBalanceChange($scope, $pool, $after);

            return ['organisation_revision' => $next, 'outcome' => $pending ? 'pending_capacity' : 'assigned',
                'item' => $this->store->assignment($scope, $id), 'balance' => $after];
        });
    }

    /**
     * Raises a pool's granted quantity under an expand_pool stance.
     *
     * The quantity event carries the actor, which is the published obligation
     * on {@see SeatOverAllocationOutcome::EXPAND_POOL}: a pool that grew
     * because a provider said so has to be distinguishable from one an
     * administrator bought more seats for, and the reason and the actor on the
     * ledger row are what distinguish them.
     *
     * @param array<string, mixed> $pool
     *
     * @return array<string, mixed>
     */
    private function expandPool(OrgScope $scope, int $revision, array $pool, int $poolId, int $by): array
    {
        $this->store->update('seat_pools', $scope, $poolId, [
            'seats_granted' => (int) $pool['seats_granted'] + $by,
            // A pool expanded to seat somebody is funded by that fact, so a
            // stance that expands an unfunded pool funds it rather than
            // leaving a capacity the ledger cannot account for.
            'funded_at' => $pool['funded_at'] ?? $this->store->now(),
        ]);
        $this->store->ledger(
            $scope,
            $revision,
            $poolId,
            null,
            $pool['funded_at'] === null ? SeatEventType::POOL_FUNDED : SeatEventType::POOL_QUANTITY_INCREASED,
            $pool['funded_at'] === null ? (int) $pool['seats_granted'] + (int) $pool['seats_purchased'] + $by : $by,
            SeatEventReason::GRANTED_BY_ADMIN
        );

        return $this->store->pool($scope, $poolId);
    }

    /**
     * Refuses a direct assignment to somebody a reservation in this pool is
     * already holding a seat for.
     *
     * The reservation is found through the invitation that took it, because
     * the invited address is the only thing that connects a reservation with
     * no account to the account that later signs up: `seat_assignments` has no
     * address of its own and must not grow one, since a seat table holding
     * personal data is a second place a subject-access request has to reach.
     *
     * The refusal names the invitation, so the administrator can revoke it and
     * assign, or leave it and let the acceptance convert it. Both end with one
     * seat spent on one person, which the double write did not.
     */
    private function requireNoOutstandingReservation(OrgScope $scope, int $poolId, int $userId): void
    {
        $this->store->requireNoOutstandingReservation($scope, $poolId, $this->users->findById($userId)?->email);
    }

    /**
     * The seat scope's two answers, asked of the pool's registered provider.
     *
     * @param array<string, mixed> $pool
     */
    private function scopeContext(array $pool, int $courseId): SeatDecisionContext
    {
        [$params, $version] = OrganisationLearningStore::params($pool, 'scope_params');

        return SeatDecisionContext::forScope($pool, $params, $version, $courseId, $this->store->instant());
    }

    /**
     * Refuses an assignment the pool's scope does not cover, and refuses one
     * whose scope provider is not installed.
     *
     * That is the published miss direction and it is fail closed at the pool:
     * no assignment is written and the message names the key, because a build
     * that silently assumed one seat per course would let a catalogue pool go
     * over its purchased count without anybody seeing it.
     *
     * @param array<string, mixed> $pool
     */
    private function requireScopeCovers(array $pool, int $courseId): void
    {
        $key = (string) ($pool['scope_key'] ?? '');

        if (!$this->scopes->has($key)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('This pool needs the "%s" seat scope, which is not installed.', $key));
        }

        if (!$this->scopes->get($key)->covers($this->scopeContext($pool, $courseId))) {
            throw new ConflictException('This pool\'s seat scope does not cover that course.');
        }
    }

    /**
     * How many seats one assignment of this pool spends.
     *
     * A provider answering less than one is refused rather than trusted: a
     * zero-arity seat is a free seat, and a pool that hands them out has
     * stopped being a licence.
     *
     * @param array<string, mixed> $pool
     */
    private function arityFor(array $pool, int $courseId): int
    {
        $key = (string) ($pool['scope_key'] ?? '');

        if (!$this->scopes->has($key)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('This pool needs the "%s" seat scope, which is not installed.', $key));
        }

        $arity = $this->scopes->get($key)->arity($this->scopeContext($pool, $courseId));

        if ($arity < 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('The "%s" seat scope answered an arity below one.', $key));
        }

        return $arity;
    }

    /**
     * What the pool does about somebody it cannot seat.
     *
     * The miss is fail closed and is the same answer the free provider gives,
     * which is why an uninstalled stance costs a durable row rather than a
     * discarded person: refusing to record them would lose exactly the twenty
     * people this whole mechanism exists to keep visible.
     *
     * @param array<string, mixed> $pool
     * @param array{capacity: int, held: int, available: int, ledger_balance: int, reconciled: bool} $balance
     */
    private function overAllocationFor(OrgScope $scope, array $pool, array $balance, int $userId): SeatOverAllocationOutcome
    {
        $key = (string) ($pool['over_allocation_key'] ?? '');

        if (!$this->overAllocation->has($key)) {
            return SeatOverAllocationOutcome::queue();
        }

        [$params, $version] = OrganisationLearningStore::params($pool, 'policy_params');

        return $this->overAllocation->get($key)->decide(SeatDecisionContext::forOverAllocation(
            $pool,
            $params,
            $version,
            (int) $pool['course_id'],
            $balance['available'],
            $balance['held'],
            $userId,
            $this->store->instant(),
        ));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function release(Principal $actor, int $orgId, int $assignmentId, int $expected, array $input = []): array
    {
        // Validated before the transaction opens, so a misspelt disposition
        // is a bad request rather than a rolled back revocation.
        $disposition = AttemptDisposition::of($input['attempt_disposition'] ?? null);
        $reason = $this->reasonKey($input);

        return $this->store->database->transactional(function () use (
            $actor,
            $orgId,
            $assignmentId,
            $expected,
            $disposition,
            $reason
        ): array {
            $scope = $this->access->require($actor, $orgId, 'seats');
            $next = $this->organisations->claim($scope, $expected);
            $this->lifecycle->release($scope, $next, $assignmentId, $reason, false, $disposition);
            $row = $this->store->assignment($scope, $assignmentId);
            $this->organisations->event($scope, $next, 'seats.released', $row['user_id'] === null ? null : (int) $row['user_id'], [
                'assignment_id' => $assignmentId, 'attempt_disposition' => $disposition, 'reason_key' => $reason,
            ]);
            $poolId = (int) $row['pool_id'];
            $balance = $this->store->balance($scope, $poolId);
            // A release can take a pool from exhausted back to having room,
            // and it can also leave it exhausted, which is the case an
            // administrator needs to hear about: a reclaim that did not credit
            // anything back under a `never` policy changes nothing about how
            // full the pool is and the alert says so.
            $this->alerts?->afterBalanceChange($scope, $this->store->pool($scope, $poolId), $balance);

            return ['organisation_revision' => $next, 'item' => $row, 'attempt_disposition' => $disposition,
                'reason_key' => $reason, 'balance' => $balance];
        });
    }

    /**
     * The reason an administrator gave for taking a seat back, validated
     * against the registry.
     *
     * The reason key was a literal at the one call site and no route input
     * named it, so a registered third-party reason could be resolved on the
     * ledger read and written by nothing: the registry was label-only in
     * practice. A miss is fail-closed and names the field, because a reason
     * nothing resolves is a ledger row no report can group.
     *
     * @param array<string, mixed> $input
     */
    private function reasonKey(array $input): string
    {
        if (!\array_key_exists('reason_key', $input) || $input['reason_key'] === null) {
            return SeatEventReason::RECLAIMED_BY_ADMIN;
        }

        $key = $input['reason_key'];

        if (!\is_string($key) || !$this->reasons->has($key)) {
            throw ValidationException::forField('reason_key', 'Select an installed seat event reason.');
        }

        return $key;
    }

    /** @return array<string, mixed> */
    public function ledger(Principal $actor, int $orgId, int $poolId, int $page = 1, int $perPage = 25): array
    {
        // seat_events carries a pool and no unit, so the same refusal as the
        // reconciliation above: the ledger is the organisation's and a subtree
        // manager has no narrowed view of it to be given.
        $scope = $this->access->requireOrganisationWide($actor, $orgId, 'events');
        $this->store->pool($scope, $poolId);
        OrganisationInput::page($page, $perPage);
        $result = $this->store->page(OrgScopedReader::from($scope, 'seat_events')
            ->where('pool_id', '=', $poolId), $page, $perPage);

        // The reason registry is asked here and nowhere on the write path.
        // A row whose reason no installed provider resolves keeps its key and
        // is rendered unlabelled, which is the published miss direction: a
        // reason is evidence, and an unknown label is a smaller loss than a
        // discarded reason. It is also what makes the older rows readable,
        // because a ledger written before the vocabulary existed still says
        // why in whatever words its writer used.
        //
        // The labels travel beside the rows rather than on them, and that is
        // deliberate: a ledger row's hash is computed over the row, so a
        // reader verifying the chain from this response has to receive the
        // row and nothing else. A per-row label would have made every
        // projection unverifiable.
        $labels = [];

        foreach ($result['items'] as $row) {
            $key = (string) ($row['reason_key'] ?? '');

            if ($key !== '' && !\array_key_exists($key, $labels)) {
                $labels[$key] = $this->reasons->has($key) ? $this->reasons->get($key)->label() : null;
            }
        }

        return $result + ['balance' => $this->store->balance($scope, $poolId), 'reason_labels' => $labels];
    }
}
