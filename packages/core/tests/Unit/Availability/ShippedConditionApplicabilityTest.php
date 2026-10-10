<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Availability;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Provider\Availability\CompletionCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\EnrolmentSourceCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\RoleCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\ScoreCondition;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Tests\Support\StubAuthorization;

/**
 * Which surfaces each shipped free condition declares, and the one that was
 * added without any of them declaring it.
 *
 * A leaf used outside its declared applicability is refused at save, so the
 * Automation surface's stored tests would have refused every shipped leaf.
 * The prerequisite asks for the decision to be made explicitly for every
 * shipped condition rather than by blanket inclusion, and each provider now
 * carries its reasoning in a docblock beside its list.
 */
final class ShippedConditionApplicabilityTest extends TestCase
{
    /**
     * @return array<string, array{0: AvailabilityConditionInterface}>
     */
    public static function shippedConditions(): array
    {
        return [
            'date' => [new DateCondition()],
            'completion' => [new CompletionCondition()],
            'score' => [new ScoreCondition()],
            'role' => [new RoleCondition(new StubAuthorization())],
            'enrolment_source' => [new EnrolmentSourceCondition()],
        ];
    }

    /**
     * @dataProvider shippedConditions
     */
    public function testEveryShippedConditionDeclaresTheAutomationSurface(
        AvailabilityConditionInterface $condition,
    ): void {
        self::assertContains(
            AvailabilitySurface::Automation,
            $condition->applicability(),
            $condition->key() . ' cannot be used in an automation stored test',
        );
    }

    /**
     * @dataProvider shippedConditions
     */
    public function testDeclaringTheSurfaceDoesNotChangeWhereARuleIsStored(
        AvailabilityConditionInterface $condition,
    ): void {
        // storesInAvailabilityRules() is deliberately unchanged: an
        // automation rule keeps its tree in its own row, so the two surfaces
        // that share availability_rules are still exactly two.
        self::assertFalse(AvailabilitySurface::Automation->storesInAvailabilityRules());
        self::assertNotSame([], $condition->applicability());
    }

    public function testTheTwoContentSurfacesAreStillTheOnlyOnesInTheRuleTable(): void
    {
        $stored = array_values(array_filter(
            AvailabilitySurface::cases(),
            static fn (AvailabilitySurface $surface): bool => $surface->storesInAvailabilityRules(),
        ));

        self::assertSame(
            [AvailabilitySurface::ContentGating, AvailabilitySurface::EnrolmentGating],
            $stored,
        );
    }

    public function testTheProgressReadingLeavesStayOutOfEnrolmentGating(): void
    {
        // The decision is per condition and not a blanket inclusion: there is
        // no attempt and no progress to read before the learner is enrolled.
        foreach ([new CompletionCondition(), new ScoreCondition()] as $condition) {
            self::assertNotContains(AvailabilitySurface::EnrolmentGating, $condition->applicability());
        }
    }
}
