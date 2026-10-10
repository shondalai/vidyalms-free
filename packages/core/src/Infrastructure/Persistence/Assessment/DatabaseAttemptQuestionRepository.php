<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptQuestion;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptQuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The frozen manifest against the database port. Rows are written once per
 * attempt and only the answered status is ever updated afterwards, which is
 * what makes the manifest a record of what was served rather than a cache of
 * the current quiz.
 */
final class DatabaseAttemptQuestionRepository implements AttemptQuestionRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function freeze(int $attemptId, array $questions): void
    {
        if ($questions === []) {
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->transactional(function () use ($attemptId, $questions, $now): void {
            foreach ($questions as $question) {
                $this->database->upsert(
                    'attempt_questions',
                    [
                        'attempt_id' => $attemptId,
                        'position' => $question->position,
                        'screen' => $question->screen,
                        'question_id' => $question->questionId,
                        'question_version_id' => $question->questionVersionId,
                        'points' => $question->points,
                        'scored' => $question->scored ? 1 : 0,
                        'presented' => json_encode($question->presented, JSON_THROW_ON_ERROR),
                        'choice_sequence' => $question->choiceSequence->isEmpty()
                            ? null
                            : json_encode($question->choiceSequence->toArray(), JSON_THROW_ON_ERROR),
                        'variant' => $question->variant === null
                            ? null
                            : json_encode($question->variant, JSON_THROW_ON_ERROR),
                        'definition_hash' => $question->definitionHash,
                        'answered_status' => $question->answeredStatus->value,
                        'policy_key' => $question->policyKey,
                        'policy_params' => $question->policyParams === []
                            ? null
                            : json_encode($question->policyParams, JSON_THROW_ON_ERROR),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    ['attempt_id', 'position'],
                    [
                        'screen',
                        'question_id',
                        'question_version_id',
                        'points',
                        'scored',
                        'presented',
                        'choice_sequence',
                        'variant',
                        'definition_hash',
                        'answered_status',
                        'policy_key',
                        'policy_params',
                        'updated_at',
                    ],
                );
            }
        });
    }

    public function forAttempt(int $attemptId): array
    {
        $rows = $this->database->selectAll(
            Query::from('attempt_questions')
                ->where('attempt_id', '=', $attemptId)
                ->orderBy('position')
                ->orderBy('id'),
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function recordAnsweredStatus(int $attemptId, int $questionId, AnsweredStatus $status): void
    {
        $this->database->update(
            'attempt_questions',
            ['answered_status' => $status->value, 'updated_at' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['attempt_id' => $attemptId, 'question_id' => $questionId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): AttemptQuestion
    {
        return new AttemptQuestion(
            id: (int) $row['id'],
            attemptId: (int) $row['attempt_id'],
            position: (int) $row['position'],
            screen: (int) $row['screen'],
            questionId: (int) $row['question_id'],
            questionVersionId: $row['question_version_id'] === null ? null : (int) $row['question_version_id'],
            points: (float) $row['points'],
            scored: (int) $row['scored'] === 1,
            presented: $this->decode($row['presented']),
            choiceSequence: ChoiceSequence::fromArray(
                $row['choice_sequence'] === null ? null : $this->decode($row['choice_sequence']),
            ),
            definitionHash: (string) $row['definition_hash'],
            answeredStatus: AnsweredStatus::fromWire($row['answered_status'] === null ? null : (string) $row['answered_status']),
            variant: $row['variant'] === null ? null : $this->decode($row['variant']),
            policyKey: ($row['policy_key'] ?? null) === null ? null : (string) $row['policy_key'],
            policyParams: ($row['policy_params'] ?? null) === null ? [] : $this->decode($row['policy_params']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (!\is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
    }
}
