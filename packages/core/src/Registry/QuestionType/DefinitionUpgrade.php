<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderRegistry;

/**
 * The version chain, run: the registered upgraders for a type, then the same
 * walk recursively through every nested definition a container holds.
 *
 * Both halves existed and neither ran. `DefinitionUpgraderRegistry` composed
 * chains that nothing resolved, and `NestedDefinitionInterface` was
 * implemented with no consumer anywhere, while the one upgrade path called
 * the provider's own single-method `upgradeDefinition()` and never recursed.
 * The group seam invites a family to register an upgrader, so a vendor who
 * bumped a type to version 2 and shipped one as instructed got a green build,
 * a ledger row, and an upgrader that never executed: every stored v1 row kept
 * definition_version 1, and DefinitionVersionAwareInterface then routed all
 * of them to the placeholder at grade time. Data survives, marks do not,
 * which is the exact failure the registry-miss contract exists to prevent,
 * caused by the mechanism written to prevent it.
 *
 * **Order: the container first, then its parts.** A container's own upgrader
 * may move, rename or re-key the list its parts live in, so walking the parts
 * before it would walk the old shape.
 *
 * **The version a container reports is the minimum across itself and every
 * part**, which the design states is the only value that keeps the
 * questions.definition_version index safe: a batch upgrade looking for rows
 * below version 3 has to find a version 3 composite whose cloze part is still
 * at 2.
 */
final class DefinitionUpgrade
{
    /** A definition nests no deeper than this before it is treated as a cycle. */
    private const MAX_DEPTH = 8;

    private function __construct()
    {
    }

    /**
     * The definition brought up to the shape the resolved providers write,
     * with the version of the body that comes back.
     *
     * An unregistered key, or a chain that cannot be completed, leaves the
     * definition exactly as stored: applying half an upgrade produces a shape
     * no version of the provider understands, and the version gate then
     * routes the row to the placeholder rather than to a formula that cannot
     * read it.
     *
     * @param array<string, mixed> $definition
     *
     * @return array{array<string, mixed>, int}
     */
    public static function apply(
        QuestionTypeRegistry $types,
        DefinitionUpgraderRegistry $upgraders,
        string $typeKey,
        array $definition,
        int $storedVersion,
        int $depth = 0,
    ): array {
        if ($depth >= self::MAX_DEPTH || !$types->has($typeKey)) {
            return [$definition, $storedVersion];
        }

        $provider = $types->get($typeKey);
        $target = $provider->definitionVersion();

        if ($storedVersion < $target) {
            $upgraded = $upgraders->upgrade($typeKey, $definition, $storedVersion, $target);

            if ($upgraded !== $definition) {
                $definition = $upgraded;
                $storedVersion = $target;
            } else {
                // No chain, so the provider's own single-step upgrade is the
                // only route left. It is the shipped contract for the free
                // six and predates the registry.
                $definition = $provider->upgradeDefinition($definition, $storedVersion);
                $storedVersion = $target;
            }
        }

        if (!$provider instanceof NestedDefinitionInterface) {
            return [$definition, $storedVersion];
        }

        $lowest = $storedVersion;

        foreach ($provider->nestedDefinitions($definition) as $path => $nested) {
            [$part, $partVersion] = self::apply(
                $types,
                $upgraders,
                $nested['typeKey'],
                $nested['definition'],
                self::versionIn($nested['definition']),
                $depth + 1,
            );

            $definition = self::setAt($definition, explode('.', $path), $part);
            $lowest = min($lowest, $partVersion);
        }

        return [$definition, $lowest];
    }

    /**
     * The version a stored definition reports, which for a container is the
     * minimum across itself and every part it holds.
     *
     * Written into questions.definition_version at save. The column used to
     * carry the container's own version alone, so a batch upgrade indexing on
     * it could not find a current composite holding a stale part.
     *
     * @param array<string, mixed> $definition
     */
    public static function versionOf(
        QuestionTypeRegistry $types,
        string $typeKey,
        array $definition,
        int $depth = 0,
    ): int {
        if (!$types->has($typeKey)) {
            return self::versionIn($definition);
        }

        $provider = $types->get($typeKey);
        $version = $provider->definitionVersion();

        if ($depth >= self::MAX_DEPTH || !$provider instanceof NestedDefinitionInterface) {
            return $version;
        }

        foreach ($provider->nestedDefinitions($definition) as $nested) {
            $version = min($version, self::versionOf($types, $nested['typeKey'], $nested['definition'], $depth + 1));
        }

        return $version;
    }

    /**
     * The schema_version a definition states, defaulting to 1, which is what
     * a definition written before the field existed is.
     *
     * @param array<string, mixed> $definition
     */
    public static function versionIn(array $definition): int
    {
        $declared = $definition['schema_version'] ?? null;

        return \is_int($declared) && $declared > 0 ? $declared : 1;
    }

    /**
     * Writes a nested definition back at the dotted path the container named
     * it under. A path that does not resolve leaves the document untouched,
     * because a container that named a path it does not hold is a provider
     * bug rather than a reason to invent structure.
     *
     * @param array<array-key, mixed> $node
     * @param list<string>            $path
     * @param array<string, mixed>    $value
     *
     * @return array<array-key, mixed>
     */
    private static function setAt(array $node, array $path, array $value): array
    {
        $head = array_shift($path);

        if ($head === null) {
            return $value;
        }

        $key = ctype_digit($head) && \array_key_exists((int) $head, $node) ? (int) $head : $head;

        if (!\array_key_exists($key, $node)) {
            return $node;
        }

        if ($path === []) {
            $node[$key] = $value;

            return $node;
        }

        $child = $node[$key];

        if (!\is_array($child)) {
            return $node;
        }

        $node[$key] = self::setAt($child, $path, $value);

        return $node;
    }
}
