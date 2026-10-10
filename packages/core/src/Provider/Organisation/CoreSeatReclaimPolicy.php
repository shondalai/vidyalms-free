<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatReclaimContext;
use Shondalai\VidyaLMS\Core\Registry\SeatReclaim\SeatReclaimInterface;

/**
 * One of the four free reclaim policies.
 *
 * Never, always, before the learner started, and before they finished.
 *
 * A completed seat is refused by the release ceremony itself, beside the other
 * seat invariants, so a fifth policy registered by a package cannot reopen a
 * finished seat by forgetting a clause and every policy is still asked. That
 * guard used to sit in the registry, where it answered before any provider
 * was consulted.
 */
final class CoreSeatReclaimPolicy implements SeatReclaimInterface
{
    public function __construct(private readonly string $name)
    {
    }

    public function key(): string
    {
        return $this->name;
    }

    public function label(): string
    {
        return 'org.seat_reclaim.' . $this->name;
    }

    public function allows(SeatReclaimContext $context): bool
    {
        return match ($this->name) {
            'always' => true,
            'before_start' => !$context->started,
            'before_completion' => !$context->completed,
            default => false,
        };
    }
}
