<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * The version chains this site can run.
 *
 * The free build registers none: the six free types have never changed
 * shape. The registry ships anyway, because a seam only Pro uses is a seam
 * nobody tests, and because the miss has to be defined before a chain
 * exists: an unresolved upgrader leaves the definition exactly as stored,
 * and the definition version gate then routes that row to the placeholder
 * rather than to a grading formula that cannot read it.
 *
 * @extends AbstractProviderRegistry<DefinitionUpgraderInterface>
 */
final class DefinitionUpgraderRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return DefinitionUpgraderInterface::class;
    }

    public static function keyFor(string $typeKey, int $fromVersion, int $toVersion): string
    {
        return sprintf('%s:%d:%d', $typeKey, $fromVersion, $toVersion);
    }

    /**
     * The chain from $fromVersion to $toVersion, or an empty list when it
     * cannot be completed. Partial chains are never returned: applying half
     * an upgrade produces a shape no version of the provider understands.
     *
     * @return list<DefinitionUpgraderInterface>
     */
    public function chainFor(string $typeKey, int $fromVersion, int $toVersion): array
    {
        if ($fromVersion >= $toVersion) {
            return [];
        }

        $chain = [];
        $version = $fromVersion;

        while ($version < $toVersion) {
            $step = $this->stepFrom($typeKey, $version, $toVersion);

            if ($step === null) {
                return [];
            }

            $chain[] = $step;
            $version = $step->toVersion();
        }

        return $chain;
    }

    /**
     * Runs the chain, or returns the definition untouched when the chain is
     * incomplete.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function upgrade(string $typeKey, array $definition, int $fromVersion, int $toVersion, ?string $nestedPath = null): array
    {
        foreach ($this->chainFor($typeKey, $fromVersion, $toVersion) as $upgrader) {
            $definition = $upgrader->upgrade(
                $definition,
                UpgradeContext::create($typeKey, $upgrader->fromVersion(), $nestedPath),
            );
        }

        return $definition;
    }

    private function stepFrom(string $typeKey, int $fromVersion, int $toVersion): ?DefinitionUpgraderInterface
    {
        // Prefer the longest step that does not overshoot, so a provider may
        // ship a 1-to-3 shortcut beside 1-to-2 and 2-to-3.
        $best = null;

        foreach ($this->all() as $upgrader) {
            if ($upgrader->typeKey() !== $typeKey || $upgrader->fromVersion() !== $fromVersion) {
                continue;
            }

            if ($upgrader->toVersion() > $toVersion) {
                continue;
            }

            if ($best === null || $upgrader->toVersion() > $best->toVersion()) {
                $best = $upgrader;
            }
        }

        return $best;
    }
}
