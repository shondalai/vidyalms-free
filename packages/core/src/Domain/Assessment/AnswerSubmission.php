<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * One saved answer and everything that travels with it.
 *
 * The four scalar parameters of upsertAnswer collapse into one value object
 * so the next field does not break both adapters again. None of raw_input,
 * ui_state, presentation_form or certainty had a wire field or a port
 * parameter, and raw_input in particular is what precision marking has to
 * judge on and is impossible to backfill.
 *
 * The wire accepts the bare value as the legacy shape, so a shipped client
 * keeps working; fromWire is the one place that decides which shape arrived.
 */
final class AnswerSubmission
{
    public const FORM_INTERACTIVE = 'interactive';
    public const FORM_NON_DRAG_POINTER = 'non_drag_pointer';
    public const FORM_KEYBOARD = 'keyboard';
    public const FORM_LINEAR_ALTERNATIVE = 'linear_alternative';
    public const FORM_SCRIBE = 'scribe';
    public const FORM_BRAILLE_TRANSCRIPT = 'braille_transcript';

    /**
     * An untrusted client report that an item could not be rendered.
     * Retained for diagnostics; it cannot exempt a question from scoring.
     */
    public const FORM_NOT_RENDERED = 'not_rendered';

    /** @var array<string, mixed>|null */
    public readonly ?array $uiState;

    /**
     * @param array<string, mixed>|null $uiState
     */
    public function __construct(
        public readonly mixed $response,
        ?array $uiState = null,
        public readonly ?string $rawInput = null,
        public readonly string $presentationForm = self::FORM_INTERACTIVE,
        public readonly ?int $certainty = null,
        /**
         * Whether the learner submitted this response **for processing**,
         * which is what a try means.
         *
         * The runner posts the whole answer map on every autosave, so a try
         * counted from every changed answer counted the number of times the
         * learner changed their mind. Under interactive_multi_try at penalty
         * 0.2 a learner who answered true, false, true, false, true and
         * never checked anything scored 2 of 10 for a correct answer, while
         * a learner who never revised paid nothing: the penalty was charging
         * revision, which is the opposite of what it is for.
         *
         * Only the per-item check control sets this. An autosave leaves it
         * false, is still recorded (the event table is where per-item timing
         * lives) and costs the learner nothing.
         */
        public readonly bool $processed = false,
    ) {
        $this->uiState = $uiState;
    }

    /**
     * @return list<string>
     */
    public static function forms(): array
    {
        return [
            self::FORM_INTERACTIVE,
            self::FORM_NON_DRAG_POINTER,
            self::FORM_KEYBOARD,
            self::FORM_LINEAR_ALTERNATIVE,
            self::FORM_SCRIBE,
            self::FORM_BRAILLE_TRANSCRIPT,
            self::FORM_NOT_RENDERED,
        ];
    }

    /**
     * Whether the client was able to present this item to the learner at all.
     */
    public function wasRendered(): bool
    {
        return $this->presentationForm !== self::FORM_NOT_RENDERED;
    }

    /**
     * Whether this submission is the same answer as one already stored.
     *
     * The runner posts the whole answer map on every autosave, and an event
     * row per posted key made "try" the number of autosaves rather than the
     * number of tries: ten autosaves of a two-question quiz wrote twenty
     * rows and reported try ten for a question answered once and never
     * changed. A try penalty would then charge a learner whose only offence
     * was leaving the tab open, the table would grow at questions times
     * autosaves, and per-item timing could not be reconstructed from rows
     * that record no learner action.
     *
     * Compared on the scoreable value and the two facts that describe how it
     * was given, not on the UI state: a half-completed drag moving under the
     * cursor is not a new try.
     */
    public function isSameAnswerAs(self $other): bool
    {
        return $this->response === $other->response
            && $this->rawInput === $other->rawInput
            && $this->presentationForm === $other->presentationForm
            && $this->certainty === $other->certainty;
    }

    /**
     * Accepts either the envelope shape or the bare value a shipped client
     * still sends. A bare array is only an envelope when it carries the
     * "response" key, because every legacy answer of a container type is
     * itself an array.
     */
    public static function fromWire(mixed $wire): self
    {
        $envelope = \is_array($wire) && \array_key_exists('response', $wire);
        foreach (['response' => 262144, 'ui_state' => 16384, 'raw_input' => 65536] as $field => $limit) {
            $value = $envelope ? ($wire[$field] ?? null) : ($field === 'response' ? $wire : null);
            try {
                $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw \Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException::forField($field, 'The answer contains invalid data.');
            }
            if (strlen($encoded) > $limit) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw \Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException::forField($field, 'The answer exceeds the supported size.');
            }
        }
        if (!\is_array($wire) || !\array_key_exists('response', $wire)) {
            return new self($wire);
        }

        $uiState = $wire['ui_state'] ?? null;
        $rawInput = $wire['raw_input'] ?? null;
        $form = $wire['presentation_form'] ?? self::FORM_INTERACTIVE;
        $certainty = $wire['certainty'] ?? null;

        if ($form === self::FORM_NOT_RENDERED) {
            return new self(null, presentationForm: self::FORM_NOT_RENDERED);
        }

        return new self(
            $wire['response'],
            \is_array($uiState) ? $uiState : null,
            \is_string($rawInput) ? $rawInput : null,
            \is_string($form) && \in_array($form, self::forms(), true) ? $form : self::FORM_INTERACTIVE,
            \is_int($certainty) ? $certainty : null,
            ($wire['processed'] ?? false) === true,
        );
    }
}
