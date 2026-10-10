<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StimulusRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionSchemaCheck;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionUpgrade;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyReferenceDeclaringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;

/**
 * Adds one question to a quiz.
 *
 * "scored" is a column rather than a definition field, and that is what makes
 * an opinion scale authorable: a scale with no right answer is a question
 * that contributes to neither sum of the attempt totals, so it neither gives
 * a free mark nor moves the pass percentage. Putting it in the definition
 * instead would hide it from the totals, which are what have to exclude it.
 */
final class AddQuestion
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly QuestionRepository $questions,
        private readonly QuestionTypeRegistry $questionTypes,
        private readonly CourseAccess $access,
        private readonly ?StimulusRepository $stimuli = null,
        /**
         * Optional on the same terms as every dependency added to a shipped
         * class: absent, a declared older shape is upgraded by the provider's
         * own single-step upgradeDefinition(), which is what the free six
         * ship and what the read path already falls back to.
         */
        private readonly ?DefinitionUpgraderRegistry $upgraders = null,
        private readonly ?\Shondalai\VidyaLMS\Core\Registry\Rubric\RubricRegistry $rubrics = null,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     * @param ?int                 $stimulusId        the shared passage this
     *                                                question is asked about,
     *                                                which is what lets the
     *                                                attempt view group the
     *                                                questions that share one
     * @param ?int                 $definitionVersion the shape the caller
     *                                                wrote this definition
     *                                                at, when it is not the
     *                                                type's current one
     */
    public function execute(
        Principal $principal,
        int $quizId,
        string $type,
        array $definition,
        float $points = 1.0,
        bool $scored = true,
        ?int $stimulusId = null,
        ?int $definitionVersion = null,
    ): Question {
        $this->access->requireSignedIn($principal);

        $quiz = $this->quizzes->byId($quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $quizId);

        $this->access->require($principal, $quiz->courseId, Capability::TEACH);

        if (!$this->questionTypes->has($type)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('type', sprintf('Unknown question type "%s".', $type));
        }

        if ($points <= 0) {
            throw ValidationException::forField('points', 'Points must be greater than zero.');
        }

        $provider = $this->questionTypes->get($type);
        $definition = $this->atCurrentShape($provider, $type, $definition, $definitionVersion);
        $definition = $this->withDefaultPolicy($provider, $definition);
        $provider->validateDefinition($definition);
        ($this->rubrics ?? new \Shondalai\VidyaLMS\Core\Registry\Rubric\RubricRegistry())
            ->validateAttachment($principal, $definition, $quiz->courseId);

        // Belt to the type's own call, and the belt is what covers a
        // third-party type that publishes a schema and forgets to enforce
        // it. The design says the generated catalogue is the published contract an
        // importer validates against, so a definition that file accepts and
        // the engine reinterprets is the drift this closes.
        DefinitionSchemaCheck::enforceFor($provider, $definition);
        QuizRules::requireUnscoredWhenPolicyScoresNothing($provider, $definition, $scored);

        return $this->questions->add(new Question(
            id: null,
            quizId: $quizId,
            type: $type,
            position: $this->questions->nextPosition($quizId),
            points: $points,
            definition: $definition,
            state: Question::STATE_PUBLISHED,
            definitionVersion: DefinitionUpgrade::versionOf($this->questionTypes, $type, $definition),
            scored: $scored,
            stimulusId: $this->stimulusOn($quiz->courseId, $stimulusId),
        ));
    }

    /**
     * The definition brought to the shape the resolved type writes today.
     *
     * A caller that names no version is saying the body is already current,
     * which is what every authoring screen means and what this use case
     * assumed before the parameter existed. A caller that names an older one
     * is saying something a generated draft, an importer and a restored
     * export all need to be able to say: the body is at that shape. Storing
     * it unchanged is the silent failure, because the row is stamped with the
     * version the type writes today, so the read path sees nothing to upgrade
     * and hands a renamed field's old spelling to a grader that looks for the
     * new one. Every answer is then marked wrong and nothing reports it.
     *
     * A version above the type's own is refused rather than clamped: it names
     * a shape this installation cannot write, and guessing which one the
     * caller meant is how a definition ends up mislabelled in the other
     * direction.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function atCurrentShape(
        QuestionTypeInterface $provider,
        string $type,
        array $definition,
        ?int $declaredVersion,
    ): array {
        if ($declaredVersion === null) {
            return $definition;
        }

        $current = $provider->definitionVersion();

        if ($declaredVersion < 1 || $declaredVersion > $current) {
            throw ValidationException::forField(
                'definition_version',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('Between 1 and %d, the shape "%s" writes today.', $current, $type),
            );
        }

        if ($declaredVersion === $current) {
            return $definition;
        }

        [$upgraded] = DefinitionUpgrade::apply(
            $this->questionTypes,
            $this->upgraders ?? new DefinitionUpgraderRegistry(),
            $type,
            $definition,
            $declaredVersion,
        );

        return $upgraded;
    }

    /**
     * The passage a question is asked about, checked against the course that
     * owns it.
     *
     * A stimulus is course scoped, so a question pointing at one on another
     * course would render a passage the learner is not enrolled to read.
     */
    private function stimulusOn(int $courseId, ?int $stimulusId): ?int
    {
        if ($stimulusId === null) {
            return null;
        }

        $stimulus = $this->stimuli?->byId($stimulusId);

        if ($stimulus === null || $stimulus->courseId !== $courseId) {
            throw ValidationException::forField(
                'stimulus_id',
                'That passage is not on this course.',
            );
        }

        return $stimulusId;
    }

    /**
     * A new question gets its type's current default policy written into its
     * definition; an existing one is never touched.
     *
     * That asymmetry is the whole mechanism behind "new items default to
     * guess_neutral, existing items are pinned to plus_minus". A definition
     * that names no policy is one authored before the field existed and
     * grades under the legacy pin, so stamping the current default on an
     * edit would silently move the mark of a question authored years ago.
     * Only creation stamps.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    private function withDefaultPolicy(QuestionTypeInterface $provider, array $definition): array
    {
        if (!$provider instanceof ScoringPolicyAwareInterface) {
            return $definition;
        }

        // Where the rule goes is the type's answer, never this layer's
        // guess. A container type keeps its own under another name, and
        // stamping a default into "policy" wrote a field its own schema
        // forbids and its own grading ignores.
        $field = $provider instanceof PolicyReferenceDeclaringInterface
            ? $provider->policyField()
            : PolicyResolution::FIELD;

        if (\is_array($definition[$field] ?? null)) {
            return $definition;
        }

        return [$field => ['key' => $provider->defaultPolicy()]] + $definition;
    }
}
