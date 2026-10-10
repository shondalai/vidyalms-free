<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * A feature provider repository that can say how many of a package's rows
 * are recorded inactive.
 *
 * A narrow capability interface rather than a method on
 * FeatureProviderRepository, because a package may hand the kernel a
 * repository of its own through the Repositories bundle and a method added
 * to a shipped interface breaks every one of those.
 *
 * The ledger uses it to keep one promise it could not otherwise keep: a
 * package that is installed has no inactive rows. Without it the promise
 * held only when the uninstall path ran to completion, and a site that
 * reached the inactive state any other way (a half-finished uninstall, a
 * database restored from before one, two packages racing on boot) stayed
 * there for good, because the key-set signature had not moved and sync()
 * therefore wrote nothing on every later boot.
 *
 * Default when absent: the ledger trusts the signature alone, which is the
 * behaviour it had before this existed.
 */
interface InactiveProviderCounting
{
    public function countInactive(string $package): int;
}
