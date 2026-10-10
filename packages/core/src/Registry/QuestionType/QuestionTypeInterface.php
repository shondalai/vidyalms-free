<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One kind of question. The free build ships six (single choice, multiple
 * choice, true or false, short answer, fill in the blank, ordering); the
 * whole catalogue, its scoring policies and its extension seams are
 * specified in docs/technical/12-assessment-types.md.
 *
 * The definition is the authoring-side JSON stored on the question row; it
 * contains the correct answers. presentForLearner() must strip everything
 * that gives the answer away, because it is the only shape that leaves the
 * server before an item session closes.
 *
 * **This interface is shipped bytes that third parties are invited to
 * implement, so nothing may ever be added to it.** Optional behaviour
 * arrives as a narrow capability interface probed with instanceof, each with
 * a defined default when absent: ContextAwarePresenting and
 * ContextAwareGrading for the context-taking halves of the two methods here,
 * DefinitionContractInterface for the schema and the projection allowlist,
 * DefinitionVersionAwareInterface for the version gate,
 * ScoringPolicyAwareInterface for a selectable marking rule,
 * PrimitiveComposingInterface for the interaction it is built from,
 * ReportingMetadataInterface for its place in the picker,
 * AnswerValidatingInterface for save-time constraints, and
 * ManualGradingDecidingInterface for the cheap pending-human answer.
 */
interface QuestionTypeInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * Validates an authored definition, throwing
     * Domain\Shared\ValidationException with field errors when invalid.
     *
     * @param array<string, mixed> $definition
     */
    public function validateDefinition(array $definition): void;

    /**
     * The learner-safe shape of the definition: prompt, options, hints.
     * Never correctness, never expected answers, never per-option feedback.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function presentForLearner(array $definition): array;

    /**
     * Grades one submitted answer against the definition, server-side only.
     *
     * @param array<string, mixed> $definition
     */
    public function grade(array $definition, mixed $answer): GradeResult;

    /**
     * The version of the definition shape this provider writes today.
     *
     * Stored definitions are provider-shaped, and a provider key outlives any
     * particular implementation of it: replace() is the documented way for a
     * package to take over a key the free build registered. Without a version
     * on the row, the replacement reinterprets every definition already stored
     * with nothing to tell an old one from a new one. Start at 1 and raise it
     * whenever the shape changes; DefaultDefinitionVersion is the shape that
     * has never changed.
     */
    public function definitionVersion(): int;

    /**
     * Brings a definition written by an older version of this provider up to
     * definitionVersion(). Called by the repository on read, before anything
     * else sees the payload, so validation, presentation and grading only
     * ever deal with the current shape.
     *
     * $fromVersion is 0 for a row stored before versions existed.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function upgradeDefinition(array $definition, int $fromVersion): array;
}
