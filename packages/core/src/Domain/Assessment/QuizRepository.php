<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

interface QuizRepository
{
    public function add(Quiz $quiz): Quiz;

    public function byId(int $id): ?Quiz;

    public function save(Quiz $quiz): void;

    /**
     * Every quiz of a course, oldest first. Curriculum order is the
     * caller's to apply: it owns the module and lesson positions.
     *
     * @return list<Quiz>
     */
    public function forCourse(int $courseId): array;
}
