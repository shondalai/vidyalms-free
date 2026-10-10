<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityComposer;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityFactLoaders;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\SubjectTree;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityState;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\Availability\AlwaysNeverCondition;
use Shondalai\VidyaLMS\Core\Tests\Support\Availability\ExhaustedWithoutDeclaringCondition;
use Shondalai\VidyaLMS\Core\Tests\Support\Availability\SetLikeCondition;

/**
 * The composition algebra, stated exactly and tested exactly.
 */
final class AvailabilityComposerTest extends TestCase
{
    private const NOW = '2026-03-05T00:00:00+00:00';

    public function testAndNamesTheMaximumOpensAtAcrossBlockingChildren(): void
    {
        $outcome = $this->compose($this->node('and', [
            $this->dateLeaf('2026-03-10T09:00:00+00:00'),
            $this->dateLeaf('2026-03-03T09:00:00+00:00'),
        ]));

        // A node whose children open on 3 March and 10 March opens on 10
        // March. The loose "earliest across the blocking leaves" shorthand is
        // wrong here and would promise a date the item will not honour.
        self::assertSame(AvailabilityState::Blocked, $outcome->state);
        self::assertSame('2026-03-10T09:00:00+00:00', $outcome->opensAt?->format(\DateTimeInterface::ATOM));
    }

    public function testOrNamesTheMinimumOpensAtAcrossBlockingChildren(): void
    {
        $outcome = $this->compose($this->node('or', [
            $this->dateLeaf('2026-03-10T09:00:00+00:00'),
            $this->dateLeaf('2026-03-08T09:00:00+00:00'),
        ]));

        self::assertSame('2026-03-08T09:00:00+00:00', $outcome->opensAt?->format(\DateTimeInterface::ATOM));
    }

    public function testANodeNamesNoInstantWhenAnyBlockingChildNamesNone(): void
    {
        $outcome = $this->compose($this->node('and', [
            $this->dateLeaf('2026-03-10T09:00:00+00:00'),
            ['type' => SetLikeCondition::KEY, 'payload' => ['held' => false]],
        ]));

        self::assertNull($outcome->opensAt);
    }

    public function testANestedTreeWithNegationMatchesItsDeMorganEquivalent(): void
    {
        $negated = $this->compose($this->node('not_and', [
            $this->dateLeaf('2026-03-10T09:00:00+00:00'),
            $this->dateLeaf('2026-03-01T09:00:00+00:00'),
        ]));

        // NOT(a AND b) is (NOT a) OR (NOT b), pushed down to the leaves.
        $deMorgan = $this->compose($this->node('or', [
            $this->node('not_and', [$this->dateLeaf('2026-03-10T09:00:00+00:00')]),
            $this->node('not_and', [$this->dateLeaf('2026-03-01T09:00:00+00:00')]),
        ]));

        self::assertSame($negated->state, $deMorgan->state);
        self::assertSame(
            $negated->opensAt?->format(\DateTimeInterface::ATOM),
            $deMorgan->opensAt?->format(\DateTimeInterface::ATOM),
        );
    }

    public function testAClosedWindowIsBlockedAndExhaustedAndNeverNever(): void
    {
        $outcome = $this->compose($this->node('and', [
            [
                'type' => DateCondition::KEY,
                'payload' => StoredShape::wrap(['from' => null, 'until' => '2026-03-01T00:00:00+00:00'], 1),
            ],
        ]));

        self::assertSame(AvailabilityState::Blocked, $outcome->state);
        self::assertTrue($outcome->exhausted);
        self::assertNull($outcome->opensAt);
        self::assertFalse($outcome->countsTowardsCompletion());
    }

    public function testANeverFromAProviderWithoutLearnerFilteringIsDowngraded(): void
    {
        $outcome = $this->compose(
            $this->node('and', [['type' => AlwaysNeverCondition::KEY, 'payload' => []]]),
            [new AlwaysNeverCondition()],
        );

        // A silently corrupted denominator is the one failure in this design
        // that is invisible until credentials are wrong, so the downgrade is
        // the composer's and not the provider's to be trusted with.
        self::assertSame(AvailabilityState::Blocked, $outcome->state);
        self::assertTrue($outcome->countsTowardsCompletion());
    }

