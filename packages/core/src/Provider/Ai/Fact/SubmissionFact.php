<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * The three facts a feedback draft is built from, and only one of them is
 * about a person.
 *
 * `submission.body` is a named learner's own work, so it resolves to **one**
 * user and the run writes a subject row for them. `submission.rubric` and
 * `assignment.brief` are the instructor's own material and resolve to
 * nobody, which is why they are separate facts rather than fields of the
 * first: declaring the brief should not put a learner in the run's subject
 * list, and declaring the body should.
 *
 * The body is untrusted content in the strongest sense in the whole design.
 * It is written by the person the output is about, it is free text, and the
 * output goes into an instructor's field. Every one of those is a reason the
 * trust marking is applied by the redactor and never by the task.
 */
final class SubmissionFact implements AiFactProviderInterface
{
    public const BODY = 'submission.body';
    public const RUBRIC = 'submission.rubric';
    public const BRIEF = 'assignment.brief';

    public const SUBJECT_SUBMISSION = 'submission';

    private function __construct(
        private readonly string $key,
        private readonly FactCardinality $cardinality,
    ) {
    }

    public static function body(): self
    {
        return new self(self::BODY, FactCardinality::One);
    }

    public static function rubric(): self
    {
        return new self(self::RUBRIC, FactCardinality::None);
    }

    public static function brief(): self
    {
        return new self(self::BRIEF, FactCardinality::None);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::body(), self::rubric(), self::brief()];
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.fact.' . str_replace('.', '_', $this->key) . '.label';
    }

    public function resolvesToUsers(): FactCardinality
    {
        return $this->cardinality;
    }

    public function resolve(AiFactContext $context): mixed
    {
        if ($context->subjectType !== self::SUBJECT_SUBMISSION || $context->subjectId === null) {
            return null;
        }

        $submission = $context->database->selectOne(
            Query::from('submissions')
                ->select(['assignment_id', 'text_response'])
                ->where('id', '=', $context->subjectId)
                ->limit(1),
        );

        if ($submission === null) {
            return null;
        }

        if ($this->key === self::BODY) {
            $body = $submission['text_response'] ?? null;

            return \is_string($body) ? $body : null;
        }

        $assignmentId = $submission['assignment_id'] ?? null;

        if (!\is_int($assignmentId) && !\is_numeric($assignmentId)) {
            return null;
        }

        $assignment = $context->database->selectOne(
            Query::from('assignments')
                ->select(['instructions', 'max_points'])
                ->where('id', '=', (int) $assignmentId)
                ->limit(1),
        );

        if ($assignment === null) {
            return null;
        }

        if ($this->key === self::BRIEF) {
            $instructions = $assignment['instructions'] ?? null;

            return \is_string($instructions) ? $instructions : null;
        }

        // The rubric a free build has is the marking scheme expressed as the
        // points available, which is honest rather than impressive: a
        // criterion-level rubric is a paid shape, and inventing one here
        // would put a structure in the manifest that the site never wrote.
        return ['max_points' => $assignment['max_points'] ?? null];
    }
}
