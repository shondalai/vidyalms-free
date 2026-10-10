<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgType;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<OrgTypeInterface>
 */
final class OrgTypeRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return OrgTypeInterface::class;
    }

    /**
     * The label key for a stored type, or null when no provider resolves it.
     *
     * Null rather than a throw, because the published miss direction for this
     * point is the normal path: an organisation whose type came from a package
     * a site has since removed still reads, and the read says the type is
     * unresolved rather than hiding it or refusing.
     */
    public function labelFor(string $key): ?string
    {
        return $this->has($key) ? $this->get($key)->label() : null;
    }
}
