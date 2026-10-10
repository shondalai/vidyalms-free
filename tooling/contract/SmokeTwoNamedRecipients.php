<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;

/**
 * An audience of exactly the people the smoke section names.
 *
 * A schedule takes its recipients as an audience rather than as a list of
 * addresses, because revoking somebody's role has to revoke their report and a
 * comma separated address field on an admin form is an unauthenticated mail
 * relay. This is the smallest provider that satisfies that contract, and it is
 * registered into the live kernel for the length of one section and never
 * shipped.
 *
 * It names two people on purpose, and they are deliberately unequal: one holds
 * the report's capability and one does not, so the section can prove both
 * halves of the per recipient rule against a real authorisation port rather
 * than against a stub that always says yes.
 */
final class SmokeTwoNamedRecipients implements AudienceResolverInterface
{
    public const KEY = 'vidyalms-smoke-two-named';

    /** @param list<int> $userIds */
    public function __construct(private readonly array $userIds)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Two named recipients (smoke)';
    }

    public function resolve(AudienceContext $context): iterable
    {
        yield from $this->userIds;
    }
}
