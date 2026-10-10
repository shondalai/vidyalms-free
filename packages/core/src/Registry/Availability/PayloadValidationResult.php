<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * What a condition said about a payload at save time.
 *
 * Errors refuse the save; warnings do not. The split is not cosmetic: a
 * score maximum at or above the maximum attainable score is a warning
 * because a course total can exceed a hundred with bonus points, while a
 * minimum above a maximum is a leaf that can never be satisfied and would
 * sit in the denominator forever.
 *
 * Both are keys plus parameters rather than sentences, for the same reason a
 * reason is: the core owns no locale.
 */
final class PayloadValidationResult
{
    /**
     * @param array<string, array<string, mixed>> $errors   reason key to parameters
     * @param array<string, array<string, mixed>> $warnings reason key to parameters
     */
    private function __construct(
        public readonly array $errors,
        public readonly array $warnings,
    ) {
    }

    public static function valid(): self
    {
        return new self([], []);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function error(string $key, array $parameters = []): self
    {
        return new self([$key => $parameters], []);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function warning(string $key, array $parameters = []): self
    {
        return new self([], [$key => $parameters]);
    }

    public function merge(self $other): self
    {
        return new self($this->errors + $other->errors, $this->warnings + $other->warnings);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
