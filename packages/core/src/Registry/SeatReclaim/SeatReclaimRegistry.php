<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatReclaim;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatReclaimContext;
use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<SeatReclaimInterface>
 */
final class SeatReclaimRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return SeatReclaimInterface::class;
    }

    /**
     * Whether this pool's policy allows the reclaim in front of it.
     *
     * An unresolved key answers false, which is the fail-closed direction and
     * the only decision this method makes on its own.
     *
     * **The completed guard used to live here and does not any more.** It
     * answered false before the registry had asked anything, so a registered
     * provider was never consulted about a finished seat and the free `always`
     * provider was overridden by its own lookup table. The guard is real and
     * it stays, but it is a seat invariant rather than a policy: it now sits
     * in {@see \Shondalai\VidyaLMS\Core\Application\Organisation\SeatLifecycle::release()}
     * beside the other seat invariants, and this registry is a pure lookup
     * again.
     */
    public function allows(string $key, SeatReclaimContext $context): bool
    {
        if (!$this->has($key)) {
            return false;
        }

        return $this->get($key)->allows($context);
    }
}
