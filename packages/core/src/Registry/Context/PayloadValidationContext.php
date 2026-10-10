<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * What a condition may read while checking its own payload at save time: the
 * surface being written, the subject the tree hangs on, the leaf's path so an
 * error can name it, and a read-only database handle.
 *
 * The handle is here because two of the free checks are facts about another
 * row: whether a completion target carries a pass mark, and what the maximum
 * attainable score of a quiz is. Asking the author to restate them in the
 * payload would put the same fact in two places.
 */
final class PayloadValidationContext
{
    private function __construct(
        public readonly DatabasePort $database,
        public readonly AvailabilitySurface $surface,
        public readonly ?GateSubject $subject,
        public readonly string $leafPath,
        public readonly ?int $courseId,
    ) {
    }

    public static function create(
        DatabasePort $database,
        AvailabilitySurface $surface,
        ?GateSubject $subject = null,
        string $leafPath = '',
        ?int $courseId = null,
    ): self {
        return new self($database, $surface, $subject, $leafPath, $courseId);
    }

    public function atPath(string $leafPath): self
    {
        return new self($this->database, $this->surface, $this->subject, $leafPath, $this->courseId);
    }
}
