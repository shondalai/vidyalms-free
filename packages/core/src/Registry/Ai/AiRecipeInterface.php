<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A multi-step build, declared rather than written into the runner.
 *
 * A recipe is an ordered list of steps over other tasks, so a course build
 * that outlines, then drafts, then checks is a registration and not a
 * sequence a shipped class holds. A job holds one task key and the flagship
 * build runs three, and the two ways out were a core orchestrator hardcoding
 * the sequence, which makes a fourth step a core edit, or a primitive. This is
 * the primitive.
 *
 * Two methods and no more. A recipe declares its steps and the runner walks
 * them; anything a later recipe needs to say arrives as a capability
 * interface probed with instanceof, because this interface is shipped bytes
 * that third parties are invited to implement.
 */
interface AiRecipeInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The ordered steps, first to last.
     *
     * @return list<AiRecipeStep>
     */
    public function steps(): array;
}
