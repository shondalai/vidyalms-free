<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Recipe;

use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckAccessibility;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\CheckInjectionMarkers;
use Shondalai\VidyaLMS\Core\Provider\Ai\Task\OutlineFromHeadings;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeStep;

/**
 * The free multi-step build: read the structure out of a document, then check
 * what came back.
 *
 * It exists to prove the primitive rather than to be impressive, and proving
 * the primitive is the point. A job holds one task key and a real build runs
 * several, so the two ways out were a core orchestrator hardcoding the
 * sequence, which makes a fourth step a core edit, or a registered recipe.
 * This is the registered recipe, and the flagship paid build is the same
 * shape with model-backed steps in it.
 *
 * Every step here runs over the null bridge, so the whole recipe costs
 * nothing and reaches nothing, and a free site can watch a multi-step job
 * queue, run, log and produce reviewable drafts before it has bought
 * anything.
 */
final class StructuralBuildRecipe implements AiRecipeInterface
{
    public const KEY = 'outline.structural_build';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.recipe.structural_build.label';
    }

    public function steps(): array
    {
        return [
            // The outline first, because everything after it fans out over
            // what it produced.
            AiRecipeStep::first(OutlineFromHeadings::KEY),

            // Then the two checks that read a source rather than a bank, over
            // each drafted node. Both are skippable when the first step found
            // no headings, which is the ordinary answer for a document that
            // was never structured.
            AiRecipeStep::over(
                CheckInjectionMarkers::KEY,
                OutlineFromHeadings::DRAFT_KIND,
                ['title' => 'subject_title'],
            ),
            AiRecipeStep::over(
                CheckAccessibility::KEY,
                OutlineFromHeadings::DRAFT_KIND,
                ['title' => 'subject_title'],
            ),
        ];
    }
}
