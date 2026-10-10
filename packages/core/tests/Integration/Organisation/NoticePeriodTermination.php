<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgMembershipTerminationContext;
use Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination\OrgMembershipTerminationPolicyInterface;

/**
 * A package's own closure policy: the membership closes and the access stays.
 *
 * The notice period a Business customer asks for, standing in for that
 * package's provider so the seam can be proven to be consulted.
 */
final class NoticePeriodTermination implements OrgMembershipTerminationPolicyInterface
{
    public const KEY = 'test_notice_period';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.membership_termination.notice_period';
    }

    public function releasesAccess(OrgMembershipTerminationContext $context): bool
    {
        return false;
    }
}
