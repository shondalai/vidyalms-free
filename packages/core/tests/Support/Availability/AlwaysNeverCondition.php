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
 * A provider that claims NEVER without implementing the interface that earns
 * the right to. The composer downgrades it and logs.
 */
final class AlwaysNeverCondition implements AvailabilityConditionInterface
{
    public const KEY = 'test.always_never';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Always never';
    }

    public function applicability(): array
    {
        return [AvailabilitySurface::ContentGating];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        return AvailabilityOutcome::never('test.never');
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        return new AvailabilityReason('test.never');
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
