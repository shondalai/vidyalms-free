<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Publication;

/**
 * One thing a publication check found, and whether it stops the transition.
 *
 * Three levels rather than two. Warnings inform; blocks refuse; and
 * "acknowledge" is the level the design gives exactly one check, the scalar
 * whose initial value sits inside an accepted band, which is legitimate
 * often enough that refusing it outright would be wrong and dangerous often
 * enough that a warning nobody reads is not enough. An acknowledgement is
 * recorded against the check's code, so acknowledging one item's problem
 * does not silence the same problem elsewhere.
 *
 * The message is a translator key with parameters rather than a sentence:
 * these are author-facing copy and copy goes through TranslatorPort.
 */
final class PublicationFinding
{
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ACKNOWLEDGE = 'acknowledge';
    public const LEVEL_BLOCKS = 'blocks';

    /** @var array<string, scalar|null> */
    public readonly array $parameters;

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function __construct(
        public readonly string $level,
        public readonly string $code,
        public readonly string $messageKey,
        array $parameters,
        public readonly ?int $questionId,
        public readonly ?int $slotId,
    ) {
        $this->parameters = $parameters;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function warning(
        string $code,
        string $messageKey,
        array $parameters = [],
        ?int $questionId = null,
        ?int $slotId = null,
    ): self {
        return new self(self::LEVEL_WARNING, $code, $messageKey, $parameters, $questionId, $slotId);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function acknowledge(
        string $code,
        string $messageKey,
        array $parameters = [],
        ?int $questionId = null,
        ?int $slotId = null,
    ): self {
        return new self(self::LEVEL_ACKNOWLEDGE, $code, $messageKey, $parameters, $questionId, $slotId);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function blocks(
        string $code,
        string $messageKey,
        array $parameters = [],
        ?int $questionId = null,
        ?int $slotId = null,
    ): self {
        return new self(self::LEVEL_BLOCKS, $code, $messageKey, $parameters, $questionId, $slotId);
    }

    public function isBlocking(): bool
    {
        return $this->level === self::LEVEL_BLOCKS;
    }

    public function needsAcknowledgement(): bool
    {
        return $this->level === self::LEVEL_ACKNOWLEDGE;
    }

    /**
     * The item this names, so a refusal points at the thing rather than at
     * the quiz. "Publication refused" with no item is a support ticket.
     */
    public function reference(): string
    {
        if ($this->slotId !== null) {
            return 'slot:' . $this->slotId;
        }

        return $this->questionId !== null ? 'question:' . $this->questionId : 'quiz';
    }
}
