<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Authorization;

/**
 * The reverse direction of the authorization port: who holds a capability.
 *
 * An optional capability interface rather than a method on AuthorizationPort,
 * so no existing adapter fatals on upgrade. Adding a required method to a
 * shipped port turns a core upgrade into a white screen on every site whose
 * adapter has not been rebuilt in the same minute, which is a failure this
 * codebase has already produced once.
 *
 * The free site_managers audience needs it and nothing else in the core does:
 * AuthorizationPort exposes only a forward check for one user, UsersPort
 * cannot enumerate by capability, and the core never joins into CMS tables,
 * so there is not even a candidate set to loop over. Left unadded, every
 * adapter invents the lookup separately and they disagree about scope.
 *
 * Default when absent: the audience resolves empty and one degradation is
 * logged, which is the miss direction the audience registry already
 * publishes.
 */
interface HoldersOfCapability
{
    /**
     * @return list<int> user ids, paged, in a stable order
     */
    public function holdersOf(string $capability, ?string $resource, int $limit, int $offset): array;
}
