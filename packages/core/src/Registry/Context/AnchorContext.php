<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * What a schedule anchor may read to resolve an instant.
 *
 * One anchor vocabulary is shared by availability conditions, the cohort
 * schedule resolver and notification schedules, so a package that adds a
 * cohort start anchor adds it to all three at once rather than to one of
 * three copies. That is why this context exists rather than three.
 */
final class AnchorContext
{
    /**
     * @param array<string, mixed>|null $enrolment
     * @param array<string, mixed>|null $course
     * @param array<string, mixed>      $payload   the leaf's own payload, which is where the absolute anchor reads its instant
     * @param array<string, mixed>      $facts
     */
    private function __construct(
        public readonly Principal $principal,
        public readonly \DateTimeImmutable $now,
        public readonly ?array $enrolment,
        public readonly ?array $course,
        public readonly array $payload,
        public readonly string $siteTimezone,
        private readonly array $facts,
    ) {
    }

    /**
     * @param array<string, mixed>|null $enrolment
     * @param array<string, mixed>|null $course
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>      $facts
     */
    public static function create(
        Principal $principal,
        \DateTimeImmutable $now,
        ?array $enrolment = null,
        ?array $course = null,
        array $payload = [],
        string $siteTimezone = 'UTC',
        array $facts = [],
    ): self {
        return new self($principal, $now, $enrolment, $course, $payload, $siteTimezone, $facts);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromAvailability(AvailabilityContext $context, array $payload): self
    {
        $carried = [
            AvailabilityContext::FACT_ENTITLEMENTS,
            AvailabilityContext::FACT_COHORT,
            AvailabilityContext::FACT_COHORT_MEMBERSHIP,
            AvailabilityContext::FACT_PROGRESS,
        ];

        $facts = [];

        foreach ($carried as $key) {
            if ($context->hasFact($key)) {
                $facts[$key] = $context->facts($key);
            }
        }

        return new self(
            $context->principal,
            $context->now,
            $context->enrolment,
            $context->course,
            $payload,
            $context->siteTimezone(),
            $facts,
        );
    }

    public function hasFact(string $key): bool
    {
        return \array_key_exists($key, $this->facts);
    }

    public function facts(string $key): mixed
    {
        return $this->facts[$key] ?? null;
    }

    /**
     * The published resolution chain, in one accessor: the cohort's zone,
     * then the course's, then the site's.
     *
     * It is here rather than in each anchor because an anchor that reads one
     * step of the chain by hand picks the wrong one by accident. Two free
     * anchors did exactly that and stepped the site calendar for a course
     * with its own timezone, so a plus-seven-days offset across a spring
     * transition landed an hour late in local terms and the authoritative
     * reading rendered beside it named the wrong zone.
     */
    public function resolvedTimezone(): string
    {
        $cohort = $this->facts(AvailabilityContext::FACT_COHORT);

        if (\is_array($cohort)) {
            $zone = $cohort['timezone'] ?? null;

            if (\is_string($zone) && $zone !== '') {
                return $zone;
            }
        }

        $zone = $this->course['timezone'] ?? null;

        if (\is_string($zone) && $zone !== '') {
            return $zone;
        }

        return $this->siteTimezone;
    }
}
