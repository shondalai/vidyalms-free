<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * How a write path refuses a gated item, in one place.
 *
 * Two refusals, and which one is correct is a privacy question rather than a
 * taste question. A hidden item is **absent**, so it answers not-found: a
 * reason string discloses the existence and the name of a gated assessment,
 * and the title itself is the leak. A visible blocked item answers a
 * conflict carrying the same reason the outline already showed the learner,
 * because being told "not found" for something the outline says opens on 14
 * October is a support ticket rather than an answer.
 *
 * The extensions are exactly the members of the access block the outline and
 * the lesson response already carry, so a client renders one sentence from
 * one shape whether it read it from a GET or was refused by a POST.
 */
final class AvailabilityRefusal
{
    private function __construct()
    {
    }

    /**
     * The throwable for a decision that refuses entry, or null when the
     * decision permits it.
     */
    public static function for(AvailabilityDecision $decision, string $entity, int $id): ?\Throwable
    {
        if ($decision->isAvailable()) {
            return null;
        }

        if ($decision->isHidden()) {
            return EntityNotFoundException::for($entity, $id);
        }

        $outcome = $decision->outcome;

        return ConflictException::with(
            'The item is not available yet.',
            array_filter([
                'reason' => $outcome->reasonKey,
                'reason_parameters' => $outcome->reasonParameters,
                'opens_at' => $outcome->opensAt?->format(\DateTimeInterface::ATOM),
                'opens_at_timezone' => $outcome->opensAtTimezone,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
        );
    }
}
