<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * The capability keys the condition palette publishes, so a screen and a
 * test name the same strings rather than two spellings of one idea.
 *
 * Every capability is a narrow interface probed with instanceof and every
 * one states its default when absent. There is deliberately no "supports"
 * method on the provider interface: adding a method to a shipped interface
 * breaks every external implementation.
 */
final class ConditionCapabilities
{
    public const BATCH_EVALUATES_ITEMS = 'batch_evaluates_items';
    public const FILTERS_LEARNERS = 'filters_learners';
    public const DECLARES_REFERENCES = 'declares_references';
    public const NAMES_OPENS_AT = 'names_opens_at';
    public const REQUIRES_LEARNER_ACTION = 'requires_learner_action';
    public const SUPPORTS_PAYLOAD_VERSIONS = 'supports_payload_versions';
    public const REQUIRES_FACTS = 'requires_facts';
    public const DECLARES_EXHAUSTION = 'declares_exhaustion';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function of(AvailabilityConditionInterface $condition): array
    {
        $capabilities = [];

        if ($condition instanceof BatchEvaluatesItems) {
            $capabilities[] = self::BATCH_EVALUATES_ITEMS;
        }

        if ($condition instanceof FiltersLearners) {
            $capabilities[] = self::FILTERS_LEARNERS;
        }

        if ($condition instanceof DeclaresReferences) {
            $capabilities[] = self::DECLARES_REFERENCES;
        }

        if ($condition instanceof NamesOpensAt) {
            $capabilities[] = self::NAMES_OPENS_AT;
        }

        if ($condition instanceof RequiresLearnerAction) {
            $capabilities[] = self::REQUIRES_LEARNER_ACTION;
        }

        if ($condition instanceof SupportsPayloadVersions) {
            $capabilities[] = self::SUPPORTS_PAYLOAD_VERSIONS;
        }

        if ($condition instanceof RequiresFacts) {
            $capabilities[] = self::REQUIRES_FACTS;
        }

        if ($condition instanceof DeclaresExhaustion) {
            $capabilities[] = self::DECLARES_EXHAUSTION;
        }

        return $capabilities;
    }
}
