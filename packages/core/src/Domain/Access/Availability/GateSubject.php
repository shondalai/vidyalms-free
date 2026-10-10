<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * The row a tree is attached to, or the entity a leaf names.
 *
 * Rules attach to courses, modules, lessons, quizzes and assignments, and
 * ancestors evaluate first and short-circuit: a closed module never
 * evaluates its forty lesson trees.
 */
final class GateSubject
{
    public const TYPE_COURSE = 'course';
    public const TYPE_MODULE = 'module';
    public const TYPE_LESSON = 'lesson';
    public const TYPE_QUIZ = 'quiz';
    public const TYPE_ASSIGNMENT = 'assignment';

    public function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [self::TYPE_COURSE, self::TYPE_MODULE, self::TYPE_LESSON, self::TYPE_QUIZ, self::TYPE_ASSIGNMENT];
    }

    public static function isKnownType(string $type): bool
    {
        return \in_array($type, self::types(), true);
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }

    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }
}
