<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\EvaluatedInsideTheReservation;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Tests\Support\SqliteDatabase;

/**
 * A windowed ceiling that records the transaction depth it was asked at.
 *
 * The reservation stage's docblock and the standards page both said the
 * ceiling evaluation and the write of the reserved row happen inside one
 * `DatabasePort::transactional()` call, and both said why: two concurrent runs
 * cannot then read the same headroom. In fact the policy fold was stage two,
 * the reservation was stage six, and the transaction wrapped the insert alone,
 * so a probe reading the depth at decide time recorded zero.
 */
final class WindowedProbePolicy implements AiPolicyInterface, EvaluatedInsideTheReservation
{
    /** @var list<int> */
    public array $depths = [];

    public function __construct(
        private readonly SqliteDatabase $database,
        private readonly ?PolicyDecision $answer = null,
    ) {
    }

    public function key(): string
    {
        return 'test.windowed_probe';
    }

    public function label(): string
    {
        return 'ai.policy.test_windowed_probe.label';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        $this->depths[] = $this->database->transactionDepth();

        return $this->answer ?? PolicyDecision::allow();
    }
}
