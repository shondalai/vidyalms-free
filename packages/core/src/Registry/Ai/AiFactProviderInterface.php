<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One named learner or item fact a task may declare a need for.
 *
 * A fact is a registered provider rather than a name the core knows, which is
 * what lets a vendor ship a nineteenth without a core edit, and what makes a
 * task declaring a fact nothing supplies a registration-time refusal rather
 * than a run-time surprise.
 */
interface AiFactProviderInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * How many people this fact resolves to, which is what drives the run's
     * subject write. It is declared rather than counted from the value,
     * because counting would answer differently for a fact that legitimately
     * resolved to one person and a fact that resolved to one person today.
     */
    public function resolvesToUsers(): FactCardinality;

    /**
     * The value, resolved under the asking Principal.
     *
     * This is the context that carries a database handle, and it is the
     * reason AiTaskContext does not have to: a fact is resolved here and
     * handed to the task as a value, so a task can never read a fact it did
     * not declare.
     */
    public function resolve(AiFactContext $context): mixed;
}
