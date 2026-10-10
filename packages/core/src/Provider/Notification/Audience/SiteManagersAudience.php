<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Audience;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Port\Authorization\HoldersOfCapability;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;

/**
 * Everyone holding the manage capability.
 *
 * It needs the reverse direction of the authorization port, which is an
 * optional capability interface rather than a method on the port: the port
 * exposes only a forward check for one user, the people port cannot enumerate
 * by capability, and the core never joins into CMS tables, so there is not
 * even a candidate set to loop over.
 *
 * When the host does not implement it the audience resolves empty and one
 * degradation is logged, which is the miss direction the audience registry
 * publishes: resolves empty, one suppression counted with its reason, never a
 * broadcast. It deliberately does not declare FiltersByVisibility: a
 * site-wide resolver may not be used inside a scoped run.
 */
final class SiteManagersAudience implements AudienceResolverInterface
{
    public const KEY = 'site_managers';

    /** How many holders are fetched per page from the host. */
    private const PAGE = 200;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.audience.site_managers';
    }

    public function resolve(AudienceContext $context): iterable
    {
        $authorization = $context->authorization;

        if (!$authorization instanceof HoldersOfCapability) {
            $this->logger->notice('VidyaLMS site_managers audience degraded: the host cannot enumerate capability holders.');

            return;
        }

        $offset = 0;

        while (true) {
            $holders = $authorization->holdersOf(Capability::MANAGE, null, self::PAGE, $offset);

            if ($holders === []) {
                return;
            }

            foreach ($holders as $userId) {
                yield $userId;
            }

            if (\count($holders) < self::PAGE) {
                return;
            }

            $offset += self::PAGE;
        }
    }
}
