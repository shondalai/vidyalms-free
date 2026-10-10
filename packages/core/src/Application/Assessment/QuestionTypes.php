<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionVersionAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The one place a question's provider is resolved, and therefore the one
 * place the registry-miss contract is implemented.
 *
 * Seven call sites used to resolve providers themselves and each got it
 * wrong in one of two ways. SubmitAttempt, GetAttemptForLearner,
 * GradeAnswerManually, SaveAnswers and the authoring guards called get()
 * without catching, so removing a package threw out of submission, out of
 * the learner attempt view and out of manual grading. GradebookQueue and
 * ManualGradingStatus caught and skipped, which is worse: every essay,
 * upload, drawing and recording awaiting a human dropped silently out of the
 * instructor queue and out of the pending count, with no error anywhere.
 *
 * Both go through here now, and the version gate goes with them: a row whose
 * definition version exceeds what the resolved provider declares is a miss
 * of exactly the same kind, because a provider that cannot read the shape
 * cannot mark it either.
 */
final class QuestionTypes
{
    /** The extension point the ledger records a question type under. */
    public const EXTENSION_POINT = 'question_type';

    public function __construct(
        private readonly QuestionTypeRegistry $registry,
        private readonly ?TranslatorPort $translator = null,
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    public function forQuestion(Question $question): ResolvedQuestionType
    {
        return $this->resolve($question->type, $question->definitionVersion);
    }

    /**
     * The provider for one item of a frozen manifest.
     *
     * A served question whose frozen definition the store no longer holds is
     * a miss even when its provider resolves perfectly, because the thing
     * that is gone is what the attempt was graded from. Re-marking it against
     * the live questions row would move a mark under a graded attempt with no
     * trace, which is the exact failure content addressing exists to prevent.
     */
    public function forServed(ServedQuestion $question): ResolvedQuestionType
    {
        if ($question->definitionLost) {
            return $this->described(ResolvedQuestionType::definitionLost($question->typeKey), $question->typeKey);
        }

        return $this->resolve($question->typeKey, $question->definitionVersion);
    }

    public function resolve(string $typeKey, int $definitionVersion = 1): ResolvedQuestionType
    {
        try {
            $provider = $this->registry->get($typeKey);
        } catch (UnknownProviderException) {
            return $this->described(ResolvedQuestionType::unknownType($typeKey), $typeKey);
        }

        if (!$this->supportsVersion($provider, $definitionVersion)) {
            return $this->described(
                ResolvedQuestionType::unsupportedVersion($typeKey, $provider, $definitionVersion),
                $typeKey,
            );
        }

        return ResolvedQuestionType::available($typeKey, $provider);
    }

    /**
     * A miss carries the site's language and the name of the package that
     * registered the key, so the explanatory grade row a learner and a grader
     * both read is translated and says which package is missing rather than
     * only which type key could not be resolved.
     */
    private function described(ResolvedQuestionType $resolved, string $typeKey): ResolvedQuestionType
    {
        return $resolved->describedBy(
            $this->translator,
            $this->packages?->packageOf(self::EXTENSION_POINT, $typeKey),
        );
    }

    /**
     * A provider that says nothing supports version 1 only, which is the
     * conservative reading: it has never declared it can read a newer shape,
     * so it is assumed it cannot.
     */
    private function supportsVersion(object $provider, int $definitionVersion): bool
    {
        $supported = $provider instanceof DefinitionVersionAwareInterface
            ? $provider->supportedDefinitionVersions()
            : [1];

        // A row stored before versions existed reads as 0 and is version 1
        // by definition, so it is never gated out.
        return $definitionVersion <= 0 || \in_array($definitionVersion, $supported, true);
    }
}
