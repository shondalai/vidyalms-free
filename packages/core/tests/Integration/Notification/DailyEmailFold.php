<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsDigest;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/** A fold registered by a package, which is how a digest frequency arrives. */
final class DailyEmailFold implements ScheduledTaskHandlerInterface, SupportsDigest
{
    public function key(): string
    {
        return 'x_acme.notification.digest.daily';
    }

    public function foldsDigest(string $channelKey, string $frequency): bool
    {
        return $channelKey === 'email' && $frequency === Prefs::FREQUENCY_DAILY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        return 'Folded nothing.';
    }
}
