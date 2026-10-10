<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\QuestionPreset;

use Shondalai\VidyaLMS\Core\Port\I18n\NeutralStrings;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\PresetContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionPreset\QuestionPresetInterface;

/**
 * A preset that is a plain record: the free build's six aliases of its own
 * types, and the shape a third party registers.
 *
 * One class rather than six, because a preset genuinely is data. A preset
 * that needed behaviour would implement the interface itself, which is the
 * seam the interface exists for.
 *
 * The label is a catalogue key resolved at read time through the translator,
 * never an English literal handed in at boot: the label reaches the author's
 * picker through GET /question-types, so it is copy and copy goes through the
 * port. A caller with no translator gets the neutral catalogue's English.
 */
final class AliasPreset implements QuestionPresetInterface
{
    /**
     * @param string               $labelKey           a TranslatorPort key, not a string to show
     * @param array<string, mixed> $definitionSkeleton
     * @param list<string>         $sourceAliases
     */
    public function __construct(
        private readonly string $key,
        private readonly string $labelKey,
        private readonly string $group,
        private readonly string $baseTypeKey,
        private readonly string $defaultPolicy,
        private readonly array $definitionSkeleton = [],
        private readonly array $sourceAliases = [],
        private readonly ?string $helpUrl = null,
        private readonly ?TranslatorPort $translator = null,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(PresetContext $context): string
    {
        return NeutralStrings::say($this->translator, $this->labelKey);
    }

    public function group(PresetContext $context): string
    {
        return $this->group;
    }

    public function baseTypeKey(PresetContext $context): string
    {
        return $this->baseTypeKey;
    }

    public function definitionSkeleton(PresetContext $context): array
    {
        return $this->definitionSkeleton;
    }

    public function defaultPolicy(PresetContext $context): string
    {
        return $this->defaultPolicy;
    }

    public function sourceAliases(PresetContext $context): array
    {
        return $this->sourceAliases;
    }

    public function helpUrl(PresetContext $context): ?string
    {
        return $this->helpUrl;
    }
}
