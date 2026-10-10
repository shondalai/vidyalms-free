<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiContentType;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiGroundingMode;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRiskTier;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiSurfaceInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresContentType;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRiskTier;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskInput;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresTaskOutput;
use Shondalai\VidyaLMS\Core\Registry\Ai\ProducesFindingsOnly;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresGrounding;
use Shondalai\VidyaLMS\Core\Registry\Ai\TargetsExtensionPoint;
use Shondalai\VidyaLMS\Core\Registry\Ai\TaskDescriptor;
use Shondalai\VidyaLMS\Core\Registry\Ai\TaskMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\PlacementContext;
use Shondalai\VidyaLMS\Core\Provider\Ai\Surface\CoreAiSurface;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * What this caller may invoke, on this surface, in this scope.
 *
 * The generic mount reads this one route and draws itself, which is the test of
 * whether the placement registry is real: adding a helper adds no route, and a
 * task that declares an existing placement key reaches a shipped panel with no
 * edit to the free provider that owns it.
 *
 * Three misses are answered here rather than left to the screen, and each takes
 * the direction the registry publishes. A placement whose surface no provider
 * answers is not offered at all. A placement whose anchor the running bundle
 * does not publish is not offered either, so a workbench newer than its host
 * degrades rather than drawing into a slot that does not exist. And a task with
 * no enabled route is offered and reports `not_configured`, rather than failing
 * at click time.
 */
final class AiPlacementCatalogue
{
    public function __construct(
        private readonly Registries $registries,
        private readonly AiEnablementState $enablement,
        private readonly AiPolicyCentre $policies,
        private readonly AiAccess $access,
        private readonly DatabasePort $database,
        // Every label a registry publishes is a catalogue key, so the reader
        // translates before it publishes. Without it the generic mount draws
        // "ai.placement.workbench_question_bank.label" as a panel heading.
        private readonly TranslatorPort $translator,
    ) {
    }

    /**
     * @param list<string> $anchors the slots the running bundle publishes; empty means every slot
     *
     * @return array<string, mixed>
     */
    public function execute(
        Principal $principal,
        ?string $surfaceKey,
        array $anchors,
        string $scopeType = 'site',
        int $scopeId = 0,
        string $locale = 'en-GB',
    ): array {
        // A learner may read the learner-facing placements and nothing else.
        // The one learner-facing position in the design carries three
        // disclosure strings, and Article 50(1) asks for them BEFORE the first
        // interaction: they lived only on a payload every learner was answered
        // 403 for, so the obligation was owed to a person who could not reach
        // the text that discharges it.
        $authoring = $this->access->mayAuthor($principal, $scopeType === 'course' ? $scopeId : null);

        if (!$authoring) {
            $this->access->requireSelf($principal);
        }

        $siteEnabled = $this->enablement->siteEnabled();
        $stopped = $this->enablement->stopped();
        $policy = $this->policies->state($principal);
        $tasksWithRoute = $this->tasksWithEnabledRoute();

        $items = [];

        foreach ($this->registries->aiPlacements->all() as $placement) {
            if (!$placement instanceof AiPlacementInterface) {
                continue;
            }

            if ($surfaceKey !== null && $surfaceKey !== '' && $placement->surfaceKey() !== $surfaceKey) {
                continue;
            }

            if (!$this->registries->aiSurfaces->has($placement->surfaceKey())) {
                continue;
            }

            if ($anchors !== [] && !\in_array($placement->anchor(), $anchors, true)) {
                continue;
            }

            if (!$this->offerableTo($placement, $principal, $authoring, $scopeType)) {
                continue;
            }

            $items[] = $this->offer($placement, $principal, $scopeType, $scopeId, $locale, $siteEnabled, $stopped, $tasksWithRoute);
        }

        usort($items, static fn (array $a, array $b): int => ((string) $a['surface_key'] . '/' . (string) $a['key'])
            <=> ((string) $b['surface_key'] . '/' . (string) $b['key']));

        return [
            'items' => $items,
            'site_enabled' => $siteEnabled,
            'stopped' => $stopped,
            'policy_accepted' => ($policy['accepted'] ?? false) === true,
            'policy_version' => $policy['policy'] === null ? null : (int) $policy['policy']['version'],
        ];
    }

