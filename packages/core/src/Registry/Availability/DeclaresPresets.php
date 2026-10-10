<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * The named restrictions a condition contributes to the Add restriction list.
 *
 * Default when absent: the condition offers no preset and is reachable only
 * through the raw condition palette.
 *
 * A preset is configuration over the same grammar, which is why the design
 * says a fifth preset is a data change and not code. It was a literal array
 * inside a shipped core class, so contributing one really was a core edit and
 * installing the paid package added no preset at all: the one authoring aid
 * the design says covers the great majority of real authoring could never
 * grow past what the free build shipped. Ownership sits with the condition
 * that the preset compiles into, exactly as the payload schema does.
 *
 * Each entry is `{key, label, anchor_key, fields}`; the condition key is the
 * declaring provider's own and is filled in by the catalogue, so a preset
 * cannot name a condition it does not belong to.
 */
interface DeclaresPresets
{
    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     anchor_key?: string|null,
     *     fields: list<array{key: string, type: string, label: string, required: bool}>
     * }>
     */
    public function declaredPresets(): array;
}
