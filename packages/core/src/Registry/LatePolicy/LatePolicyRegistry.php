<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LatePolicy;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<LatePolicyInterface>
 */
final class LatePolicyRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'late_policy';

    /** The provider a miss falls back to, which the free build always ships. */
    public const FALLBACK_KEY = 'none';

    protected function providerInterface(): string
    {
        return LatePolicyInterface::class;
    }
}
