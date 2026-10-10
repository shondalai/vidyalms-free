<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\AnchorInstant;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\BatchEvaluatesItems;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresExhaustion;
use Shondalai\VidyaLMS\Core\Registry\Availability\FiltersLearners;
use Shondalai\VidyaLMS\Core\Registry\Availability\PayloadValidationResult;
use Shondalai\VidyaLMS\Core\Registry\Availability\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Availability\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * Why this person is here: the source of the entitlement that admitted them.
 *
 * It is the second set-like free provider, and it reads entitlements.source,
 * which is a free column. The obvious alternative, a group membership, would
 * read a table the free schema does not carry, which is a seam nobody can
 * test.
 *
 * The expiry case is the reason this class carries a long comment. A learner
 * holding a purchase entitlement with no end date and a seat entitlement
 * expiring on 30 June keeps an active enrolment while the seat lapses, so a
 * module gated on seat changes outcome by the clock alone with the enrolment
 * untouched. Returning never there would let the clock write into a cached
 * administrative exclusion set with no invalidation anything could fire,
 * quietly removing a module the learner never opened from the denominator and
 * issuing an unearned certificate. So an expired-only match returns blocked
 * with exhausted true, which is computed live and never cached, and never is
 * reserved for a learner who has never held a matching entitlement at all.
 */
final class EnrolmentSourceCondition implements
    AvailabilityConditionInterface,
    ConditionMetadataInterface,
    BatchEvaluatesItems,
    FiltersLearners,
    DeclaresExhaustion,
    RequiresFacts,
    SupportsPayloadVersions,
    ValidatesPayload
{
    use EvaluatesManyByEvaluate;

    public const KEY = 'enrolment_source';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.availability.condition.enrolment_source';
    }

    public function group(): string
    {
        return ConditionMetadataInterface::GROUP_AUDIENCE;
    }

    public function order(): int
    {
        return 50;
    }

    public function advisory(): bool
    {
        return false;
    }

    /**
     * Automation is declared: why a learner is here is a standing fact about
     * the entitlement behind them, and a rule that acts on people who bought
     * a seat but not on people a manager enrolled is the case the surface
     * exists for. The leaf already answers BLOCKED with exhausted true for an
     * entitlement that has just lapsed, so a scheduler reading it repeatedly
     * gets a stable answer rather than one that flips with the clock.
     */
    /**
     * Redemption gating is excluded, deliberately.
     *
     * It asks how the learner got access to this course, and at
     * redemption there is no access yet: that is what the purchase is
     * about to create. A restriction phrased over the entitlement the
     * checkout has not written is a restriction over nothing.
     */
    public function applicability(): array
    {
        return [
            AvailabilitySurface::ContentGating,
            AvailabilitySurface::NotificationRule,
            AvailabilitySurface::AwardCriteria,
            AvailabilitySurface::EnrolmentGating,
            AvailabilitySurface::Automation,
        ];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function supportedPayloadVersions(): array
    {
        return [1];
    }

    public function requiredFacts(): array
    {
        return [AvailabilityContext::FACT_ENTITLEMENTS];
    }

    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['sources'],
            'properties' => [
                'sources' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => ['type' => 'string'],
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        $sources = $this->sourcesOf($payload);

        if ($sources === []) {
            return AvailabilityOutcome::blocked('availability.enrolment_source.unconfigured');
        }

        [$effective, $expired] = $this->countMatches($sources, $context);
        $parameters = ['sources' => $sources];
        $held = $effective > 0;

        if ($held !== $negated) {
            return AvailabilityOutcome::available();
        }

        $reason = $negated
            ? 'availability.enrolment_source.forbidden'
            : 'availability.enrolment_source.required';

        // Held once and now lapsed: reached by the clock, so blocked and
        // exhausted rather than never.
        if (!$negated && $expired > 0) {
            return AvailabilityOutcome::blocked($reason, $parameters, null, null, true);
        }

        if ($negated) {
            return AvailabilityOutcome::never($reason, $parameters);
        }

        return AvailabilityOutcome::never($reason, $parameters);
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        return new AvailabilityReason(
            $negated ? 'availability.enrolment_source.forbidden' : 'availability.enrolment_source.required',
            ['sources' => $this->sourcesOf($payload)],
        );
    }

    public function filterLearners(array $payload, bool $negated, array $userIds, AvailabilityContext $context): array
    {
        $sources = $this->sourcesOf($payload);

        if ($sources === []) {
            return [];
        }

        $byUser = [];

        foreach ($this->entitlementRows($context) as $row) {
            $userId = $row['user_id'] ?? null;

            if (!\is_numeric($userId)) {
                continue;
            }

            if (!\in_array((string) ($row['source'] ?? ''), $sources, true)) {
                continue;
            }

            if ($this->isEffective($row, $context->now)) {
                $byUser[(int) $userId] = true;
            }
        }

        $matched = [];

        foreach ($userIds as $userId) {
            if (isset($byUser[$userId]) !== $negated) {
                $matched[] = $userId;
            }
        }

        return $matched;
    }

    public function validatePayload(array $payload, PayloadValidationContext $context): PayloadValidationResult
    {
        return $this->sourcesOf($payload) === []
            ? PayloadValidationResult::error('availability.enrolment_source.sources_required')
            : PayloadValidationResult::valid();
    }

    /**
     * @param list<string> $sources
     *
     * @return array{0: int, 1: int} effective matches, then expired matches
     */
    private function countMatches(array $sources, AvailabilityContext $context): array
    {
        $effective = 0;
        $expired = 0;
        $userId = $context->principal->userId;

        foreach ($this->entitlementRows($context) as $row) {
            if (!\in_array((string) ($row['source'] ?? ''), $sources, true)) {
                continue;
            }

            $rowUser = $row['user_id'] ?? null;

            if (\is_numeric($rowUser) && (int) $rowUser !== $userId) {
                continue;
            }

            if ($this->isEffective($row, $context->now)) {
                ++$effective;
                continue;
            }

            ++$expired;
        }

        return [$effective, $expired];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function entitlementRows(AvailabilityContext $context): array
    {
        $rows = [];

        foreach ($context->factRows(AvailabilityContext::FACT_ENTITLEMENTS) as $row) {
            if (\is_array($row)) {
                /** @var array<string, mixed> $row */
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isEffective(array $row, \DateTimeImmutable $now): bool
    {
        $status = (string) ($row['status'] ?? 'active');

        if ($status !== 'active') {
            return false;
        }

        $startsAt = AnchorInstant::fromRow($row, 'starts_at');
        $expiresAt = AnchorInstant::fromRow($row, 'expires_at');

        if ($startsAt !== null && $now < $startsAt) {
            return false;
        }

        return $expiresAt === null || $now < $expiresAt;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function sourcesOf(array $payload): array
    {
        $sources = $payload['sources'] ?? null;

        if (!\is_array($sources)) {
            return [];
        }

        $clean = [];

        foreach ($sources as $source) {
            if (\is_string($source) && $source !== '') {
                $clean[] = $source;
            }
        }

        return $clean;
    }
}
