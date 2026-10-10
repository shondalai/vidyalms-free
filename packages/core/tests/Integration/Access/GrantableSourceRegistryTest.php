<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceRegistry;

/**
 * Which sources may be granted is resolved from the registry.
 *
 * It was a fixed list of six words inside the use case, so a package that
 * registered its own checkout could not write an entitlement without editing
 * a shipped core file, which is the one thing ADR 0002 says a paid or
 * third-party feature must never need.
 */
final class GrantableSourceRegistryTest extends TestCase
{
    private const ADMIN_ID = 10;
    private const LEARNER_ID = 42;

    private AccessFixture $fixture;
    private int $courseId = 0;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
        $this->fixture->grantCapability(self::ADMIN_ID, Capability::ENROL_OTHERS);
        $this->fixture->createUser(self::LEARNER_ID);
        $this->courseId = $this->fixture->createCourse();
    }

    public function testTheFreeStorefrontKeepsEveryShippedGrantWorking(): void
    {
        foreach (
            [
            Entitlement::SOURCE_MANUAL,
            Entitlement::SOURCE_IMPORT,
            Entitlement::SOURCE_PURCHASE,
            Entitlement::SOURCE_SUBSCRIPTION,
            Entitlement::SOURCE_COUPON,
            Entitlement::SOURCE_SEAT,
            ] as $index => $source
        ) {
            $entitlement = $this->fixture->grantEntitlement->execute(
                Principal::user(self::ADMIN_ID),
                self::LEARNER_ID,
                $this->courseId,
                $source,
                'ref-' . $index,
            );

            self::assertSame($source, $entitlement->source);
        }
    }

    public function testAnUnregisteredCommerceWordIsRefusedAndTheStaffPathsAreNot(): void
    {
        $grant = $this->grantWith(new CommerceSourceRegistry());

        $manual = $grant->execute(
            Principal::user(self::ADMIN_ID),
            self::LEARNER_ID,
            $this->courseId,
            Entitlement::SOURCE_MANUAL,
        );
        self::assertSame(Entitlement::SOURCE_MANUAL, $manual->source);

        $this->expectException(ValidationException::class);
        $grant->execute(
            Principal::user(self::ADMIN_ID),
            self::LEARNER_ID,
            $this->courseId,
            Entitlement::SOURCE_PURCHASE,
            'order-1',
        );
    }

    public function testARegisteredSourceGrantsExactlyTheWordsItDeclares(): void
    {
        $registry = new CommerceSourceRegistry();
        $registry->register(new CouponOnlyCommerceSource());
        $grant = $this->grantWith($registry);

        $coupon = $grant->execute(
            Principal::user(self::ADMIN_ID),
            self::LEARNER_ID,
            $this->courseId,
            Entitlement::SOURCE_COUPON,
            'code-1',
        );
        self::assertSame(Entitlement::SOURCE_COUPON, $coupon->source);

        $this->expectException(ValidationException::class);
        $grant->execute(
            Principal::user(self::ADMIN_ID),
            self::LEARNER_ID,
            $this->courseId,
            Entitlement::SOURCE_SUBSCRIPTION,
            'sub-1',
        );
    }

    /**
     * A storefront that only answers price questions may not start writing
     * entitlements by being registered.
     */
    public function testASourceThatDeclaresNothingGrantsNothing(): void
    {
        $registry = new CommerceSourceRegistry();
        $registry->register(new PriceOnlyCommerceSource());

        $this->expectException(ValidationException::class);
        $this->grantWith($registry)->execute(
            Principal::user(self::ADMIN_ID),
            self::LEARNER_ID,
            $this->courseId,
            Entitlement::SOURCE_PURCHASE,
            'order-1',
        );
    }

    /**
     * The two words the domain confers elsewhere stay ungrantable however
     * loudly a provider declares them: `open` comes from the enrolment rule
     * and `org` only through the organisation path.
     */
    public function testAProviderCannotDeclareItsWayIntoTheWordsTheDomainOwns(): void
    {
        $registry = new CommerceSourceRegistry();
        $registry->register(new OverreachingCommerceSource());

        $this->expectException(ValidationException::class);
        $this->grantWith($registry)->execute(
            Principal::user(self::ADMIN_ID),
            self::LEARNER_ID,
            $this->courseId,
            Entitlement::SOURCE_OPEN,
        );
    }

    private function grantWith(CommerceSourceRegistry $registry): GrantEntitlement
    {
        $env = $this->fixture->env;

        return new GrantEntitlement(
            $this->fixture->courseAccess,
            $env->users,
            $env->database,
            $this->fixture->entitlements,
            $this->fixture->enrolLearner,
            $this->fixture->eventBus,
            $env->clock,
            $registry,
        );
    }
}
