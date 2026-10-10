<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Organisation\ProvisioningWatermarks;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\SupportsDelta;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Runs each registered provisioning source from its watermark.
 *
 * **On a free build it runs nothing, and that is a published result rather
 * than a no-op.** The three free sources are all one-shot provenances: an
 * administrator's own hand, an accepted invitation, and an uploaded file.
 * None of them declares SupportsDelta, so the sweep finds nothing to sweep and
 * says so in its own result line.
 *
 * It is registered free anyway, for the reason every task key on both hosts is
 * registered where it is: the key is stored in host job rows and both drains
 * resolve a handler by it, so a key that appears only when a paid package is
 * installed is a key that fails to resolve on every site that later removes
 * one. A registered handler answering "nothing to do" is a drained job; an
 * unregistered key is a job that sits pending for ever with an unresolved-type
 * marker on it.
 *
 * The watermark is advanced only after a source's own run returns, and never
 * when it throws. Re-reading a page is cheap and idempotent; skipping one is a
 * person who never arrives.
 */
final class ProvisioningSyncTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    /**
     * The key follows the vocabulary every registered task on both hosts uses.
     * The design's table spells it "org.provisioning_sync"; a host job row
     * stores this string and both drains resolve a handler by it.
     */
    public const KEY = 'vidyalms.organisation.provisioning_sync';

    /** How many organisations one run sweeps, so a large site drains over several runs. */
    public const BATCH = 50;

    public function __construct(
        private readonly ProvisioningSourceRegistry $sources,
        private readonly ProvisioningWatermarks $watermarks,
        private readonly OrgIdentityStore $store,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $continuous = $this->continuousSources();

        if ($continuous === []) {
            return 'idle: no registered provisioning source sweeps incrementally';
        }

        $organisations = $this->store->organisationsWithVerifiedConnections(self::BATCH);
        $swept = 0;
        $seen = 0;
        $changed = 0;

        foreach ($organisations as $organisationId) {
            foreach ($continuous as $source) {
                $key = $source->key();
                $result = $source->sweepFrom($organisationId, $this->watermarks->watermarkFor($organisationId, $key));
                $swept++;
                $seen += (int) ($result['seen'] ?? 0);
                $changed += (int) ($result['changed'] ?? 0);

                // After the rows, never before. A run that failed part way has
                // already thrown by here and the previous watermark stands.
                $this->watermarks->advance($organisationId, $key, $result['watermark'] ?? null);
            }
        }

        return sprintf(
            'sources=%d organisations=%d runs=%d seen=%d changed=%d',
            \count($continuous),
            \count($organisations),
            $swept,
            $seen,
            $changed
        );
    }

    /**
     * The registered sources that can be swept at all.
     *
     * Both conditions, because they are two different claims: a source may be
     * continuous and still have no incremental read, and a source declaring
     * the incremental capability while calling itself one-shot has said two
     * contradictory things and is not swept on the safer of them.
     *
     * @return list<ProvisioningSourceInterface&SupportsDelta>
     */
    private function continuousSources(): array
    {
        $sweepable = [];

        foreach ($this->sources->all() as $source) {
            if ($source instanceof SupportsDelta && $source->isContinuous()) {
                $sweepable[] = $source;
            }
        }

        return $sweepable;
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d H');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
