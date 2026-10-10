<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

interface FeatureProviderRepository
{
    /**
     * Upserts the (extension point, provider key) pair as active for the
     * given package.
     */
    public function record(
        string $extensionPoint,
        string $providerKey,
        string $package,
        ?string $conformanceResult = null,
        bool $flagged = false,
    ): void;

    /**
     * @return list<FeatureProvider>
     */
    public function listActive(): array;

    /**
     * Every recorded key of one extension point, active or not, mapped to the
     * package that registered it.
     *
     * Inactive rows are included on purpose: the reader is the removal
     * placeholder, and the package worth naming there is precisely the one
     * that has been uninstalled.
     *
     * @return array<string, string> provider key => package
     */
    public function packagesFor(string $extensionPoint): array;

    /**
     * Marks every provider of the package inactive and returns how many
     * rows changed.
     */
    public function markInactive(string $package): int;
}
