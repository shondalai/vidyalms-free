<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * One thing a task proposes, before any human has accepted it.
 *
 * It names the extension point and provider key it targets rather than the
 * use case that would write it, so accepting a draft resolves an acceptor
 * from a registry instead of a shipped switch. It never carries a domain
 * entity: a candidate is a payload and a citation list, and nothing more.
 *
 * The ordinal pair is what lets a multi-step build express a tree (a module
 * and the lessons under it) without a second shape.
 */
final class AiDraftCandidate
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $citations
     */
    private function __construct(
        public readonly string $draftKind,
        public readonly string $targetExtensionPoint,
        public readonly ?string $targetProviderKey,
        public readonly array $payload,
        public readonly array $citations,
        public readonly int $ordinal,
        public readonly ?int $parentOrdinal,
        /**
         * The shape version of the payload above.
         *
         * The repository wrote a hardcoded 1 into both the StoredShape
         * envelope and the mirrored column, and the question acceptor's own
         * docblock promised the opposite: "the draft's own payload version
         * travels as the definition version, so an accepted draft written at a
         * type's newer shape is not silently stored as version one". It had no
         * version to travel, so a vendor question type at definition shape two
         * could not express it through a draft at all.
         */
        public readonly int $payloadVersion,
        /**
         * The predicted difficulty of the thing this candidate proposes, and
         * the method that produced it.
         *
         * `ai_drafts` has carried `predicted_difficulty` and
         * `predicted_difficulty_method` since the table shipped, the draft
         * presenter renders both, and nothing could write either: a task
         * speaks through this object and this object had no way to say it.
         * The pair is here rather than in the payload because the payload is
         * the definition the acceptor hands to a use case, and a prediction
         * inside it would be a field the type does not own.
         *
         * It is never the empirical p-value and never overwrites one. The
         * stratified composition reads the measured field, which lives
         * somewhere else entirely, and a test asserts it.
         */
        public readonly ?float $predictedDifficulty,
        public readonly ?string $predictedDifficultyMethod,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $citations
     */
    public static function create(
        string $draftKind,
        string $targetExtensionPoint,
        array $payload,
        ?string $targetProviderKey = null,
        array $citations = [],
        int $ordinal = 0,
        ?int $parentOrdinal = null,
        int $payloadVersion = 1,
        ?float $predictedDifficulty = null,
        ?string $predictedDifficultyMethod = null,
    ): self {
        return new self(
            $draftKind,
            $targetExtensionPoint,
            $targetProviderKey,
            $payload,
            $citations,
            $ordinal,
            $parentOrdinal,
            max(1, $payloadVersion),
            // Clamped to the proportion a difficulty estimate is, so a
            // column typed decimal(5,3) never has to refuse a write.
            $predictedDifficulty === null ? null : max(0.0, min(1.0, $predictedDifficulty)),
            $predictedDifficultyMethod,
        );
    }
}
