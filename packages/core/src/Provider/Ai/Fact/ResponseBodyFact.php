<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Fact;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\FactCardinality;
use Shondalai\VidyaLMS\Core\Registry\Ai\NamesFactSubjects;
use Shondalai\VidyaLMS\Core\Registry\Context\AiFactContext;

/**
 * What a learner actually wrote, singly and in a set.
 *
 * **The cardinality difference is the whole reason these are two facts.** One
 * response is one person, and the run writes one subject row. A response set
 * over a question is every person who answered it, and **a fact whose values
 * resolve to more than one user yields one subject row per user**, which is
 * how the answer-grouping placement ends up honestly recorded: a hundred
 * learners' answers went to a comparison, and a hundred learners can see that
 * in their own activity view.
 *
 * That is precisely what an earlier shape could not say. Pointing the
 * grouping placement at a check whose input was draft ids meant learner
 * answer text would have reached a comparison under a task declaring no facts
 * at all, which produces an empty redaction manifest and an activity view
 * telling every one of those learners that nothing about them was sent.
 *
 * The responses are untrusted content and the redactor strips identifiers
 * before anything leaves, like every other learner-data path.
 *
 * **The subjects are declared rather than inferred.** The set is keyed by
 * attempt because a learner with two attempts wrote two answers, and no
 * convention over that shape can find a person in it: under the runner's
 * fallback a grouping run over a hundred learners wrote no subject row at all,
 * so the sentence above about a hundred learners seeing it in their activity
 * view was false. The people come back from the attempt rows the responses
 * already name, which is one join and no second read of anybody's text.
 */
final class ResponseBodyFact implements AiFactProviderInterface, NamesFactSubjects
{
    public const BODY = 'response.body';
    public const BODY_SET = 'response.body_set';

    /** How many responses one grouping run may read, so a large cohort is bounded. */
    public const SET_LIMIT = 500;

    private function __construct(
        private readonly string $key,
        private readonly FactCardinality $cardinality,
    ) {
    }

    public static function body(): self
    {
        return new self(self::BODY, FactCardinality::One);
    }

    public static function bodySet(): self
    {
        return new self(self::BODY_SET, FactCardinality::Many);
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::body(), self::bodySet()];
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

    /**
     * @return list<int>
     */
    public function subjectsIn(mixed $value, AiFactContext $context): array
    {
        $attemptIds = $this->attemptIdsIn($value, $context);

        if ($attemptIds === []) {
            return [];
        }

        $rows = $context->database->selectAll(
            Query::from('attempts')
                ->select(['user_id'])
                ->whereIn('id', $attemptIds)
                ->limit(self::SET_LIMIT),
        );

        $people = [];

        foreach ($rows as $row) {
            $userId = $row['user_id'] ?? null;

            if (is_numeric($userId) && (int) $userId > 0) {
                $people[] = (int) $userId;
            }
        }

        return array_values(array_unique($people));
    }

    /**
     * The attempts a resolved value names: the keys of a set, and the subject
     * itself for the single-response fact.
     *
     * @return list<int>
     */
    private function attemptIdsIn(mixed $value, AiFactContext $context): array
    {
        if ($this->key === self::BODY) {
            return $value === null || $context->subjectId === null ? [] : [$context->subjectId];
        }

        if (!\is_array($value)) {
            return [];
        }

        $ids = [];

        foreach (array_keys($value) as $key) {
            $key = (string) $key;

            if (str_starts_with($key, 'attempt:')) {
                $id = (int) substr($key, \strlen('attempt:'));

                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function resolve(AiFactContext $context): mixed
    {
        if ($context->subjectId === null) {
            return null;
        }

        if ($this->key === self::BODY) {
            if ($context->subjectType !== RecordedMarkFact::SUBJECT_ATTEMPT) {
                return null;
            }

            $row = $context->database->selectOne(
                Query::from('attempt_answers')
                    ->select(['answer'])
                    ->where('attempt_id', '=', $context->subjectId)
                    ->limit(1),
            );

            $answer = $row['answer'] ?? null;

            return \is_string($answer) ? $answer : null;
        }

        if ($context->subjectType !== ItemStatisticFact::SUBJECT_QUESTION) {
            return null;
        }

        $rows = $context->database->selectAll(
            Query::from('attempt_answers', 'aa')
                ->select(['aa.attempt_id', 'aa.answer'])
                ->where('aa.question_id', '=', $context->subjectId)
                ->orderBy('aa.attempt_id', Query::SORT_ASC)
                ->limit(self::SET_LIMIT),
        );

        $responses = [];

        foreach ($rows as $row) {
            $answer = $row['answer'] ?? null;
            $attemptId = $row['attempt_id'] ?? null;

            if (\is_string($answer) && $answer !== '' && (\is_int($attemptId) || \is_numeric($attemptId))) {
                // Keyed by attempt rather than by user, because the grouping
                // proposal is confirmed against attempts and a learner with
                // two attempts wrote two answers.
                $responses['attempt:' . (int) $attemptId] = $answer;
            }
        }

        return $responses;
    }
}
