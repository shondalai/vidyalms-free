<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\FailureDisposition;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyInterface;

/**
 * Park for manual resume, which is the shipped default.
 *
 * Make's Break directive under a clearer name: it keeps a failed run resumable
 * with enough state to review rather than only logged, and it is the default
 * for any action whose effect cannot be undone.
 */
final class ParkFailurePolicy implements FailurePolicyInterface
{
    public const KEY = 'park';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.failure.park';
    }

    public function disposition(): FailureDisposition
    {
        return FailureDisposition::Park;
    }
}
