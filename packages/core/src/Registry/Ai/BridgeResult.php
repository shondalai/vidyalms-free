<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * What one model call produced.
 *
 * It carries no refusal member, because a refusal is the other half of the
 * bridge's return union: one object answers a call that produced something
 * and a different object answers a call that did not, so no caller has to
 * decide which of two fields is authoritative on a result whose text is empty.
 *
 * The model actually used is recorded rather than the model asked for, since
 * an upstream that silently fell back has answered a different question and
 * an outage post-mortem needs to see that. Tokens and cost are nullable for
 * the same reason CostRecord's amount is: a bridge that meters nothing says
 * it does not know, and never says zero.
 */
final class BridgeResult
{
    /** @var array<string, mixed>|null */
    public readonly ?array $structure;

    /** @var list<string> */
    public readonly array $droppedConstraints;

    /**
     * @param array<string, mixed>|null $structure
     * @param list<string>              $droppedConstraints schema keywords the bridge could not honour
     */
    private function __construct(
        public readonly ?string $text,
        ?array $structure,
        public readonly bool $schemaValid,
        array $droppedConstraints,
        public readonly string $model,
        public readonly ?string $promptRef,
        public readonly ?string $providerRequestId,
        public readonly ?string $providerFingerprint,
        public readonly ?string $finishReason,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly CostRecord $cost,
        public readonly bool $unbilled,
    ) {
        $this->structure = $structure;
        $this->droppedConstraints = $droppedConstraints;
    }

    /**
     * @param list<string> $droppedConstraints
     */
    public static function text(
        string $text,
        string $model,
        ?CostRecord $cost = null,
        ?string $promptRef = null,
        ?string $providerRequestId = null,
        ?string $providerFingerprint = null,
        ?string $finishReason = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        bool $unbilled = false,
        array $droppedConstraints = [],
    ): self {
        return new self(
            $text,
            null,
            true,
            $droppedConstraints,
            $model,
            $promptRef,
            $providerRequestId,
            $providerFingerprint,
            $finishReason,
            $tokensIn,
            $tokensOut,
            $cost ?? CostRecord::unknown(CostRecord::UNIT_NONE),
            $unbilled,
        );
    }

    /**
     * @param array<string, mixed> $structure
     * @param list<string>         $droppedConstraints
     */
    public static function structured(
        array $structure,
        string $model,
        bool $schemaValid = true,
        ?CostRecord $cost = null,
        ?string $promptRef = null,
        ?string $providerRequestId = null,
        ?string $providerFingerprint = null,
        ?string $finishReason = null,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        bool $unbilled = false,
        array $droppedConstraints = [],
    ): self {
        return new self(
            null,
            $structure,
            $schemaValid,
            $droppedConstraints,
            $model,
            $promptRef,
            $providerRequestId,
            $providerFingerprint,
            $finishReason,
            $tokensIn,
            $tokensOut,
            $cost ?? CostRecord::unknown(CostRecord::UNIT_NONE),
            $unbilled,
        );
    }

    /**
     * Whether the constrained decoding claim held. A bridge that dropped a
     * keyword produced a result the runner flags rather than one it trusts.
     */
    public function isSchemaEnforced(): bool
    {
        return $this->schemaValid && $this->droppedConstraints === [];
    }
}
