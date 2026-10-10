<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEvent;

/**
 * What recording a signal did, which is either a row or an explicit refusal
 * to write one.
 *
 * The suppression is answered rather than silently swallowed, so the client
 * stops reporting instead of retrying every blur for the rest of the
 * attempt, and so nobody reading the code later mistakes an empty ledger for
 * a learner who never left the window.
 */
final class IntegrityEventOutcome
{
    private function __construct(
        public readonly bool $recorded,
        public readonly ?IntegrityEvent $event,
    ) {
    }

    public static function recorded(IntegrityEvent $event): self
    {
        return new self(true, $event);
    }

    public static function suppressed(): self
    {
        return new self(false, null);
    }
}
