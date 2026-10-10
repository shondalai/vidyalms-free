<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * The version half of QuestionTypeInterface, for a provider whose definition
 * shape has never changed.
 *
 * A trait rather than an abstract base: every provider here is final, takes
 * its own constructor dependencies and implements one interface, and a base
 * class would put a second thing in the way of all three for the sake of two
 * default methods.
 *
 * A provider that changes its shape stops using this, raises the number and
 * writes the upgrade. Nothing else has to know.
 */
trait DefaultDefinitionVersion
{
    public function definitionVersion(): int
    {
        return 1;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function upgradeDefinition(array $definition, int $fromVersion): array
    {
        return $definition;
    }
}
