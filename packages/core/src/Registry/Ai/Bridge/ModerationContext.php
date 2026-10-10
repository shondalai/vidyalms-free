<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * What a moderating bridge may read while it assesses segments.
 *
 * It sits beside the capability rather than in Registry\Context because the
 * design names it only in the Moderates signature and does not list it among
 * the eight contexts every new provider method takes. It follows the same
 * shape anyway: private constructor, named factory, readonly members, no
 * Principal and no domain entity.
 *
 * The direction matters to a moderating provider, because a policy about what
 * a person may send is not the same policy as what a product may render.
 */
final class ModerationContext
{
    public const DIRECTION_INPUT = 'input';
    public const DIRECTION_OUTPUT = 'output';

    private function __construct(
        public readonly string $direction,
        public readonly string $locale,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    /** Before anything leaves the site. */
    public static function forInput(\DateTimeImmutable $now, string $locale = 'en-GB'): self
    {
        return new self(self::DIRECTION_INPUT, $locale, $now);
    }

    /** Before anything is rendered to a person. */
    public static function forOutput(\DateTimeImmutable $now, string $locale = 'en-GB'): self
    {
        return new self(self::DIRECTION_OUTPUT, $locale, $now);
    }
}
