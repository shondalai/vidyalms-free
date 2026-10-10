<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * One foreign question, either translated into a free question type or
 * refused with the reason.
 *
 * The two states are one class on purpose. A refusal that never reached the
 * writer would be a question that vanished between the reader and the log,
 * and "unknown types are skipped with a problem naming the type, never
 * silently converted" is the rule this shape enforces: a mapper that cannot
 * carry a type has to say which type it was, and the only way to say it is to
 * hand back a draft that carries the word.
 */
final class QuestionDraft
{
    /**
     * @param array<string, mixed> $definition
     */
    private function __construct(
        public readonly string $key,
        public readonly string $sourceType,
        public readonly string $type,
        public readonly array $definition,
        public readonly float $points,
        public readonly ?string $refusal,
        public readonly ?string $note,
    ) {
    }

    /**
     * @param array<string, mixed> $definition a free question type's own shape
     */
    public static function of(
        string $key,
        string $sourceType,
        string $type,
        array $definition,
        float $points,
        ?string $note = null,
    ): self {
        return new self($key, $sourceType, $type, $definition, $points > 0.0 ? $points : 1.0, null, $note);
    }

    /**
     * A question this importer will not carry, and why. The source type is
     * kept whatever the reason, because the count per type is what tells a
     * site owner how much of their exam needs rebuilding.
     */
    public static function refused(string $key, string $sourceType, string $reason): self
    {
        return new self($key, $sourceType, '', [], 1.0, $reason, null);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
