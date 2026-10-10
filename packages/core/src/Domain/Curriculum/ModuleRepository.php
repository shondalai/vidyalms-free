<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

interface ModuleRepository
{
    public function byId(int $id): ?Module;

    /**
     * @return list<Module> ordered by position
     */
    public function byCourse(int $courseId): array;

    /**
     * @return list<int>
     */
    public function idsByCourse(int $courseId): array;

    public function nextPosition(int $courseId): int;

    /**
     * Persists a new module and returns its id; the entity's own id is ignored.
     */
    public function add(Module $module): int;

    public function update(Module $module): void;

    /**
     * @param array<int, int> $positions module id to new position
     */
    public function updatePositions(array $positions): void;
}
