<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;

/**
 * The condition checks its own payload at save time. Default when absent:
 * the payload is stored as given and only the structural rules the tree
 * itself owns are enforced.
 *
 * This is what keeps the save-time pass generic. A picker hint is the
 * friendly duplicate of the check and never the check, because a rule
 * reaching the table through the API, an importer or a course copy would
 * otherwise store a leaf that can never be satisfied, evaluate to blocked
 * forever and sit in the completion denominator.
 */
interface ValidatesPayload
{
    /**
     * @param array<string, mixed> $payload
     */
    public function validatePayload(array $payload, PayloadValidationContext $context): PayloadValidationResult;
}
