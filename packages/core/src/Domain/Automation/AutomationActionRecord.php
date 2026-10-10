<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * One row of the audit, written once per executed step.
 *
 * It records the identity the step ran under as well as the subject it acted
 * on, because the failure mode the idempotency literature names is precisely a
 * step gated under one business identity and completed under another,
 * undetectable after the fact. The actor is the rule's authoriser and never a
 * literal "system".
 *
 * The idempotency guarantee is not carried here; it is carried by
 * idempotency_keys under the automation scope. This table is the audit,
 * retained past the queue's pruning, and its idem_key index is a lookup rather
 * than a constraint. Splitting them is deliberate: the ledger has to be
 * written before the side effect inside a small transaction, and the audit
 * carries before and after state that the ledger has no business holding.
 */
final class AutomationActionRecord
{
    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    public function __construct(
        public readonly ?int $queueId,
        public readonly ?int $runId,
        public readonly ?int $ruleId,
        public readonly int $ruleVersion,
        public readonly int $stepIndex,
        public readonly string $actionKey,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $actorUserId,
        public readonly string $idemKey,
        public readonly bool $success,
        public readonly \DateTimeImmutable $executedAt,
        public readonly array $beforeState = [],
        public readonly array $afterState = [],
        public readonly int $stateVersion = 1,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly ?int $aiRunId = null,
    ) {
    }
}
