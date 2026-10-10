<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ReportDelivery;

/**
 * What a delivery provider answers.
 *
 * It reports rather than throws, because the miss direction for this registry
 * is that the run completes, the artefact waits, and the schedule records a
 * delivery failure an admin can see. The expensive half already succeeded, and
 * discarding it to report a mail problem is the wrong trade.
 */
final class DeliveryResult
{
    private function __construct(
        public readonly bool $delivered,
        public readonly string $reason,
        /** What the provider did, for the run record: a queue row id, a path, a message id. */
        public readonly ?string $reference = null,
    ) {
    }

    public static function delivered(?string $reference = null): self
    {
        return new self(true, '', $reference);
    }

    /** Delivery did not happen and the run is still good. */
    public static function failed(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * The artefact is waiting where the caller can already reach it and no
     * delivery was needed, which is what the free `download` provider answers.
     */
    public static function waiting(?string $reference = null): self
    {
        return new self(true, '', $reference);
    }
}
