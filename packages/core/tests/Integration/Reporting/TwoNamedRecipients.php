<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;

/**
 * An audience of exactly the people the test names.
 *
 * A schedule takes recipients as an audience rather than as a list of
 * addresses, because revoking somebody's role has to revoke their report, and
 * a comma-separated address field in an admin form is an unauthenticated mail
 * relay. This is the smallest thing that satisfies that contract for a test.
 */
final class TwoNamedRecipients implements AudienceResolverInterface
{
    public const KEY = 'test-two-named';

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
        return 'Two named recipients (test)';
    }

    public function resolve(AudienceContext $context): iterable
    {
        yield from $this->userIds;
    }
}
