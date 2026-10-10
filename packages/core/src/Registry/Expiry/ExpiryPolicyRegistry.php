<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Expiry;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * The expiry shapes this site can compute.
 *
 * The miss is the gentle direction and it is published here: an unresolved
 * policy means the credential never expires. Failing the other way would
 * expire every credential a site issued under a policy whose package was
 * removed, and a credential wrongly reported as lapsed is a credential
 * wrongly refused by an employer, a board or a regulator. A credential
 * wrongly reported as in force is corrected by revoking it, which is a
 * mechanism this design already has.
 *
 * @extends AbstractProviderRegistry<ExpiryPolicyInterface>
 */
final class ExpiryPolicyRegistry extends AbstractProviderRegistry
{
    /** What an unresolved key means, published once. */
    public const MISS_MEANS_NEVER_EXPIRES = true;

    protected function providerInterface(): string
    {
        return ExpiryPolicyInterface::class;
    }
}
