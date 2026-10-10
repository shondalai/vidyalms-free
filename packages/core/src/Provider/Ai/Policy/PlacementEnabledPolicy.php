<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Application\Ai\AiPlacementCatalogue;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresEnablementLevel;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\TaskMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PlacementContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * The first two levels of enablement, and the stop above all four.
 *
 * Everything is off by default. The site enables AI at all; the site enables
 * each placement; a route enables each task; and each person accepts the
 * versioned policy. Above all four sits **one control that halts every task
 * on the site in a safe state**, which is what a stop obligation asks for and
 * what a stop assembled from twelve toggles does not satisfy.
 *
 * The stop is read here rather than described in a paragraph, and it is read
 * **first**: an operator who has stopped the site has stopped it for a run
 * whose placement is enabled and whose person accepted everything.
 *
 * The toggles themselves are generated from the registries rather than
 * hand-written, so a new helper arrives with its own toggle and no admin
 * screen is edited. This policy therefore names no placement key: it asks the
 * settings resolver about whichever placement the intent carries.
 */
final class PlacementEnabledPolicy implements AiPolicyInterface, DeclaresRefusalKind, DeclaresEnablementLevel
{
    public const KEY = 'placement_enabled';

    /** The setting the one stop control is stored under, at site scope. */
    public const SETTING_STOP = 'stop';

    /** The setting the first level of enablement is stored under. */
    public const SETTING_SITE_ENABLED = 'enabled';

    /** The prefix each placement's own toggle is stored under. */
    public const SETTING_PLACEMENT_PREFIX = 'placement.';

    public const REASON_STOPPED = 'ai.policy.stopped';
    public const REASON_SITE_DISABLED = 'ai.policy.site_disabled';
    public const REASON_PLACEMENT_DISABLED = 'ai.policy.placement_disabled';

    /**
     * The two registries the derived-placement rule reads.
     *
     * They are optional so a policy exercised on its own still constructs and
     * still answers the first three questions; absent, a run naming no
     * placement is treated as a job, which is what it was before the rule
     * existed. The boot registers this with both, so on a real site a person's
     * run cannot be one.
     */
    public function __construct(
        private readonly ?AiPlacementRegistry $placements = null,
        private readonly ?AiTaskRegistry $tasks = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * Two of the four, because this one policy owns both switches and
     * splitting it into two providers would give one stored row two owners.
     */
    public function enablementLevels(): array
    {
        return [
            ['level' => 'site', 'setting' => self::SETTING_SITE_ENABLED, 'stored_in' => 'provider_settings'],
            [
                'level' => 'placement',
                'setting' => self::SETTING_PLACEMENT_PREFIX . '{placement_key}',
                'stored_in' => 'provider_settings',
            ],
        ];
    }

    /**
     * All four of these are a switch somebody has to turn on, which is
     * `not_configured` and is not a budget.
     */
    public function refusalKindFor(string $reason): ?RefusalKind
    {
        return \in_array($reason, [
            self::REASON_STOPPED,
            self::REASON_SITE_DISABLED,
            self::REASON_PLACEMENT_DISABLED,
        ], true) ? RefusalKind::NotConfigured : null;
    }

    public function label(): string
    {
        return 'ai.policy.placement_enabled.label';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        if ($context->setting(self::SETTING_STOP, false) === true) {
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_STOPPED);
        }

        if ($context->setting(self::SETTING_SITE_ENABLED, false) !== true) {
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_SITE_DISABLED);
        }

        if ($intent->placementKey === null) {
            return $this->withoutANamedPlacement($intent, $context);
        }

        $enabled = $context->setting(self::SETTING_PLACEMENT_PREFIX . $intent->placementKey, false);

        return $enabled === true
            ? PolicyDecision::allow()
            : PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_PLACEMENT_DISABLED);
    }

    /**
     * Level two, for a run that named no placement.
     *
     * **The old reading was that an absent placement key means no toggle
     * applies, and it was keyed on the ABSENCE of a field the caller
     * controls.** One site, one disabled panel, the same body twice: naming
     * the panel refused, omitting it succeeded and wrote the draft. A gate a
     * caller turns off by deleting one optional field is not a gate.
     *
     * So the question is keyed on who acted instead. A queued build and a
     * scheduled sweep act through no panel and are governed by the site
     * switch above, exactly as before. A PERSON acted through one, and if
     * they did not say which, every panel that would offer this task is asked
     * and one enabled panel is enough: naming a disabled panel and omitting
     * the field now answer the same way, which is the whole repair.
     */
    private function withoutANamedPlacement(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        if ($intent->actorKind !== AiRunIntent::ACTOR_PERSON) {
            return PolicyDecision::allow();
        }

        if ($this->placements === null || $this->tasks === null) {
            return PolicyDecision::allow();
        }

        $offering = $this->placementsOffering($intent, $context);

        if ($offering === []) {
            // A task that asked for no panel has no panel toggle to consult,
            // and inventing one would refuse a task an API caller holding
            // TEACH or MANAGE may legitimately invoke with no surface at all.
            // Level two gates panels; a task in no panel is governed by the
            // site switch above and by the front door's own capability.
            return PolicyDecision::allow();
        }

        foreach ($offering as $key) {
            if ($context->setting(self::SETTING_PLACEMENT_PREFIX . $key, false) === true) {
                return PolicyDecision::allow();
            }
        }

        return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_PLACEMENT_DISABLED);
    }

    /**
     * The registered placements this task asked to be offered in.
     *
     * `placements()` is the authoritative binding between a task and a
     * placement and is the only one, so it is what this reads: a task that
     * asked for no panel has no panel a person could have acted through, and a
     * task naming a panel nothing registers has none either. The placement's
     * own predicate still refuses a descriptor it will not take.
     *
     * It never asks `offersFor()`, because that is the per-request permission
     * half and the permissions were already resolved at the front door: asking
     * again here would be a second authorisation answer, which is the thing
     * this design keeps refusing to have two of.
     *
     * @return list<string>
     */
    private function placementsOffering(AiRunIntent $intent, AiDecisionContext $context): array
    {
        if ($this->tasks === null || $this->placements === null || !$this->tasks->has($intent->taskKey)) {
            return [];
        }

        $task = $this->tasks->get($intent->taskKey);

        if (!$task instanceof AiTaskInterface || !$task instanceof TaskMetadataInterface) {
            return [];
        }

        $keys = [];

        foreach ($task->placements() as $placementKey) {
            if (!$this->placements->has($placementKey)) {
                continue;
            }

            $placement = $this->placements->get($placementKey);

            if (!$placement instanceof AiPlacementInterface) {
                continue;
            }

            $descriptor = AiPlacementCatalogue::descriptorOf(
                $task,
                $placement->surfaceKey(),
                $placement->scopeLevel(),
            );

            $placementContext = PlacementContext::create(
                $context->principal,
                $placement->surfaceKey(),
                $placement->anchor(),
                $intent->scopeType,
                $intent->scopeId,
                'en-GB',
                [],
                $context->orgScope,
            );

            if ($placement->accepts($descriptor, $placementContext)) {
                $keys[] = $placement->key();
            }
        }

        return array_values(array_unique($keys));
    }
}
