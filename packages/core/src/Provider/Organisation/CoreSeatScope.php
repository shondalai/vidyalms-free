<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\SeatScope\SeatScopeInterface;

/**
 * The two free seat scopes.
 *
 * `course` spends one seat per learner per course and is what every pool on
 * both development sites already is, which is why it is the stored default:
 * a pool written before the column existed reads back as a course pool and
 * behaves exactly as it did.
 *
 * `programme` spends one seat per learner for a fixed course set consumed as
 * a unit, which is IOMAD's documented rule for a program licence, and it is
 * free because a fixed set is a list of ids in the pool's own scope_params
 * and needs no catalogue behind it. `catalogue`, which spends one seat per
 * course per learner, is Business and is not registered here.
 */
final class CoreSeatScope implements SeatScopeInterface
{
    public const COURSE = 'course';
    public const PROGRAMME = 'programme';

    public function __construct(private readonly string $name)
    {
    }

    public function key(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'org.seat_scope.' . $this->name;
    }

    public function covers(SeatDecisionContext $context): bool
    {
        if ($context->courseId === null) {
            return false;
        }

        if ($this->name === self::PROGRAMME) {
            $ids = $context->params['course_ids'] ?? [];

            return \is_array($ids) && \in_array($context->courseId, array_map('intval', array_filter($ids, 'is_numeric')), true);
        }

        // A course pool covers the course it was funded for, and the pool row
        // is the authority on which that is. Reading the course from the
        // context rather than from a second query is what keeps a provider a
        // decision.
        return $context->courseId === (int) ($context->pool['course_id'] ?? 0);
    }

    /**
     * One, for both free scopes.
     *
     * A programme is one licence use however many courses it contains, which
     * is the documented rule this scope exists to express; the arity that is
     * not one belongs to the Business `catalogue` scope.
     */
    public function arity(SeatDecisionContext $context): int
    {
        return 1;
    }
}
