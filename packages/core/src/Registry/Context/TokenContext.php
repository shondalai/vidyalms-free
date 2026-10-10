<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a token resolver may read.
 *
 * The parameter bag is whatever the calling surface assembled: a
 * notification's parameters, or a certificate render's context snapshot. A
 * resolver reads what it recognises and answers null for anything else,
 * which leaves the placeholder intact.
 */
final class TokenContext
{
    /**
     * @param array<string, mixed> $parameters
     */
    private function __construct(
        public readonly array $parameters,
        public readonly string $locale,
        public readonly ?int $recipientUserId,
        public readonly ?int $courseId,
        public readonly string $timezone,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function create(
        array $parameters = [],
        string $locale = 'en-GB',
        ?int $recipientUserId = null,
        ?int $courseId = null,
        string $timezone = 'UTC',
    ): self {
        return new self($parameters, $locale, $recipientUserId, $courseId, $timezone);
    }

    public function string(string $key): ?string
    {
        $value = $this->parameters[$key] ?? null;

        if (\is_string($value)) {
            return $value;
        }

        return \is_int($value) || \is_float($value) ? (string) $value : null;
    }
}
