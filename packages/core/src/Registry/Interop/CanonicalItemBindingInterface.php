<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Interop;

use Shondalai\VidyaLMS\Core\Registry\Context\MapperContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The half of the anti-corruption layer that writes.
 *
 * Selecting a target is not the same as filling it, and a preset cannot fill
 * it: a preset carries a skeleton and its source aliases, and nothing in a
 * skeleton can write a foreign item's choices, correct keys, mapping entries,
 * coordinates, gap markers or tolerance band into a target type's own field
 * names. The alias picks the type; this writes it.
 *
 * A binding belongs to the type that owns the definition shape, which is why
 * it is keyed by the type key and why adding a format costs a type nothing: a
 * type that has a binding is importable and exportable from every registered
 * format at once, and a third-party type becomes so the day it registers a
 * preset and one of these.
 *
 * Neither direction throws. A binding that cannot carry something says so in
 * the result's warnings and writes what it can, because a lossy import that
 * lands with a visible warning list is far more useful to a migrating teacher
 * than a hard failure.
 */
interface CanonicalItemBindingInterface extends ProviderInterface
{
    /** The registered question type key this binding writes, which is its key. */
    public function typeKey(): string;

    /**
     * What this binding cannot carry, in the vocabulary of the canonical
     * lanes, so an importer can name it in the job log before it runs rather
     * than after: "shuffle", "mapping", "per_choice_match_max".
     *
     * @return list<string>
     */
    public function drops(): array;

    /**
     * Writes the resolved marking rule into a definition this binding wrote.
     *
     * On the binding rather than on the caller because the field is the
     * type's own: nineteen types keep the rule under "policy" and the
     * multi-part item keeps it under "aggregation", and a caller that assumed
     * one of them produced a definition the other refused.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $policy     {key, params}
     *
     * @return array<string, mixed>
     */
    public function applyPolicy(array $definition, array $policy): array;

    public function fromCanonical(CanonicalItem $item, MapperContext $context): BindingResult;

    /**
     * @param array<string, mixed> $definition
     */
    public function toCanonical(array $definition, MapperContext $context): CanonicalItem;
}
