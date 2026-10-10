<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;
use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderInterface;
use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderRegistry;

/**
 * A one-step upgrader that records the step it ran, so a chain can be
 * asserted rather than assumed.
 */
final class StubDefinitionUpgrader implements DefinitionUpgraderInterface
{
    public function __construct(
        private readonly string $typeKey,
        private readonly int $from,
        private readonly int $to,
    ) {
    }

    public function key(): string
    {
        return DefinitionUpgraderRegistry::keyFor($this->typeKey, $this->from, $this->to);
    }

    public function typeKey(): string
    {
        return $this->typeKey;
    }

    public function fromVersion(): int
    {
        return $this->from;
    }

    public function toVersion(): int
    {
        return $this->to;
    }

    public function upgrade(array $definition, UpgradeContext $context): array
    {
        $steps = $definition['steps'] ?? [];
        $definition['steps'] = [...(\is_array($steps) ? $steps : []), [$this->from, $this->to]];

        return $definition;
    }
}
