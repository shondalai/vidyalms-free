<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Grades;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeChange;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeProgress;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Grades\GradeBookStore;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationRegistry;
use Shondalai\VidyaLMS\Core\Provider\GradeAggregation\MeanGradeAggregation;

/**
 * Where `course_progress.quiz_average` stops being a number the progress
 * recalculation adds up for itself.
 *
 * Two things happen here and they are deliberately the same call. The best
 * percentage per reachable quiz is written into the gradebook as a grade cell
 * with its own change entry, and the cached average is then folded out of those
 * cells by a `GradeAggregation` provider. The number on the progress bar is
 * therefore derived from stored grade cells through the registry, exactly like
 * every other total in the product, rather than from an `array_sum` in the
 * middle of a use case that has nine other jobs.
 *
 * **The write is a no-op when nothing moved.** A progress recalculation runs on
 * every lesson completion and every attempt, and a change log carrying an entry
 * for every one of them would be a log nobody can read during the dispute it
 * exists for. {@see RecordGrade} compares before writing, so a recompute that
 * changes no grade writes no row.
 *
 * **The inputs stay exactly what they were.** Best percentage per quiz, over
 * graded attempts, over the quizzes the learner can still reach: an archived
 * lesson's quiz has left the course and must stop steering the average, or
 * archiving the lesson somebody failed would leave the failure in the number
 * for ever. Moving the arithmetic does not move the question.
 */
final class QuizGradeSync
{
    public function __construct(
        private readonly RecordGrade $grades,
        private readonly GradeBookStore $store,
        private readonly GradeAggregationRegistry $aggregations,
        /**
         * Trailing and defaulted, so a caller built before it existed keeps
         * constructing this class. It is here because the failure mode without
         * it is the worst kind: the progress recalculation runs inside an event
         * listener, the bus logs a listener failure and continues, and a
         * gradebook write that threw would leave the progress bar frozen at
         * whatever it last said with nothing naming the gradebook.
         */
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Records the cells and answers the average, or null when there is none.
     *
     * A null answer sends the caller back to its own arithmetic, which is the
     * safe direction: the gradebook is a derived store, and a derived store
     * that cannot be written must not be allowed to stop the number it derives
     * from being computed.
     *
     * @param array<int, float> $bestPerQuiz quiz id => best percentage
     */
    public function syncAndAverage(int $courseId, int $userId, array $bestPerQuiz): ?float
    {
        try {
            return $this->fold($courseId, $userId, $bestPerQuiz);
        } catch (\Throwable $failure) {
            $this->logger->warning(
                'A VidyaLMS course average could not be folded out of the gradebook: course {course}, {reason}.',
                ['course' => $courseId, 'reason' => $failure->getMessage()],
            );

            return null;
        }
    }

    /**
     * @param array<int, float> $bestPerQuiz
     */
    private function fold(int $courseId, int $userId, array $bestPerQuiz): ?float
    {
        if ($bestPerQuiz === []) {
            return null;
        }

        $cells = [];

        foreach ($bestPerQuiz as $quizId => $percentage) {
            $item = GradeItem::forSource(
                $courseId,
                GradeItem::SOURCE_QUIZ,
                $quizId,
                'Quiz ' . $quizId,
                100.0,
            );

            $stored = $this->grades->bySystem(
                $item,
                $userId,
                round($percentage, 5),
                100.0,
                // A quiz whose best attempt has a percentage has been graded on
                // both AGS axes: the learner finished it and the marking, human
                // or machine, produced the number this cell holds.
                GradeProgress::autoGraded(),
                GradeChange::REASON_REGRADE,
                'course-progress:' . $courseId . ':quiz:' . $quizId . ':u' . $userId,
                ['quiz_id' => $quizId, 'source' => 'course_progress_recompute'],
            );

            $cells[] = $stored->forAggregation('quiz:' . $quizId, $item->weightBp);
        }

        // The registry is the only arithmetic. `mean` is the strategy the
        // cached average has always used, named rather than open-coded, and a
        // site that replaces the provider changes the number here and in the
        // gradebook together instead of in one of the two.
        $total = $this->aggregations->total(
            MeanGradeAggregation::KEY,
            $cells,
            GradeAggregationContext::create('course_progress:' . $courseId, false, 0.0, 100.0 * \count($cells)),
        );

        if ($total === null || $total->empty || $total->maximum <= 0.0) {
            return null;
        }

        // Computed off the value and the maximum rather than through
        // percentage(), because that method rounds to four places first and a
        // second rounding over a rounded number can move the last digit of an
        // average that used to be computed in one step.
        return round($total->value / $total->maximum * 100, 2);
    }

    /**
     * Whether this course has any gradebook rows at all, which is what a screen
     * asks before offering a gradebook tab.
     */
    public function hasItems(int $courseId): bool
    {
        return $this->store->itemsForCourse($courseId) !== [];
    }
}
