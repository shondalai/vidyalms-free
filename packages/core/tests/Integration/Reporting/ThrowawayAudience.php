<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;

/**
 * A fixed list of recipients, registered from a `KernelReady` listener.
 *
 * The three free audiences all resolve through the host: `site_managers` needs
 * the optional `HoldersOfCapability` capability that the test authorization
 * double deliberately does not implement, and the other two need a seeded
 * course. This exists so the per-recipient fan-out can be driven at all, and
 * registering it through the public `register()` a package would use is what
 * makes it proof that the seam works rather than a privileged path beside it.
 */
final class ThrowawayAudience implements AudienceResolverInterface
{
    public const KEY = 'throwaway_recipients';

    /** @param list<int> $recipients */
    public function __construct(private readonly array $recipients)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Throwaway recipients';
    }

    public function resolve(AudienceContext $context): iterable
    {
        return $this->recipients;
    }
}
