<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What a rule does when a step has failed for the last time: stop, continue,
 * or hand the run to a compensating step.
 *
 * Stored beside the retry policy on the same row and registered for the same
 * reason.
 */
interface FailurePolicyInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * Which of the four it is.
     *
     * The save-time check that refuses an inert retry-plus-continue pairing
     * reads this and the retry plan, which is why both are registries rather
     * than const lists: the check reads two registries instead of two
     * hardcoded arrays.
     *
     * **The four dispositions are closed and a fifth is not registrable.**
     * This docblock used to say a site needing a fifth registers one, which is
     * not true and could not be: FailureDisposition is a PHP backed enum, PHP
     * enums cannot be extended, and RunAutomationStep branches on Park, then
     * Halt, then permanent. What the registry actually buys is the
     * ladder-pairing check above, the labels the builder renders, and a policy
     * key an author can name per step; a third-party policy re-labels one of
     * the four and re-selects which, and that is the whole of it. A fifth
     * disposition is a core change with a behaviour object behind it, and it
     * is not one this wave makes.
     */
    public function disposition(): FailureDisposition;
}
