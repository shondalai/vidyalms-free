<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assignments;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/** A practical rubric and its immutable, criterion-by-criterion result. */
final class AssignmentRubric
{
    /**
     * @param array<mixed> $criteria
     * @return list<array{id: string, label: string, max_points: float}>
     */
    public static function configure(array $criteria, float $maximum): array
    {
        if (count($criteria) > 50) {
            throw ValidationException::forField('rubric', 'Use at most 50 criteria.');
        }
        $result = [];
        $ids = [];
        foreach ($criteria as $index => $criterion) {
            if (!is_array($criterion)) {
                throw ValidationException::forField('rubric', 'Each criterion must be an object.');
            }
            $id = $criterion['id'] ?? '';
            $label = $criterion['label'] ?? '';
            $points = $criterion['max_points'] ?? null;
            if (
                !is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1 || isset($ids[$id])
                || !is_string($label) || trim($label) === '' || strlen($label) > 500
                || (!is_int($points) && !is_float($points)) || !is_finite((float) $points) || $points <= 0
            ) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('rubric.' . $index, 'Each criterion needs a unique id, a label and positive points.');
            }
            $ids[$id] = true;
            $result[] = ['id' => $id, 'label' => trim($label), 'max_points' => round((float) $points, 2)];
        }
        if ($result !== [] && abs(array_sum(array_column($result, 'max_points')) - $maximum) > 0.001) {
            throw ValidationException::forField('rubric', 'Criterion points must add up to the assignment maximum.');
        }
        return $result;
    }

    /** @param list<array{id: string, label: string, max_points: float}> $rubric
     *  @param array<mixed> $awards
     *  @return list<array{id: string, label: string, max_points: float, points: float, feedback: ?string}>
     */
    public static function assess(array $rubric, array $awards): array
    {
        if (count($rubric) !== count($awards)) {
            throw ValidationException::forField('criteria', 'Score every rubric criterion exactly once.');
        }
        $byId = [];
        foreach ($awards as $award) {
            if (!is_array($award) || !is_string($award['id'] ?? null) || isset($byId[$award['id']])) {
                throw ValidationException::forField('criteria', 'Criterion ids must be unique.');
            }
            $byId[$award['id']] = $award;
        }
        $result = [];
        foreach ($rubric as $criterion) {
            $award = $byId[$criterion['id']] ?? [];
            $points = $award['points'] ?? null;
            $feedback = $award['feedback'] ?? null;
            if (
                (!is_int($points) && !is_float($points)) || !is_finite((float) $points)
                || $points < 0 || $points > $criterion['max_points'] || ($feedback !== null && !is_string($feedback))
            ) {
                throw ValidationException::forField('criteria', 'Each score must be within its criterion maximum.');
            }
            $result[] = $criterion + ['points' => round((float) $points, 2), 'feedback' => $feedback];
        }
        return $result;
    }
}
