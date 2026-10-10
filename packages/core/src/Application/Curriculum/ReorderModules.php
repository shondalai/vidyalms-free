<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class ReorderModules
{
    public function __construct(
        private readonly ModuleRepository $modules,
        private readonly CourseAccess $access,
    ) {
    }

    /**
     * @param list<int> $orderedModuleIds every module of the course exactly once,
     *                                    in the desired order
     */
    public function execute(Principal $principal, int $courseId, array $orderedModuleIds): void
    {
        $this->access->require($principal, $courseId, Capability::TEACH);

        $ordered = array_values($orderedModuleIds);
        $current = $this->modules->idsByCourse($courseId);

        if (!$this->isCompletePermutation($ordered, $current)) {
            throw ValidationException::forField(
                'modules',
                'The ordered list must contain every module of the course exactly once.',
            );
        }

        $positions = [];

        foreach ($ordered as $index => $moduleId) {
            $positions[$moduleId] = $index + 1;
        }

        $this->modules->updatePositions($positions);
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
