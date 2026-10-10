<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * A ledger row recording which package supplied a provider for an extension
 * point, so an uninstall can be reported to the admin instead of features
 * silently degrading.
 */
final class FeatureProvider
{
    public function __construct(
        public readonly string $extensionPoint,
        public readonly string $providerKey,
        public readonly string $package,
        public readonly bool $active,
        /** The conformance kit result the provider declared, when it declared one. */
        public readonly ?string $conformanceResult = null,
        /** True when the provider declared no verified conformance result. */
        public readonly bool $flagged = false,
    ) {
    }
}
