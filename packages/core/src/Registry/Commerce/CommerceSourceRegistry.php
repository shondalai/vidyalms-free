<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Commerce;

use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Where a commerce source registers, and where the reserved organisation
 * prefix stops being a convention.
 *
 * `SourceRef` reserves `org{id}` for the organisation family and says the
 * prefix "is enforced rather than described". It was enforced only inside
 * `SourceRef::compose()`, which the shipped host bridges do not call, so a
 * third-party source keyed `org7` seated cleanly and could mint into the
 * organisation namespace. The guard belongs here, at the one door every
 * source comes through, rather than at a composer some sources never use.
 *
 * @extends AbstractProviderRegistry<CommerceSourceInterface>
 */
final class CommerceSourceRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return CommerceSourceInterface::class;
    }

    protected function guardProvider(ProviderInterface $provider): void
    {
        SourceRef::requireUnreservedKey($provider->key());
    }
}
