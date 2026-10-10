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
 * A bridge that answers and keeps the request it was handed, which is how the
 * manifest conformance assertion sees the wire.
 *
 * It reaches nothing. Every value it returns is computed from the request in
 * front of it, so this test double is exactly as offline as the free build's
 * own null bridge.
 */
final class RecordingBridge implements ModelBridgeInterface, GeneratesText
{
    public ?BridgeRequest $request = null;

    public ?BridgeContext $context = null;

    public function __construct(private readonly string $key = 'recording')
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'test.bridge.recording';
    }

    public function unit(): string
    {
        return CostRecord::UNIT_TOKENS;
    }

    public function run(BridgeRequest $request, BridgeContext $context): BridgeResult|BridgeRefusal
    {
        $this->request = $request;
        $this->context = $context;

        if ($request->payload->isEmpty()) {
            // The union is exercised rather than declared. A bridge handed
            // nothing has nothing to answer with, and saying so as a refusal
            // is what the return type is for.
            return BridgeRefusal::of(RefusalKind::InputRejected, 'test.empty_payload');
        }

        return BridgeResult::text(
            'answered',
            $this->key,
            CostRecord::of(CostRecord::UNIT_TOKENS, 12.0),
        );
    }
}
