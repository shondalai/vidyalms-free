<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgUnitMoveContext;
use Shondalai\VidyaLMS\Core\Registry\OrgUnitMove\OrgUnitMovePolicyInterface;

/**
 * The free build's move: everything travels, including a unit-scoped role.
 *
 * A member moved from one unit to another arrives holding what they held,
 * their unit-scoped role now anchored on the unit they moved to. That is what
 * the free build already did, silently and with nothing recorded; what it did
 * not have was anywhere to say so, so a Business policy that drops a unit
 * manager's scope on the way out of a unit had nothing to register into.
 */
final class CarryEverythingUnitMove implements OrgUnitMovePolicyInterface
{
    public const KEY = 'carry_everything';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.unit_move.carry_everything';
    }

    public function carriesUnitScopedRole(OrgUnitMoveContext $context): bool
    {
        return true;
    }
}
