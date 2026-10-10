<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceCourseFacts;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Read-only lookups into Curriculum and Progress for the three facts a
 * certificate freezes at issue. Credentials writes neither context.
 *
 * The category comes from the host's own taxonomy through its port rather than
 * from a core table, because a category is a CMS row on both platforms and the
 * core never joins into one.
 */
final class SqlIssuanceCourseFacts implements IssuanceCourseFacts
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly UsersPort $users,
        private readonly ?CourseCategoryPort $categories = null,
    ) {
    }

    public function categoryOf(int $courseId): ?string
    {
        if ($this->categories === null || $courseId <= 0) {
            return null;
        }

        $row = $this->database->selectOne(
            Query::from('courses')->select(['category_id'])->where('id', '=', $courseId),
        );

        $categoryId = $row === null ? null : $row['category_id'] ?? null;

        if (!is_numeric($categoryId) || (int) $categoryId <= 0) {
            return null;
        }

        $category = $this->categories->findById((int) $categoryId);

        return $category === null || $category->name === '' ? null : $category->name;
    }

    public function instructorsOf(int $courseId): ?string
    {
        if ($courseId <= 0) {
            return null;
        }

        $rows = $this->database->selectAll(
            Query::from('course_instructors')
                ->select(['user_id', 'role'])
                ->where('course_id', '=', $courseId)
                ->orderBy('id', 'asc'),
        );

        $lead = [];
        $other = [];

        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;

            if (!is_numeric($userId)) {
                continue;
            }

            if (($row['role'] ?? '') === 'lead') {
                $lead[] = (int) $userId;
                continue;
            }

            $other[] = (int) $userId;
        }

        $ids = array_values(array_unique([...$lead, ...$other]));

        if ($ids === []) {
            return null;
        }

        $names = $this->users->namesFor($ids);
        $ordered = [];

        foreach ($ids as $id) {
            $name = $names[$id] ?? null;

            if (\is_string($name) && $name !== '') {
                $ordered[] = $name;
            }
        }

        return $ordered === [] ? null : implode(', ', $ordered);
    }

    public function gradeFor(?int $enrolmentId): ?string
    {
        if ($enrolmentId === null || $enrolmentId <= 0) {
            return null;
        }

        $row = $this->database->selectOne(
            Query::from('course_progress')->select(['percent'])->where('enrolment_id', '=', $enrolmentId),
        );

        $percent = $row === null ? null : $row['percent'] ?? null;

        if (!is_numeric($percent)) {
            return null;
        }

        // The stored decimal, trimmed of the trailing zeros a 5,2 column
        // always carries, with the unit the figure is in. A bare "86.00" on a
        // certificate is a number nobody can read as a proportion.
        $trimmed = rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.');

        return $trimmed . '%';
    }
}
