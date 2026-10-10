<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * The internally structured parts of one item, for a type whose single
 * questions row grades as several.
 *
 * A composite is one row, one points value and one mark, with per-part
 * attempt_grades rows joined by part_id. The manual grading path therefore has
 * to carry part_id end to end: AttemptGrade, AttemptRepository::addGrade,
 * GradeAnswerManually and the grading route all take it, the clamp is against
 * the part's maximum rather than the item's, and manualGradingComplete()
 * requires one manual row per pending part rather than one per question.
 *
 * It ships before any composite exists so the free build exercises the
 * per-part path with a single-part roll-up. The alternative was to add it
 * with the first composite type, which would mean editing a use case, an HTTP
 * handler, openapi.yaml, the regenerated SDK and the instructor gradebook,
 * and the cost of that rises the moment third-party graders and clients
 * exist.
 *
 * The default when a provider does not implement this is one roll-up part
 * covering the whole item: part_id null, maximum the item's points, and one
 * manual row closes it, which is exactly today's behaviour.
 */
interface PartScoringInterface
{
    /**
     * The parts of one authored definition, in presentation order.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<QuestionPart>
     */
    public function parts(array $definition): array;
}
