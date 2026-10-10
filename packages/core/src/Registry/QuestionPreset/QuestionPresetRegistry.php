<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionPreset;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\PresetContext;

/**
 * The presets the picker offers, over the types this site has.
 *
 * The miss: an unresolved preset is simply absent, because a preset is
 * authoring sugar and stores nothing. No row ever references a preset key,
 * which is exactly why it can disappear without consequence and why it is
 * the right place for teacher vocabulary.
 *
 * @extends AbstractProviderRegistry<QuestionPresetInterface>
 */
final class QuestionPresetRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return QuestionPresetInterface::class;
    }

    /**
     * The preset a foreign type identifier maps to, or null. This is what
     * makes adding an import mapping a data change rather than mapper code.
     */
    public function forSourceAlias(string $alias, ?PresetContext $context = null): ?QuestionPresetInterface
    {
        $context ??= PresetContext::neutral();

        foreach ($this->all() as $preset) {
            if (\in_array($alias, $preset->sourceAliases($context), true)) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * @return list<QuestionPresetInterface>
     */
    public function forBaseType(string $typeKey, ?PresetContext $context = null): array
    {
        $resolved = $context ?? PresetContext::neutral();

        return array_values(array_filter(
            $this->all(),
            static fn (QuestionPresetInterface $preset): bool => $preset->baseTypeKey($resolved) === $typeKey,
        ));
    }
}
