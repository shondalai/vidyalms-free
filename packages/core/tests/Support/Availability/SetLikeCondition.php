<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\FiltersLearners;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * A set-like provider: it filters learners, so it has earned the right to
 * return NEVER, and its answer never moves by the clock.
 */
final class SetLikeCondition implements AvailabilityConditionInterface, FiltersLearners
{
    public const KEY = 'test.set_like';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Set like';
    }

    public function applicability(): array
    {
        return [AvailabilitySurface::ContentGating];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        $held = (bool) ($payload['held'] ?? false);

        return $held !== $negated
            ? AvailabilityOutcome::available()
            : AvailabilityOutcome::never('test.set_like.required');
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        return new AvailabilityReason('test.set_like.required');
    }

    public function filterLearners(array $payload, bool $negated, array $userIds, AvailabilityContext $context): array
    {
        return (bool) ($payload['held'] ?? false) !== $negated ? $userIds : [];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }
}
