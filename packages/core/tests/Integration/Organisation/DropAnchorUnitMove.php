<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgUnitMoveContext;
use Shondalai\VidyaLMS\Core\Registry\OrgUnitMove\OrgUnitMovePolicyInterface;

/**
 * A package's own move policy: a unit-scoped role does not travel.
 *
 * The stricter of the two answers the design names, standing in for a Business
 * provider so the seam can be proven to be consulted.
 */
final class DropAnchorUnitMove implements OrgUnitMovePolicyInterface
{
    public const KEY = 'test_drop_unit_scope';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.unit_move.drop_unit_scope';
    }

    public function carriesUnitScopedRole(OrgUnitMoveContext $context): bool
    {
        return false;
    }
}
