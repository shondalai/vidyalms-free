<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One restriction a leaf can name.
 *
 * Drip is not a subsystem and gets no subsystem: it is condition providers
 * registered at the same point as prerequisite, score and role, and a fixed
 * date and a relative offset are the same condition with a different anchor.
 *
 * describe() returns a reason key plus parameters, never a rendered sentence,
 * because the core owns no locale and the React apps and both adapters must
 * translate. That is the anti-corruption boundary between a paid condition
 * and the UI.
 *
 * applicability() is the guard on reusing one grammar in four places. An
 * availability leaf evaluates against a learner viewing an item; a
 * notification leaf evaluates against a recipient at a send moment with no
 * item in hand. Using a leaf outside its declared surface is a save-time
 * refusal, not a silent mismatch at evaluation.
 *
 * Negation is the leaf's own business, and the conformance kit asserts it:
 * for every deterministic condition, evaluate(payload, true, ctx) is the
 * complement of evaluate(payload, false, ctx) under the mapping AVAILABLE to
 * (BLOCKED or NEVER). A set-like leaf complements AVAILABLE to NEVER and
 * back; a time-like leaf complements to BLOCKED.
 *
 * Optional behaviour arrives as a narrow capability interface probed with
 * instanceof, never as a new method here: adding a method to this interface
 * breaks every external implementation, and third parties are invited to
 * implement it.
 */
interface AvailabilityConditionInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The surfaces this condition may be authored on.
     *
     * @return list<\Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface>
     */
    public function applicability(): array;

    /**
     * @param array<string, mixed> $payload the unwrapped body at this provider's own version
     */
    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome;

    /**
     * @param array<string, mixed> $payload
     */
    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason;

    /** The payload version this provider writes today. */
    public function currentShape(): int;

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function upgrade(array $payload, int $from, UpgradeContext $context): array;
}
