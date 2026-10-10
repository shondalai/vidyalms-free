<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Rubric;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * The seam through which a human's per-criterion marks become one mark.
 *
 * **The contract declared this and no server implemented it.** openapi.yaml
 * says exactly one of points_awarded and criteria is sent and that sending
 * neither answers 422, ManualGrade carries rubric_version_id and criteria,
 * GradebookQuizAnswer carries a resolved rubric, and the shipped gradebook
 * queue already posts the criteria shape. The core read only points_awarded,
 * never read criteria, and attempt_grades.rubric_version_id and
 * attempt_grade_criteria were written by no reachable path. The moment the
 * server emitted a rubric, the grade route would have answered 422 for every
 * rubric-marked answer, leaving the attempt submitted with passed null
 * forever.
 *
 * Rubrics are a paid feature, so the entities live in the paid package and
 * the core knows only this shape. A site with no provider degrades to
 * points: a grader types a mark, exactly as they always did. That is the
 * registry miss contract, and it is why this is a registry rather than a
 * dependency.
 */
interface RubricProviderInterface extends ProviderInterface
{
    /**
     * The rubric version a question is marked against, or null when it is
     * marked on points alone.
     */
    public function forQuestion(int $questionId): ?ResolvedRubric;

    /**
     * The mark a set of level selections earns out of an item's maximum, or
     * null while any criterion is still unmarked.
     *
     * Null is not zero: a partly marked rubric is pending, and an unmarked
     * criterion must never read as a wrong answer.
     *
     * @param array<int, int> $levelByCriterion criterion id to selected level id
     */
    public function pointsFor(ResolvedRubric $rubric, array $levelByCriterion, float $maximum): ?float;

    /**
     * Records what the rater chose against one grade row.
     *
     * **The selected level id, never only the points.** Two raters who both
     * awarded three marks may have chosen different levels for different
     * reasons, and a row holding only the three cannot tell them apart, show
     * the learner which descriptor applied, or survive a rubric revision.
     *
     * @param array<int, int>    $levelByCriterion criterion id to selected level id
     * @param array<int, string> $comments         criterion id to the rater's comment
     */
    public function record(int $gradeId, ResolvedRubric $rubric, array $levelByCriterion, array $comments = []): void;
}
