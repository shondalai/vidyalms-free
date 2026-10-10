<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRegistry;

final class CreateQuiz
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly DatabasePort $database,
        private readonly CourseAccess $access,
        private readonly AttemptPolicyRegistry $behaviours,
        private readonly AggregationMethodRegistry $aggregations,
        private readonly NegativeMarkingRegistry $negativeMarking,
    ) {
    }

    public function execute(
        Principal $principal,
        int $courseId,
        string $title,
        ?int $lessonId = null,
        ?string $description = null,
        int $passMark = 0,
        ?int $maxAttempts = null,
        ?int $timeLimitSeconds = null,
        bool $isFinal = false,
        string $showResults = Quiz::SHOW_RESULTS_AFTER_SUBMIT,
        ?QuizPolicy $policy = null,
    ): Quiz {
        $this->access->require($principal, $courseId, Capability::TEACH);

        QuizRules::validate($title, $passMark, $maxAttempts, $timeLimitSeconds, $showResults);

        // The policy set is refused when it names a provider this site does
        // not have, which is the write-side half of the registry-driven
        // settings form.
        $resolved = QuizRules::validatePolicy(
            $policy ?? new QuizPolicy(),
            $this->behaviours,
            $this->aggregations,
            $this->negativeMarking,
        );

        if ($lessonId !== null) {
            QuizRules::requireQuizLessonOfCourse($this->database, $courseId, $lessonId);
        }

        return $this->quizzes->add(new Quiz(
            id: null,
            courseId: $courseId,
            lessonId: $lessonId,
            title: trim($title),
            description: $description,
            passMark: $passMark,
            maxAttempts: $maxAttempts,
            timeLimitSeconds: $timeLimitSeconds,
            isFinal: $isFinal,
            showResults: $showResults,
            policy: $resolved,
        ));
    }
}
