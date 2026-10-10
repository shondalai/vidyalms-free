<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\EnrolmentRule;

/**
 * Whether this person may enrol, and if not, why in a key the host can
 * translate.
 *
 * The reason is a key plus parameters and never a sentence, for the same
 * reason an availability reason is: the core owns no locale.
 */
final class EnrolmentDecision
{
    /**
     * @param array<string, mixed> $reasonParameters
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reasonKey = null,
        public readonly array $reasonParameters = [],
    ) {
    }

    public static function allow(): self
    {
        return new self(true);
    }

    /**
     * @param array<string, mixed> $reasonParameters
     */
    public static function refuse(string $reasonKey, array $reasonParameters = []): self
    {
        return new self(false, $reasonKey, $reasonParameters);
    }
}
