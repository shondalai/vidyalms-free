<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * Loads one fact set once for a whole render set.
 *
 * This is what makes a third-party leaf contribute to the preload rather than
 * issue a query per item, which is the difference between one outline render
 * holding the query budget and not. A fact key a leaf declares and no loader
 * answers is a save-time refusal rather than a silent null, which is why the
 * collection can be asked whether it holds a key.
 */
interface AvailabilityFactLoaderInterface
{
    public function factKey(): string;

    /**
     * @param list<int> $courseIds the courses in the render set
     */
    public function load(AvailabilityContext $context, array $courseIds): mixed;
}
