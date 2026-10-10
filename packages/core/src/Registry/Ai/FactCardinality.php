<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * How many people a fact resolves to.
 *
 * It is on the provider rather than inferred from the value, because it is
 * what drives the run's subject write: a run that touched forty learners has
 * to be able to say so afterwards, and counting the rows a value happened to
 * contain would answer differently for a fact that legitimately resolved to
 * one person and a fact that resolved to one person this time.
 */
enum FactCardinality: string
{
    /** An item or course fact. Nobody is a subject of the run because of it. */
    case None = 'none';

    /** One named learner. */
    case One = 'one';

    /** A set, whose size the run records. */
    case Many = 'many';
}
