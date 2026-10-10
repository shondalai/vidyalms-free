<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Application\Assessment\ManagedStimulus;
use Shondalai\VidyaLMS\Core\Application\Assessment\QuizSummaryView;
use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeAttemptChange;
use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeOutcome;
use Shondalai\VidyaLMS\Core\Application\Platform\ManualGrade;
use Shondalai\VidyaLMS\Core\Application\Shared\GradeOutcome;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEvent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Assignment;
use Shondalai\VidyaLMS\Core\Http\Handler\Presenter;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\Context\PresetContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionPreset\QuestionPresetInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\AccessibilityDeclaringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionVersionAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyReferenceDeclaringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * The wire shapes of quizzes, questions, attempts and the assignment
 * configuration a lesson carries.
 *
 * Assignments ride with assessment here rather than in a presenter of their
 * own because an assignment never travels alone on this surface: it is a
 * field of an authoring lesson and a field of a gradebook row.
 */
final class AssessmentPresenter
{
    public const SHAPE_QUIZ = 'quiz_summary';
    public const SHAPE_QUIZ_AUTHORING = 'quiz_authoring';
    public const SHAPE_QUESTION = 'question';
    public const SHAPE_QUESTION_TYPE = 'question_type';
    public const SHAPE_QUESTION_PRESET = 'question_preset';
    public const SHAPE_ASSIGNMENT = 'assignment';
    public const SHAPE_ATTEMPT = 'attempt_detail';
    public const SHAPE_MANUAL_GRADE = 'manual_grade';
    public const SHAPE_GRADE_OUTCOME = 'grade_outcome';
    public const SHAPE_STIMULUS = 'managed_stimulus';
    public const SHAPE_INTEGRITY_EVENT = 'integrity_event';
    public const SHAPE_REGRADE_RESULT = 'regrade_result';

