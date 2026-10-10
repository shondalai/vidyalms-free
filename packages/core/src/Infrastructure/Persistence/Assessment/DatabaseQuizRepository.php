<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\ItemSessionControl;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\ReviewVisibility;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\DeclaresPayloadShape;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class DatabaseQuizRepository implements QuizRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        /**
         * The aggregation seam, so a method's own parameters are stored under
         * the method's own shape number.
         *
         * Trailing and defaulted so a harness that builds this with two
         * arguments still builds; absent, the bag is written unversioned,
         * which is what it always was.
         */
        private readonly ?AggregationMethodRegistry $aggregations = null,
    ) {
    }

    public function add(Quiz $quiz): Quiz
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->database->insert('quizzes', [
            ...$this->toRow($quiz),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $quiz->withId($id);
    }

    public function byId(int $id): ?Quiz
    {
        $row = $this->database->selectOne(Query::from('quizzes')->where('id', '=', $id));

        return $row === null ? null : $this->fromRow($row);
    }

    public function save(Quiz $quiz): void
    {
        if ($quiz->id === null) {
            throw new \LogicException('Cannot save a quiz that has not been added.');
        }

        $this->database->update('quizzes', [
            ...$this->toRow($quiz),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $quiz->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Quiz $quiz): array
    {
        $policy = $quiz->policy;
        $control = $policy->itemSessionControl;

        return [
            'course_id' => $quiz->courseId,
            'lesson_id' => $quiz->lessonId,
            'title' => $quiz->title,
            'description' => $quiz->description,
            'pass_mark' => $quiz->passMark,
            'max_attempts' => $quiz->maxAttempts,
            'time_limit_seconds' => $quiz->timeLimitSeconds,
            'is_final' => $quiz->isFinal ? 1 : 0,
            'show_results' => $quiz->showResults,
            'shuffle_questions' => $policy->shuffleQuestions ? 1 : 0,
            'shuffle_options' => $policy->shuffleOptions ? 1 : 0,
            'one_per_page' => $policy->onePerPage ? 1 : 0,
            'behaviour' => $policy->behaviour,
            'navigation_mode' => $policy->navigationMode,
            'submission_mode' => $policy->submissionMode,
            'opens_at' => $policy->opensAt?->format('Y-m-d H:i:s'),
            'closes_at' => $policy->closesAt?->format('Y-m-d H:i:s'),
            'expiry_policy' => $policy->expiryPolicy,
            'grace_seconds' => $policy->graceSeconds,
            'auto_submit' => $policy->autoSubmit ? 1 : 0,
            'cooldown_after_first_seconds' => $policy->cooldownAfterFirstSeconds,
            'cooldown_after_later_seconds' => $policy->cooldownAfterLaterSeconds,
            'attempts_counted' => $policy->attemptsCounted,
            'attempt_aggregation' => $policy->attemptAggregation,
            // Stamped with the method's own shape where it declares one. The
            // bag held a provider's settings with no version anywhere near it,
            // while every newer seam stamps one, so a method that moved its
            // shape could not tell its old rows apart. The version travels
            // inside the JSON and the read strips it, so nothing downstream
            // and nothing on the contract sees the envelope.
            'attempt_aggregation_params' => $policy->attemptAggregationParams === null
                ? null
                : json_encode(
                    $this->stampedParams($policy->attemptAggregation, $policy->attemptAggregationParams),
                    JSON_THROW_ON_ERROR,
                ),
            'negative_marking_policy' => $policy->negativeMarkingPolicy,
            'allow_quiz_negative' => $policy->allowQuizNegative ? 1 : 0,
            'try_penalty' => $policy->tryPenalty,
            'item_max_attempts' => $control->maxAttempts,
            'item_show_feedback' => $control->showFeedback ? 1 : 0,
            'item_allow_review' => $control->allowReview ? 1 : 0,
            'item_show_solution' => $control->showSolution ? 1 : 0,
            'item_allow_comment' => $control->allowComment ? 1 : 0,
            'item_allow_skipping' => $control->allowSkipping ? 1 : 0,
            'item_validate_responses' => $control->validateResponses ? 1 : 0,
            'review_visibility' => $policy->reviewVisibility === null
                ? null
                : json_encode($policy->reviewVisibility->toArray(), JSON_THROW_ON_ERROR),
            'composition' => $policy->composition,
        ];
    }

    public function forCourse(int $courseId): array
    {
        $rows = $this->database->selectAll(
            Query::from('quizzes')->where('course_id', '=', $courseId)->orderBy('id'),
        );

        return array_map($this->fromRow(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Quiz
    {
        $showResults = (string) $row['show_results'];

        return new Quiz(
            id: (int) $row['id'],
            courseId: (int) $row['course_id'],
            lessonId: $row['lesson_id'] === null ? null : (int) $row['lesson_id'],
            title: (string) $row['title'],
            description: $row['description'] === null ? null : (string) $row['description'],
            passMark: (int) $row['pass_mark'],
            maxAttempts: $row['max_attempts'] === null ? null : (int) $row['max_attempts'],
            timeLimitSeconds: $row['time_limit_seconds'] === null ? null : (int) $row['time_limit_seconds'],
            isFinal: (int) $row['is_final'] === 1,
            showResults: $showResults,
            policy: $this->policyFromRow($row, $showResults),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function policyFromRow(array $row, string $showResults): QuizPolicy
    {
        $defaults = new QuizPolicy();
        $grace = (int) ($row['grace_seconds'] ?? QuizPolicy::MIN_GRACE_SECONDS);

        return new QuizPolicy(
            behaviour: $this->nullableString($row, 'behaviour'),
            navigationMode: $this->stringOr($row, 'navigation_mode', $defaults->navigationMode),
            submissionMode: $this->stringOr($row, 'submission_mode', $defaults->submissionMode),
            shuffleQuestions: (int) ($row['shuffle_questions'] ?? 0) === 1,
            shuffleOptions: (int) ($row['shuffle_options'] ?? 0) === 1,
            onePerPage: (int) ($row['one_per_page'] ?? 1) === 1,
            opensAt: $this->nullableDateTime($row, 'opens_at'),
            closesAt: $this->nullableDateTime($row, 'closes_at'),
            expiryPolicy: $this->stringOr($row, 'expiry_policy', $defaults->expiryPolicy),
            graceSeconds: max(QuizPolicy::MIN_GRACE_SECONDS, $grace),
            autoSubmit: (int) ($row['auto_submit'] ?? 1) === 1,
            cooldownAfterFirstSeconds: $this->nullableInt($row, 'cooldown_after_first_seconds'),
            cooldownAfterLaterSeconds: $this->nullableInt($row, 'cooldown_after_later_seconds'),
            attemptsCounted: $this->stringOr($row, 'attempts_counted', $defaults->attemptsCounted),
            attemptAggregation: $this->stringOr($row, 'attempt_aggregation', $defaults->attemptAggregation),
            attemptAggregationParams: $this->decodeObject($row['attempt_aggregation_params'] ?? null),
            negativeMarkingPolicy: $this->nullableString($row, 'negative_marking_policy'),
            allowQuizNegative: (int) ($row['allow_quiz_negative'] ?? 0) === 1,
            tryPenalty: (float) ($row['try_penalty'] ?? 0),
            itemSessionControl: new ItemSessionControl(
                maxAttempts: (int) ($row['item_max_attempts'] ?? 1),
                showFeedback: (int) ($row['item_show_feedback'] ?? 0) === 1,
                allowReview: (int) ($row['item_allow_review'] ?? 1) === 1,
                showSolution: (int) ($row['item_show_solution'] ?? 0) === 1,
                allowComment: (int) ($row['item_allow_comment'] ?? 0) === 1,
                allowSkipping: (int) ($row['item_allow_skipping'] ?? 1) === 1,
                validateResponses: (int) ($row['item_validate_responses'] ?? 0) === 1,
            ),
            reviewVisibility: $this->reviewVisibility($row, $showResults),
            composition: ($row['composition'] ?? null) === null ? null : (string) $row['composition'],
        );
    }

    /**
     * A null column means "derive it from show_results", which is what keeps
     * every quiz authored before the matrix existed behaving exactly as it
     * did.
     *
     * @param array<string, mixed> $row
     */
    private function reviewVisibility(array $row, string $showResults): ?ReviewVisibility
    {
        $stored = $this->decodeObject($row['review_visibility'] ?? null);

        return $stored === null ? null : ReviewVisibility::fromArray($stored, $showResults);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringOr(array $row, string $key, string $default): string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function nullableDateTime(array $row, string $key): ?\DateTimeImmutable
    {
        $value = $row[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * The stored bag with the method's own shape number in it, where the
     * method declares one.
     *
     * A method that does not declare {@see DeclaresPayloadShape} writes no
     * version key at all, which reads back as unversioned: the truthful answer
     * about a bag whose author never said what shape it is.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function stampedParams(string $method, array $params): array
    {
        $provider = $this->aggregations !== null && $this->aggregations->has($method)
            ? $this->aggregations->get($method)
            : null;

        return $provider instanceof DeclaresPayloadShape
            ? StoredShape::wrap($params, $provider->currentShape())
            : $params;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeObject(mixed $value): ?array
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (!\is_array($decoded)) {
            return null;
        }

        $object = [];

        /** @var mixed $item */
        foreach ($decoded as $name => $item) {
            $object[(string) $name] = $item;
        }

        // The shape envelope is storage and never a field: the domain object,
        // the presenter and the contract all see the provider's own bag.
        return StoredShape::bodyOf($object);
    }
}
