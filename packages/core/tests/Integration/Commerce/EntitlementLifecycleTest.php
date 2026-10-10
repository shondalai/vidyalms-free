<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Commerce;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Access\ActivateEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\HoldEntitlement;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlementBySourceRef;
use Shondalai\VidyaLMS\Core\Application\Commerce\BackgroundActorResolver;
use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Access\AccessFixture;

/**
 * The entitlement seams a refund, a dispute, a webhook and a waiver need,
 * driven end to end against the SQLite harness.
 *
 * Every one of them closes a hole the shipped spine had: the digest that made
 * the unique index protect nothing on the money path, the reason and the
 * external key that had no writer at all, the hold that failed open, and the
 * background actor a webhook did not have.
 */
final class EntitlementLifecycleTest extends TestCase
{
    private const MANAGER = 4001;
    private const LEARNER = 4002;
    private const OTHER_LEARNER = 4003;

    private AccessFixture $fixture;

    private int $courseId;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
        $this->fixture->createUser(self::MANAGER);
        $this->fixture->createUser(self::LEARNER);
        $this->fixture->createUser(self::OTHER_LEARNER);
        $this->fixture->grantCapability(self::MANAGER);
        $this->courseId = $this->fixture->createCourse(['price_mode' => 'paid']);
    }

    public function testTheDigestIsWrittenOnTheMoneyPathAndNotOnlyOnTheOrganisationOne(): void
    {
        $ref = SourceRef::compose('wc', 'order', 9001, $this->courseId);

        $entitlement = $this->grant($ref, Entitlement::SOURCE_PURCHASE);
        $row = $this->fixture->entitlementRow($entitlement->id());

        self::assertNotNull($row);
        self::assertSame(
            SourceRef::digest(Entitlement::SOURCE_PURCHASE, $ref),
            $row['source_ref_digest'],
            'the money path landed with a null digest, so the unique index protected nothing there',
        );
    }

    public function testAConcurrentGrantIsRefusedByTheIndexAndReReadRatherThanDuplicated(): void
    {
        $ref = SourceRef::compose('wc', 'order', 9002, $this->courseId);
        $first = $this->grant($ref, Entitlement::SOURCE_PURCHASE);

        // The read-then-write the shipped code relied on is bypassed exactly
        // the way a concurrent delivery bypasses it: the row is hidden from
        // the lookup by blanking source_ref, while the digest the unique index
        // is actually on stays. The second insert then has to be refused by
        // the database rather than by the read.
        $this->fixture->env->database->update('entitlements', ['source_ref' => null], ['id' => $first->id()]);

        $second = $this->grant($ref, Entitlement::SOURCE_PURCHASE);

        self::assertSame($first->id(), $second->id(), 'the collision produced a second row instead of a re-read');
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('entitlements')
                ->where('source_ref_digest', '=', SourceRef::digest(Entitlement::SOURCE_PURCHASE, $ref)),
        ));
    }

    public function testACollisionOnAnotherLearnersReferenceIsRaisedAndNeverReturned(): void
    {
        $ref = SourceRef::compose('native', 'gift', 77, $this->courseId);
        $first = $this->grant($ref, Entitlement::SOURCE_COUPON);

        // A second redeemer of one gift code, one second later. Returning the
        // re-read row would tell this person the redemption succeeded while
        // the first redeemer holds the access.
        $this->fixture->env->database->update('entitlements', ['source_ref' => null], ['id' => $first->id()]);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('already belongs to another learner or course');

        $this->fixture->grantEntitlement->execute(
            Principal::user(self::MANAGER),
            self::OTHER_LEARNER,
            $this->courseId,
            Entitlement::SOURCE_COUPON,
            $ref,
        );
    }

    public function testAGrantCanBeTakenWithoutEnrollingAndActivatedAfterwards(): void
    {
        $entitlement = $this->fixture->grantEntitlement->execute(
            Principal::user(self::MANAGER),
            self::LEARNER,
            $this->courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            null,
            false,
        );

        self::assertNull(
            $this->fixture->enrolmentRow(self::LEARNER, $this->courseId),
            'the hold did not hold: a grant told not to activate enrolled the learner anyway',
        );

        $activate = new ActivateEntitlement(
            $this->fixture->courseAccess,
            $this->fixture->entitlements,
            $this->fixture->enrolLearner,
            $this->fixture->env->clock,
        );
        $activate->execute(Principal::user(self::MANAGER), $entitlement->id());

        $enrolment = $this->fixture->enrolments->findByUserAndCourse(self::LEARNER, $this->courseId);
        self::assertNotNull($enrolment);
        self::assertTrue($enrolment->isActive());
    }

    /**
     * A hold that quietly lifts itself is worse than no hold.
     *
     * Nothing stores $activate, so a held grant carrying a starts_at was
     * enrolled by ExpireEntitlements' activation pass the moment the date
     * arrived: the pass selects an active row whose starts_at has passed and
     * enrols unconditionally, waiver or no waiver, which is the exact failure
     * the flag exists to close. The combination is refused at the one moment
     * somebody can do something about it.
     */
    public function testAHeldGrantCannotAlsoBeScheduled(): void
    {
        try {
            $this->fixture->grantEntitlement->execute(
                Principal::user(self::MANAGER),
                self::LEARNER,
                $this->courseId,
                Entitlement::SOURCE_MANUAL,
                null,
                $this->fixture->env->clock->now()->modify('+30 days')->format('Y-m-d H:i:s'),
                null,
                false,
            );

            self::fail('a held grant was accepted with a start date the sweep would act on');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException $refusal) {
            self::assertArrayHasKey('starts_at', $refusal->errors);
            self::assertStringContainsString('activation sweep would enrol it', $refusal->errors['starts_at']);
        }

        self::assertSame(
            0,
            $this->fixture->env->database->count(
                Query::from('entitlements')->where('user_id', '=', self::LEARNER),
            ),
            'a refused grant wrote a row',
        );
    }

    public function testAHoldWithNoStartDateIsDurableAcrossTheSweep(): void
    {
        $entitlement = $this->fixture->grantEntitlement->execute(
            Principal::user(self::MANAGER),
            self::LEARNER,
            $this->courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            null,
            null,
            false,
        );

        self::assertNull($this->fixture->enrolmentRow(self::LEARNER, $this->courseId));

        // The activation pass only reaches a row with a starts_at, which is
        // why a hold with none survives it.
        self::assertNull(
            $this->fixture->env->database->selectOne(
                Query::from('entitlements')->where('id', '=', $entitlement->id())->whereNotNull('starts_at'),
            ),
        );
    }

    public function testAHoldSuspendsTheEnrolmentAndRecordsWhyAndAgainstWhat(): void
    {
        $entitlement = $this->grant(SourceRef::compose('wc', 'order', 9003, $this->courseId), Entitlement::SOURCE_PURCHASE);

        $held = $this->hold()->execute(Principal::user(self::MANAGER), $entitlement->id(), 'dispute_open', 'dp_9003');

        self::assertSame(Entitlement::STATUS_EXPIRED, $held->status);
        self::assertSame('dispute_open', $held->revokedReason);
        self::assertSame('dp_9003', $held->revokedRef);

        // The assertion that would have failed against ExtendEntitlement
        // alone: flipping the entitlement and leaving the enrolment leaves the
        // content fully available, which is what made a dispute hold fail open.
        $enrolment = $this->fixture->enrolments->findByUserAndCourse(self::LEARNER, $this->courseId);
        self::assertNotNull($enrolment);
        self::assertSame(Enrolment::STATUS_SUSPENDED, $enrolment->status);
        self::assertNotSame([], $this->fixture->eventsOfType(EnrolmentSuspended::class));

        $row = $this->fixture->entitlementRow($entitlement->id());
        self::assertNotNull($row);
        self::assertSame('dispute_open', $row['revoked_reason']);
        self::assertSame('dp_9003', $row['revoked_ref']);
    }

    public function testAHoldIsIdempotentSoARedeliveryProducesOneSuspension(): void
    {
        $entitlement = $this->grant(SourceRef::compose('wc', 'order', 9005, $this->courseId), Entitlement::SOURCE_PURCHASE);
        $hold = $this->hold();

        $hold->execute(Principal::user(self::MANAGER), $entitlement->id(), 'dispute_open', 'dp_9005');
        $hold->execute(Principal::user(self::MANAGER), $entitlement->id(), 'dispute_open', 'dp_9005');

        self::assertCount(1, $this->fixture->eventsOfType(EnrolmentSuspended::class));
    }

    public function testARevocationByTheShopsOwnReferenceRecordsTheDisputeItWasAbout(): void
    {
        $ref = SourceRef::compose('wc', 'order', 9004, $this->courseId);
        $this->grant($ref, Entitlement::SOURCE_PURCHASE);

        $byRef = new RevokeEntitlementBySourceRef($this->fixture->entitlements, $this->fixture->revokeEntitlement);
        $revoked = $byRef->execute(
            Principal::user(self::MANAGER),
            Entitlement::SOURCE_PURCHASE,
            $ref,
            'refund_partial',
            'rf_412',
        );

        self::assertNotNull($revoked);
        self::assertSame(Entitlement::STATUS_REVOKED, $revoked->status);
        self::assertSame('refund_partial', $revoked->revokedReason);
        // Keyed on the refund and not on the entitlement's own id: a self
        // reference tells a later reinstatement nothing.
        self::assertSame('rf_412', $revoked->revokedRef);

        $row = $this->fixture->entitlementRow($revoked->id());
        self::assertNotNull($row);
        self::assertSame('rf_412', $row['revoked_ref']);

        // A reference this LMS never granted against is a normal path, not an
        // exception out of a webhook drain.
        self::assertNull($byRef->execute(
            Principal::user(self::MANAGER),
            Entitlement::SOURCE_PURCHASE,
            'wc-order-1-course-1',
        ));
    }

    public function testTheBackgroundActorIsResolvedPerCourseAndIsNeverSynthetic(): void
    {
        $settings = new Settings(new DatabaseSettingsRepository(
            $this->fixture->env->database,
            $this->fixture->env->clock,
        ));
        $resolver = new BackgroundActorResolver($this->fixture->courseAccess, $this->fixture->guard, $settings);

        // Nobody configured and no current actor: the honest answer is null,
        // and the caller leaves the order paid and unfulfilled with an alert
        // rather than granting under an invented principal.
        self::assertNull($resolver->resolve($this->courseId));

        $settings->set(BackgroundActorResolver::SETTING, self::MANAGER);
        $configured = new BackgroundActorResolver($this->fixture->courseAccess, $this->fixture->guard, $settings);
        $resolved = $configured->resolve($this->courseId);

        self::assertSame(self::MANAGER, $resolved?->userId);
        self::assertTrue($this->fixture->courseAccess->can($resolved, $this->courseId, Capability::ENROL_OTHERS));

        // The current actor wins over the configured one, so a return-URL
        // fulfilment inside a manager's own request is attributed to them.
        self::assertSame(
            self::MANAGER,
            $configured->resolve($this->courseId, Principal::user(self::MANAGER))?->userId,
        );

        // A guest is never a candidate, so a public request cannot become one.
        self::assertSame(self::MANAGER, $configured->resolve($this->courseId, Principal::guest())?->userId);

        // A configured account that cannot enrol on this course answers null
        // rather than an actor the use case would then refuse: the site-wide
        // question and the per-course one give different answers, which is the
        // whole reason this resolves per course.
        $settings->set(BackgroundActorResolver::SETTING, self::LEARNER);
        $ineligible = new BackgroundActorResolver($this->fixture->courseAccess, $this->fixture->guard, $settings);
        self::assertNull($ineligible->resolve($this->courseId));

        // The same account written as a string, which is what a form posts and
        // what a CLI argument is. Under the strict read this answered nobody,
        // so a background grant was silently disabled and the order was left
        // paid and unfulfilled with the configuration gap reported as if the
        // setting had never been made.
        $settings->set(BackgroundActorResolver::SETTING, (string) self::MANAGER);
        $stringy = new BackgroundActorResolver($this->fixture->courseAccess, $this->fixture->guard, $settings);
        self::assertSame(self::MANAGER, $stringy->resolve($this->courseId)?->userId);

        // And a value that is not a whole number is still unset rather than
        // guessed at, which is what keeps the strict reading's promise.
        $settings->set(BackgroundActorResolver::SETTING, 'the site administrator');
        $nonsense = new BackgroundActorResolver($this->fixture->courseAccess, $this->fixture->guard, $settings);
        self::assertNull($nonsense->resolve($this->courseId));
    }

    private function grant(string $ref, string $source): Entitlement
    {
        return $this->fixture->grantEntitlement->execute(
            Principal::user(self::MANAGER),
            self::LEARNER,
            $this->courseId,
            $source,
            $ref,
        );
    }

    private function hold(): HoldEntitlement
    {
        return new HoldEntitlement(
            $this->fixture->courseAccess,
            $this->fixture->entitlements,
            $this->fixture->enrolments,
            $this->fixture->eventBus,
            $this->fixture->env->clock,
        );
    }
}
