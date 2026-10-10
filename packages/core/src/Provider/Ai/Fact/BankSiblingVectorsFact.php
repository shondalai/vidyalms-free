<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * The embedding vectors of the items a new item would sit beside, on a site
 * that has any.
 *
 * **On the free build it always answers the empty set, and that is the
 * honest answer rather than a stub.** Vectors are produced by a bridge
 * declaring an embedding capability, and the free build's only bridge reaches
 * no model, so there is nothing to return. The duplicate check reads the
 * emptiness and says on its finding that it compared by normalised exact
 * matching, which is the part that matters: a check that quietly degraded
 * would report a clean bank it never compared semantically.
 *
 * It ships free, and it is registered free, because a task declaring a fact
 * that nothing supplies is refused **at registration**. Leaving this
 * unregistered until the paid package arrives would take the duplicate check
 * out of the free build entirely, which is the opposite of what the free
 * build is for.
 */
final class BankSiblingVectorsFact implements AiFactProviderInterface
{
    public const KEY = 'bank.sibling_vectors';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.fact.bank_sibling_vectors.label';
    }

    public function resolvesToUsers(): FactCardinality
    {
        return FactCardinality::None;
    }

    /**
     * @return array<string, list<float>>
     */
    public function resolve(AiFactContext $context): mixed
    {
        return [];
    }
}
