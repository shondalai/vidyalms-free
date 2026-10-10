<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The action can read params written at more than one version.
 *
 * Absent means [1], and a params document at any other version is a miss
 * treated exactly as an unregistered action key is: the rule is degraded and
 * the screen names it. That rule carries over verbatim from the registry miss
 * table, because a paid package decorating a free action through replace()
 * means removing the package re-registers the free provider under the same key
 * and a payload written by the paid version reaches an implementation that
 * cannot read its own fields. *
 * The name collides deliberately with the availability namesake under
 * Registry\Availability. PHP namespaces keep the two apart and an author who
 * has met one has met the other, but a provider class implementing both an
 * availability condition and an automation action needs an aliased use
 * statement, so the collision is named here rather than discovered.
 */
interface SupportsPayloadVersions
{
    /** @return list<int> */
    public function supportedParamsVersions(): array;
}
