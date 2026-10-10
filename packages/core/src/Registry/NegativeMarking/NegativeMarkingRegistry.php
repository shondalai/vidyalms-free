<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\NegativeMarking;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * The negative marking rules this site can charge a wrong answer under.
 *
 * The miss is defined here, and it is the safe direction. An unresolved rule
 * means no penalty at all: negative marking is off for that attempt and the
 * substitution is recorded on the policy snapshot. Failing the other way
 * would charge a learner under a rule nobody can name, on a quiz whose
 * instructions promised a penalty this site can no longer describe.
 *
 * @extends AbstractProviderRegistry<NegativeMarkingRuleInterface>
 */
final class NegativeMarkingRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return NegativeMarkingRuleInterface::class;
    }

    public function resolve(?string $key): ?NegativeMarkingRuleInterface
    {
        if ($key === null || $key === '' || !$this->has($key)) {
            return null;
        }

        return $this->get($key);
    }
}
