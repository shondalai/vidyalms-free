<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskPayload;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeResult;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * A task that asks the caller for one call, which is what a model-backed task
 * does and what nothing in the free build could do.
 *
 * It exists because the seam contract asserted registration and not
 * reachability: `modelBridges` answered one key and no shipped code path
 * called `ModelBridgeInterface::run()` at all, because nothing seated an
 * `AiBridgeCaller` on the task context. A task written against the published
 * contract therefore could not reach a model, which made the stage's own
 * zero-core-edits claim false.
 *
 * It reaches no network: the only registered bridge on a free build is the
 * null one, which computes its answer from the request in front of it.
 */
final class BridgeCallingTask implements AiTaskInterface
{
    public int $calls = 0;

    public bool $sawBridge = false;

    public BridgeResult|BridgeRefusal|null $answer = null;

    public function __construct(private readonly string $key = 'test.calls_the_bridge')
    {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'ai.task.' . str_replace('.', '_', $this->key) . '.label';
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        ++$this->calls;
        $this->sawBridge = $context->hasBridge();

        $caller = $context->bridge();

        if ($caller === null) {
            return AiTaskResult::create();
        }

        $this->answer = $caller->call(AiTaskPayload::create($request, [[
            'provenance' => 'lesson:1',
            'trust' => 'untrusted',
            'text' => 'Steep the leaves for three minutes.',
        ]]));

        return AiTaskResult::create();
    }
}
