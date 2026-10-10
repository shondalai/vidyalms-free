<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * A task with an inner loop that asks whether it has been stopped.
 *
 * The signal is on the context precisely so a task can be written this way,
 * and both production call sites stopped at the twelfth positional argument,
 * so `isCancelled()` answered false for every task on every run and the
 * between-stage check was the only stop there was.
 */
final class SignalReadingTask implements AiTaskInterface
{
    public bool $sawCancellation = false;

    /**
     * Set before the ask, so the test's signal can tell its own stage-boundary
     * observations apart from the one the task made.
     */
    public bool $entered = false;

    public function key(): string
    {
        return 'test.reads_the_signal';
    }

    public function label(): string
    {
        return 'ai.task.test_reads_the_signal.label';
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        $this->entered = true;
        $this->sawCancellation = $context->isCancelled();

        return AiTaskResult::create();
    }
}
