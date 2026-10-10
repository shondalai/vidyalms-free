<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionPreset;

use Shondalai\VidyaLMS\Core\Registry\Context\PresetContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A named authoring shortcut over an existing type. It creates no provider
 * key, stores no new value in questions.type and adds no code path.
 *
 * This is the layer that lets the picker show teacher vocabulary while
 * questions.type keeps the algebra key, and it is the direct fix for the
 * failure every surveyed product committed: Moodle has three calculated
 * types for one idea, Blackboard has two fill-in-the-blank types, Canvas
 * shipped three blank types before merging them.
 *
 * source_aliases is also how an importer selects a target without growing
 * mapper code. Selecting a target is not the same as filling it: a preset
 * carries the skeleton and the aliases, and the type's own canonical binding
 * does the writing.
 *
 * **Every accessor takes a context.** Without one, label() has to be resolved
 * to a string at boot and a preset can never be localised per request, which
 * is the picker answering in whichever language the kernel happened to be
 * built in. The rule is applied to all seven rather than only to label(),
 * because a context parameter is the only signature shape that grows later
 * without breaking an implementation somebody else ships, and nothing outside
 * this repository implements this interface yet, so the cost of doing it now
 * is zero.
 */
interface QuestionPresetInterface extends ProviderInterface
{
    public function label(PresetContext $context): string;

    /** One of ReportingMetadataInterface's eight groups. */
    public function group(PresetContext $context): string;

    /** The registered question type key this preset compiles to. */
    public function baseTypeKey(PresetContext $context): string;

    /**
     * A partial definition the authoring form starts from. It must validate
     * against the base type's schema, which a self-test asserts rather than
     * a reviewer.
     *
     * @return array<string, mixed>
     */
    public function definitionSkeleton(PresetContext $context): array;

    /**
     * The scoring policy key a question created from this preset gets. It
     * must appear in the base type's supported list, which the same
     * self-test asserts.
     */
    public function defaultPolicy(PresetContext $context): string;

    /**
     * Foreign type identifiers this preset is the target for, such as
     * "moodle:match" or "cq:sequence".
     *
     * @return list<string>
     */
    public function sourceAliases(PresetContext $context): array;

    public function helpUrl(PresetContext $context): ?string;
}
