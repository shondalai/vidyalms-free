<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What describe() may read. The same rule is described one way to an author
 * and another to a learner, so the audience is a member rather than an
 * inference from the caller.
 */
final class DescriptionContext
{
    public const AUDIENCE_AUTHOR = 'author';
    public const AUDIENCE_LEARNER = 'learner';

    public const VERBOSITY_SHORT = 'short';
    public const VERBOSITY_FULL = 'full';

    private function __construct(
        public readonly string $locale,
        public readonly string $audience,
        public readonly string $verbosity,
    ) {
    }

    public static function forLearner(string $locale, string $verbosity = self::VERBOSITY_SHORT): self
    {
        return new self($locale, self::AUDIENCE_LEARNER, $verbosity);
    }

    public static function forAuthor(string $locale, string $verbosity = self::VERBOSITY_FULL): self
    {
        return new self($locale, self::AUDIENCE_AUTHOR, $verbosity);
    }

    public function isForAuthor(): bool
    {
        return $this->audience === self::AUDIENCE_AUTHOR;
    }
}
