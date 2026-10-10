<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;

/**
 * The host secret store every core test environment carries, empty until a
 * test puts something in it.
 *
 * It exists because a secret is no longer storable in a provider's parameter
 * bag: `feedback_secret` used to be a `write_only` params field, which masked
 * the read path and stored the shared secret in cleartext all the same, so a
 * test could arrange one with a plain save. The value now lives where a site
 * would put it, behind a reference, and a test arranges it here.
 *
 * The reference still goes through the shared grammar rather than a bare array
 * lookup, because the rule that an unprefixed value never resolves is the rule
 * that keeps a pasted secret out of the column.
 */
final class FakeSecrets implements SecretsPort
{
    /** @var array<string, string> */
    private array $values = [];

    public function put(string $ref, string $value): void
    {
        $this->values[$ref] = $value;
    }

    public function resolve(string $ref): ?string
    {
        return SecretRef::parse($ref) === null ? null : ($this->values[$ref] ?? null);
    }
}
