<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Credentials\BackfillCertificates;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The reconciliation sweep for issuance, started by the host's own recurring
 * clock.
 *
 * Nothing else can start it: a completed enrolment with no certificate is
 * precisely the case where no event will ever fire again, so a sweep is the
 * only thing that will ever look for the gap.
 */
final class BackfillCertificatesTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.certificate.backfill';

    /** Bins per period, matching the other daily sweeps. */
    private const BIN_COUNT = 24;

    public function __construct(private readonly BackfillCertificates $backfill)
    {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $binIndex = $run->int('binIndex', 0);
        $binCount = $run->int('binCount', self::BIN_COUNT);
        $issued = $this->backfill->execute($binIndex, $binCount);

        return sprintf('Certificate backfill %d of %d issued %d certificate(s).', $binIndex, $binCount, $issued);
    }

    public function binCount(): int
    {
        return self::BIN_COUNT;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'certificate.backfill:' . $periodBucket . ':' . $binIndex;
    }
}
