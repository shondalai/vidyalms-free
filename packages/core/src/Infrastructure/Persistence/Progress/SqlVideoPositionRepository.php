<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress;

use Shondalai\VidyaLMS\Core\Domain\Progress\VideoPosition;
use Shondalai\VidyaLMS\Core\Domain\Progress\VideoPositionRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SqlVideoPositionRepository implements VideoPositionRepository
{
    private const TABLE = 'video_positions';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function find(int $userId, int $lessonId): ?VideoPosition
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)
                ->where('user_id', '=', $userId)
                ->where('lesson_id', '=', $lessonId),
        );

        if ($row === null) {
            return null;
        }

        return new VideoPosition(
            (int) $row['user_id'],
            (int) $row['lesson_id'],
            (int) $row['position_seconds'],
            $row['duration_seconds'] === null ? null : (int) $row['duration_seconds'],
        );
    }

    public function save(VideoPosition $position): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->upsert(
            self::TABLE,
            [
                'user_id' => $position->userId,
                'lesson_id' => $position->lessonId,
                'position_seconds' => $position->positionSeconds,
                'duration_seconds' => $position->durationSeconds,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['user_id', 'lesson_id'],
            ['position_seconds', 'duration_seconds', 'updated_at'],
        );
    }
}
