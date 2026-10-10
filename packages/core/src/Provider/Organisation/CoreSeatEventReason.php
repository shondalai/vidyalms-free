<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatEventReason;
use Shondalai\VidyaLMS\Core\Registry\SeatEventReason\SeatEventReasonInterface;

/**
 * One of the eleven free reason keys.
 *
 * The registry is a label lookup and nothing more, deliberately: the ledger
 * stores the key whether or not a provider resolves it, so an uninstalled
 * package costs a label and never a reason. That is why this provider carries
 * no decision method and why the read path asks the registry rather than the
 * writer.
 *
 * Three of the ten are inherited from the commerce revocation reasons rather
 * than invented here, so a refund that revokes a seat and a refund that
 * revokes a personal purchase are one word in both ledgers.
 */
final class CoreSeatEventReason implements SeatEventReasonInterface
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
        return 'org.seat_reason.' . $this->name;
    }

    public function group(): string
    {
        return SeatEventReason::group($this->name);
    }
}
