<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Access\ExpireEntitlements;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

final class ExpireEntitlementsTask implements ScheduledTaskHandlerInterface
{
    public const KEY = 'vidyalms.entitlements.expire';

    public function __construct(private readonly ExpireEntitlements $expire)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $swept = $this->expire->execute();

        return sprintf(
            'expired=%d suspended=%d activated=%d',
            $swept['expired'],
            $swept['suspended'],
            $swept['activated'],
        );
    }
}
