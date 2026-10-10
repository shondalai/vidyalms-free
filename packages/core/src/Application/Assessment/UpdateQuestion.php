<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionSchemaCheck;

/**
 * Revises the definition and points of a question. The type is fixed for the
 * lifetime of the question; the revised definition is validated again by its
 * question type provider.
 *
 * An edit never stamps a default scoring policy. A definition that names
 * none is one authored before the field existed and grades under its type's
 * legacy pin, so writing the current default here would silently move the
 * mark of every question anyone happens to open.
 */
final class UpdateQuestion
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly QuizRepository $quizzes,
        private readonly QuestionTypes $types,
        private readonly CourseAccess $access,
        private readonly ?\Shondalai\VidyaLMS\Core\Registry\Rubric\RubricRegistry $rubrics = null,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function execute(
        Principal $principal,
        int $questionId,
        array $definition,
        float $points,
        ?bool $scored = null,
    ): Question {
        $this->access->requireSignedIn($principal);

        $question = $this->questions->byId($questionId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Question', $questionId);
        $quiz = $this->quizzes->byId($question->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $question->quizId);

        $this->access->require($principal, $quiz->courseId, Capability::TEACH);

        if ($question->isRemoved()) {
            throw new ConflictException('A removed question cannot be updated.');
        }

        if ($points <= 0) {
            throw ValidationException::forField('points', 'Points must be greater than zero.');
        }

        $resolved = $this->types->forQuestion($question);

        // The authoring guard is one of the seven registry-miss call sites.
        // It used to throw UnknownProviderException straight out of the
        // authoring screen; it now refuses with a message naming what is
        // missing, because the row is still perfectly valid and the site
        // owner needs to know which package to reinstall rather than seeing
        // a stack trace.
        if (!$resolved->isAvailable() || $resolved->provider === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('type', (string) $resolved->missFeedback());
        }

        $resolved->provider->validateDefinition($definition);
        ($this->rubrics ?? new \Shondalai\VidyaLMS\Core\Registry\Rubric\RubricRegistry())
            ->validateAttachment($principal, $definition, $quiz->courseId);
        DefinitionSchemaCheck::enforceFor($resolved->provider, $definition);

        $revised = $question->withRevision($definition, $points, $scored);
        $this->questions->save($revised);

        return $revised;
    }
}
