<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgVisibilityContext;
use Shondalai\VidyaLMS\Core\Registry\OrgVisibility\OrgVisibilityPolicyInterface;

/**
 * A package's own visibility policy, standing in for the Business one.
 *
 * It widens the organisation's view to rows the learner bought themselves,
 * which is the widening the design names, and it exists to prove the seam is
 * consulted rather than to be a shipped policy.
 */
final class WideningVisibilityPolicy implements OrgVisibilityPolicyInterface
{
    public const KEY = 'test_declared_purchases';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'test.visibility.declared_purchases';
    }

    public function visibleSources(OrgVisibilityContext $context): array
    {
        return ['purchase'];
    }
}
