<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseCounts;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRecord;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Assembles management course rows from a set of course records: the counts
 * and the instructors for a whole page in a fixed number of queries, never
 * one per course. Shared by the list and the single-course view so both show
 * the same numbers.
 *
 * Holds no authorization of its own; every caller is a use case that has
 * already applied the per-course rule.
 */
final class ManagedCourseView
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly UsersPort $users,
    ) {
    }

    /**
     * @param list<CourseRecord> $records
     *
     * @return list<ManagedCourse>
     */
    public function forRecords(array $records): array
    {
        if ($records === []) {
            return [];
        }

        $ids = array_map(static fn (CourseRecord $record): int => $record->course->id, $records);
        $counts = $this->courses->countsFor($ids);
        $instructorRows = $this->courses->instructorsFor($ids);
        $names = $this->names($instructorRows);

        $managed = [];

        foreach ($records as $record) {
            $courseId = $record->course->id;
            $instructors = [];

            foreach ($instructorRows[$courseId] ?? [] as $row) {
                $instructors[] = new Instructor($row['user_id'], $names[$row['user_id']] ?? null, $row['role']);
            }

            $managed[] = new ManagedCourse(
                $record->course,
                $record->createdAt,
                $record->updatedAt,
                $counts[$courseId] ?? CourseCounts::empty(),
                $instructors,
            );
        }

        return $managed;
    }

    /**
     * @return list<Instructor>
     */
    public function instructorsOf(Course $course): array
    {
        $rows = $this->courses->instructorsFor([$course->id])[$course->id] ?? [];
        $names = $this->names([$course->id => $rows]);
        $instructors = [];

        foreach ($rows as $row) {
            $instructors[] = new Instructor($row['user_id'], $names[$row['user_id']] ?? null, $row['role']);
        }

        return $instructors;
    }

    /**
     * @param array<int, list<array{user_id: int, role: string}>> $instructorRows
     *
     * @return array<int, string>
     */
    private function names(array $instructorRows): array
    {
        $userIds = [];

        foreach ($instructorRows as $rows) {
            foreach ($rows as $row) {
                $userIds[$row['user_id']] = true;
            }
        }

        return $this->users->namesFor(array_map(\intval(...), array_keys($userIds)));
    }
}
