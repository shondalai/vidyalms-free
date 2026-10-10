<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;

/**
 * One leaf a batching provider is asked about: its payload, whether the
 * operator negated it, and the subject it hangs on.
 *
 * A value object rather than three positional members, for the reason every
 * other provider method takes a context: a later field is additive and no
 * registered provider breaks.
 */
final class ConditionEvaluationRequest
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly bool $negated,
        public readonly ?GateSubject $subject = null,
    ) {
    }
}
