<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * One step of a declared multi-task build.
 *
 * A job holds one task key and the flagship build runs three, so the two ways
 * out were a core orchestrator hardcoding the sequence, which makes a fourth
 * step a core edit, or a primitive. This is the primitive: a step names the
 * task, the draft kind of the previous step it fans out over, how a parent
 * draft becomes the child's subject, and whether it may be skipped when the
 * previous step produced nothing.
 */
final class AiRecipeStep
{
    /** @var array<string, string> */
    public readonly array $subjectFromParent;

    /**
     * @param array<string, string> $subjectFromParent parent draft field to child subject field
     */
    private function __construct(
        public readonly string $taskKey,
        public readonly ?string $fansOutOverDraftKind,
        array $subjectFromParent,
        public readonly bool $skippableWhenEmpty,
    ) {
        $this->subjectFromParent = $subjectFromParent;
    }

    /** The first step of a recipe, which fans out over nothing. */
    public static function first(string $taskKey): self
    {
        return new self($taskKey, null, [], false);
    }

    /**
     * @param array<string, string> $subjectFromParent
     */
    public static function over(
        string $taskKey,
        string $draftKind,
        array $subjectFromParent = [],
        bool $skippableWhenEmpty = true,
    ): self {
        return new self($taskKey, $draftKind, $subjectFromParent, $skippableWhenEmpty);
    }
}
