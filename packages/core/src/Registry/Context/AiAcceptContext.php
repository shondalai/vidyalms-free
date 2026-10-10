<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * What an acceptor may read while it turns one draft into one artefact.
 *
 * The Principal here is always a human, because no risk tier permits
 * automatic acceptance and the accept use case refuses anything else before
 * an acceptor is resolved. The acceptor is handed the person rather than
 * resolving one, so the audit names whoever is holding editorial
 * responsibility rather than a synthetic system user.
 *
 * It carries no run and no draft: the draft is the first argument of accept()
 * and the run is deliberately out of reach, so an acceptor cannot re-read the
 * prompt, the segments or the cost on its way to a domain write.
 */
final class AiAcceptContext
{
    private function __construct(
        public readonly Principal $accepter,
        public readonly string $targetExtensionPoint,
        public readonly string $targetProviderKey,
        public readonly string $runRef,
        public readonly \DateTimeImmutable $now,
        public readonly string $locale,
    ) {
    }

    public static function create(
        Principal $accepter,
        string $targetExtensionPoint,
        string $targetProviderKey,
        string $runRef,
        \DateTimeImmutable $now,
        string $locale = 'en-GB',
    ): self {
        if ($accepter->isGuest()) {
            throw new \InvalidArgumentException('A draft is accepted by a named person, never by a guest.');
        }

        return new self($accepter, $targetExtensionPoint, $targetProviderKey, $runRef, $now, $locale);
    }
}
