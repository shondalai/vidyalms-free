<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * Where the caller writes the attempt row for every candidate it tried.
 *
 * It is a one-method contract rather than a repository handle so the caller
 * holds the narrowest thing that can do its job: a persistence class
 * satisfying this can also do twenty other things, and none of them belong to
 * the object that holds a bridge.
 *
 * The write happens before the success test, so an implementation that
 * batched rows until the chain finished would lose exactly the rows an outage
 * post-mortem needs. Write on call.
 */
interface AiAttemptLedgerInterface
{
    public function record(AiAttemptRecord $attempt): void;
}
