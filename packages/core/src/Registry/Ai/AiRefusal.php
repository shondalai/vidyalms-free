<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * Why a task produced nothing, as a closed kind plus translatable parameters
 * rather than a sentence.
 *
 * A refusal is an ordinary outcome and never an exception: a ceiling reached,
 * a policy denial or a deadline is a thing the surface has to render, and an
 * exception out of a provider is reserved for programmer error.
 *
 * The kind was a free string until this wave, which meant every provider could
 * invent its own vocabulary and the one distinction the retry decision turns
 * on, a model that declined against a transport that failed, was a spelling
 * convention. It is RefusalKind now, while nothing anywhere constructs one, so
 * the correction is free exactly once.
 */
final class AiRefusal
{
    /** @var array<string, scalar|null> */
    public readonly array $parameters;

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function __construct(
        public readonly RefusalKind $kind,
        public readonly string $messageKey,
        array $parameters,
    ) {
        $this->parameters = $parameters;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function of(RefusalKind $kind, string $messageKey, array $parameters = []): self
    {
        return new self($kind, $messageKey, $parameters);
    }

    /** Whether the same run may be attempted again unchanged. */
    public function isRetryable(): bool
    {
        return $this->kind->isRetryable();
    }
}
