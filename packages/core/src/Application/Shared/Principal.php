<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

/**
 * The authenticated caller, handed to the core by the HTTP port. The core
 * never knows how authentication happened (Joomla session or API token,
 * WordPress cookie plus nonce or application password); it only sees an id
 * and asks the AuthorizationPort about capabilities.
 */
final class Principal
{
    private function __construct(
        public readonly int $userId,
        public readonly bool $guest,
    ) {
    }

    public static function user(int $userId): self
    {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('A user principal needs a positive user id.');
        }

        return new self($userId, false);
    }

    public static function guest(): self
    {
        return new self(0, true);
    }

    public function isGuest(): bool
    {
        return $this->guest;
    }
}
