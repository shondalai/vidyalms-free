<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityRef;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/** Emitted only after the host CMS has authenticated a person. */
final class LearnerSignedIn extends DomainEvent implements ActivityProjectable
{
    public function __construct(\DateTimeImmutable $at, public readonly int $userId, private readonly string $reference)
    {
        parent::__construct($at);
    }

    public function eventName(): string
    {
        return 'vidyalms.learner.signed_in';
    }

    public function payload(): array
    {
        return ['user_id' => $this->userId];
    }

    public function idempotencyKey(): string
    {
        return 'sign-in:' . $this->reference;
    }

    public function activityRef(): ActivityRef
    {
        return new ActivityRef($this->userId);
    }
}
