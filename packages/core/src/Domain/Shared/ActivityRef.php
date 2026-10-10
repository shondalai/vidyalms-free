<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * Who and what one event is about, as the learner timeline records it.
 *
 * The subject is a person: the learner for anything a learner did, the
 * member of staff for anything an author did. The three ids are the context
 * that makes a row navigable and are null wherever the event genuinely has
 * none.
 */
final class ActivityRef
{
    public function __construct(
        public readonly int $userId,
        public readonly ?int $courseId = null,
        public readonly ?int $lessonId = null,
        public readonly ?int $enrolmentId = null,
    ) {
    }
}
