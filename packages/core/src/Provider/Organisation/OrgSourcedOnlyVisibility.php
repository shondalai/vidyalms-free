<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgVisibilityContext;
use Shondalai\VidyaLMS\Core\Registry\OrgVisibility\OrgVisibilityPolicyInterface;

/**
 * The free build's disclosure answer: an organisation sees what it granted and
 * nothing else.
 *
 * A learner who bought a course with their own money has bought it with their
 * own money, and an employer reading the organisation's learning list is shown
 * the rows the organisation sourced. That is what the free build has always
 * done; what it did not have was anywhere to say so, so a Business policy
 * widening it to rows the learner declared had nothing to register into that
 * anything read.
 *
 * It answers the empty list rather than `['organisation']`, because the
 * organisation's own rows are not a disclosure decision: the reader narrows on
 * them whatever any policy says, and a policy able to remove them would be a
 * policy able to blind an organisation to its own grants.
 */
final class OrgSourcedOnlyVisibility implements OrgVisibilityPolicyInterface
{
    public const KEY = 'org_sourced_only';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.visibility.org_sourced_only';
    }

    public function visibleSources(OrgVisibilityContext $context): array
    {
        return [];
    }
}
