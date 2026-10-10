<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityRule;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionCapabilities;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresPresets;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\ProviderEdition;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;

/**
 * Everything the restriction authoring form renders itself from.
 *
 * Presentation order is declared and never inherited: a provider replacing a
 * free one keeps the displaced key's place, so a decorated condition does not
 * jump to the top of the list and split its family. Registration order is not
 * the author's picker order, and treating it as one is a mistake recorded
 * once in this codebase already.
 *
 * The presets are configuration over the same grammar and compile into an
 * ordinary tree, so a fifth preset is a data change and not code, and nothing
 * a preset produces is unreachable from the raw editor. Exposing the raw
 * condition list and nothing else is precisely why relative-date dripping
 * needs a plugin and a forum thread elsewhere; offering three named modes an
 * author cannot extend is the opposite failure.
 */
final class AvailabilityCatalogue
{
    public function __construct(
        private readonly AvailabilityConditionRegistry $conditions,
        private readonly ScheduleAnchorRegistry $anchors,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, ?string $surface = null): array
    {
        $this->guard->requireAny($principal, Capability::TEACH, Capability::MANAGE, Capability::ADMIN);

        $wanted = $surface === null ? null : AvailabilitySurface::tryFrom($surface);

        return [
            'conditions' => $this->conditionRows($wanted),
            'anchors' => $this->anchorRows(),
            'presets' => $this->presetRows(),
            'default_visibility' => AvailabilityRule::VISIBILITY_SHOWN,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function conditionRows(?AvailabilitySurface $surface): array
    {
        $rows = [];

        foreach ($this->conditions->all() as $condition) {
            $applicability = array_map(
                static fn (AvailabilitySurface $case): string => $case->value,
                $condition->applicability(),
            );

            if ($surface !== null && !\in_array($surface->value, $applicability, true)) {
                continue;
            }

            $capabilities = ConditionCapabilities::of($condition);
            $metadata = $condition instanceof ConditionMetadataInterface ? $condition : null;
            $versions = $condition instanceof SupportsPayloadVersions ? $condition->supportedPayloadVersions() : [1];

            $rows[] = [
                'key' => $condition->key(),
                'label' => $this->translator->translate($condition->label()),
                'description' => null,
                'group' => $metadata?->group() ?? ConditionMetadataInterface::GROUP_SCHEDULE,
                'order' => $metadata?->order() ?? 1000,
                'applicability' => $applicability,
                'edition' => ProviderEdition::of(
                    $this->packages,
                    AvailabilityConditionRegistry::EXTENSION_POINT,
                    $condition->key(),
                ),
                'available' => true,
                'advisory' => $metadata?->advisory() ?? false,
                'names_opens_at' => \in_array(ConditionCapabilities::NAMES_OPENS_AT, $capabilities, true),
                'filters_learners' => \in_array(ConditionCapabilities::FILTERS_LEARNERS, $capabilities, true),
                'schema_version' => $condition->currentShape(),
                'supported_payload_versions' => $versions,
                'params_schema' => $metadata?->paramsSchema() ?? ['type' => 'object'],
                'capabilities' => $capabilities,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$a['group'], $a['order'], $a['label']] <=> [$b['group'], $b['order'], $b['label']];
        });

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anchorRows(): array
    {
        $rows = [];

        foreach ($this->anchors->all() as $anchor) {
            $rows[] = [
                'key' => $anchor->key(),
                'label' => $this->translator->translate($anchor->label()),
                'description' => null,
                'edition' => ProviderEdition::of(
                    $this->packages,
                    ScheduleAnchorRegistry::EXTENSION_POINT,
                    $anchor->key(),
                ),
                'available' => true,
            ];
        }

        return $rows;
    }

    /**
     * The named restrictions the Add restriction list offers, and the fields
     * each one asks for.
     *
     * A preset whose condition or anchor is not registered is listed as
     * unavailable rather than hidden, so an author who used it before a
     * package was removed sees why the row stopped working.
     *
     * @return list<array<string, mixed>>
     */
    private function presetRows(): array
    {
        $presets = [];

        // Contributions, in the registry's own presentation order, so a
        // package that registers a condition brings its presets with it and
        // a sixth preset is never an edit to this file. `replace()` keeps a
        // displaced key's position, so a decorated free condition keeps its
        // presets where the author last saw them.
        foreach ($this->conditions->all() as $condition) {
            if (!$condition instanceof DeclaresPresets) {
                continue;
            }

            foreach ($condition->declaredPresets() as $preset) {
                $presets[] = [
                    'key' => $preset['key'],
                    'label' => $preset['label'],
                    'condition_key' => $condition->key(),
                    'anchor_key' => $preset['anchor_key'] ?? null,
                    'fields' => $preset['fields'],
                ];
            }
        }

        foreach ($this->legacyPresets() as $preset) {
            $presets[] = $preset;
        }

        $rows = [];
        $seen = [];

        foreach ($presets as $preset) {
            $conditionKey = $preset['condition_key'];
            $anchorKey = $preset['anchor_key'];

            if (isset($seen[$preset['key']])) {
                continue;
            }

            $seen[$preset['key']] = true;

            $rows[] = $this->translatePresetMetadata($preset) + [
                'description' => null,
                'available' => $this->conditions->has($conditionKey)
                    && ($anchorKey === null || $this->anchors->has($anchorKey)),
            ];
        }

        return $rows;
    }

    /**
     * Translate presentation copy without changing values compiled into a rule.
     * Providers can also supply literal copy, which must not be humanised by a CMS translator.
     *
     * @param array<array-key, mixed> $metadata
     * @return array<array-key, mixed>
     */
    private function translatePresetMetadata(array $metadata): array
    {
        foreach (['label', 'description', 'hint', 'placeholder', 'unavailable_reason'] as $property) {
            $value = $metadata[$property] ?? null;

            if (\is_string($value) && str_starts_with($value, 'vidyalms.')) {
                $metadata[$property] = $this->translator->translate($value);
            }
        }

        foreach (['fields', 'options'] as $children) {
            if (!\is_array($metadata[$children] ?? null)) {
                continue;
            }

            foreach ($metadata[$children] as $key => $child) {
                if (\is_array($child)) {
                    $metadata[$children][$key] = $this->translatePresetMetadata($child);
                }
            }
        }

        return $metadata;
    }

    /**
     * The presets whose condition has not yet moved them onto itself.
     *
     * They are listed here so a build mid-migration still offers every named
     * restriction, and a condition that declares its own wins by key: the
     * contribution loop runs first and a duplicate key from this list is
     * skipped.
     *
     * @return list<array<string, mixed>>
     */
    private function legacyPresets(): array
    {
        return [
            [
                'key' => 'date_from',
                'label' => 'vidyalms.availability.preset.date_from',
                'condition_key' => 'date',
                'anchor_key' => null,
                'fields' => [
                    ['key' => 'from', 'type' => 'datetime', 'label' => 'vidyalms.availability.preset.field.from', 'required' => true],
                    ['key' => 'tz', 'type' => 'timezone', 'label' => 'vidyalms.availability.preset.field.tz', 'required' => false],
                ],
            ],
            [
                'key' => 'date_until',
                'label' => 'vidyalms.availability.preset.date_until',
                'condition_key' => 'date',
                'anchor_key' => null,
                'fields' => [
                    ['key' => 'until', 'type' => 'datetime', 'label' => 'vidyalms.availability.preset.field.until', 'required' => true],
                    ['key' => 'tz', 'type' => 'timezone', 'label' => 'vidyalms.availability.preset.field.tz', 'required' => false],
                ],
            ],
            [
                'key' => 'after_item',
                'label' => 'vidyalms.availability.preset.after_item',
                'condition_key' => 'completion',
                'anchor_key' => null,
                'fields' => [
                    ['key' => 'target', 'type' => 'item', 'label' => 'vidyalms.availability.preset.field.target', 'required' => true],
                ],
            ],
            [
                'key' => 'score_at_least',
                'label' => 'vidyalms.availability.preset.score_at_least',
                'condition_key' => 'score',
                'anchor_key' => null,
                'fields' => [
                    ['key' => 'target', 'type' => 'item', 'label' => 'vidyalms.availability.preset.field.target', 'required' => true],
                    ['key' => 'min', 'type' => 'number', 'label' => 'vidyalms.availability.preset.field.min', 'required' => true],
                ],
            ],
            [
                'key' => 'days_after_enrolment',
                'label' => 'vidyalms.availability.preset.days_after_enrolment',
                'condition_key' => 'relative_date',
                'anchor_key' => 'enrolment.enrolled_at',
                'fields' => [
                    [
                        'key' => 'offset_seconds',
                        'type' => 'number',
                        'label' => 'vidyalms.availability.preset.field.offset',
                        'required' => true,
                    ],
                    [
                        // A unit and not a whole-day count: one day means
                        // twenty-four hours after the anchor in one reading
                        // and the next calendar day in another, and both are
                        // authorable here so neither is a surprise.
                        'key' => 'offset_unit',
                        'type' => 'select',
                        'label' => 'vidyalms.availability.preset.field.offset_unit',
                        'required' => true,
                    ],
                ],
            ],
        ];
    }
}
