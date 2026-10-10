<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The action's params name ids that a course copy has to remap.
 *
 * Absent means a course copy does not remap ids inside the params and the
 * builder warns, because a silently unremapped id is a rule that fires against
 * the original course from inside the copy.
 *
 * The references are written into availability_rule_refs under owner_type
 * "automation_rule", which is the same table and the same save-time pass every
 * other owner already uses. *
 * The name collides deliberately with the availability namesake under
 * Registry\Availability. PHP namespaces keep the two apart and an author who
 * has met one has met the other, but a provider class implementing both an
 * availability condition and an automation action needs an aliased use
 * statement, so the collision is named here rather than discovered.
 */
interface DeclaresReferences
{
    /**
     * @param array<string, mixed> $params
     *
     * @return list<array{type: string, id: int}>
     */
    public function referencesIn(array $params): array;
}
