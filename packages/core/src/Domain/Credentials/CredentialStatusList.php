<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * One published bitstring, sized to the specification's minimum from the very
 * first row.
 *
 * The 16KB uncompressed minimum, 131,072 single-bit entries, exists for herd
 * privacy: a list sized to the real number of credentials leaks the issuer's
 * volume. It is not a tuning parameter, and a list of a hundred thousand
 * credentials compresses to roughly twelve and a half kilobytes anyway, so
 * publishing it is one cheap static file.
 *
 * Separate rows per purpose, because revocation and suspension are
 * independent axes and the verifier algorithm checks the retrieved list's
 * purpose against the entry's before reading a bit.
 */
final class CredentialStatusList
{
    public const MINIMUM_SIZE = 131072;

    public function __construct(
        public readonly int $id,
        public readonly string $purpose,
        public readonly string $listUuid,
        public readonly int $size = self::MINIMUM_SIZE,
        public readonly int $nextIndex = 0,
        public readonly ?string $encodedList = null,
        public readonly int $ttlMs = 300000,
        public readonly ?\DateTimeImmutable $dirtySince = null,
        public readonly ?\DateTimeImmutable $publishedAt = null,
    ) {
    }

    public function isFull(): bool
    {
        return $this->nextIndex >= $this->size;
    }

    /**
     * Whether a build has ever published this list. The free build allocates
     * indexes without publishing, so a status endpoint answers 404 until a
     * paid build fills this in, which is honest: a list that resolved to an
     * all-zero bitstring would tell a verifier every credential is valid.
     */
    public function isPublished(): bool
    {
        return $this->encodedList !== null && $this->publishedAt !== null;
    }
}