    public function testAnExhaustedFlagFromAnUndeclaredProviderIsIgnored(): void
    {
        $outcome = $this->compose(
            $this->node('and', [['type' => ExhaustedWithoutDeclaringCondition::KEY, 'payload' => []]]),
            [new ExhaustedWithoutDeclaringCondition()],
        );

        self::assertFalse($outcome->exhausted);
        self::assertTrue($outcome->countsTowardsCompletion());
    }

    public function testASetLikeProviderMayReturnNeverAndLeavesTheDenominator(): void
    {
        $outcome = $this->compose(
            $this->node('and', [['type' => SetLikeCondition::KEY, 'payload' => ['held' => false]]]),
            [new SetLikeCondition()],
        );

        self::assertSame(AvailabilityState::Never, $outcome->state);
        self::assertFalse($outcome->countsTowardsCompletion());
    }

    public function testAnUnregisteredProviderFailsClosedWithNoInstant(): void
    {
        $outcome = $this->compose($this->node('and', [['type' => 'acme.absent', 'payload' => []]]));

        self::assertSame(AvailabilityState::Blocked, $outcome->state);
        self::assertSame(AvailabilityComposer::REASON_PROVIDER_MISSING, $outcome->reasonKey);
        self::assertNull($outcome->opensAt);
    }

    public function testAPayloadAboveTheSupportedVersionIsTreatedAsAMiss(): void
    {
        $outcome = $this->compose($this->node('and', [
            [
                'type' => DateCondition::KEY,
                'payload' => StoredShape::wrap(['from' => '2026-03-10T09:00:00+00:00'], 9),
            ],
        ]));

        // A resolvable key carrying an unsupported payload version is a miss
        // and is treated identically to a missing provider: a plain presence
        // check cannot see it.
        self::assertSame(AvailabilityComposer::REASON_PROVIDER_MISSING, $outcome->reasonKey);
    }

    public function testAnOrNodeIsExhaustedOnlyWhenEveryChildIs(): void
    {
        $closed = [
            'type' => DateCondition::KEY,
            'payload' => StoredShape::wrap(['from' => null, 'until' => '2026-03-01T00:00:00+00:00'], 1),
        ];

        $mixed = $this->compose($this->node('or', [$closed, $this->dateLeaf('2026-03-10T09:00:00+00:00')]));
        self::assertFalse($mixed->exhausted);

        $bothClosed = $this->compose($this->node('or', [$closed, $closed]));
        self::assertTrue($bothClosed->exhausted);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<object>         $extra
     */
    private function compose(array $node, array $extra = []): \Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome
    {
        $registry = new AvailabilityConditionRegistry();
        $registry->register(new DateCondition());

        foreach ($extra as $condition) {
            /** @var \Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface $condition */
            $registry->register($condition);
        }

        $composer = new AvailabilityComposer($registry, new AvailabilityFactLoaders(), new NullLogger());

        $context = AvailabilityContext::create(
            Principal::user(5),
            AvailabilitySurface::ContentGating,
            new \DateTimeImmutable(self::NOW),
        );

        $tree = AvailabilityTreeCodec::decode(StoredShape::wrap($node, 1));
        $decisions = $composer->evaluateSet(['s' => new SubjectTree($tree, null)], $context);

        return $decisions['s']->outcome;
    }

    /**
     * @param list<array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private function node(string $op, array $children): array
    {
        return ['op' => $op, 'c' => $children];
    }

    /**
     * @return array<string, mixed>
     */
    private function dateLeaf(string $from): array
    {
        return [
            'type' => DateCondition::KEY,
            'payload' => StoredShape::wrap(['from' => $from, 'until' => null, 'tz' => 'UTC'], 1),
        ];
    }
}
