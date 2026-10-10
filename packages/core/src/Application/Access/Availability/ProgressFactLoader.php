<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\CompletionCondition;
use Shondalai\VidyaLMS\Core\Provider\Availability\ProgressFacts;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The item-state map one learner's whole render set is folded from.
 *
 * Five reads, whatever the item count: the course's lessons, this
 * enrolment's lesson progress, the course's quizzes with their pass marks,
 * this enrolment's attempts, and this enrolment's submissions. Without the
 * preload a completion leaf on every item of a sixty-item outline is sixty
 * queries and the query budget test fails, which is the failure that will not
 * surface until a customer has a large course with several leaves per item.
 */
final class ProgressFactLoader implements AvailabilityFactLoaderInterface
{
    private const ATTEMPT_IN_PROGRESS = 'in_progress';
    private const SUBMISSION_GRADED = 'graded';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function factKey(): string
    {
        return AvailabilityContext::FACT_PROGRESS;
    }

    public function load(AvailabilityContext $context, array $courseIds): mixed
    {
        if ($courseIds === []) {
            return ProgressFacts::pack([], [], []);
        }

        $states = [];
        $titles = [];
        $order = [];

        $lessons = $this->database->selectAll(
            Query::from('lessons', 'l')
                ->select(['l.id', 'l.title', 'l.module_id', 'l.position', 'm.position'])
                ->join('modules', 'm', 'l.module_id', 'm.id')
                ->whereIn('l.course_id', $courseIds)
                ->where('l.state', '=', Lesson::STATE_PUBLISHED)
                ->where('m.state', '=', Module::STATE_PUBLISHED)
                ->orderBy('m.position')
                ->orderBy('l.position')
                ->orderBy('l.id'),
        );

        $lessonIds = [];
        $moduleLessons = [];

        foreach ($lessons as $row) {
            $lessonId = (int) $row['id'];
            $lessonIds[] = $lessonId;
            $order[GateSubject::TYPE_LESSON][] = $lessonId;
            $titles[GateSubject::TYPE_LESSON][$lessonId] = (string) ($row['title'] ?? '');
            $states[GateSubject::TYPE_LESSON][$lessonId] = CompletionCondition::STATE_INCOMPLETE;
            $moduleLessons[(int) $row['module_id']][] = $lessonId;
        }

        $enrolmentId = $context->enrolmentId();

        if ($enrolmentId !== null && $lessonIds !== []) {
            $progress = $this->database->selectAll(
                Query::from('lesson_progress')
                    ->select(['lesson_id', 'completed_at'])
                    ->where('enrolment_id', '=', $enrolmentId)
                    ->whereIn('lesson_id', $lessonIds)
                    ->whereNotNull('completed_at'),
            );

            foreach ($progress as $row) {
                $states[GateSubject::TYPE_LESSON][(int) $row['lesson_id']] = CompletionCondition::STATE_COMPLETE;
            }
        }

        $this->loadModules($courseIds, $moduleLessons, $states, $titles, $order);
        $this->loadQuizzes($courseIds, $enrolmentId, $states, $titles);
        $this->loadAssignments($lessonIds, $enrolmentId, $states, $titles);

        return ProgressFacts::pack($states, $titles, $order);
    }

    /**
     * @param list<int>                         $courseIds
     * @param array<int, list<int>>             $moduleLessons
     * @param array<string, array<int, string>> $states
     * @param array<string, array<int, string>> $titles
     * @param array<string, list<int>>          $order
     */
    private function loadModules(array $courseIds, array $moduleLessons, array &$states, array &$titles, array &$order): void
    {
        $modules = $this->database->selectAll(
            Query::from('modules')
                ->select(['id', 'title'])
                ->whereIn('course_id', $courseIds)
                ->where('state', '=', Module::STATE_PUBLISHED)
                ->orderBy('position')
                ->orderBy('id'),
        );

        foreach ($modules as $row) {
            $moduleId = (int) $row['id'];
            $order[GateSubject::TYPE_MODULE][] = $moduleId;
            $titles[GateSubject::TYPE_MODULE][$moduleId] = (string) ($row['title'] ?? '');

            $children = $moduleLessons[$moduleId] ?? [];
            $complete = $children !== [];

            foreach ($children as $lessonId) {
                $state = $states[GateSubject::TYPE_LESSON][$lessonId] ?? CompletionCondition::STATE_INCOMPLETE;

                if ($state !== CompletionCondition::STATE_COMPLETE && $state !== CompletionCondition::STATE_COMPLETE_PASS) {
                    $complete = false;
                    break;
                }
            }

            $states[GateSubject::TYPE_MODULE][$moduleId] = $complete
                ? CompletionCondition::STATE_COMPLETE
                : CompletionCondition::STATE_INCOMPLETE;
        }
    }

