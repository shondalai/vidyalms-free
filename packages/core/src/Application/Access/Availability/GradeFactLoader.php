<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\GradeFacts;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The percentage map a score leaf reads.
 *
 * The best percentage per quiz over the enrolment's closed attempts, the same
 * percentage projected onto the lesson holding the quiz so a leaf can name
 * either, and the course total from course_progress. No grade recorded is
 * absent rather than zero, because a band whose minimum is zero would
 * otherwise admit a learner who has sat nothing.
 */
final class GradeFactLoader implements AvailabilityFactLoaderInterface
{
    private const ATTEMPT_IN_PROGRESS = 'in_progress';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function factKey(): string
    {
        return AvailabilityContext::FACT_GRADES;
    }

    public function load(AvailabilityContext $context, array $courseIds): mixed
    {
        $enrolmentId = $context->enrolmentId();

        if ($courseIds === [] || $enrolmentId === null) {
            return GradeFacts::pack([], []);
        }

        $percentages = [];
        $titles = [];

        $quizzes = $this->database->selectAll(
            Query::from('quizzes')
                ->select(['id', 'title', 'lesson_id'])
                ->whereIn('course_id', $courseIds),
        );

        $lessonOfQuiz = [];
        $quizIds = [];

        foreach ($quizzes as $row) {
            $quizId = (int) $row['id'];
            $quizIds[] = $quizId;
            $titles[GateSubject::TYPE_QUIZ][$quizId] = (string) ($row['title'] ?? '');

            if ($row['lesson_id'] !== null) {
                $lessonOfQuiz[$quizId] = (int) $row['lesson_id'];
            }
        }

        if ($quizIds !== []) {
            $attempts = $this->database->selectAll(
                Query::from('attempts')
                    ->select(['quiz_id', 'percentage'])
                    ->where('enrolment_id', '=', $enrolmentId)
                    ->whereIn('quiz_id', $quizIds)
                    ->where('status', '!=', self::ATTEMPT_IN_PROGRESS)
                    ->whereNotNull('percentage'),
            );

            foreach ($attempts as $row) {
                $quizId = (int) $row['quiz_id'];
                $percentage = (float) $row['percentage'];
                $best = $percentages[GateSubject::TYPE_QUIZ][$quizId] ?? null;

                if ($best === null || $percentage > $best) {
                    $percentages[GateSubject::TYPE_QUIZ][$quizId] = $percentage;

                    if (isset($lessonOfQuiz[$quizId])) {
                        $percentages[GateSubject::TYPE_LESSON][$lessonOfQuiz[$quizId]] = $percentage;
                    }
                }
            }
        }

        $courseProgress = $this->database->selectOne(
            Query::from('course_progress')
                ->select(['percent'])
                ->where('enrolment_id', '=', $enrolmentId),
        );

        if ($courseProgress !== null && $courseProgress['percent'] !== null) {
            foreach ($courseIds as $courseId) {
                $percentages[GateSubject::TYPE_COURSE][$courseId] = (float) $courseProgress['percent'];
            }
        }

        return GradeFacts::pack($percentages, $titles);
    }
}
