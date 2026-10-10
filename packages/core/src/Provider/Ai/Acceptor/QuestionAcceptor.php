<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor;

use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Ai\AcceptedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraft;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AiAcceptContext;

/**
 * A question draft becomes a question **through AddQuestion and never by a
 * table write**.
 *
 * That is not a style preference. AddQuestion resolves the type, runs the
 * type's own published schema through the definition checker, applies the
 * cross-field rules a schema cannot express, and writes the versioned stored
 * shape with its mirrored column. A draft written straight into the table
 * would skip all four, and the item would behave differently from what the
 * definition says with no error anywhere.
 *
 * The draft's own payload version travels as the definition version, so an
 * accepted draft written at a type's newer shape is not silently stored as
 * version one.
 */
final class QuestionAcceptor implements AiDraftAcceptorInterface
{
    public const KEY = 'question';

    /** The payload key naming the quiz the item is added to. */
    public const PAYLOAD_QUIZ_ID = 'quiz_id';

    public function __construct(private readonly AddQuestion $addQuestion)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.acceptor.question.label';
    }

    public function accept(AiDraft $draft, AiAcceptContext $context): AcceptedArtefact
    {
        $quizId = $draft->payload[self::PAYLOAD_QUIZ_ID] ?? null;

        if (!\is_int($quizId) || $quizId <= 0) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::PAYLOAD_QUIZ_ID,
                'An accepted question draft names the quiz it is added to.',
            );
        }

        $definition = $draft->payload;
        unset($definition[self::PAYLOAD_QUIZ_ID]);

        $question = $this->addQuestion->execute(
            $context->accepter,
            $quizId,
            $context->targetProviderKey,
            $definition,
            1.0,
            true,
            null,
            // The draft's own shape version, so a newer definition shape
            // survives acceptance rather than being read back as version one.
            $draft->payloadVersion > 0 ? $draft->payloadVersion : null,
        );

        return AcceptedArtefact::of(
            $context->targetExtensionPoint,
            $context->targetProviderKey,
            'question',
            $question->id ?? 0,
            ['quiz_id' => $quizId],
        );
    }
}
