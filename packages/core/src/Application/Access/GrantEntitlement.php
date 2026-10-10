<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgEntitlementGrantContext;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningLock;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\EntitlementGranted;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\Commerce\GrantsEntitlements;

/**
 * Grants an entitlement to a beneficiary and enrols them through the shared
 * EnrolLearner path. Manual and import grants come from staff; purchase,
 * subscription, coupon and seat grants are shop callbacks executed under a
 * system principal. Every path requires Capability::ENROL_OTHERS.
 *
 * Idempotent per (source, source_ref): a replayed callback returns the
 * existing entitlement and emits nothing. A grant that carries no
 * source_ref (manual and import, the two staff paths) is idempotent per
 * (user, course, source) instead, so enrolling the same learner twice
 * cannot leave a second entitlement that no screen can reach and that a
 * revoke of the visible one would not remove. Changing an existing grant's
 * window is ExtendEntitlement; removing it is RevokeEntitlement.
 *
 * **The idempotency is the database's and no longer a read-then-write.** The
 * lookup above is an optimisation; the guarantee is the unique index on
 * entitlements.source_ref_digest, whose value is written inside the insert by
 * the repository and derived by one helper. Two concurrent deliveries of one
 * verified webhook, or a webhook racing the return-URL fulfilment a gateway
 * recommends running concurrently, both miss the read and both insert: the
 * index refuses the second, the violation is caught, the row is re-read, and
 * **the re-read is verified before it is returned**. If its user or course
 * does not match the request that is a conflict and it is raised, never
 * returned, because returning it is how a collision becomes a silent
 * cross-account grant.
 *
 * **The reference grammar is one vocabulary with two families in it**, and it
 * lives in {@see \Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef}
 * rather than being described here and composed elsewhere. The commerce
 * family is `{commerceSourceKey}-{objectType}-{id}[-item-{n}][-user-{userId}]-course-{courseId}[-r{n}]`,
 * whose first segment is a registered commerce source key so two bridges
 * cannot mint the same reference; the organisation family opens with the
 * reserved `org{organisationId}` prefix. Four rules hold across both: the
 * first segment has an owner, a reference always names the course, a
 * reference names the beneficiary wherever the object does not imply one, and
 * a membership composes one reference per course and never a shared one. The
 * trailing `-r{n}` is the reversal ordinal a regrant after a revocation
 * appends, because ExtendEntitlement refuses a revoked row and a reinstatement
 * must be a distinct identity rather than a collision with it.
 */
final class GrantEntitlement
{
    /**
     * The two paths that are nobody's commerce: a person with the capability
     * decided, or a file said so. They are named here because they need no
     * provider, not because the vocabulary is closed.
     */
    private const STAFF_SOURCES = [
        Entitlement::SOURCE_MANUAL,
        Entitlement::SOURCE_IMPORT,
    ];

    /**
     * The two words another path confers, whatever a provider declares.
     * `open` is the enrolment rule's answer and `org` is written only by
     * executeForOrganisation, which carries a witness this entry point has
     * no way to check.
     */
    private const CONFERRED_ELSEWHERE = [
        Entitlement::SOURCE_OPEN,
        Entitlement::SOURCE_ORG,
    ];

    public function __construct(
        private readonly CourseAccess $access,
        private readonly UsersPort $users,
        private readonly DatabasePort $database,
        private readonly EntitlementRepository $entitlements,
        private readonly EnrolLearner $enrolLearner,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        /**
         * Who may grant which source, resolved rather than listed. Optional
         * so a harness that predates the registry still builds this class;
         * absent, no commerce word is grantable, which is the fail-closed
         * direction for a build that has registered no commerce source at
         * all.
         */
        private readonly ?CommerceSourceRegistry $commerceSources = null,
    ) {
    }

    public function execute(
        Principal $actor,
        int $userId,
        int $courseId,
        string $source,
        ?string $sourceRef = null,
        ?string $startsAt = null,
        ?string $expiresAt = null,
        /**
         * Whether the grant also enrols the learner.
         *
         * Additive and trailing, defaulted to the shipped behaviour. It
         * exists because this method ended with an unconditional enrolment
         * and hasStartedBy() is true whenever starts_at is null, so there was
         * no way to grant without enrolling and the consumer withdrawal hold
         * could not be expressed at all. A future-dated start is not a
         * substitute: the expiry sweep's activation pass enrols the learner
         * the moment it arrives, waiver or no waiver. ActivateEntitlement is
         * what the waiver capture calls afterwards.
         *
         * **It is durable only with no start date, and the other combination
         * is refused rather than documented.** Nothing stores this flag, so a
         * held grant carrying a starts_at is enrolled by that same activation
         * pass when the date arrives; false beside a starts_at is therefore a
         * ValidationException rather than a hold that quietly lifts itself.
         */
        bool $activate = true,
    ): Entitlement {
        $this->access->require($actor, $courseId, Capability::ENROL_OTHERS);
        $this->requireGrantable($source);

        if (!$this->users->exists($userId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('User', $userId);
        }

        $course = $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId));

