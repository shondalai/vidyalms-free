<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assignments;

interface AssignmentRepository
{
    public function ofId(int $id): ?Assignment;

    public function ofLesson(int $lessonId): ?Assignment;

    /**
     * Inserts or, when the lesson already has an assignment, updates it in
     * place. Returns the persisted assignment carrying its id.
     */
    public function save(Assignment $assignment): Assignment;
}
