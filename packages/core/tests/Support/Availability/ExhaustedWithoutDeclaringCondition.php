<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * A provider that sets exhausted without declaring it. The flag is ignored
 * and logged, exactly as an undeclared NEVER is downgraded.
 */
final class ExhaustedWithoutDeclaringCondition implements AvailabilityConditionInterface
{
    public const KEY = 'test.undeclared_exhaustion';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Undeclared exhaustion';
    }

    public function applicability(): array
    {
        return [AvailabilitySurface::ContentGating];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        return AvailabilityOutcome::blocked('test.blocked', [], null, null, true);
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        return new AvailabilityReason('test.blocked');
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
