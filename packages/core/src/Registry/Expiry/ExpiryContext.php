<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Expiry;

/**
 * What an expiry policy is told.
 *
 * A context object rather than four parameters, on the rule every new
 * provider method in this design follows: a later fact is additive here and a
 * fifth parameter breaks every implementation.
 *
 * Both instants are carried because they are different questions. A
 * credential earned on the last day of a course and issued three days later
 * expires from the completion under some regimes and from the issue under
 * others, and a policy that could see only one would have to guess which.
 * The timezone is the site's, because "the end of the calendar year" is a
 * local statement and computing it in UTC moves it for half the world.
 */
final class ExpiryContext
{
    private function __construct(
        public readonly \DateTimeImmutable $issuedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly ?\DateTimeImmutable $validFrom,
        public readonly ?int $days,
        public readonly ?\DateTimeImmutable $fixedAt,
        public readonly string $timezone,
        public readonly ?int $templateId,
    ) {
    }

    public static function create(
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $completedAt = null,
        ?\DateTimeImmutable $validFrom = null,
        ?int $days = null,
        ?\DateTimeImmutable $fixedAt = null,
        string $timezone = 'UTC',
        ?int $templateId = null,
    ): self {
        return new self($issuedAt, $completedAt, $validFrom, $days, $fixedAt, $timezone, $templateId);
    }

    /**
     * The instant validity runs from, which is where every relative policy
     * counts from: valid_from when the issuer set one, and the issue
     * otherwise.
     */
    public function effectiveFrom(): \DateTimeImmutable
    {
        return $this->validFrom ?? $this->issuedAt;
    }

    public function zone(): \DateTimeZone
    {
        try {
            return new \DateTimeZone($this->timezone);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
