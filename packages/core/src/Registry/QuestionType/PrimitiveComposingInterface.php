<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;

/**
 * Which of the eight interaction primitives a type is built from, and on
 * which of the three surfaces.
 *
 * Without it, core would have to know the mapping, and a hardcoded list of
 * the free six inside the attempt pipeline is exactly the fork this design
 * forbids: a third-party type would silently get no seeded shuffle, no
 * per-choice fixed handling and no constraint enforcement, and its author
 * would have no way to opt in short of a core release.
 *
 * A type that does not declare a primitive gets no presentation sequence,
 * which is the safe default rather than a wrong one.
 */
interface PrimitiveComposingInterface
{
    /** An InteractionPrimitiveRegistry key. */
    public function interactionPrimitive(): string;

    public function surface(): Surface;

    /**
     * Whether this item shuffles even when the quiz does not ask for it.
     *
     * Ordering says yes unless the author turns it off, because an item
     * authored with its steps already in key order otherwise pays full marks
     * to a learner who submits without touching it. Every other free type
     * says no and follows the quiz.
     *
     * It takes the presentation context for the reason every provider method
     * does: without one a type cannot consult the learner accessibility
     * profile or the seed when deciding, and adding the parameter after a
     * third-party type ships is a breaking change.
     *
     * @param array<string, mixed> $definition
     */
    public function shufflesByDefault(array $definition, PresentationContext $context): bool;
}
