<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Acceptor;

use Shondalai\VidyaLMS\Core\Application\Assessment\GradeAnswerManually;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Ai\AcceptedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraft;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\AiAcceptContext;

/**
 * A feedback draft becomes feedback, and **never becomes a mark**.
 *
 * It writes through the manual grading use case with the points argument left
 * null, which that use case reads as feedback only. That is the sharpest line
 * in the design expressed as one argument: this acceptor has no way to pass a
 * mark, the placement that produces these drafts lists one fillable field and
 * it is the feedback textarea, no task's output schema may bind a scored
 * field, and the score field appears in no placement's list anywhere in the
 * product.
 *
 * The reasoning is on the record rather than implied. A score that plays a
 * determining role in a downstream decision is automated decision-making even
 * with a human button in front of it, and prefilling a mark would put the
 * product inside the high-risk category for evaluating learning outcomes.
 * Feedback drafting is a different act: it says what to do next, and the
 * grading engine has already said what happened.
 */
final class FeedbackTextAcceptor implements AiDraftAcceptorInterface
{
    public const KEY = 'feedback_text';

    public const PAYLOAD_ATTEMPT_ID = 'attempt_id';
    public const PAYLOAD_QUESTION_ID = 'question_id';
    public const PAYLOAD_FEEDBACK = 'feedback_to_learner';

    public function __construct(private readonly GradeAnswerManually $grading)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.acceptor.feedback_text.label';
    }

    public function accept(AiDraft $draft, AiAcceptContext $context): AcceptedArtefact
    {
        $attemptId = $draft->payload[self::PAYLOAD_ATTEMPT_ID] ?? null;
        $questionId = $draft->payload[self::PAYLOAD_QUESTION_ID] ?? null;
        $feedback = $draft->payload[self::PAYLOAD_FEEDBACK] ?? null;

        if (!\is_int($attemptId) || $attemptId <= 0) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::PAYLOAD_ATTEMPT_ID,
                'Accepted feedback names the attempt it belongs to.',
            );
        }

        if (!\is_int($questionId) || $questionId <= 0) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::PAYLOAD_QUESTION_ID,
                'Accepted feedback names the question it belongs to.',
            );
        }

        if (!\is_string($feedback) || trim($feedback) === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField(self::PAYLOAD_FEEDBACK, 'Accepted feedback has text in it.');
        }

        // recordFeedback() and never execute(). The marking use case reads a
        // null mark with no rubric criteria as "you forgot the mark" and
        // throws, so this acceptor could never accept anything and the 422 a
        // reviewer saw asked them to supply the one thing this whole design
        // forbids. There is still no branch anywhere in this class that could
        // pass a mark: the method it calls does not take one.
        $this->grading->recordFeedback(
            $context->accepter,
            $attemptId,
            $questionId,
            trim($feedback),
        );

        return AcceptedArtefact::of(
            $context->targetExtensionPoint,
            $context->targetProviderKey,
            'attempt_grade',
            $attemptId,
            ['question_id' => $questionId],
        );
    }
}
