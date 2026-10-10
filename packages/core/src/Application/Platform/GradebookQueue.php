<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Assessment\QuestionTypes;
use Shondalai\VidyaLMS\Core\Application\Assessment\ResolvedQuestionType;
use Shondalai\VidyaLMS\Core\Application\Assessment\SignedMedia;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GradingBacklog;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Platform\GradebookRepository;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;
use Shondalai\VidyaLMS\Core\Registry\Rubric\RubricRegistry;

/**
 * Assembles the two sources of grading work for one course into one list:
 * assignment submissions, and the served questions of submitted attempts
 * that a human must mark.
 *
 * Two rules changed here and both were bugs against stated intent.
 *
 * It used to catch UnknownProviderException and skip. That is worse than
 * throwing: removing a package dropped every essay, upload, drawing and
 * recording awaiting a human silently out of the queue and out of the
 * pending count, with no error anywhere, and an attempt with a pending essay
 * read as fully graded. An unresolvable type is now counted as pending with
 * an explanatory row naming the missing package.
 *
 * And it used to read the answers table, so an item the learner never opened
 * had no row and never reached the queue. It reads the frozen served set, so
 * whether a human is needed is decided from the authored intent rather than
 * from whether anything was written.
 *
 * This holds no authorization. Every caller is a use case that has already
 * applied the per-course rule.
 */
