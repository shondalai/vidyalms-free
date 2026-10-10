<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\ActivityEntry;
use Shondalai\VidyaLMS\Core\Domain\Platform\ActivityLogRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class DatabaseActivityLogRepository implements ActivityLogRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function append(ActivityEntry $entry): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $row = [
            'user_id' => $entry->userId,
            'course_id' => $entry->courseId,
            'lesson_id' => $entry->lessonId,
            'enrolment_id' => $entry->enrolmentId,
            'action' => $entry->action,
            'context' => $entry->context === null ? null : json_encode($entry->context, JSON_THROW_ON_ERROR),
            'occurred_at' => $entry->occurredAt->format('Y-m-d H:i:s'),
            'event_ref' => $entry->eventRef,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($entry->eventRef === null) {
            $this->database->insert('activity_log', $row);

            return;
        }

        // Do-nothing upsert on the unique event reference: a redelivered
        // event keeps exactly one timeline row.
        $this->database->upsert('activity_log', $row, ['event_ref'], []);
    }
}
