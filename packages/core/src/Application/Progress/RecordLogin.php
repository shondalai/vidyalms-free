<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerSignedIn;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

/** Host-only hook, deliberately not exposed as a callable HTTP route. */
final class RecordLogin
{
    public function __construct(private readonly EventBus $events, private readonly ClockPort $clock, private readonly RandomPort $random)
    {
    }

    public function execute(Principal $principal): void
    {
        if ($principal->isGuest()) {
            throw new NotAuthorizedException('Sign in first.');
        }
        $this->events->dispatch(new LearnerSignedIn($this->clock->now(), $principal->userId, $this->random->uuid()));
    }
}
