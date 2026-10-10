<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The reader over the preloaded grade fact set.
 *
 * A percentage is absent rather than zero when no grade has been recorded,
 * because "no grade recorded" is treated as not satisfied and not as a score
 * of zero, and the two would otherwise be indistinguishable to a band whose
 * minimum is zero.
 */
final class GradeFacts
{
    public const KEY_PERCENTAGES = 'percentages';
    public const KEY_TITLES = 'titles';

    /**
     * @param array<string, array<int, float>>  $percentages
     * @param array<string, array<int, string>> $titles
     */
    private function __construct(
        private readonly array $percentages,
        private readonly array $titles,
    ) {
    }

    public static function of(AvailabilityContext $context): self
    {
        if (!$context->hasFact(AvailabilityContext::FACT_GRADES)) {
            return new self([], []);
        }

        $raw = $context->factRows(AvailabilityContext::FACT_GRADES);

        /** @var array<string, array<int, float>> $percentages */
        $percentages = \is_array($raw[self::KEY_PERCENTAGES] ?? null) ? $raw[self::KEY_PERCENTAGES] : [];
        /** @var array<string, array<int, string>> $titles */
        $titles = \is_array($raw[self::KEY_TITLES] ?? null) ? $raw[self::KEY_TITLES] : [];

        return new self($percentages, $titles);
    }

    /**
     * @param array<string, array<int, float>>  $percentages
     * @param array<string, array<int, string>> $titles
     *
     * @return array<string, mixed>
     */
    public static function pack(array $percentages, array $titles = []): array
    {
        return [self::KEY_PERCENTAGES => $percentages, self::KEY_TITLES => $titles];
    }

    public function percentageOf(GateSubject $subject): ?float
    {
        $value = $this->percentages[$subject->type][$subject->id] ?? null;

        return \is_float($value) || \is_int($value) ? (float) $value : null;
    }

    public function titleOf(GateSubject $subject): ?string
    {
        return $this->titles[$subject->type][$subject->id] ?? null;
    }
}
