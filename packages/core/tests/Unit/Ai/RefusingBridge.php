<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\Bridge\GeneratesText;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;

/**
 * A bridge whose transport is always down, which is the first candidate in
 * the chain-walking case: retryable, and saying nothing at all about the
 * content.
 */
final class RefusingBridge implements ModelBridgeInterface, GeneratesText
{
    public function __construct(private readonly string $key = 'refusing')
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'test.bridge.refusing';
    }

    public function unit(): string
    {
        return CostRecord::UNIT_TOKENS;
    }

    public function run(BridgeRequest $request, BridgeContext $context): BridgeResult|BridgeRefusal
    {
        if ($context->routeParam('recover') === true) {
            // The route param that brings the transport back, so the union is
            // exercised rather than declared and a chain test can assert that
            // a recovered candidate is used rather than skipped.
            return BridgeResult::text('recovered', $this->key, CostRecord::none());
        }

        return BridgeRefusal::of(RefusalKind::UpstreamUnavailable, 'test.transport_down');
    }
}
