<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\FailureDisposition;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyInterface;

/**
 * Hand the run to the steps declared as its error branch.
 *
 * The branch is expressed with the machinery the first cut already has rather
 * than by putting a graph in the stored shape: the error steps are ordinary
 * later steps whose guard tree names the failure, which is what removes the
 * need for a branch element while still letting one rule fan into differently
 * gated steps.
 *
 * Like continue, it moves on from the first failure, so a ladder behind it is
 * inert and the pairing is refused at save.
 */
final class ErrorBranchFailurePolicy implements FailurePolicyInterface
{
    public const KEY = 'error_branch';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.failure.error_branch';
    }

    public function disposition(): FailureDisposition
    {
        return FailureDisposition::ErrorBranch;
    }
}
