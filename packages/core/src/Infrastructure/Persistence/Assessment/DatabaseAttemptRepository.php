<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\AnswerSubmission;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\GradeState;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Attempts, answers and grade history against the database port. Grade rows
 * are insert-only here by construction: no update or delete on
 * attempt_grades exists in this class.
 */
final class DatabaseAttemptRepository implements AttemptRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function add(Attempt $attempt): Attempt
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->database->insert('attempts', [
            'quiz_id' => $attempt->quizId,
            'enrolment_id' => $attempt->enrolmentId,
            'user_id' => $attempt->userId,
            'attempt_number' => $attempt->attemptNumber,
            'status' => $attempt->status,
            'started_at' => $attempt->startedAt->format('Y-m-d H:i:s'),
            'submitted_at' => $attempt->submittedAt?->format('Y-m-d H:i:s'),
            'score' => $attempt->score,
            'max_score' => $attempt->maxScore,
            'percentage' => $attempt->percentage,
            'passed' => $attempt->passed === null ? null : ($attempt->passed ? 1 : 0),
            'time_spent_seconds' => $attempt->timeSpentSeconds,
            'presentation_seed' => $attempt->presentationSeed,
            'behaviour' => $attempt->behaviour,
            'policy_snapshot' => $attempt->policySnapshot === []
                ? null
                : json_encode($attempt->policySnapshot, JSON_THROW_ON_ERROR),
            'expires_at' => $attempt->expiresAt?->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $attempt->withId($id);
    }

    public function byId(int $id): ?Attempt
    {
        $row = $this->database->selectOne(Query::from('attempts')->where('id', '=', $id));

        return $row === null ? null : $this->fromRow($row);
    }

    public function inProgressFor(int $quizId, int $userId): ?Attempt
    {
        $row = $this->database->selectOne(
            Query::from('attempts')
                ->where('quiz_id', '=', $quizId)
                ->where('user_id', '=', $userId)
                ->where('status', '=', Attempt::STATUS_IN_PROGRESS)
                ->orderBy('attempt_number', Query::SORT_DESC),
        );

        return $row === null ? null : $this->fromRow($row);
    }

    public function countFor(int $quizId, int $userId): int
    {
        return $this->database->count(
            Query::from('attempts')
                ->where('quiz_id', '=', $quizId)
                ->where('user_id', '=', $userId),
        );
    }

    public function forQuizAndUser(int $quizId, int $userId): array
    {
        $rows = $this->database->selectAll(
            Query::from('attempts')
                ->where('quiz_id', '=', $quizId)
                ->where('user_id', '=', $userId)
                ->orderBy('attempt_number'),
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function closedForQuiz(int $quizId): array
    {
        $rows = $this->database->selectAll(
            Query::from('attempts')
                ->where('quiz_id', '=', $quizId)
                ->whereIn('status', [Attempt::STATUS_SUBMITTED, Attempt::STATUS_GRADED])
                ->orderBy('id'),
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function upsertAnswer(
        int $attemptId,
        int $questionId,
        AnswerSubmission $submission,
        \DateTimeImmutable $answeredAt,
    ): void {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->upsert(
            'attempt_answers',
            [
                'attempt_id' => $attemptId,
                'question_id' => $questionId,
                'answer' => json_encode($submission->response, JSON_THROW_ON_ERROR),
                'answered_at' => $answeredAt->format('Y-m-d H:i:s'),
                'ui_state' => $submission->uiState === null
                    ? null
                    : json_encode($submission->uiState, JSON_THROW_ON_ERROR),
                'raw_input' => $submission->rawInput,
                'presentation_form' => $submission->presentationForm,
                'certainty' => $submission->certainty,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['attempt_id', 'question_id'],
            ['answer', 'answered_at', 'ui_state', 'raw_input', 'presentation_form', 'certainty', 'updated_at'],
        );
    }

    public function recordAnswerEvent(
        int $attemptId,
        int $questionId,
        AnswerSubmission $submission,
        \DateTimeImmutable $occurredAt,
        ?string $partId = null,
    ): void {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Autosaves retain the latest draft only. Processed events remain
        // immutable because they determine the interactive grading penalty.
        if (!$submission->processed) {
            $this->database->delete('attempt_answer_events', [
                'attempt_id' => $attemptId,
                'question_id' => $questionId,
                'part_id' => $partId,
                'processed' => 0,
            ]);
        }
        $this->database->insert('attempt_answer_events', [
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
            'part_id' => $partId,
            'try' => $submission->processed ? $this->tryCountFor($attemptId, $questionId, $partId) : 0,
            'response' => json_encode($submission->response, JSON_THROW_ON_ERROR),
            'raw_input' => $submission->rawInput,
            'presentation_form' => $submission->presentationForm,
            'certainty' => $submission->certainty,
            'processed' => $submission->processed ? 1 : 0,
            'occurred_at' => $occurredAt->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * How many tries this response has had.
     *
     * **A try is a response submitted for processing, never an autosave.**
     * The runner posts the whole answer map on every autosave, so counting
     * every event row counted the number of times the learner changed their
     * mind: under a try penalty of 0.2 a learner who answered and revised
     * four times scored 2 of 10 for a correct answer while one who never
     * revised paid nothing, which is the opposite of what the penalty is
     * for. The submit itself is the last try, so the count is the processed
     * events plus one and is never below one.
     *
     * Per part when a part is named, because the penalty applies per part:
     * applying it to the whole item is the bug Moodle shipped and fixed in
     * MDL-36955.
     */
    public function tryCountFor(int $attemptId, int $questionId, ?string $partId = null): int
    {
        $query = Query::from('attempt_answer_events')
            ->where('attempt_id', '=', $attemptId)
            ->where('question_id', '=', $questionId)
            ->where('processed', '=', 1);

        if ($partId !== null) {
            $query->where('part_id', '=', $partId);
        }

        return $this->database->count($query) + 1;
    }

    public function submissionsFor(int $attemptId): array
    {
        $rows = $this->database->selectAll(
            Query::from('attempt_answers')->where('attempt_id', '=', $attemptId),
        );

        $submissions = [];

        foreach ($rows as $row) {
            $uiState = $row['ui_state'] ?? null;
            $decodedState = \is_string($uiState) && $uiState !== ''
                ? json_decode($uiState, true, 512, JSON_THROW_ON_ERROR)
                : null;
            $rawInput = $row['raw_input'] ?? null;
            $form = $row['presentation_form'] ?? null;
            $certainty = $row['certainty'] ?? null;

            $submissions[(int) $row['question_id']] = new AnswerSubmission(
                json_decode((string) $row['answer'], true, 512, JSON_THROW_ON_ERROR),
                \is_array($decodedState) ? $decodedState : null,
                \is_string($rawInput) ? $rawInput : null,
                \is_string($form) ? $form : AnswerSubmission::FORM_INTERACTIVE,
                $certainty === null ? null : (int) $certainty,
            );
        }

        return $submissions;
    }

    public function recordPolicySnapshot(
        int $attemptId,
        string $presentationSeed,
        string $behaviour,
        array $policySnapshot,
        ?\DateTimeImmutable $expiresAt,
    ): void {
        $this->database->update('attempts', [
            'presentation_seed' => $presentationSeed,
            'behaviour' => $behaviour,
            'policy_snapshot' => json_encode($policySnapshot, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $attemptId]);
    }

    public function answersFor(int $attemptId): array
    {
        $rows = $this->database->selectAll(
            Query::from('attempt_answers')->where('attempt_id', '=', $attemptId),
        );

        $answers = [];

        foreach ($rows as $row) {
            $answers[(int) $row['question_id']] = json_decode((string) $row['answer'], true, 512, JSON_THROW_ON_ERROR);
        }

        return $answers;
    }

    public function addGrade(AttemptGrade $grade): AttemptGrade
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->database->insert('attempt_grades', [
            'attempt_id' => $grade->attemptId,
            'question_id' => $grade->questionId,
            'grader_id' => $grade->graderId,
            'points_awarded' => $grade->pointsAwarded,
            'max_points' => $grade->maxPoints,
            'feedback' => $grade->feedback,
            'graded_at' => $grade->gradedAt->format('Y-m-d H:i:s'),
            'part_id' => $grade->partId,
            'raw_score' => $grade->rawScore,
            'min_score' => $grade->minScore,
            'policy_key' => $grade->policyKey,
            'scoring_params' => $grade->scoringParams === []
                ? null
                : json_encode($grade->scoringParams, JSON_THROW_ON_ERROR),
            'session_status' => $grade->sessionStatus->value,
            'score_status' => $grade->scoreStatus->value,
            'state' => $grade->state->value,
            'answered_status' => $grade->answeredStatus->value,
            'feedback_ids' => $grade->feedbackIds === []
                ? null
                : json_encode($grade->feedbackIds, JSON_THROW_ON_ERROR),
            'version' => $grade->version,
            'rubric_version_id' => $grade->rubricVersionId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new AttemptGrade(
            $id,
            $grade->attemptId,
            $grade->questionId,
            $grade->graderId,
            $grade->pointsAwarded,
            $grade->maxPoints,
            $grade->feedback,
            $grade->gradedAt,
            $grade->partId,
            $grade->rawScore,
            $grade->minScore,
            $grade->policyKey,
            $grade->scoringParams,
            $grade->sessionStatus,
            $grade->scoreStatus,
            $grade->state,
            $grade->answeredStatus,
            $grade->feedbackIds,
            $grade->version,
            $grade->rubricVersionId,
        );
    }

    public function gradesFor(int $attemptId): array
    {
        $rows = $this->database->selectAll(
            Query::from('attempt_grades')
                ->where('attempt_id', '=', $attemptId)
                ->orderBy('id'),
        );

        return array_map($this->gradeFromRow(...), $rows);
    }

    public function recordSubmission(
        int $attemptId,
        \DateTimeImmutable $submittedAt,
        string $status,
        float $score,
        float $maxScore,
        ?float $percentage,
        ?bool $passed,
        ?int $timeSpentSeconds,
    ): void {
        $this->database->update('attempts', [
            'status' => $status,
            'submitted_at' => $submittedAt->format('Y-m-d H:i:s'),
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'passed' => $passed === null ? null : ($passed ? 1 : 0),
            'time_spent_seconds' => $timeSpentSeconds,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $attemptId]);
    }

    public function recordGradingOutcome(
        int $attemptId,
        string $status,
        float $score,
        float $maxScore,
        float $percentage,
        ?bool $passed,
    ): void {
        $this->database->update('attempts', [
            'status' => $status,
            'score' => $score,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'passed' => $passed === null ? null : ($passed ? 1 : 0),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $attemptId]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): Attempt
    {
        return new Attempt(
            id: (int) $row['id'],
            quizId: (int) $row['quiz_id'],
            enrolmentId: (int) $row['enrolment_id'],
            userId: (int) $row['user_id'],
            attemptNumber: (int) $row['attempt_number'],
            status: (string) $row['status'],
            startedAt: $this->dateTime((string) $row['started_at']),
            submittedAt: $row['submitted_at'] === null ? null : $this->dateTime((string) $row['submitted_at']),
            score: $row['score'] === null ? null : (float) $row['score'],
            maxScore: $row['max_score'] === null ? null : (float) $row['max_score'],
            percentage: $row['percentage'] === null ? null : (float) $row['percentage'],
            passed: $row['passed'] === null ? null : (int) $row['passed'] === 1,
            timeSpentSeconds: $row['time_spent_seconds'] === null ? null : (int) $row['time_spent_seconds'],
            presentationSeed: ($row['presentation_seed'] ?? null) === null ? null : (string) $row['presentation_seed'],
            behaviour: ($row['behaviour'] ?? null) === null ? null : (string) $row['behaviour'],
            policySnapshot: $this->decodeObject($row['policy_snapshot'] ?? null),
            expiresAt: ($row['expires_at'] ?? null) === null ? null : $this->dateTime((string) $row['expires_at']),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function gradeFromRow(array $row): AttemptGrade
    {
        return new AttemptGrade(
            id: (int) $row['id'],
            attemptId: (int) $row['attempt_id'],
            questionId: $row['question_id'] === null ? null : (int) $row['question_id'],
            graderId: $row['grader_id'] === null ? null : (int) $row['grader_id'],
            pointsAwarded: (float) $row['points_awarded'],
            maxPoints: (float) $row['max_points'],
            feedback: $row['feedback'] === null ? null : (string) $row['feedback'],
            gradedAt: $this->dateTime((string) $row['graded_at']),
            partId: ($row['part_id'] ?? null) === null ? null : (string) $row['part_id'],
            rawScore: ($row['raw_score'] ?? null) === null ? null : (float) $row['raw_score'],
            minScore: ($row['min_score'] ?? null) === null ? null : (float) $row['min_score'],
            policyKey: ($row['policy_key'] ?? null) === null ? null : (string) $row['policy_key'],
            scoringParams: $this->decodeObject($row['scoring_params'] ?? null),
            sessionStatus: SessionStatus::fromWire(
                ($row['session_status'] ?? null) === null ? null : (string) $row['session_status'],
            ),
            scoreStatus: ScoreStatus::fromWire(
                ($row['score_status'] ?? null) === null ? null : (string) $row['score_status'],
            ),
            state: GradeState::fromWire(($row['state'] ?? null) === null ? null : (string) $row['state']),
            answeredStatus: AnsweredStatus::fromWire(
                ($row['answered_status'] ?? null) === null ? null : (string) $row['answered_status'],
            ),
            feedbackIds: $this->decodeStringList($row['feedback_ids'] ?? null),
            version: ($row['version'] ?? null) === null ? 1 : (int) $row['version'],
            rubricVersionId: ($row['rubric_version_id'] ?? null) === null
                ? null
                : (int) $row['rubric_version_id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(mixed $value): array
    {
        if (!\is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function decodeStringList(mixed $value): array
    {
        return array_values(array_filter($this->decodeObject($value), '\is_string'));
    }

    private function dateTime(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
