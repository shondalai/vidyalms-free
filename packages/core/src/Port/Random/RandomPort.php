<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Random;

/**
 * Randomness, injectable so tests are deterministic. Verification codes must
 * come from a cryptographically secure source in real implementations.
 */
interface RandomPort
{
    public function uuid(): string;

    /**
     * An unambiguous uppercase alphanumeric code of the given length, for
     * certificate verification.
     */
    public function verificationCode(int $length): string;
}
