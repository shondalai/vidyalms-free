<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * One course as its workbench shows it: the stored fields, the instructors,
 * the prerequisites, the counts and what the gradebook is holding.
 *
 * Reading the workbench needs any of the four course capabilities, because
 * the header is the same for the person who authors the course, the person
 * who grades it and the person who reads its reports.
 */
final class GetManagedCourse
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly ManagedCourseView $view,
        private readonly GradingBacklog $backlog,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $courseId): ManagedCourseDetail
    {
        $this->access->require(
            $principal,
            $courseId,
            Capability::TEACH,
            Capability::GRADE,
            Capability::ENROL_OTHERS,
            Capability::VIEW_REPORTS,
        );

        $records = $this->courses->records([$courseId], null, null, null, 'ASC', 1, 0);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $record = $records[0] ?? throw EntityNotFoundException::for('Course', $courseId);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $managed = $this->view->forRecords([$record])[0] ?? throw EntityNotFoundException::for('Course', $courseId);

        return new ManagedCourseDetail(
            $managed,
            $this->courses->byIds($this->courses->prerequisiteIds($courseId)),
            $this->backlog->pendingCountFor($courseId),
        );
    }

    /**
     * The same detail for a course the caller has just written, without a
     * second authorization round trip: the write already applied the rule.
     */
    public function reload(int $courseId): ManagedCourseDetail
    {
        $records = $this->courses->records([$courseId], null, null, null, 'ASC', 1, 0);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $record = $records[0] ?? throw EntityNotFoundException::for('Course', $courseId);
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $managed = $this->view->forRecords([$record])[0] ?? throw EntityNotFoundException::for('Course', $courseId);

        return new ManagedCourseDetail(
            $managed,
            $this->courses->byIds($this->courses->prerequisiteIds($courseId)),
            $this->backlog->pendingCountFor($courseId),
        );
    }
}