final class GradebookQueue implements GradingBacklog
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * The signer is an optional last parameter, which is how every
     * dependency added to a shipped class arrives here: a required one
     * white-screens an installed Pro build the moment the two packages are a
     * release apart. Without it the queue behaves as it did, which is to say
     * a grader sees a broken image.
     */
    public function __construct(
        private readonly GradebookRepository $gradebook,
        private readonly QuestionTypes $types,
        private readonly UsersPort $users,
        private readonly ?SignedMedia $media = null,
        /**
         * Rubrics, when a package provides them. Optional and last so an
         * adapter built before the seam existed keeps constructing this use
         * case, and so a site with no provider answers a null rubric rather
         * than failing.
         */
        private readonly ?RubricRegistry $rubrics = null,
    ) {
    }

    /**
     * @return list<GradebookItem>
     */
    public function forCourse(int $courseId): array
    {
        $names = new LearnerNames($this->users);

        $items = [
            ...$this->submissionItems($courseId, $names),
            ...$this->answerItems($courseId, $names),
        ];

        // One users call for the whole queue, made after every row is known:
        // the names cross the CMS boundary in a single lookup rather than one
        // per learner.
        $names->resolve();

        return array_map(
            static fn (GradebookItem $item): GradebookItem => $item->withLearnerName($names->of($item->learnerId)),
            $items,
        );
    }

    public function pendingCountFor(int $courseId): int
    {
        $pending = 0;

        foreach ($this->forCourse($courseId) as $item) {
            if ($item->isPending()) {
                ++$pending;
            }
        }

        return $pending;
    }

    /**
     * @return list<GradebookItem>
     */
    private function submissionItems(int $courseId, LearnerNames $names): array
    {
        $rows = $this->gradebook->submissionsForCourse($courseId);
        $filesBySubmission = [];

        $submissionIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        foreach ($this->gradebook->filesFor($submissionIds) as $file) {
            $filesBySubmission[(int) $file['submission_id']][] = new GradebookFile(
                (string) $file['path'],
                (string) $file['filename'],
                (int) $file['size_bytes'],
                (string) $file['mime'],
            );
        }

        $items = [];

        foreach ($rows as $row) {
            $submittedAt = $this->dateTime($row['submitted_at']);

            if ($submittedAt === null) {
                continue;
            }

            $id = (int) $row['id'];
            $userId = (int) $row['user_id'];
            /** @var array<string, mixed> $assessment */
            $assessment = json_decode((string) ($row['assessment_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $maxPoints = (float) ($assessment['max_points'] ?? $row['max_points']);
            $graded = (string) $row['status'] === Submission::STATUS_GRADED;
            $gradedAt = $this->dateTime($row['graded_at']);
            $graderId = $row['grader_id'] === null ? null : (int) $row['grader_id'];

            $grade = $graded && $gradedAt !== null && $graderId !== null
                ? new ManualGrade(
                    (float) $row['points_awarded'],
                    $maxPoints,
                    $row['feedback'] === null ? null : (string) $row['feedback'],
                    $gradedAt,
                    $graderId,
                )
                : null;

            $names->want($userId);

            $items[] = new GradebookItem(
                'submission:' . $id,
                GradebookItem::TYPE_SUBMISSION,
                $grade === null ? GradebookItem::STATUS_PENDING : GradebookItem::STATUS_GRADED,
                $userId,
                null,
                $courseId,
                (int) $row['lesson_id'],
                (string) $row['lesson_title'],
                $submittedAt,
                $grade,
                new GradebookSubmission(
                    $id,
                    (int) $row['assignment_id'],
                    (int) $row['enrolment_id'],
                    (int) $row['attempt_number'],
                    $row['text_response'] === null ? null : (string) $row['text_response'],
                    $submittedAt,
                    $maxPoints,
                    (int) $row['allow_resubmission'] === 1,
                    $filesBySubmission[$id] ?? [],
                    $assessment + [
                        'pass_percentage' => isset($row['pass_percentage']) ? (float) $row['pass_percentage'] : null,
                        'rubric' => json_decode((string) ($row['rubric_json'] ?? '[]'), true, 512, JSON_THROW_ON_ERROR),
                    ],
                ),
                null,
            );
        }

        return $items;
    }

    /**
     * @return list<GradebookItem>
     */
    private function answerItems(int $courseId, LearnerNames $names): array
    {
        $answerRows = $this->gradebook->attemptAnswersForCourse($courseId);
        $rows = $this->servedRows($courseId, $answerRows);

        if ($rows === []) {
            return [];
        }

        $attemptIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['attempt_id'],
            $rows,
        )));

        $latestGrades = [];

        foreach ($this->gradebook->manualGradesFor($attemptIds) as $grade) {
            $gradedAt = $this->dateTime($grade['graded_at']);

            if ($gradedAt === null || $grade['question_id'] === null || $grade['grader_id'] === null) {
                continue;
            }

            $latestGrades[(int) $grade['attempt_id'] . ':' . (int) $grade['question_id']] = new ManualGrade(
                (float) $grade['points_awarded'],
                (float) $grade['max_points'],
                $grade['feedback'] === null ? null : (string) $grade['feedback'],
                $gradedAt,
                (int) $grade['grader_id'],
            );
        }

        $frozenDefinitions = $this->gradebook->definitionsByHash(array_map(
            static fn (array $row): string => \is_string($row['definition_hash'] ?? null)
                ? $row['definition_hash']
                : '',
            $rows,
        ));

        $items = [];

        foreach ($rows as $row) {
            $submittedAt = $this->dateTime($row['submitted_at']);

            if ($submittedAt === null) {
                continue;
            }

            // An item the learner never opened has no answered_at, and it is
            // exactly the item that used to fall out of the queue. The
            // submission moment stands in for it, so an unanswered essay is
            // ordered with the attempt it belongs to.
            $answeredAt = $this->dateTime($row['answered_at'] ?? null) ?? $submittedAt;

            // The FROZEN definition, never the live questions row.
            //
            // Deciding pending-human from the live row meant an authoring edit
            // after submission silently emptied the instructor's queue: adding
            // one accepted spelling to a submitted short answer took the item
            // out of the queue, left the attempt at submitted with a null pass
            // flag and its grade row at pending_external_scoring, and removed
            // the only row a grader could have closed it from. It also made
            // this use case and ManualGradingStatus disagree about the same
            // attempt, because that one already asked the frozen definition.
            $frozen = $frozenDefinitions[(string) ($row['definition_hash'] ?? '')] ?? null;
            $definition = $frozen === null
                ? $this->decode($row['question_definition'])
                : $this->decode($frozen['definition']);
            $answer = $this->signedAnswer($this->decodeAnswer($row['answer'] ?? null), (int) $row['user_id']);
            $type = $frozen === null ? (string) $row['question_type'] : (string) $frozen['type'];
            $version = $this->versionOf($row, $frozen);
            $externalScored = isset($row['question_external_scored']) && \is_string($row['question_external_scored'])
                ? $row['question_external_scored']
                : null;

            // An unresolvable type is counted as pending with an explanatory
            // row rather than skipped. Catching and continuing was worse than
            // throwing: every essay, upload, drawing and recording awaiting a
            // human dropped silently out of the queue and out of the pending
            // count, with no error anywhere, and an attempt with a pending
            // essay read as fully graded.
            $resolved = $this->types->resolve($type, $version);

            if (!$resolved->requiresHumanGrader($definition, $externalScored)) {
                continue;
            }

            $attemptId = (int) $row['attempt_id'];
            $questionId = (int) $row['question_id'];
            $userId = (int) $row['user_id'];
            $grade = $latestGrades[$attemptId . ':' . $questionId] ?? null;

            $names->want($userId);

            $items[] = new GradebookItem(
                'answer:' . $attemptId . ':' . $questionId,
                GradebookItem::TYPE_QUIZ_ANSWER,
                $grade === null ? GradebookItem::STATUS_PENDING : GradebookItem::STATUS_GRADED,
                $userId,
                null,
                $courseId,
                // A quiz need not hang off a lesson; when it does not, the
                // row names the quiz and carries no lesson at all.
                $row['lesson_id'] === null ? null : (int) $row['lesson_id'],
                $row['lesson_title'] === null ? (string) $row['quiz_title'] : (string) $row['lesson_title'],
                $submittedAt,
                $grade,
                null,
                new GradebookQuizAnswer(
                    $attemptId,
                    (int) $row['quiz_id'],
                    (string) $row['quiz_title'],
                    (int) $row['attempt_number'],
                    $questionId,
                    (int) $row['question_position'],
                    $type,
                    (float) $row['question_points'],
                    // The frozen projection when there is one, so a queue row
                    // renders as the learner saw it and still renders when
                    // the provider is gone. The response shape is what the
                    // generic fallback renderer keys on.
                    $this->projectionOf($row, $resolved, $definition),
                    $answer,
                    $answeredAt,
                    // The rubric a rater marks this answer against, resolved
                    // here so the queue is one round trip. Null on a site
                    // with no rubric provider, which is what makes the
                    // grading surface degrade to a points box.
                    self::rubricShape($this->rubrics, $questionId),
                ),
            );
        }

        return $items;
    }

    /**
     * The served set of every attempt that has one, plus the answer rows of
     * every attempt that predates the manifest.
     *
     * Both sources are needed and neither is redundant. The manifest has a
     * row for an item the learner left blank, which is the one the queue used
     * to lose; an attempt started before the manifest existed has no manifest
     * rows at all and would otherwise vanish from the queue entirely.
     *
     * @param list<array<string, mixed>> $answerRows
     *
     * @return list<array<string, mixed>>
     */
    private function servedRows(int $courseId, array $answerRows): array
    {
        $served = $this->gradebook->attemptQuestionsForCourse($courseId);
        $answersByKey = [];
        $attemptsWithManifest = [];

        foreach ($answerRows as $row) {
            $answersByKey[(int) $row['attempt_id'] . ':' . (int) $row['question_id']] = $row;
        }

        $rows = [];

        foreach ($served as $row) {
            $attemptId = (int) $row['attempt_id'];
            $attemptsWithManifest[$attemptId] = true;
            $answer = $answersByKey[$attemptId . ':' . (int) $row['question_id']] ?? [];

            $rows[] = $row + [
                'answer' => $answer['answer'] ?? null,
                'answered_at' => $answer['answered_at'] ?? null,
                'question_position' => $row['position'] ?? 0,
                'question_points' => $row['points'] ?? 0,
            ];
        }

        foreach ($answerRows as $row) {
            if (!isset($attemptsWithManifest[(int) $row['attempt_id']])) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    /**
     * The rubric a rater marks one answer against, or null when this site
     * has no rubric provider or the question is marked on points.
     *
     * @return array<string, mixed>|null
     */
    private static function rubricShape(?RubricRegistry $rubrics, int $questionId): ?array
    {
        $resolved = $rubrics?->forQuestion($questionId);

        return $resolved === null ? null : $resolved[1]->toArray();
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function projectionOf(array $row, ResolvedQuestionType $resolved, array $definition): array
    {
        $presented = $row['presented'] ?? null;

        if (\is_string($presented) && $presented !== '') {
            return $this->signed($resolved, $this->decode($presented));
        }

        $shape = isset($row['question_response_shape']) && \is_string($row['question_response_shape'])
            ? $row['question_response_shape']
            : null;

        return $this->signed($resolved, $resolved->present($definition, PresentationContext::unseeded(), $shape));
    }

    /**
     * The learner's own submitted files, resolved to signed, expiring URLs
     * for the grader.
     *
     * Without it no artifact type is markable: the grader is asked to mark a
     * file they cannot open, and the private storage path travels to the
     * browser as plain text. The response shape the design gives a file is
     * {ref, data, mime, name, size_bytes} with data null, so the ref is what
     * is walked for, wherever in the envelope it sits.
     *
     * The URL goes beside the ref rather than replacing it, because a grade
     * row and a regrade both key on the stored ref and the queue is a read
     * projection rather than a rewrite.
     */
    private function signedAnswer(mixed $answer, int $userId): mixed
    {
        if (!\is_array($answer) || $this->media === null) {
            return $answer;
        }

        $ref = $answer['ref'] ?? null;

        // Client-supplied URLs are never authoritative in the grader view.
        unset($answer[SignedMedia::URL_KEY]);
        if (\is_string($ref) && \Shondalai\VidyaLMS\Core\Domain\Shared\MediaRef::isLearnerWork($ref)
            && (str_starts_with($ref, 'attempts/' . $userId . '/') || str_starts_with($ref, 'submissions/' . $userId . '/'))) {
            $answer[SignedMedia::URL_KEY] = $this->media->signedUrlFor($ref);
        }

        foreach ($answer as $key => $value) {
            if (\is_array($value)) {
                $answer[$key] = $this->signedAnswer($value, $userId);
            }
        }

        return $answer;
    }
    /**
     * The projection with every declared storage ref turned into a signed,
     * expiring URL, exactly as the learner read path does it.
     *
     * The queue used to hand the frozen projection over verbatim, so the
     * grader of a drawing saw a broken background and the private storage
     * path of every media-bearing item was published as plain text. Every
     * artifact type defaults to external marking and is therefore always in
     * this queue, which makes it the one place the signing seam matters
     * most.
     *
     * @param array<string, mixed> $presented
     *
     * @return array<string, mixed>
     */
    private function signed(ResolvedQuestionType $resolved, array $presented): array
    {
        return $this->media?->inProjection($resolved, $presented) ?? $presented;
    }

    /**
     * The version of the definition actually in hand.
     *
     * The frozen row carries its own, and it is the one the gate must be
     * asked about; the live column is the pre-1.1.0 fallback for an attempt
     * with no manifest row.
     *
     * @param array<string, mixed>      $row
     * @param array<string, mixed>|null $frozen
     */
    private function versionOf(array $row, ?array $frozen): int
    {
        if ($frozen !== null) {
            return max(1, (int) ($frozen['definition_version'] ?? 1));
        }

        return isset($row['question_definition_version']) ? (int) $row['question_definition_version'] : 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (!\is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);

        /** @var array<string, mixed> */
        return \is_array($decoded) ? $decoded : [];
    }

    private function decodeAnswer(mixed $value): mixed
    {
        if (!\is_string($value)) {
            return null;
        }

        return json_decode($value, true);
    }

    private function dateTime(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::DATETIME_FORMAT, $value, new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed;
    }
}
