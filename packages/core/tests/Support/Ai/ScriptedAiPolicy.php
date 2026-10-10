<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * A policy whose answer the test chooses.
 *
 * Three answers, because the three are structurally different outcomes and the
 * pipeline has to distinguish them: allow, deny, and defer to an instant. A
 * fourth, "throws", is here because an unresolved gate fails closed and that is
 * a divergence from the notification precedent worth a test of its own.
 */
final class ScriptedAiPolicy implements AiPolicyInterface
{
    private function __construct(
        private readonly string $key,
        private readonly \Closure $answer,
    ) {
    }

    public static function allowing(string $key = 'test.allow'): self
    {
        return new self($key, static fn (): PolicyDecision => PolicyDecision::allow());
    }

    public static function denying(string $reason, string $key = 'test.deny'): self
    {
        return new self($key, static fn (): PolicyDecision => PolicyDecision::deny(
            [PolicyDecision::TARGET_AI_RUN],
            $reason,
        ));
    }

    public static function deferring(\DateTimeImmutable $until, string $key = 'test.defer'): self
    {
        return new self($key, static fn (): PolicyDecision => PolicyDecision::defer(
            [PolicyDecision::TARGET_AI_RUN],
            $until,
        ));
    }

    public static function unresolvable(string $key = 'test.broken'): self
    {
        return new self($key, static function (): PolicyDecision {
            throw new \RuntimeException('This policy cannot resolve its own configuration.');
        });
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Scripted test policy';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        return ($this->answer)($intent, $context);
    }
}
