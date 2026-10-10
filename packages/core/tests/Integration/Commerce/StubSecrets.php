<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Commerce;

use Shondalai\VidyaLMS\Core\Port\Secrets\SecretRef;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;

/**
 * A host store that resolves exactly what the test put in it.
 *
 * It still parses the reference through the shared grammar rather than doing a
 * bare array lookup, because the rule that an unprefixed value never resolves
 * is the rule that keeps a pasted secret out of the column, and a stub that
 * skipped it would let a test pass on a path production refuses.
 */
final class StubSecrets implements SecretsPort
{
    /** @param array<string, string> $values */
    public function __construct(private readonly array $values)
    {
    }

    public function resolve(string $ref): ?string
    {
        return SecretRef::parse($ref) === null ? null : ($this->values[$ref] ?? null);
    }
}
