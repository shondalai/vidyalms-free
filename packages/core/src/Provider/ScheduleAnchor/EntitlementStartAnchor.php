<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\AnchorRequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\DeclaresAnchorParams;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorInterface;

/**
 * The effective entitlement's start instant, falling back to when it was
 * created.
 *
 * It reads the preloaded entitlement fact set rather than issuing a query,
 * because an anchor resolved once per item in a sixty-item outline is the
 * per-leaf query the batch phase exists to avoid.
 */
final class EntitlementStartAnchor implements ScheduleAnchorInterface, DeclaresAnchorParams, AnchorRequiresFacts
{
    public const KEY = 'entitlement.starts_at';

    /**
     * @return list<string>
     */
    public function requiredFacts(): array
    {
        return [AvailabilityContext::FACT_ENTITLEMENTS];
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.anchor.entitlement_start';
    }

    /**
     * The payload this anchor reads, published rather than left to an
     * author to discover from the provider's source. The catalogue used to
     * answer an empty schema for every anchor, which is what "takes
     * nothing" looks like and was wrong for four of the five.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
    }

    public function resolve(AnchorContext $context): ?\DateTimeImmutable
    {
        $rows = $context->facts(AvailabilityContext::FACT_ENTITLEMENTS);

        if (!\is_array($rows)) {
            return null;
        }

        $earliest = null;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $instant = AnchorInstant::fromRow($row, 'starts_at') ?? AnchorInstant::fromRow($row, 'created_at');

            if ($instant === null) {
                continue;
            }

            if ($earliest === null || $instant < $earliest) {
                $earliest = $instant;
            }
        }

        return $earliest;
    }

    public function timezoneFor(AnchorContext $context): string
    {
        return $context->resolvedTimezone();
    }
}
