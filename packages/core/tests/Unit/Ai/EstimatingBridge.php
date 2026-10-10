<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\EstimatesCost;
use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\GeneratesText;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;

/**
 * A bridge that declares `EstimatesCost` and answers whatever a test seeded,
 * including the answer every real remote bridge gives on a timeout, a rate
 * limit, an unconfigured row or a counterparty whose estimate entry point has
 * not shipped: unknown.
 *
 * It reaches nothing. The result is computed from the request in front of it.
 */
final class EstimatingBridge implements ModelBridgeInterface, GeneratesText, EstimatesCost
{
    public int $estimateCalls = 0;

    public function __construct(
        private readonly CostEstimate $answer,
        private readonly string $key = 'estimating',
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'test.bridge.estimating';
    }

    public function unit(): string
    {
        return CostRecord::UNIT_TOKENS;
    }

    public function estimate(BridgeRequest $request): CostEstimate
    {
        ++$this->estimateCalls;

        return $this->answer;
    }

    public function run(BridgeRequest $request, BridgeContext $context): BridgeResult|BridgeRefusal
    {
        if ($request->payload->isEmpty()) {
            // The union is exercised rather than declared, the way the other
            // recording double does it: a bridge handed nothing has nothing to
            // answer with, and saying so as a refusal is what the type is for.
            return BridgeRefusal::of(RefusalKind::InputRejected, 'test.empty_payload');
        }

        return BridgeResult::text('answered', $this->key, CostRecord::of(CostRecord::UNIT_TOKENS, 12.0));
    }
}
