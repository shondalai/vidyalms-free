<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CarryEverythingUnitMove;
use Shondalai\VidyaLMS\Core\Provider\Organisation\OrgSourcedOnlyVisibility;
use Shondalai\VidyaLMS\Core\Provider\Organisation\SuspendAndRetainTermination;
use Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination\OrgMembershipTerminationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination\OrgMembershipTerminationRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgUnitMove\OrgUnitMovePolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgUnitMove\OrgUnitMoveRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgVisibility\OrgVisibilityPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgVisibility\OrgVisibilityRegistry;

/**
 * Which of the three organisation policy seats this organisation is running.
 *
 * The three seams were seated, published in the edition table with their miss
 * directions, and consulted by absolutely nothing: a booted kernel counted
 * zero providers on each of them, no class implemented any of the three
 * interfaces, and nothing outside the registry files read them. A Business
 * registration into any of them was a silent no-op, which is worse than an
 * absent seam because the edition table says otherwise.
 *
 * The resolution order is the one {@see OrgScopeResolver::disclosureKeyFor()}
 * already established for field disclosure and is copied deliberately rather
 * than invented: the organisation's own setting, then the site's, then the
 * free key. A key naming a provider this site has not installed falls through
 * rather than resolving to nothing, because an organisation whose paid policy
 * was uninstalled should get the free behaviour and not no behaviour.
 *
 * Absent settings and absent registries answer null, and each caller applies
 * the miss direction its own interface publishes: fail closed for disclosure
 * and for closure, the stated substitution for a unit move.
 */
final class OrgPolicyResolution
{
    /** Settings names, both site-wide and suffixed with the organisation id. */
    public const VISIBILITY_SETTING = 'organisation.visibility_policy';

    public const TERMINATION_SETTING = 'organisation.membership_termination_policy';

    public const UNIT_MOVE_SETTING = 'organisation.unit_move_policy';

    public function __construct(
        private readonly ?OrgVisibilityRegistry $visibility = null,
        private readonly ?OrgMembershipTerminationRegistry $termination = null,
        private readonly ?OrgUnitMoveRegistry $unitMove = null,
        private readonly ?Settings $settings = null,
    ) {
    }

    public function visibilityFor(int $organisationId): ?OrgVisibilityPolicyInterface
    {
        $key = $this->keyFor($this->visibility, self::VISIBILITY_SETTING, $organisationId, OrgSourcedOnlyVisibility::KEY);
        $provider = $key === null || $this->visibility === null ? null : $this->visibility->get($key);

        return $provider instanceof OrgVisibilityPolicyInterface ? $provider : null;
    }

    public function terminationFor(int $organisationId): ?OrgMembershipTerminationPolicyInterface
    {
        $key = $this->keyFor(
            $this->termination,
            self::TERMINATION_SETTING,
            $organisationId,
            SuspendAndRetainTermination::KEY,
        );
        $provider = $key === null || $this->termination === null ? null : $this->termination->get($key);

        return $provider instanceof OrgMembershipTerminationPolicyInterface ? $provider : null;
    }

    public function unitMoveFor(int $organisationId): ?OrgUnitMovePolicyInterface
    {
        $key = $this->keyFor($this->unitMove, self::UNIT_MOVE_SETTING, $organisationId, CarryEverythingUnitMove::KEY);
        $provider = $key === null || $this->unitMove === null ? null : $this->unitMove->get($key);

        return $provider instanceof OrgUnitMovePolicyInterface ? $provider : null;
    }

    /**
     * @param OrgVisibilityRegistry|OrgMembershipTerminationRegistry|OrgUnitMoveRegistry|null $registry
     */
    private function keyFor(
        OrgVisibilityRegistry|OrgMembershipTerminationRegistry|OrgUnitMoveRegistry|null $registry,
        string $setting,
        int $organisationId,
        string $free,
    ): ?string {
        if ($registry === null) {
            return null;
        }

        if ($this->settings !== null) {
            foreach ([$setting . '.' . $organisationId, $setting] as $name) {
                $chosen = $this->settings->getString($name);

                if ($chosen !== '' && $registry->has($chosen)) {
                    return $chosen;
                }
            }
        }

        return $registry->has($free) ? $free : null;
    }
}
