<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;

/**
 * The containment the engine folds along: which row each gated row sits
 * inside.
 *
 * Ancestors evaluate first and their answer wins, so an author never has to
 * copy a condition onto every child and a closed module does not have to be
 * restated on its forty lessons. The caller supplies the shape because the
 * caller already walked it; rebuilding it here would be a second set of
 * queries over rows the caller is holding.
 */
final class GateHierarchy
{
    /** @var array<string, GateSubject> */
    private array $subjects = [];

    /** @var array<string, string> */
    private array $parents = [];

    /** @var array<string, list<int>> */
    private array $sequences = [];

    /** @var array<string, bool> */
    private array $requiresPrevious = [];

    public function add(GateSubject $subject, ?GateSubject $parent = null): void
    {
        $this->subjects[$subject->key()] = $subject;

        if ($parent !== null) {
            $this->subjects[$parent->key()] ??= $parent;
            $this->parents[$subject->key()] = $parent->key();
        }
    }

    /**
     * Marks a subject as requiring the previous item, which is the sequencing
     * axis. It is never a stored condition: an implicit leaf is composed at
     * evaluation time, so flipping a course from linear to free does not
     * rewrite data, needs no migration and loses no hand-authored
     * restriction.
     */
    public function requirePrevious(GateSubject $subject): void
    {
        $this->requiresPrevious[$subject->key()] = true;
    }

    public function requiresPrevious(GateSubject $subject): bool
    {
        return $this->requiresPrevious[$subject->key()] ?? false;
    }

    /**
     * @param list<int> $ids
     */
    public function sequence(string $subjectType, array $ids): void
    {
        $this->sequences[$subjectType] = $ids;
    }

    /**
     * @return array<string, list<int>>
     */
    public function sequences(): array
    {
        return $this->sequences;
    }

    /**
     * @return array<string, GateSubject>
     */
    public function subjects(): array
    {
        return $this->subjects;
    }

    public function parentOf(GateSubject $subject): ?GateSubject
    {
        $parentKey = $this->parents[$subject->key()] ?? null;

        return $parentKey === null ? null : ($this->subjects[$parentKey] ?? null);
    }

    /**
     * Outermost first, so the fold applies the course before the module and
     * the module before the lesson.
     *
     * @return list<GateSubject>
     */
    public function ancestorsOf(GateSubject $subject): array
    {
        $chain = [];
        $current = $subject;
        $guard = 0;

        while (($parent = $this->parentOf($current)) !== null && $guard < 32) {
            array_unshift($chain, $parent);
            $current = $parent;
            ++$guard;
        }

        return $chain;
    }

    /**
     * @return list<int>
     */
    public function idsOfType(string $subjectType): array
    {
        $ids = [];

        foreach ($this->subjects as $subject) {
            if ($subject->type === $subjectType) {
                $ids[] = $subject->id;
            }
        }

        return $ids;
    }
}
