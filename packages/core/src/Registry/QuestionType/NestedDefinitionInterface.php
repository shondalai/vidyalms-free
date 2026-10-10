<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * The part definitions a composite stores inside its own JSON.
 *
 * A composite stores each part's complete definition, each with its own
 * schema_version, and those nested definitions have no questions row and
 * therefore no definition_version of their own. Without this, a version 2 to
 * 3 upgrade would find every standalone row by index and silently miss every
 * item embedded as a cloze, matrix, drop-down table or bowtie part, then hand
 * a stale definition to a provider that no longer supports it at grade time.
 *
 * The upgrade chain runs recursively through what this returns, on both the
 * lazy read path and the sweep, and questions.definition_version for a
 * composite is the minimum across the composite and all its parts, which is
 * the only value that keeps the index safe.
 *
 * The default when a provider does not implement this is no nested
 * definitions, which is right for every type that has none, and it is the
 * reason media sweeps and upgraders can be written once rather than per type.
 */
interface NestedDefinitionInterface
{
    /**
     * @param array<string, mixed> $definition
     *
     * Keyed by the path of the nested definition inside its parent.
     *
     * @return iterable<string, array{typeKey: string, definition: array<string, mixed>}>
     */
    public function nestedDefinitions(array $definition): iterable;
}
