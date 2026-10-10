<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What a task is asked to do, and nothing about who asked.
 *
 * The subject pair is the same pair an event trigger returns, so one
 * vocabulary names a lesson, a submission or a bank across the product. The
 * grounding set arrives as chunk keys rather than as text, because the
 * request is the authored intent and the resolved text belongs to
 * AiTaskPayload: a redactor handed keys is redacting nothing, so the two
 * shapes are deliberately separate.
 *
 * It carries no entity, by rule.
 */
final class AiTaskRequest
{
    /**
     * @param array<string, mixed> $options     the validated authored options
     * @param list<string>         $groundingKeys chunk keys, never chunk text
     */
    private function __construct(
        public readonly string $subjectType,
        public readonly ?int $subjectId,
        public readonly array $options,
        public readonly array $groundingKeys,
        public readonly ?string $intent,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string>         $groundingKeys
     */
    public static function create(
        string $subjectType,
        ?int $subjectId = null,
        array $options = [],
        array $groundingKeys = [],
        ?string $intent = null,
    ): self {
        return new self($subjectType, $subjectId, $options, $groundingKeys, $intent);
    }

    public function option(string $name): mixed
    {
        return $this->options[$name] ?? null;
    }
}
