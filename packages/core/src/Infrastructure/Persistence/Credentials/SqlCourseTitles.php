<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseTitles;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Read-only lookup into the Curriculum context's courses table. Credentials
 * never writes it.
 */
final class SqlCourseTitles implements CourseTitles
{
    public function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    public function titleOf(int $courseId): ?string
    {
        return $this->stringColumn($courseId, 'title');
    }

    public function summaryOf(int $courseId): ?string
    {
        return $this->stringColumn($courseId, 'summary');
    }

    private function stringColumn(int $courseId, string $column): ?string
    {
        $row = $this->database->selectOne(
            Query::from('courses')->select([$column])->where('id', '=', $courseId),
        );

        if ($row === null) {
            return null;
        }

        $value = $row[$column] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
