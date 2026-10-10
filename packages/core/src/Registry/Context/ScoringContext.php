<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a scoring policy may read beyond the response, the key and its own
 * parameters.
 *
 * It ships in the first release rather than later, and the reason is
 * concrete: a certainty-weighted policy scales the base fraction by the
 * learner's declared certainty, a try penalty needs the per-part try count,
 * and a locale-sensitive comparison needs the locale. None of those reach a
 * bare (response, key, params) signature, so the flagship policy would be
 * unimplementable against its own interface and adding the parameter
 * afterwards would break every registered provider.
 */
final class ScoringContext
{
    public const BEHAVIOUR_DEFERRED_FEEDBACK = 'deferred_feedback';
    public const BEHAVIOUR_MANUAL = 'manual';

    /**
     * @param array<string, mixed> $attemptPolicy
     */
    private function __construct(
        public readonly string $behaviour,
        public readonly int $try,
        public readonly ?int $certainty,
        public readonly string $locale,
        public readonly ?string $presentationSeed,
        public readonly array $attemptPolicy,
    ) {
    }

    /**
     * @param array<string, mixed> $attemptPolicy
     */
    public static function create(
        string $behaviour = self::BEHAVIOUR_DEFERRED_FEEDBACK,
        int $try = 1,
        ?int $certainty = null,
        string $locale = 'en-GB',
        ?string $presentationSeed = null,
        array $attemptPolicy = [],
    ): self {
        return new self($behaviour, max(1, $try), $certainty, $locale, $presentationSeed, $attemptPolicy);
    }

    public static function defaults(): self
    {
        return self::create();
    }

    /**
     * Whether the quiz this attempt started under permits an item fraction
     * below zero. Off unless the frozen snapshot says otherwise, which is
     * what keeps a per-entry negative fraction out of the gradebook on a
     * quiz whose instructions promised no penalty.
     */
    public function allowsNegativeItemScore(): bool
    {
        return ($this->attemptPolicy['negative_marking'] ?? false) === true;
    }
}
