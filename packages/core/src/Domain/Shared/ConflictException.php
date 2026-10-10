<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * The request is valid but collides with current state: enrolling twice,
 * submitting an already-submitted attempt, exceeding an attempt limit.
 * Maps to HTTP 409.
 *
 * A refusal may carry the fact the client needs in order to say something
 * useful instead of "no". A cooldown answers when the learner may try
 * again and an unopened quiz answers when it opens, because a bare refusal
 * makes the client either poll or invite the same refusal again. The
 * members travel as problem extensions, following the precedent
 * retry_after already sets, rather than as a refusal body of their own.
 */
final class ConflictException extends DomainException
{
    /** @var array<string, mixed> */
    public readonly array $extensions;

    /**
     * @param array<string, mixed> $extensions
     */
    public function __construct(string $message, array $extensions = [])
    {
        parent::__construct($message);

        $this->extensions = $extensions;
    }

    /**
     * @param array<string, mixed> $extensions
     */
    public static function with(string $message, array $extensions): self
    {
        return new self($message, $extensions);
    }
}
