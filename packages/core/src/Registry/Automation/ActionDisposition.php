<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * What one execution of one action did.
 *
 * Four words rather than a boolean, because the step machine has to tell
 * apart the three things a false would collapse: a condition that said no,
 * a policy that said not yet, and a thing that broke. Only the last one
 * reaches the retry ladder and the failure policy; a suppression is a normal
 * outcome with a named reason and a deferral is a re-queue.
 */
enum ActionDisposition: string
{
    case Executed = 'executed';
    case Suppressed = 'suppressed';
    case Deferred = 'deferred';
    case Failed = 'failed';
}
