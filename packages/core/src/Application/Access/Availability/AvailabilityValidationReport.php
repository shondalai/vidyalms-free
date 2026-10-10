<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

/**
 * What the save-time pass produced once it decided not to refuse: the
 * warnings the author should see and the reference rows to write.
 *
 * Warnings are keys plus parameters, not sentences, for the same reason a
 * reason is.
 */
final class AvailabilityValidationReport
{
    /**
     * @param list<array{code: string, parameters: array<string, mixed>, path: string}>                                   $warnings
     * @param list<array{leaf_path: string, type: string, payload_version: int, ref_type: string|null, ref_id: int|null}> $references
     */
    public function __construct(
        public readonly array $warnings = [],
        public readonly array $references = [],
    ) {
    }
}
