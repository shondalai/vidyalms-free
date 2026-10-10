<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\FailureDisposition;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyInterface;

/**
 * Record the failure and run the next step anyway.
 *
 * This is the half of the n8n bug the product is obliged to validate: pairing
 * it with a ladder above one attempt makes the ladder a dead setting, and the
 * save-time check refuses that pairing rather than letting one axis silently
 * cancel the other.
 */
final class ContinueFailurePolicy implements FailurePolicyInterface
{
    public const KEY = 'continue';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.failure.continue';
    }

    public function disposition(): FailureDisposition
    {
        return FailureDisposition::Continue;
    }
}
