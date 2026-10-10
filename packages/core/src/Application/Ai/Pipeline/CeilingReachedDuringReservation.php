<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

/**
 * The headroom went between the policy fold and the reservation.
 *
 * It is an exception and not a return value for one reason: it is raised
 * inside the transactional callback that writes the reserved row, and the row
 * must not be written when the ceiling has been reached. A callback with two
 * outcomes would have to invent its own control flow to say "do not commit",
 * which is exactly the shape a transaction boundary exists to remove.
 *
 * It never leaves the stage. The stage catches it and turns it back into the
 * ordinary outcome the design requires: a defer when the ceiling named an
 * instant, a refusal when it did not.
 */
final class CeilingReachedDuringReservation extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?\DateTimeImmutable $until,
    ) {
        parent::__construct(
            $until === null
                ? 'A ceiling was reached while the reservation was being written.'
                : 'A ceiling was reached while the reservation was being written, and answers '
                    . $until->format('Y-m-d H:i:s') . '.',
        );
    }
}