        if ($course === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $courseId);
        }

        if ($course['state'] === 'archived') {
            throw new ConflictException('An archived course cannot receive new entitlements.');
        }

        $existing = $sourceRef !== null
            ? $this->entitlements->findBySourceRef($source, $sourceRef)
            : $this->activeGrantWithoutRef($userId, $courseId, $source);

        if ($existing !== null) {
            // The enrolment is repaired rather than assumed: a replay of the
            // staff button must leave the learner on the roster even if the
            // row went missing between the two clicks. EnrolLearner emits
            // nothing when the enrolment is already active.
            if ($sourceRef === null && $existing->hasStartedBy($this->clock->now())) {
                $this->enrolLearner->execute($userId, $courseId, $existing->id());
            }

            return $existing;
        }

        $startsAtParsed = $this->parseDatetime('starts_at', $startsAt);
        $expiresAtParsed = $this->parseDatetime('expires_at', $expiresAt);

        if ($startsAtParsed !== null && $expiresAtParsed !== null && $startsAtParsed >= $expiresAtParsed) {
            throw ValidationException::forField('starts_at', 'Must be before expires_at.');
        }

        // A hold and a scheduled start cannot be asked for together, and the
        // refusal is here rather than in a comment because the combination
        // silently undid itself. $activate is a flag on this call and nothing
        // stores it, so a held grant carrying a starts_at is enrolled by
        // ExpireEntitlements' activation pass the moment that date arrives:
        // it selects status active with a starts_at that has passed and
        // enrols unconditionally, waiver or no waiver, which is the exact
        // failure the flag was added to close. Refusing says so at the one
        // moment somebody can do something about it. A hold with no start date
        // is durable and is what a withdrawal waiver actually needs; the
        // waiver capture then calls ActivateEntitlement.
        if (!$activate && $startsAtParsed !== null) {
            throw ValidationException::forField(
                'starts_at',
                'A held grant cannot also be scheduled: the activation sweep would enrol it when the date arrived. '
                . 'Grant it held with no start date and activate it when the hold is lifted.',
            );
        }

        try {
            $entitlement = $this->entitlements->add(Entitlement::grant(
                $userId,
                $courseId,
                $source,
                $sourceRef,
                $actor->userId,
                $startsAtParsed,
                $expiresAtParsed,
            ));
        } catch (UniqueConstraintViolation) {
            // The read above lost a race and the index refused the second
            // insert, which is exactly what the index exists for: two
            // concurrent deliveries of one verified webhook, or a webhook
            // racing the return-URL fulfilment a gateway recommends running
            // concurrently, both miss the read and both try to insert. Only
            // this type is caught: catching Throwable here would swallow a
            // connection failure or a column-length failure and answer a paid
            // order with a grant that never happened.
            return $this->reReadAfterCollision($userId, $courseId, $source, $sourceRef);
        }

        $now = $this->clock->now();

        $this->events->dispatch(new EntitlementGranted(
            $now,
            $entitlement->id(),
            $userId,
            $courseId,
            $source,
        ));

        // A future-dated grant is provisioning, not access: the scheduler
        // sweep enrols the learner once starts_at arrives. A grant the caller
        // asked not to activate is the same shape held for a different
        // reason, and the caller activates it later.
        if ($activate && $entitlement->hasStartedBy($now)) {
            $this->enrolLearner->execute($userId, $courseId, $entitlement->id());
        }

        return $entitlement;
    }

    /**
     * The row the unique index pointed at, verified before it is returned.
     *
     * **The verification is the point.** Returning whatever the re-read found
     * is what turns a collision into a silent cross-account grant for every
     * reference shape that does not name its beneficiary: a gift code pasted
     * into a group chat produces two redemptions inside one second, user A's
     * grant inserts, user B's violates the index, is caught, is re-read, and
     * B is handed A's entitlement row. B is told the redemption succeeded, B
     * has no access, A does, and nothing on any screen shows a conflict. So a
     * mismatch is raised and never returned, and the rule lives here rather
     * than in every future reference shape remembering it.
     */
    private function reReadAfterCollision(int $userId, int $courseId, string $source, ?string $sourceRef): Entitlement
    {
        $digest = SourceRef::digest($source, $sourceRef);

        // Re-read on the digest, because the digest is what the index refused
        // on. Looking the row up by (source, source_ref) would be asking a
        // different question from the one the database answered.
        $existing = $digest === null
            ? $this->activeGrantWithoutRef($userId, $courseId, $source)
            : $this->entitlements->findBySourceRefDigest($digest);

        if ($existing === null) {
            // The index refused a row nothing can be found for, which means
            // the collision was on some other unique key. Saying so is the
            // only honest answer: a re-read that finds nothing must not
            // report a grant.
            throw new ConflictException('A concurrent write refused this grant and no matching entitlement was found.');
        }

        if ($existing->userId !== $userId || $existing->courseId !== $courseId) {
            throw new ConflictException('This source reference already belongs to another learner or course.');
        }

        return $existing;
    }

    /**
     * Whether this source may be granted here at all.
     *
     * **The vocabulary is the registry's and no longer a constant.** A
     * commerce source declares the words its own callbacks arrive under
     * through GrantsEntitlements, so a package registering its own checkout
     * grants without a core edit, which is the acceptance claim the whole
     * commerce design is bought for. Two words are still refused here
     * whatever a provider declares: `open` is what the enrolment rule
     * confers, and `org` is written only by executeForOrganisation, which
     * carries a witness this entry point has no way to check. Two more are
     * always allowed because they are nobody's commerce: a person with the
     * capability decided, or a file said so.
     *
     * A build with no commerce source registered grants only those two staff
     * paths, which is the direction that refuses rather than the one that
     * admits.
     *
     * Storable and grantable stay two different questions. Entitlement::SOURCES
     * is what the column holds and what every report groups by, so widening it
     * remains a core decision with a migration behind it; which of those words
     * this build may grant under is what the registry answers.
     */
    private function requireGrantable(string $source): void
    {
        if (\in_array($source, self::STAFF_SOURCES, true)) {
            return;
        }

        if (\in_array($source, self::CONFERRED_ELSEWHERE, true)) {
            throw ValidationException::forField(
                'source',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('"%s" is not a grantable entitlement source.', $source),
            );
        }

        foreach ($this->commerceSources?->all() ?? [] as $provider) {
            if (
                $provider instanceof GrantsEntitlements
                && \in_array($source, $provider->grantableEntitlementSources(), true)
            ) {
                return;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw ValidationException::forField('source', sprintf('"%s" is not a grantable entitlement source.', $source));
    }

    /**
     * The replay lookup of a grant that carries no source_ref. Only an
     * active entitlement counts: an expired or revoked one has stopped
     * conferring access, so a fresh grant must create a new row rather than
     * hand back a dead one.
     */
    private function activeGrantWithoutRef(int $userId, int $courseId, string $source): ?Entitlement
    {
        foreach ($this->entitlements->activeForUserAndCourse($userId, $courseId) as $entitlement) {
            if ($entitlement->source === $source && $entitlement->sourceRef === null) {
                return $entitlement;
            }
        }

        return null;
    }

    private function parseDatetime(string $field, ?string $value): ?\DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== $value) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'Expected a UTC datetime in "Y-m-d H:i:s" format.');
        }

        return $parsed;
    }

    /**
     * Callers have already authorised through OrganisationAccess and the
     * course release policy. The required context binds the organisation,
     * active member, course and immutable source reference. Runs within the
     * caller's aggregate transaction; events are returned, never dispatched
     * before the seat assignment/root CAS and outbox have committed.
     *
     * @return array{Entitlement, list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>}
     */
    public function executeForOrganisation(OrgEntitlementGrantContext $context): array
    {
        OrganisationLearningLock::claim($this->database, $context->userId, $context->courseId, $this->clock->now()->format('Y-m-d H:i:s'));
        $existing = $this->entitlements->findBySourceRef($context->source, $context->sourceRef);
        if ($existing !== null) {
            $row = $this->database->selectOne(Query::from('entitlements')->where('id', '=', $existing->id())
                ->where('organisation_id', '=', $context->scope->organisationId));
            if ($row === null || $existing->userId !== $context->userId || $existing->courseId !== $context->courseId) {
                throw new ConflictException('This source reference belongs to another entitlement.');
            }

            return [$existing, []];
        }
        if (!$this->users->exists($context->userId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('User', $context->userId);
        }
        $expires = $this->parseDatetime('expires_at', $context->expiresAt);
        $now = $this->clock->now();
        if ($expires !== null && $expires <= $now) {
            throw ValidationException::forField('expires_at', 'The access window has already ended.');
        }
        $entitlement = $this->entitlements->add(Entitlement::grant(
            $context->userId,
            $context->courseId,
            $context->source,
            $context->sourceRef,
            $context->scope->actorId,
            $now,
            $expires,
        ));
        // The digest is written by the repository inside the insert now, so
        // this statement carries only the two organisation anchors. It stays
        // a second write because the anchors are not on the entity, and the
        // grant race on this path is closed by OrganisationLearningLock above
        // rather than by the index.
        $this->database->update('entitlements', [
            'organisation_id' => $context->scope->organisationId,
            'org_membership_id' => $context->membershipId,
        ], ['id' => $entitlement->id()]);
        $entitlement = $this->entitlements->find($entitlement->id())
            ?? throw new \LogicException('The organisation entitlement write was not readable.');
        [$enrolment, $events] = $this->enrolLearner->executeDeferred($context->userId, $context->courseId, $entitlement->id());
        array_unshift($events, new EntitlementGranted(
            $now,
            $entitlement->id(),
            $context->userId,
            $context->courseId,
            $context->source
        ));

        return [$entitlement, $events];
    }
}