    /**
     * @param list<int>                         $courseIds
     * @param array<string, array<int, string>> $states
     * @param array<string, array<int, string>> $titles
     */
    private function loadQuizzes(array $courseIds, ?int $enrolmentId, array &$states, array &$titles): void
    {
        $quizzes = $this->database->selectAll(
            Query::from('quizzes')
                ->select(['id', 'title', 'pass_mark'])
                ->whereIn('course_id', $courseIds),
        );

        $quizIds = [];

        foreach ($quizzes as $row) {
            $quizId = (int) $row['id'];
            $quizIds[] = $quizId;
            $titles[GateSubject::TYPE_QUIZ][$quizId] = (string) ($row['title'] ?? '');
            $states[GateSubject::TYPE_QUIZ][$quizId] = CompletionCondition::STATE_INCOMPLETE;
        }

        if ($enrolmentId === null || $quizIds === []) {
            return;
        }

        $attempts = $this->database->selectAll(
            Query::from('attempts')
                ->select(['quiz_id', 'passed', 'status'])
                ->where('enrolment_id', '=', $enrolmentId)
                ->whereIn('quiz_id', $quizIds)
                ->where('status', '!=', self::ATTEMPT_IN_PROGRESS),
        );

        foreach ($attempts as $row) {
            $quizId = (int) $row['quiz_id'];
            $passed = $row['passed'] !== null && (int) $row['passed'] === 1;
            $current = $states[GateSubject::TYPE_QUIZ][$quizId] ?? CompletionCondition::STATE_INCOMPLETE;

            if ($passed) {
                $states[GateSubject::TYPE_QUIZ][$quizId] = CompletionCondition::STATE_COMPLETE_PASS;
                continue;
            }

            if ($current !== CompletionCondition::STATE_COMPLETE_PASS) {
                $states[GateSubject::TYPE_QUIZ][$quizId] = CompletionCondition::STATE_COMPLETE_FAIL;
            }
        }
    }

    /**
     * @param list<int>                         $lessonIds
     * @param array<string, array<int, string>> $states
     * @param array<string, array<int, string>> $titles
     */
    private function loadAssignments(array $lessonIds, ?int $enrolmentId, array &$states, array &$titles): void
    {
        if ($lessonIds === []) {
            return;
        }

        $assignments = $this->database->selectAll(
            Query::from('assignments', 'a')
                ->select(['a.id', 'a.lesson_id', 'l.title'])
                ->join('lessons', 'l', 'a.lesson_id', 'l.id')
                ->whereIn('a.lesson_id', $lessonIds),
        );

        $assignmentIds = [];
        $lessonByAssignment = [];

        foreach ($assignments as $row) {
            $assignmentId = (int) $row['id'];
            $lessonByAssignment[$assignmentId] = (int) $row['lesson_id'];
            $assignmentIds[] = $assignmentId;
            $titles[GateSubject::TYPE_ASSIGNMENT][$assignmentId] = (string) ($row['title'] ?? '');
            $states[GateSubject::TYPE_ASSIGNMENT][$assignmentId] = CompletionCondition::STATE_INCOMPLETE;
        }

        if ($enrolmentId === null || $assignmentIds === []) {
            return;
        }

        $submissions = $this->database->selectAll(
            Query::from('submissions')
                ->select(['assignment_id', 'status', 'assessment_json'])
                ->where('enrolment_id', '=', $enrolmentId)
                ->whereIn('assignment_id', $assignmentIds)
                ->orderBy('attempt_number'),
        );

        foreach ($submissions as $row) {
            $assignmentId = (int) $row['assignment_id'];
            $states[GateSubject::TYPE_ASSIGNMENT][$assignmentId] = CompletionCondition::STATE_INCOMPLETE;
            if ((string) ($row['status'] ?? '') !== self::SUBMISSION_GRADED) {
                continue;
            }

            $assessment = json_decode((string) ($row['assessment_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
            $states[GateSubject::TYPE_ASSIGNMENT][$assignmentId] = match ($assessment['decision'] ?? null) {
                'approved' => CompletionCondition::STATE_COMPLETE_PASS,
                'failed' => CompletionCondition::STATE_COMPLETE_FAIL,
                'resubmit' => CompletionCondition::STATE_INCOMPLETE,
                default => CompletionCondition::STATE_COMPLETE,
            };
            if (isset($assessment['decision'])) {
                $states[GateSubject::TYPE_LESSON][$lessonByAssignment[$assignmentId]] = $states[GateSubject::TYPE_ASSIGNMENT][$assignmentId];
            }
        }
    }
}
