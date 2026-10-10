<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;

/**
 * The one place use cases ask permission. Guests hold no capabilities.
 */
final class AccessGuard
{
    public function __construct(
        private readonly AuthorizationPort $authorization,
    ) {
    }

    public function can(Principal $principal, string $capability, ?string $resource = null): bool
    {
        if ($principal->isGuest()) {
            return false;
        }

        return $this->authorization->can($principal->userId, $capability, $resource);
    }

    public function require(Principal $principal, string $capability, ?string $resource = null): void
    {
        if (!$this->can($principal, $capability, $resource)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw NotAuthorizedException::forCapability($capability);
        }
    }

    /**
     * Any one of these capabilities is enough.
     *
     * A site-wide screen that a route already lets a manager reach must not
     * be the one screen a manager cannot reach, and stating the alternatives
     * here keeps that a use case rule rather than a note in a controller.
     * The refusal names the first capability, which is the one the route
     * documents as its primary.
     *
     * @param string ...$capabilities
     */
    public function requireAny(Principal $principal, string ...$capabilities): void
    {
        if ($capabilities === []) {
            throw new \InvalidArgumentException('At least one capability is required.');
        }

        foreach ($capabilities as $capability) {
            if ($this->can($principal, $capability)) {
                return;
            }
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw NotAuthorizedException::forCapability($capabilities[0]);
    }

    public function requireAuthenticated(Principal $principal): void
    {
        if ($principal->isGuest()) {
            throw new NotAuthorizedException('Authentication is required.');
        }
    }
}
