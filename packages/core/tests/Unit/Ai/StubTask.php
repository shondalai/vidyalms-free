<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskResult;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * The smallest thing that is a task.
 *
 * The registration guard cases extend it and declare exactly the one property
 * each of them is about, so a case that refuses a misdeclared task cannot be
 * passing for some second reason nobody wrote down.
 */
abstract class StubTask implements AiTaskInterface
{
    public function key(): string
    {
        return 'stub.task';
    }

    public function label(): string
    {
        return 'stub.task.label';
    }

    public function run(AiTaskRequest $request, AiTaskContext $context): AiTaskResult
    {
        return AiTaskResult::create();
    }
}
