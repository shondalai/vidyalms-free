<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCreditRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class SqlCourseCreditRepository implements CourseCreditRepository
{
    private const TABLE = 'course_credits';
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function forCourse(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('course_id', '=', $courseId)
                ->orderBy('scheme', Query::SORT_ASC),
        );

        return array_map($this->map(...), $rows);
    }

    public function findFor(int $courseId, string $scheme): ?CourseCredit
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->where('course_id', '=', $courseId)->where('scheme', '=', $scheme),
        );

        return $row === null ? null : $this->map($row);
    }

    public function save(CourseCredit $credit): void
    {
        $now = $this->clock->now()->format(self::DATETIME);

        $this->database->upsert(
            self::TABLE,
            [
                'course_id' => $credit->courseId,
                'scheme' => $credit->scheme,
                'params' => json_encode(
                    StoredShape::wrap($credit->params, $credit->paramsVersion),
                    JSON_THROW_ON_ERROR,
                ),
                'params_version' => $credit->paramsVersion,
                'created_by' => $credit->createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['course_id', 'scheme'],
            ['params', 'params_version', 'updated_at'],
        );
    }

    public function delete(int $courseId, string $scheme): void
    {
        $this->database->delete(self::TABLE, ['course_id' => $courseId, 'scheme' => $scheme]);
    }

    public function schemesInUse(): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)->select(['scheme'])->groupBy('scheme')->orderBy('scheme', Query::SORT_ASC),
        );

        return array_values(array_unique(array_map(
            static fn (array $row): string => (string) $row['scheme'],
            $rows,
        )));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): CourseCredit
    {
        $params = [];
        $raw = $row['params'] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            if (\is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                $params = StoredShape::bodyOf($decoded);
            }
        }

        return new CourseCredit(
            (int) $row['course_id'],
            (string) $row['scheme'],
            $params,
            (int) ($row['params_version'] ?? 1),
            isset($row['created_by']) ? (int) $row['created_by'] : null,
            (int) $row['id'],
        );
    }
}
