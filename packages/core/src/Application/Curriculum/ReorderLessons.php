<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class ReorderLessons
{
    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly ModuleRepository $modules,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @param list<int> $orderedLessonIds every lesson of the module exactly once,
     *                                    in the desired order
     */
    public function execute(Principal $principal, int $moduleId, array $orderedLessonIds): void
    {
        $this->access->requireSignedIn($principal);

        $module = $this->modules->byId($moduleId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Module', $moduleId);

        $this->access->require($principal, $module->courseId, Capability::TEACH);

        $ordered = array_values($orderedLessonIds);
        $current = $this->lessons->idsByModule($moduleId);

        if (!$this->isCompletePermutation($ordered, $current)) {
            throw ValidationException::forField(
                'lessons',
                'The ordered list must contain every lesson of the module exactly once.',
            );
        }

        $positions = [];

        foreach ($ordered as $index => $lessonId) {
            $positions[$lessonId] = $index + 1;
        }

        $this->lessons->updatePositions($positions);
    }

    /**
     * @param list<int> $ordered
     * @param list<int> $current
     */
    private function isCompletePermutation(array $ordered, array $current): bool
    {
        if (\count($ordered) !== \count($current) || \count($ordered) !== \count(array_unique($ordered))) {
            return false;
        }

        sort($ordered);
        sort($current);

        return $ordered === $current;
    }
}
