<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingDecision;

/**
 * What one assertion did, and when it did nothing, why.
 *
 * A refusal on the identity path is never an exception. A person signing in is
 * not a caller making a request, and throwing at them turns a misconfigured
 * connection into a white screen at the login form; what has to happen instead
 * is that nothing changes, the reason is recorded, and somebody with the
 * settings right can read it afterwards.
 *
 * The reason vocabulary is closed, because the screen renders it and a support
 * conversation quotes it. Every one of them names a specific thing an operator
 * can go and fix.
 */
final class ProvisioningOutcome
{
    private function __construct(
        public readonly bool $applied,
        public readonly ?string $reason,
        public readonly ?MappingDecision $decision,
        public readonly int $grantsApplied,
        public readonly bool $governed,
        public readonly ?string $decisionRef,
        public readonly ?int $membershipId,
    ) {
    }

    public static function refused(string $reason, ?MappingDecision $decision = null): self
    {
        return new self(false, $reason, $decision, 0, false, null, null);
    }

    public static function applied(
        MappingDecision $decision,
        int $grantsApplied,
        bool $governed,
        string $decisionRef,
        ?int $membershipId,
    ): self {
        return new self(true, null, $decision, $grantsApplied, $governed, $decisionRef, $membershipId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'applied' => $this->applied,
            'reason' => $this->reason,
            'grants_applied' => $this->grantsApplied,
            'directory_managed' => $this->governed,
            'decision_ref' => $this->decisionRef,
            'membership_id' => $this->membershipId,
            'may_remove' => $this->decision !== null && $this->decision->mayRemove,
            'removal_basis' => $this->decision?->removalBasis,
        ];
    }
}
