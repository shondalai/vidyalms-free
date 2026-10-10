<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\FieldDisclosure;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\FieldDisclosureContext;

/**
 * @extends AbstractProviderRegistry<FieldDisclosurePolicyInterface>
 */
final class FieldDisclosureRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return FieldDisclosurePolicyInterface::class;
    }

    /**
     * The decision, with the published miss direction applied here rather than
     * in each caller.
     *
     * An unresolvable policy key omits the field. It is the same shape
     * {@see \Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryRegistry::deliverThrough()}
     * uses and for the same reason: a miss direction implemented at each call
     * site is a miss direction the eleventh call site gets backwards, and the
     * backwards answer here is disclosing a column nobody decided to disclose.
     *
     * It fails closed even though the free build always registers `org_minimal`,
     * because the reachable failure is not an absent free provider: it is an
     * organisation whose stored disclosure key names a Business policy after
     * the Business package is uninstalled. The columns that policy widened
     * disappear, which is the right direction, and nothing silently reverts to
     * the free set under a key that no longer resolves.
     */
    public function decideThrough(?string $key, FieldDisclosureContext $context): string
    {
        if ($key === null || $key === '' || !$this->has($key)) {
            return FieldDisclosureDecision::OMIT;
        }

        return FieldDisclosureDecision::of($this->get($key)->decide($context));
    }
}
