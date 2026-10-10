<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Secrets;

/**
 * The grammar half of secret resolution, published once so three adapters
 * parse a reference identically.
 *
 * A reference is `scheme:name`, the scheme is one of two, and the name is a
 * conservative identifier. It is deliberately narrow: an environment variable
 * name and a host store key are both identifiers on every platform this
 * product runs on, and accepting more would mean each adapter deciding for
 * itself what a path traversal looks like in its own store.
 */
final class SecretRef
{
    /** Conservative on purpose: what every host accepts and nothing that could traverse. */
    private const NAME_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_.-]{0,190}\z/D';

    private function __construct(
        public readonly string $scheme,
        public readonly string $name,
    ) {
    }

    /** Null rather than an exception: an unparseable reference is a miss, and a miss fails closed. */
    public static function parse(string $ref): ?self
    {
        $position = strpos($ref, ':');

        if ($position === false) {
            // An unprefixed value would let a site owner paste the secret
            // itself into the column, and it would work, which is worse than
            // it failing.
            return null;
        }

        $scheme = substr($ref, 0, $position);
        $name = substr($ref, $position + 1);

        if (!\in_array($scheme, SecretsPort::SCHEMES, true)) {
            return null;
        }

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return null;
        }

        // Only variables deliberately provisioned for outbound VidyaLMS
        // integrations may be resolved. Database/cloud/system credentials
        // must never become sendable through a manager-configured endpoint.
        if ($scheme === SecretsPort::SCHEME_ENV && !str_starts_with($name, 'VIDYALMS_SECRET_')) {
            return null;
        }

        return new self($scheme, $name);
    }

    public function isEnvironment(): bool
    {
        return $this->scheme === SecretsPort::SCHEME_ENV;
    }

    public function __toString(): string
    {
        return $this->scheme . ':' . $this->name;
    }
}