    public function __construct(
        private readonly Decorated $decorated,
        private readonly string $locale = 'en-GB',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function quizSummary(QuizSummaryView $view): array
    {
        $quiz = $view->quiz;

        return $this->decorated->apply(self::SHAPE_QUIZ, [
            'id' => $quiz->id,
            'course_id' => $quiz->courseId,
            'lesson_id' => $quiz->lessonId,
            'lesson' => $view->lesson === null ? null : [
                'id' => $view->lesson->id,
                'title' => $view->lesson->title,
                'module_id' => $view->lesson->moduleId,
                'module_title' => $view->lesson->moduleTitle,
            ],
            'title' => $quiz->title,
            'description' => $quiz->description,
            'pass_mark' => $quiz->passMark,
            'max_attempts' => $quiz->maxAttempts,
            'time_limit_seconds' => $quiz->timeLimitSeconds,
            'is_final' => $quiz->isFinal,
            'show_results' => $quiz->showResults,
            'question_count' => $view->questionCount,
            'policy' => $this->quizPolicy($quiz->policy),
        ], $view);
    }

    /**
     * Every quiz-level policy setting in one object, exactly as the settings
     * form writes it back. None of it is visible to a question type.
     *
     * @return array<string, mixed>
     */
    public function quizPolicy(QuizPolicy $policy): array
    {
        return [
            'behaviour' => $policy->behaviour ?? AttemptPolicyRegistry::DEFERRED_FEEDBACK,
            'navigation_mode' => $policy->navigationMode,
            'submission_mode' => $policy->submissionMode,
            'shuffle_questions' => $policy->shuffleQuestions,
            'shuffle_options' => $policy->shuffleOptions,
            'one_per_page' => $policy->onePerPage,
            'opens_at' => Presenter::dateTime($policy->opensAt),
            'closes_at' => Presenter::dateTime($policy->closesAt),
            'expiry_policy' => $policy->expiryPolicy,
            'grace_seconds' => $policy->graceSeconds,
            'auto_submit' => $policy->autoSubmit,
            'cooldown_after_first_seconds' => $policy->cooldownAfterFirstSeconds,
            'cooldown_after_later_seconds' => $policy->cooldownAfterLaterSeconds,
            'attempts_counted' => $policy->attemptsCounted,
            'attempt_aggregation' => $policy->attemptAggregation,
            'attempt_aggregation_params' => $policy->attemptAggregationParams === null
                ? null
                : Presenter::freeFormObject($policy->attemptAggregationParams),
            'negative_marking_policy' => $policy->negativeMarkingPolicy,
            'allow_quiz_negative' => $policy->allowQuizNegative,
            'try_penalty' => $policy->tryPenalty,
            'item_session_control' => $policy->itemSessionControl->toArray(),
            'review_visibility' => $policy->reviewVisibility?->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function quizAuthoring(QuizSummaryView $view): array
    {
        return $this->decorated->apply(self::SHAPE_QUIZ_AUTHORING, $this->quizSummary($view) + [
            'questions' => array_map($this->question(...), $view->questions),
        ], $view);
    }

    /**
     * @return array<string, mixed>
     */
    public function question(Question $question): array
    {
        return $this->decorated->apply(self::SHAPE_QUESTION, [
            'id' => $question->id,
            'quiz_id' => $question->quizId,
            'type' => $question->type,
            'position' => $question->position,
            'points' => $question->points,
            'definition' => Presenter::freeFormObject($question->definition),
        ], $question);
    }

    /**
     * Key, label, group and order rather than registration order passed
     * through.
     *
     * Registration order is not the author's picker order, and treating it
     * as one was a mistake: replace() keeps the displaced key's position, so
     * decorating the free six would pin them at positions one to six and
     * split the selection family across the list. Presentation order is
     * declared, which is the only arrangement in which a third-party type
     * lands in the right family without a core edit.
     *
     * @return array<string, mixed>
     */
    public function questionType(QuestionTypeInterface $type, ?ScoringPolicyRegistry $policies = null): array
    {
        return $this->decorated->apply(
            self::SHAPE_QUESTION_TYPE,
            [
                'key' => $type->key(),
                'label' => $type->label(),
                'group' => $type instanceof ReportingMetadataInterface
                    ? $type->group()
                    : ReportingMetadataInterface::GROUP_SELECTION,
                'order' => $type instanceof ReportingMetadataInterface ? $type->order() : 0,
                'response_shape' => $type instanceof DefinitionContractInterface
                    ? $type->responseShape()->key()
                    : null,
                // What the author's policy picker may offer, computed from the
                // registry so this route, the catalogue artefact and the
                // authoring guard cannot disagree about the same question.
                'offerable_policies' => $this->offerablePolicies($type, $policies),
                // Where the rule goes, beside which rules may be chosen. A
                // container type keeps its own under another name, and the
                // picker that assumed "policy" seeded every composite with a
                // field its own schema forbids and its own grading ignores.
                'policy_field' => $type instanceof PolicyReferenceDeclaringInterface
                    ? $type->policyField()
                    : PolicyResolution::FIELD,
                // A comprehensive authoring client needs the provider's
                // actual contracts, not a second switch statement that will
                // drift the moment Pro or a third party registers a type.
                'authoring' => $this->questionTypeAuthoring($type, $policies),
            ],
            $type,
        );
    }

    /**
     * The machine-readable inputs needed to render and validate an authoring
     * form. Definition schemas contain structure, never an authored answer.
     *
     * @return array<string, mixed>
     */
    private function questionTypeAuthoring(
        QuestionTypeInterface $type,
        ?ScoringPolicyRegistry $policies,
    ): array {
        $definition = $type instanceof DefinitionContractInterface ? $type : null;
        $primitive = $type instanceof PrimitiveComposingInterface ? $type : null;
        $accessibility = $type instanceof AccessibilityDeclaringInterface ? $type : null;

        return [
            'definition_version' => $type->definitionVersion(),
            'supported_definition_versions' => $type instanceof DefinitionVersionAwareInterface
                ? $type->supportedDefinitionVersions()
                : [$type->definitionVersion()],
            'definition_schema' => $definition?->definitionSchema() ?? [],
            'primitive' => $primitive?->interactionPrimitive(),
            'surface' => $primitive?->surface()->value,
            'scoring_policies' => $this->questionTypeScoringPolicies($type, $policies),
            'accessibility' => [
                'interaction_modes' => $accessibility?->interactionModes() ?? [
                    AccessibilityDeclaringInterface::POINTER_NO_DRAG,
                    AccessibilityDeclaringInterface::KEYBOARD,
                ],
                'essential_dragging_justification' => $accessibility?->essentialDraggingJustification(),
                'conformance_result' => $accessibility?->conformanceResult(),
                'verified' => $accessibility !== null
                    && $accessibility->conformanceResult() !== null
                    && trim($accessibility->conformanceResult()) !== '',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function questionTypeScoringPolicies(
        QuestionTypeInterface $type,
        ?ScoringPolicyRegistry $policies,
    ): array {
        if ($policies === null) {
            return [];
        }

        return array_map(
            static function (string $key) use ($policies): array {
                $policy = $policies->get($key);

                \assert($policy instanceof ScoringPolicyInterface);

                return [
                    'key' => $policy->key(),
                    'label' => $policy->label(),
                    'default_params' => $policy->defaultParams(),
                    'params_schema' => $policy->paramsSchema(),
                ];
            },
            $this->offerablePolicies($type, $policies),
        );
    }

    /**
     * @return list<string>
     */
    private function offerablePolicies(QuestionTypeInterface $type, ?ScoringPolicyRegistry $policies): array
    {
        if ($policies === null || !$type instanceof ScoringPolicyAwareInterface) {
            return [];
        }

        return PolicyResolution::offerable(
            $policies,
            $type instanceof DefinitionContractInterface ? $type->responseShape() : null,
            $type->supportedPolicies(),
        );
    }

    /**
     * A preset creates no provider key and stores nothing; it exists so the
     * picker can show teacher vocabulary while questions.type keeps the
     * algebra key.
     *
     * @return array<string, mixed>
     */
    public function questionPreset(QuestionPresetInterface $preset, ?PresetContext $context = null): array
    {
        // Resolved at read time in the request's locale, so the picker answers
        // in the site's language rather than in whichever language the kernel
        // happened to be built in.
        $context ??= PresetContext::create($this->locale);

        return $this->decorated->apply(self::SHAPE_QUESTION_PRESET, [
            'preset_key' => $preset->key(),
            'label' => $preset->label($context),
            'group' => $preset->group($context),
            'base_type_key' => $preset->baseTypeKey($context),
            'default_policy' => $preset->defaultPolicy($context),
            'definition_skeleton' => Presenter::freeFormObject($preset->definitionSkeleton($context)),
            'source_aliases' => $preset->sourceAliases($context),
            'help_url' => $preset->helpUrl($context),
        ], $preset);
    }

    /**
     * @return array<string, mixed>
     */
    public function assignment(Assignment $assignment): array
    {
        return $this->decorated->apply(self::SHAPE_ASSIGNMENT, [
            'lesson_id' => $assignment->lessonId,
            'instructions' => $assignment->instructions,
            'max_points' => $assignment->maxPoints,
            'allowed_file_types' => $assignment->allowedFileTypes,
            'max_file_size_kb' => $assignment->maxFileSizeKb,
            'max_files' => $assignment->maxFiles,
            'due_at' => Presenter::dateTime($assignment->dueAt),
            'allow_resubmission' => $assignment->allowResubmission,
            'pass_percentage' => $assignment->passPercentage,
            'rubric' => $assignment->rubric,
        ], $assignment);
    }

    /**
     * @return array<string, mixed>
     */
    public function attemptDetail(Attempt $attempt, ?\DateTimeImmutable $expiresAt): array
    {
        return $this->decorated->apply(self::SHAPE_ATTEMPT, [
            'id' => $attempt->id,
            'quiz_id' => $attempt->quizId,
            'attempt_number' => $attempt->attemptNumber,
            'status' => $attempt->status,
            'started_at' => Presenter::dateTime($attempt->startedAt),
            'submitted_at' => Presenter::dateTime($attempt->submittedAt),
            'expires_at' => Presenter::dateTime($expiresAt),
            'runtime' => $this->attemptRuntime($attempt->policy()),
            'score' => $attempt->score,
            'max_score' => $attempt->maxScore,
            'percentage' => $attempt->percentage,
            'passed' => $attempt->passed,
        ], $attempt);
    }

    /**
     * The frozen policy the runner has to obey.
     *
     * Read from the attempt and never from the quiz, so changing a setting
     * cannot move a learner who is already inside an attempt.
     *
     * @return array<string, mixed>
     */
    public function attemptRuntime(AttemptPolicySet $policy): array
    {
        return Presenter::attemptRuntime($policy);
    }

    /**
     * @return array<string, mixed>
     */
    public function stimulus(ManagedStimulus $managed): array
    {
        $stimulus = $managed->stimulus;

        return $this->decorated->apply(self::SHAPE_STIMULUS, [
            'id' => $stimulus->id,
            'course_id' => $stimulus->courseId,
            'title' => $stimulus->title,
            'body' => $stimulus->body,
            'media' => $stimulus->media === null ? null : Presenter::freeFormObject($stimulus->media),
            'alt_text' => $stimulus->altText,
            'long_description' => $stimulus->longDescription,
            'question_count' => $managed->questionCount,
        ], $managed);
    }

    /**
     * @return array<string, mixed>
     */
    public function integrityEvent(IntegrityEvent $event): array
    {
        return $this->decorated->apply(self::SHAPE_INTEGRITY_EVENT, [
            'id' => $event->id,
            'attempt_id' => $event->attemptId,
            'kind' => $event->kind,
            'actor' => $event->actor,
            'occurred_at' => Presenter::dateTime($event->occurredAt),
            'context' => $event->context === null ? null : Presenter::freeFormObject($event->context),
        ], $event);
    }

    /**
     * @return array<string, mixed>
     */
    public function regradeOutcome(RegradeOutcome $outcome): array
    {
        return $this->decorated->apply(self::SHAPE_REGRADE_RESULT, [
            'policy' => $outcome->policy->value,
            'dry_run' => $outcome->dryRun,
            'attempts_examined' => $outcome->attemptsExamined,
            'attempts_changed' => $outcome->attemptsChanged,
            'grades_appended' => $outcome->gradesAppended,
            'questions_regraded' => $outcome->questionsRegraded,
            'items' => array_map($this->regradeChange(...), $outcome->items),
            // The contract's own warning shape, so one client renderer reads
            // a publication warning and a regrade warning alike.
            'warnings' => array_map(
                static fn (string $warning): array => [
                    'check' => 'regrade',
                    'message' => $warning,
                    'severity' => 'warning',
                    'slot_id' => null,
                    'question_id' => null,
                ],
                $outcome->warnings,
            ),
        ], $outcome);
    }

    /**
     * @return array<string, mixed>
     */
    private function regradeChange(RegradeAttemptChange $change): array
    {
        return [
            'attempt_id' => $change->attemptId,
            'user_id' => $change->userId,
            'attempt_number' => $change->attemptNumber,
            'score_before' => $change->scoreBefore,
            'score_after' => $change->scoreAfter,
            'percentage_before' => $change->percentageBefore,
            'percentage_after' => $change->percentageAfter,
            'passed_before' => $change->passedBefore,
            'passed_after' => $change->passedAfter,
            'questions_changed' => $change->questionsChanged,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function manualGrade(ManualGrade $grade): array
    {
        return $this->decorated->apply(self::SHAPE_MANUAL_GRADE, [
            'points_awarded' => $grade->pointsAwarded,
            'max_points' => $grade->maxPoints,
            'feedback' => $grade->feedback,
            'graded_at' => Presenter::dateTime($grade->gradedAt),
            'grader_id' => $grade->graderId,
        ], $grade);
    }

    /**
     * @return array<string, mixed>
     */
    public function gradeOutcome(GradeOutcome $outcome): array
    {
        return $this->decorated->apply(self::SHAPE_GRADE_OUTCOME, [
            'lesson_completed' => $outcome->lessonCompleted,
            'enrolment_completed' => $outcome->enrolmentCompleted,
        ], $outcome);
    }
}
