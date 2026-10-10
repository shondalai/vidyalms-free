<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * One call to a model, and the only shape a bridge is ever handed.
 *
 * **Its constructor accepts a RedactedPayload and nothing else**, so redaction
 * and transmission are one object rather than two unrelated ones. There is
 * deliberately no factory taking a segment list: a caller able to compose its
 * own segments is a caller able to put an unredacted submission on the wire
 * without touching the redactor, and the structural test asserting otherwise
 * would be unwritable.
 *
 * It carries no Principal, no domain entity, no course id and no learner
 * name. A bridge that cannot name a course cannot leak one, and that holds
 * even when the redactor is replaced.
 *
 * The output schema arrives already narrowed to the keywords this bridge
 * declared support for, because a schema a bridge cannot honour is either a
 * silent drop or a strengthening, and both are worse than a refusal.
 */
final class BridgeRequest
{
    /** @var array<string, mixed>|null */
    public readonly ?array $outputSchema;

    /** @var array<string, mixed> */
    public readonly array $routeParams;

    /**
     * @param array<string, mixed>|null $outputSchema already narrowed to this bridge's supported keywords
     * @param array<string, mixed>      $routeParams
     */
    private function __construct(
        public readonly RedactedPayload $payload,
        ?array $outputSchema,
        array $routeParams,
        public readonly \DateTimeImmutable $deadline,
        public readonly string $idempotencyKey,
        public readonly CostRecord $ceiling,
        public readonly string $callerRef,
    ) {
        $this->outputSchema = $outputSchema;
        $this->routeParams = $routeParams;
    }

    /**
     * The only way to build one.
     *
     * @param array<string, mixed>|null $outputSchema
     * @param array<string, mixed>      $routeParams
     */
    public static function fromRedactedPayload(
        RedactedPayload $payload,
        \DateTimeImmutable $deadline,
        string $idempotencyKey,
        CostRecord $ceiling,
        string $callerRef,
        ?array $outputSchema = null,
        array $routeParams = [],
    ): self {
        return new self($payload, $outputSchema, $routeParams, $deadline, $idempotencyKey, $ceiling, $callerRef);
    }

    /**
     * The segments, read through the payload so there is one place they live.
     *
     * @return list<PromptSegment>
     */
    public function segments(): array
    {
        return $this->payload->segments;
    }

    public function expectsStructure(): bool
    {
        return $this->outputSchema !== null;
    }
}
