<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * The attempt ledger a caller gets when no persistence has been wired yet.
 *
 * It keeps the rows in memory rather than discarding them, which is the
 * difference between a null object and a lie: a caller constructed without a
 * repository can still be asked what it tried, and a test can read the chain
 * back without a database. Nothing here writes a row anywhere.
 */
final class InMemoryAttemptLedger implements AiAttemptLedgerInterface
{
    /** @var list<AiAttemptRecord> */
    private array $attempts = [];

    public function record(AiAttemptRecord $attempt): void
    {
        $this->attempts[] = $attempt;
    }

    /**
     * @return list<AiAttemptRecord>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }
}