    /**
     * Whether this caller could ever invoke this placement in this scope.
     *
     * Two answers, and both used to be missing.
     *
     * A learner reads the learner-facing placements and nothing else, which is
     * what makes the disclosure they are owed reachable at all.
     *
     * An author is not offered a SITE-scope placement on a course query unless
     * they hold the capability a site-scope run needs. Offering one drew a
     * panel whose click could not succeed: the same teacher who saw
     * `admin_narrative` marked available got 403 from both the site-scope
     * listing and from POST /ai/runs naming it.
     */
    private function offerableTo(
        AiPlacementInterface $placement,
        Principal $principal,
        bool $authoring,
        string $scopeType,
    ): bool {
        $learnerFacing = $placement->surfaceKey() === CoreAiSurface::LEARNER_PLAYER;

        if (!$authoring) {
            return $learnerFacing;
        }

        if ($placement->scopeLevel() !== 'site' || $scopeType === 'site') {
            return true;
        }

        return $this->access->manages($principal);
    }

    /**
     * @param array<string, true> $tasksWithRoute
     *
     * @return array<string, mixed>
     */
    private function offer(
        AiPlacementInterface $placement,
        Principal $principal,
        string $scopeType,
        int $scopeId,
        string $locale,
        bool $siteEnabled,
        bool $stopped,
        array $tasksWithRoute,
    ): array {
        $surface = $this->registries->aiSurfaces->get($placement->surfaceKey());
        $context = PlacementContext::create(
            $principal,
            $placement->surfaceKey(),
            $placement->anchor(),
            $scopeType,
            $scopeId > 0 ? $scopeId : null,
            $locale,
            $this->permissions($placement, $principal, $scopeType === 'course' && $scopeId > 0 ? $scopeId : null),
        );

        $enabled = $this->enablement->placementEnabled($placement->key());
        $disclosure = $placement->disclosure();

        $tasks = [];
        $anyAvailable = false;

        foreach ($placement->offersFor($context) as $taskKey) {
            if (!$this->registries->aiTasks->has($taskKey)) {
                continue;
            }

            $task = $this->registries->aiTasks->get($taskKey);

            if (!$task instanceof AiTaskInterface) {
                continue;
            }

            $descriptor = self::descriptorOf($task, $placement->surfaceKey(), $placement->scopeLevel());

            if (!$placement->accepts($descriptor, $context)) {
                continue;
            }

            $routed = isset($tasksWithRoute[$taskKey]);
            $anyAvailable = $anyAvailable || $routed;
            $tasks[] = $this->taskOffer($task, $descriptor, $routed);
        }

        $available = $siteEnabled && $enabled && !$stopped && $anyAvailable;

        return [
            'key' => $placement->key(),
            'label' => $this->translator->translate($placement->label()),
            'surface_key' => $placement->surfaceKey(),
            'surface_label' => $surface instanceof AiSurfaceInterface
                ? $this->translator->translate($surface->label())
                : $placement->surfaceKey(),
            'anchor' => $placement->anchor(),
            'render_mode' => $placement->renderMode()->value,
            'scope_level' => $placement->scopeLevel(),
            'fillable_fields' => $placement->fillableFields(),
            'disclosure' => [
                /* mode is a contract value and is published verbatim. The
                   three below are the text a human is shown, which the
                   contract states in as many words ("Shown before the first
                   turn, Article 50(1)"), so they travel through the
                   translator exactly as label and surface_label above do. A
                   placement declares them as catalogue keys, and publishing a
                   key put the Article 50(1) disclosure on the surface as
                   ai.disclosure.admin_narrative.before_interaction. */
                'mode' => $disclosure->mode->value,
                'learner' => $this->translator->translate($disclosure->beforeInteractionKey),
                'reader' => $this->translator->translate($disclosure->atReadTimeKey),
                'reviewer' => $this->translator->translate($disclosure->atReviewKey),
            ],
            'enabled' => $enabled,
            'available' => $available,
            'unavailable_reason' => $available ? null : self::reason($siteEnabled, $enabled, $stopped),
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskOffer(AiTaskInterface $task, TaskDescriptor $descriptor, bool $routed): array
    {
        $metadata = $task instanceof TaskMetadataInterface ? $task : null;

        return [
            'key' => $task->key(),
            'label' => $this->translator->translate($task->label()),
            'group' => $metadata?->group(),
            'order' => $metadata?->order() ?? 0,
            'draft_kind' => $descriptor->draftKind,
            'target_extension_point' => $descriptor->targetExtensionPoint,
            'target_provider_key' => $task instanceof TargetsExtensionPoint ? $task->targetProviderKey() : null,
            'risk_tier' => ($task instanceof DeclaresRiskTier ? $task->riskTier() : AiRiskTier::Medium)->value,
            'content_type' => $descriptor->contentType,
            'grounding_mode' => ($task instanceof RequiresGrounding ? $task->groundingMode() : AiGroundingMode::None)->value,
            'citation_unit' => $task instanceof RequiresGrounding ? $task->citationUnit() : null,
            'required_facts' => $task instanceof RequiresFacts ? $task->requiredFacts() : [],
            'input_schema' => $task instanceof DeclaresTaskInput ? $task->inputSchema() : null,
            'output_schema' => $task instanceof DeclaresTaskOutput ? $task->outputSchema() : null,
            'findings_only' => $task instanceof ProducesFindingsOnly,
            'available' => $routed,
            'unavailable_reason' => $routed ? null : 'not_configured',
        ];
    }

    /**
     * The descriptor a placement's predicate is asked about.
     *
     * Absences take their published defaults, which is what makes the safe
     * reading the one an author gets by writing nothing: no declared output is
     * display only, no declared content type is plain text, and no declared
     * extension point means the task writes nothing through any use case.
     */
    public static function descriptorOf(AiTaskInterface $task, string $surfaceKey, string $scopeLevel): TaskDescriptor
    {
        $metadata = $task instanceof TaskMetadataInterface ? $task : null;
        $surfaces = $metadata === null ? [] : array_values(array_unique(array_merge([$surfaceKey], $metadata->placements())));

        return TaskDescriptor::of(
            $task->key(),
            $surfaces === [] ? [$surfaceKey] : $surfaces,
            $task instanceof DeclaresTaskOutput ? $task->draftKind() : null,
            $scopeLevel,
            $task instanceof TargetsExtensionPoint ? $task->targetExtensionPoint() : null,
            ($task instanceof DeclaresContentType ? $task->contentType() : AiContentType::PlainText)->value,
        );
    }

    /**
     * Permission is per (placement, task) pair, `ai.<placement>.<task>`,
     * resolved through the existing access spine rather than one site-wide
     * word. That is what lets an organisation allow question generation to
     * instructors in one place and deny it in another with no new code.
     *
     * @return list<string>
     */
    private function permissions(AiPlacementInterface $placement, Principal $principal, ?int $courseId): array
    {
        // A learner holds the grants of the learner-facing placement and of no
        // other, because that panel is theirs. What they may actually run over
        // is still decided by the policy chain, which asks whether the attempt
        // has been submitted and marked.
        $learnerFacing = $placement->surfaceKey() === CoreAiSurface::LEARNER_PLAYER;

        if (!$this->access->mayAuthor($principal, $courseId) && !($learnerFacing && !$principal->isGuest())) {
            return [];
        }

        $granted = [];

        foreach ($this->registries->aiTasks->keys() as $taskKey) {
            $task = $this->registries->aiTasks->get($taskKey);
            if (!$task instanceof TaskMetadataInterface || !\in_array($placement->key(), $task->placements(), true)) {
                continue;
            }
            $granted[] = 'ai.' . $placement->key() . '.' . $taskKey;
        }

        return $granted;
    }

    /**
     * Which task keys have at least one enabled route. It is one scan rather
     * than a query per task, because a picker asks about every task at once.
     *
     * @return array<string, true>
     */
    private function tasksWithEnabledRoute(): array
    {
        $keys = [];

        foreach (
            $this->database->selectAll(
                Query::from(SqlAiReadModel::ROUTES)->select(['task_key'])->where('enabled', '=', 1),
            ) as $row
        ) {
            $key = (string) ($row['task_key'] ?? '');

            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return $keys;
    }

    private static function reason(bool $siteEnabled, bool $enabled, bool $stopped): string
    {
        if ($stopped) {
            return 'stopped';
        }

        if (!$siteEnabled) {
            return 'site_disabled';
        }

        if (!$enabled) {
            return 'placement_disabled';
        }

        return 'not_configured';
    }
}
