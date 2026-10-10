<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;

/** Durable domain jobs recover even if immediate scheduling fails or a worker is interrupted. */
final class OrganisationTerminationTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const KEY = 'vidyalms.organisation.terminate_access';

    public function __construct(
        private readonly OrganisationAccessTermination $termination,
        private readonly OrganisationStore $store,
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        // Ignore external actor and tenant payloads: the durable job alone
        // carries prior authority and immutable target high-water marks.
        $jobs = $this->store->database->selectAll(Query::from('org_access_termination_jobs')
            ->where('state', '=', 'queued')->orderBy('id')->limit(5));
        $processed = 0;
        foreach ($jobs as $job) {
            try {
                $processed += $this->termination->runChunk((int) $job['id'], $this->store);
            } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException $conflict) {
                // CAS contention is retryable, not a permanent domain failure.
                throw $conflict;
            } catch (\Throwable $failure) {
                $this->store->database->update(
                    'org_access_termination_jobs',
                    ['state' => 'failed',
                    'failure_code' => 'cleanup_failed', 'updated_at' => $this->store->now()],
                    ['id' => (int) $job['id'], 'organisation_id' => (int) $job['organisation_id'], 'state' => 'queued']
                );
                throw $failure;
            }
        }
        if ($this->store->database->count(Query::from('org_access_termination_jobs')->where('state', '=', 'queued')) > 0) {
            $now = $this->clock->now();
            $this->scheduler->schedule(new ScheduledTask(
                self::KEY,
                [],
                $now->modify('+5 seconds'),
                self::KEY . ':continue:' . $now->format('YmdHis')
            ));
        }

        return 'Processed ' . $processed . ' organisation access relationships.';
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d\TH:i');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
