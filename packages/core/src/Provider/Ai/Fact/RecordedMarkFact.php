<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * The mark that already exists, passed in **as a fact and never recomputed**.
 *
 * This is the load-bearing direction of the whole no-grading rule. A task
 * that could compute a mark would be a task that grades; a task handed the
 * mark the engine already recorded is a task that can refer to a decision
 * somebody else took. So the mark travels as an input, the output schema of
 * every task is forbidden from binding one, and no placement lists a score
 * field.
 *
 * `attempt.closed` is here beside the two marks rather than in a state
 * object, because a task that needs to know an attempt is finished needs it
 * for the same reason it needs the mark: to talk about something already
 * decided.
 *
 * All three resolve to one named person, so the run writes a subject row and
 * the learner's own activity view can show it.
 */
final class RecordedMarkFact implements AiFactProviderInterface
{
    public const GRADE_RECORDED_MARK = 'grade.recorded_mark';
    public const ATTEMPT_RECORDED_MARK = 'attempt.recorded_mark';
    public const ATTEMPT_CLOSED = 'attempt.closed';

    public const SUBJECT_ATTEMPT = 'attempt';

    private function __construct(private readonly string $key)
    {
    }

    /** The mark on one assignment submission. */
    public static function gradeRecordedMark(): self
    {
        return new self(self::GRADE_RECORDED_MARK);
    }

    /** The mark on one quiz attempt. */
    public static function attemptRecordedMark(): self
    {
        return new self(self::ATTEMPT_RECORDED_MARK);
    }

    /** Whether that attempt is finished and marked. */
    public static function attemptClosed(): self
    {
        return new self(self::ATTEMPT_CLOSED);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::gradeRecordedMark(), self::attemptRecordedMark(), self::attemptClosed()];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.fact.' . str_replace('.', '_', $this->key) . '.label';
    }

    /** One named person, whose subject row the run writes. */
    public function resolvesToUsers(): FactCardinality
    {
        return FactCardinality::One;
    }

    public function resolve(AiFactContext $context): mixed
    {
        if ($context->subjectId === null) {
            return null;
        }

        if ($this->key === self::GRADE_RECORDED_MARK) {
            if ($context->subjectType !== SubmissionFact::SUBJECT_SUBMISSION) {
                return null;
            }

            $row = $context->database->selectOne(
                Query::from('submissions')
                    ->select(['points_awarded', 'graded_at'])
                    ->where('id', '=', $context->subjectId)
                    ->limit(1),
            );

            return $row === null || ($row['graded_at'] ?? null) === null
                ? null
                : ($row['points_awarded'] ?? null);
        }

        if ($context->subjectType !== self::SUBJECT_ATTEMPT) {
            return null;
        }

        $attempt = $context->database->selectOne(
            Query::from('attempts')
                ->select(['status', 'score', 'max_score', 'submitted_at'])
                ->where('id', '=', $context->subjectId)
                ->limit(1),
        );

        if ($attempt === null) {
            return null;
        }

        if ($this->key === self::ATTEMPT_CLOSED) {
            return ($attempt['status'] ?? null) === 'graded' && ($attempt['submitted_at'] ?? null) !== null;
        }

        // Null rather than zero for an attempt still being marked, because a
        // zero here would be handed to a task as the learner's mark.
        return ($attempt['status'] ?? null) === 'graded'
            ? ['score' => $attempt['score'] ?? null, 'max_score' => $attempt['max_score'] ?? null]
            : null;
    }
}
