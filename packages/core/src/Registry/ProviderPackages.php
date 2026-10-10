<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

/**
 * Which package supplied a provider key.
 *
 * The removal placeholder is required to name the missing package rather than
 * only the type key, and the "type unavailable" row in the grading queue says
 * the same thing. Both are readers of the feature provider ledger, and until
 * they existed the ledger was written on every request and read by nothing.
 *
 * The interface lives beside the registries rather than in either bounded
 * context, because the question it answers is about a provider key and not
 * about anybody's rows: Assessment asks it, Platform answers it, and neither
 * has to know the other's classes.
 */
interface ProviderPackages
{
    /**
     * The package that registered the key, active or not. An uninstalled
     * package's rows are exactly the ones worth naming, so an inactive row
     * still answers.
     */
    public function packageOf(string $extensionPoint, string $providerKey): ?string;
}
