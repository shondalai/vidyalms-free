<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A stored draft, as an acceptor is handed it.
 *
 * It is the persisted counterpart of AiDraftCandidate: a candidate is what a
 * task produced in memory, a draft is the row a person later accepts or
 * discards. The acceptor receives the draft and never the run, so an acceptor
 * cannot re-read the prompt, the segments or the cost, none of which have any
 * business in a domain write.
 *
 * The target extension point and provider key are nullable here and refused
 * by the accept use case rather than by this object, because a draft kind
 * that is display-only by construction is a legitimate row that simply has no
 * accept path.
 */
final class AiDraft
{
    /** @var array<string, mixed> */
    public readonly array $payload;

    /** @var list<string> */
    public readonly array $citations;

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $citations
     */
    private function __construct(
        public readonly int $id,
        public readonly int $runId,
        public readonly string $kind,
        public readonly ?string $targetExtensionPoint,
        public readonly ?string $targetProviderKey,
        array $payload,
        array $citations,
        public readonly int $ordinal,
        public readonly ?int $parentOrdinal,
        public readonly int $payloadVersion,
    ) {
        $this->payload = $payload;
        $this->citations = $citations;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $citations
     */
    public static function of(
        int $id,
        int $runId,
        string $kind,
        array $payload,
        ?string $targetExtensionPoint = null,
        ?string $targetProviderKey = null,
        array $citations = [],
        int $ordinal = 0,
        ?int $parentOrdinal = null,
        int $payloadVersion = 1,
    ): self {
        return new self(
            $id,
            $runId,
            $kind,
            $targetExtensionPoint,
            $targetProviderKey,
            $payload,
            $citations,
            $ordinal,
            $parentOrdinal,
            $payloadVersion,
        );
    }

    public function isAcceptable(): bool
    {
        return $this->targetExtensionPoint !== null && $this->targetProviderKey !== null;
    }
}
