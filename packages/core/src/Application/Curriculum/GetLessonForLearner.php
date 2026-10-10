<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Application\Assessment\ReportableQuizGrade;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationContext;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AttemptScore;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The player view of one lesson: metadata, the learner presentation of the
 * authored content, the caller's own progress, and, on quiz lessons, the
 * attempt summary the quiz runner needs to start or resume.
 *
 * Access follows enrolment. An active or completed enrolment (or an
 * authoring capability) opens the lesson; a suspended enrolment is refused
 * with a conflict, the same rule the progress heartbeat applies; without an
 * enrolment a free-preview lesson of a published course is served through
 * its lesson type's preview presentation (PresentsFreePreview), and any
 * other lesson is not authorized. A preview carries no progress, and the
 * heartbeat, the attempt start and the submission refuse a caller without
 * an enrolment on their own account, so opening a preview records nothing.
 * Draft lessons and unpublished courses answer "not found" for callers
 * without authoring capabilities, exactly like the curriculum, so their
 * existence is never leaked.
 */
final class GetLessonForLearner
{
    private const ENROLMENT_STATUS_ACTIVE = 'active';
    private const ENROLMENT_STATUS_COMPLETED = 'completed';
    private const ENROLMENT_STATUS_SUSPENDED = 'suspended';
    private const ATTEMPT_STATUS_IN_PROGRESS = 'in_progress';
    private const QUESTION_STATE_PUBLISHED = 'published';
    private const LESSON_TYPE_QUIZ = 'quiz';
    private const SHOW_RESULTS_NEVER = 'never';
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly CourseRepository $courses,
        private readonly LessonTypeRegistry $lessonTypes,
        private readonly CourseAccess $access,
        private readonly DatabasePort $database,
        // The reportable grade is a provider decision, not a max(). The
        // learner's best percentage used to be computed here with a literal
        // max over every closed attempt, which quietly contradicted both
        // quiz settings that decide it: attempts_counted filters the set and
        // attempt_aggregation reduces what is left.
        private readonly ?AggregationMethodRegistry $aggregations = null,
        /**
         * Availability is enforced here and not only in the player, for the
         * reason the coding standards already give: a policy guard written
         * in an adapter covers only the routes that adapter happens to own.
         * Optional and defaulted, so a kernel with no engine answers exactly
         * what it answered before.
         */
        private readonly ?AvailabilityEngine $availability = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $principal, int $lessonId): array
    {
        $lesson = $this->lessons->byId($lessonId);
        $course = $lesson === null ? null : $this->courses->byId($lesson->courseId);
        $author = $course !== null && $this->access->mayAuthor($principal, $course->id);

        // A free preview cannot bypass the parent course's visibility.
        if (
            $lesson === null
            || $course === null
            || !$this->access->mayRead($principal, $course)
            || (!$author && !$lesson->isPublished())
            || (!$author && (new LessonAvailability($this->database))->forLearner($lessonId, $lesson->courseId) === null)
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Lesson', $lessonId);
        }

        $enrolment = $this->enrolmentOf($principal, $lesson->courseId);
        $access = $this->availabilityOf($principal, $lesson, $enrolment, $author);
        // Judged before the restriction is applied, so a refusal is a
        // refusal whatever the restriction says. A preview opens a lesson to
        // visitors; it does not lift a restriction, so a closed preview
        // lesson is withheld like any other.
        $granted = $author ? LessonContentAccess::Full : $this->judgeAccess($enrolment, $lesson);
        $contentAccess = $access['state'] === 'available' ? $granted : LessonContentAccess::Withheld;

        [$content, $contentAvailable] = $this->presentContent($lesson, $contentAccess);

        return [
            'id' => $lesson->id,
            'course_id' => $lesson->courseId,
            'module_id' => $lesson->moduleId,
            'title' => $lesson->title,
            'slug' => $lesson->slug->value,
            'type' => $lesson->type,
            'summary' => $lesson->summary,
            'duration_seconds' => $lesson->durationSeconds,
            'position' => $lesson->position,
            'free_preview' => $lesson->freePreview,
            'content' => $content,
            'content_available' => $access['state'] === 'available' && $contentAvailable,
            'access' => $access,
            'progress' => $enrolment === null ? null : $this->progressOf((int) $enrolment['id'], $lessonId),
            'quiz' => $lesson->type === self::LESSON_TYPE_QUIZ ? $this->quizSummary($principal, $lesson) : null,
        ];
    }

    /**
     * The learner access block, always present.
     *
     * An author sees the item as available whatever the restrictions say,
     * because an author is looking at the course rather than working through
     * it, and a rule that hid the item from its own author would make the
     * preview route the only way to see what was authored.
     *
     * @param array<string, mixed>|null $enrolment
     *
     * @return array<string, mixed>
     */
    private function availabilityOf(Principal $principal, Lesson $lesson, ?array $enrolment, bool $author): array
    {
        if ($this->availability === null || $author) {
            return AvailabilityOutcome::available()->toLearnerArray();
        }

        return $this->availability
            ->forSubject(
                $principal,
                new GateSubject(GateSubject::TYPE_LESSON, $lesson->id),
                $lesson->courseId,
                $enrolment,
                new GateSubject(GateSubject::TYPE_MODULE, $lesson->moduleId),
            )
            ->outcome
            ->toLearnerArray();
    }

    /**
     * Read-only cross-context lookup into the Access tables; enrolments are
     * unique per user and course.
     *
     * @return array<string, mixed>|null
     */
    private function enrolmentOf(Principal $principal, int $courseId): ?array
    {
        if ($principal->isGuest()) {
            return null;
        }

        return $this->database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', $principal->userId)
                ->where('course_id', '=', $courseId),
        );
    }

    /**
     * Full for a usable enrolment, Preview for a free-preview lesson without
     * one. Everything else refuses: suspension with a conflict (suspension
     * blocks learning, the same rule as the heartbeat, and a preview does
     * not reopen it), no usable enrolment with not authorized, which the
     * kernel maps to 401 for guests.
     *
     * @param array<string, mixed>|null $enrolment
     */
    private function judgeAccess(?array $enrolment, Lesson $lesson): LessonContentAccess
    {
        if ($enrolment !== null) {
            $status = (string) $enrolment['status'];

            if ($status === self::ENROLMENT_STATUS_ACTIVE || $status === self::ENROLMENT_STATUS_COMPLETED) {
                return LessonContentAccess::Full;
            }

            if ($status === self::ENROLMENT_STATUS_SUSPENDED) {
                throw new ConflictException('The enrolment is suspended; the lesson cannot be opened.');
            }
        }

        if ($lesson->freePreview) {
            return LessonContentAccess::Preview;
        }

        throw new NotAuthorizedException('An enrolment in the course is required to open this lesson.');
    }

    /**
     * The learner presentation of the stored content. A lesson type whose
     * provider is not registered (a Pro type after Pro was removed) degrades
     * to a placeholder: content null, content_available false, while the
     * rest of the lesson still renders.
     *
     * @return array{0: array<string, mixed>|null, 1: bool}
     */
    private function presentContent(Lesson $lesson, LessonContentAccess $contentAccess): array
    {
        $stored = $this->lessons->contentOf($lesson->id);

        if ($stored === null) {
            return [null, false];
        }

        try {
            $type = $this->lessonTypes->get($lesson->type);
        } catch (UnknownProviderException) {
            return [null, false];
        }

        return [match ($contentAccess) {
            LessonContentAccess::Full => $type->presentForLearner($stored, true),
            // A type that never said what its preview shows previews as
            // "no access"; see PresentsFreePreview for why that is closed.
            LessonContentAccess::Preview => $type instanceof PresentsFreePreview
                ? $type->presentForPreview($stored)
                : $type->presentForLearner($stored, false),
            LessonContentAccess::Withheld => $type->presentForLearner($stored, false),
        }, true];
    }

    /**
     * The caller's own row, read-only from the Progress tables.
     *
     * @return array<string, mixed>|null
     */
    private function progressOf(int $enrolmentId, int $lessonId): ?array
    {
        $row = $this->database->selectOne(
            Query::from('lesson_progress')
                ->where('enrolment_id', '=', $enrolmentId)
                ->where('lesson_id', '=', $lessonId),
        );

        if ($row === null) {
            return null;
        }

        return [
            'status' => (string) $row['status'],
            'progress_percent' => (float) $row['progress_percent'],
            'seconds_spent' => (int) $row['seconds_spent'],
            'position_seconds' => $row['position_seconds'] === null ? null : (int) $row['position_seconds'],
            'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
        ];
    }

    /**
     * The runner's context for a quiz lesson: the quiz limits and the
     * caller's stored attempt totals, read-only from the Assessment tables.
     * Totals only, never grading rules; an open attempt surfaces as
     * last_attempt so the runner can offer resume, and it does not count as
     * used. No question correctness of any kind travels here. When the quiz
     * never shows results, best_percentage and the last attempt's percentage
     * and passed flag are withheld (null); attempt counts stay, they are not
     * results. An open attempt on a timed quiz carries expires_at, the
     * server deadline (started_at plus the time limit, see AttemptDeadline),
     * so the runner's timer syncs to the server clock.
     *
     * @return array<string, mixed>|null
     */
    private function quizSummary(Principal $principal, Lesson $lesson): ?array
    {
        $quiz = $this->database->selectOne(
            Query::from('quizzes')
                ->where('lesson_id', '=', $lesson->id)
                ->where('course_id', '=', $lesson->courseId)
                ->orderBy('id'),
        );

        if ($quiz === null) {
            return null;
        }

        $quizId = (int) $quiz['id'];
        $maxAttempts = $quiz['max_attempts'] === null ? null : (int) $quiz['max_attempts'];
        $timeLimitSeconds = $quiz['time_limit_seconds'] === null ? null : (int) $quiz['time_limit_seconds'];
        $revealsResults = (string) $quiz['show_results'] !== self::SHOW_RESULTS_NEVER;

        $attemptsUsed = 0;
        $bestPercentage = null;
        $gradeMethod = null;
        $gradeMethodSubstituted = false;
        $lastAttempt = null;
        $cooldownUntil = null;
        $scores = [];

        if (!$principal->isGuest()) {
            $rows = $this->database->selectAll(
                Query::from('attempts')
                    ->where('quiz_id', '=', $quizId)
                    ->where('user_id', '=', $principal->userId)
                    ->orderBy('attempt_number'),
            );

            foreach ($rows as $row) {
                $status = (string) $row['status'];

                if ($status !== self::ATTEMPT_STATUS_IN_PROGRESS) {
                    ++$attemptsUsed;
                }

                if ($status === Attempt::STATUS_SUBMITTED || $status === Attempt::STATUS_GRADED) {
                    $scores[] = new AttemptScore(
                        (int) $row['id'],
                        (int) $row['attempt_number'],
                        $row['score'] === null ? null : (float) $row['score'],
                        $row['max_score'] === null ? null : (float) $row['max_score'],
                        $row['percentage'] === null ? null : (float) $row['percentage'],
                        $row['passed'] === null ? null : (int) $row['passed'] === 1,
                    );
                }

                $cooldownUntil = $this->cooldownUntil($quiz, $row, $timeLimitSeconds);

                $lastAttempt = [
                    'id' => (int) $row['id'],
                    'status' => $status,
                    'percentage' => $revealsResults && $row['percentage'] !== null ? (float) $row['percentage'] : null,
                    'passed' => $revealsResults && $row['passed'] !== null ? (int) $row['passed'] === 1 : null,
                    'expires_at' => $status === self::ATTEMPT_STATUS_IN_PROGRESS
                        ? $this->attemptExpiresAt(
                            $timeLimitSeconds,
                            (string) $row['started_at'],
                            $row['expires_at'] ?? null,
                        )
                        : null,
                ];
            }

            [$grade, $gradeMethod, $gradeMethodSubstituted] = $this->reportableGradeWithMethod($quiz, $scores);
            $bestPercentage = $revealsResults ? $grade : null;
        }

        return [
            'id' => $quizId,
            'title' => (string) $quiz['title'],
            'description' => $quiz['description'] === null ? null : (string) $quiz['description'],
            'pass_mark' => (int) $quiz['pass_mark'],
            'max_attempts' => $maxAttempts,
            'attempts_used' => $attemptsUsed,
            'attempts_remaining' => $maxAttempts === null ? null : max(0, $maxAttempts - $attemptsUsed),
            'time_limit_seconds' => $timeLimitSeconds,
            'question_count' => $this->database->count(
                Query::from('questions')
                    ->where('quiz_id', '=', $quizId)
                    ->where('state', '=', self::QUESTION_STATE_PUBLISHED),
            ),
            'show_results' => (string) $quiz['show_results'],
            'best_percentage' => $bestPercentage,
            // Which rule produced it, and whether that is the rule the quiz
            // asked for. A substituted aggregation is a derived read with no
            // row of its own, so it travels here or it is invisible:
            // removing the package that provides average_best_n turns a
            // learner's average of their best three into their single
            // highest attempt with nothing anywhere saying so.
            'grade_method' => $gradeMethod,
            'grade_method_substituted' => $gradeMethodSubstituted,
            // The three facts a start control needs so that it never invites
            // a refusal it could have predicted.
            'opens_at' => $this->rowDateTime($quiz, 'opens_at'),
            'closes_at' => $this->rowDateTime($quiz, 'closes_at'),
            'cooldown_until' => $cooldownUntil,
            'last_attempt' => $lastAttempt,
        ];
    }

    /**
     * The one grade this quiz reports for this learner, through the
     * aggregation registry rather than a max over every attempt, with the
     * rule that produced it and whether that rule is the one the quiz asked
     * for.
     *
     * A site whose kernel predates the registry keeps the old behaviour, so
     * this stays a read that never fails: an unresolved method falls back to
     * highest, which is what the literal max was.
     *
     * **The substitution has to travel with the grade or it is invisible.**
     * Removing the package that provides average_best_n turns a learner's
     * average of their best three into their single highest attempt, here,
     * on the gradebook, and on any certificate keyed off it, and aggregation
     * is a derived read with no row of its own to record it on. A
     * substituted behaviour is written onto the attempt and shown by the
     * runner; this is the same honesty for the same class of miss.
     *
     * @param array<string, mixed> $quiz
     * @param list<AttemptScore>   $scores
     *
     * @return array{float|null, string, bool}
     */
    private function reportableGradeWithMethod(array $quiz, array $scores): array
    {
        $requested = $this->rowString($quiz, 'attempt_aggregation', 'highest');

        if ($scores === []) {
            return [null, $requested, false];
        }

        $counted = ReportableQuizGrade::counted($scores, $this->rowString($quiz, 'attempts_counted', 'all'));

        if ($counted === [] || $this->aggregations === null) {
            return [$this->highestOf($counted), $requested, false];
        }

        $method = $this->aggregations->resolve($requested);
        $grade = $method->aggregate(
            $counted,
            AggregationContext::create(
                (int) $quiz['id'],
                0,
                (int) $quiz['pass_mark'],
                $this->rowObject($quiz, 'attempt_aggregation_params'),
            ),
        );

        return [$grade?->percentage, $method->key(), $method->key() !== $requested];
    }

    /**
     * @param list<AttemptScore> $scores
     */
    private function highestOf(array $scores): ?float
    {
        $best = null;

        foreach ($scores as $score) {
            if ($score->percentage !== null) {
                $best = $best === null ? $score->percentage : max($best, $score->percentage);
            }
        }

        return $best;
    }

    /**
     * The earliest moment another attempt may start, or null when nothing
     * bars one.
     *
     * The rule is the one the Assessment context enforces: the previous
     * attempt's submitted_at plus the relevant delay, and for an attempt
     * never submitted that attempt's started_at plus the time limit plus the
     * delay, so abandoning an attempt is not a free instant retry.
     *
     * @param array<string, mixed> $quiz
     * @param array<string, mixed> $attempt
     */
    private function cooldownUntil(array $quiz, array $attempt, ?int $timeLimitSeconds): ?string
    {
        $number = (int) $attempt['attempt_number'];
        $seconds = $number <= 1
            ? $this->rowInt($quiz, 'cooldown_after_first_seconds')
            : ($this->rowInt($quiz, 'cooldown_after_later_seconds')
                ?? $this->rowInt($quiz, 'cooldown_after_first_seconds'));

        if ($seconds === null || $seconds <= 0) {
            return null;
        }

        $submittedAt = $this->rowDateTime($attempt, 'submitted_at');
        $from = $submittedAt ?? $this->attemptExpiresAt(
            $timeLimitSeconds,
            (string) $attempt['started_at'],
            $attempt['expires_at'] ?? null,
        ) ?? (string) $attempt['started_at'];

        return (new \DateTimeImmutable($from, new \DateTimeZone('UTC')))
            ->modify(sprintf('+%d seconds', $seconds))
            ->format(self::DATETIME_FORMAT);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowDateTime(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowString(array $row, string $key, string $default): string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowInt(array $row, string $key): ?int
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>|null
     */
    private function rowObject(array $row, string $key): ?array
    {
        $value = $row[$key] ?? null;

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

        return $object;
    }

    /**
     * The server deadline of an open attempt on a timed quiz, from the same
     * rule the Assessment context enforces; null when the quiz is untimed.
     *
     * The frozen column wins, so the learner reads the same deadline here as
     * in the runner even after the quiz time limit is edited. Recomputing is
     * the pre-1.1.0 fallback for a row written before the column was filled.
     */
    private function attemptExpiresAt(?int $timeLimitSeconds, string $startedAt, mixed $frozen): ?string
    {
        if (\is_string($frozen) && $frozen !== '') {
            return $frozen;
        }

        $expiresAt = AttemptDeadline::expiresAt(
            $timeLimitSeconds,
            new \DateTimeImmutable($startedAt, new \DateTimeZone('UTC')),
        );

        return $expiresAt?->format(self::DATETIME_FORMAT);
    }
}
