<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\DomainStatus;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Identity\DomainVerificationPort;
use Shondalai\VidyaLMS\Core\Port\Identity\UnverifiedDomains;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The only writer that demotes a verified domain.
 *
 * Verification is not a one-time event. A product that checks a domain once
 * and trusts the answer for ever has a latent hole: the record is deleted or
 * changed, the organisation no longer controls the namespace, and every policy
 * that depended on the claim keeps applying. So the claim is re-checked and
 * demoted, and that is why last_checked_at and failure_count are real columns
 * rather than derived facts.
 *
 * **On a free build it answers "unverifiable" and demotes nothing, and that is
 * a published answer rather than a no-op.** The refusing default port cannot
 * distinguish "the record is gone" from "nobody here can look", and demoting a
 * verified claim on the second would revoke a customer's working configuration
 * the first time this task ran after an upgrade. So the task probes the port,
 * says in its own result line that no verifier is installed, and leaves every
 * claim exactly as it found it. It is registered free anyway, because the task
 * key is stored in host job rows and both drains resolve a handler by it: a key
 * that appears only when a paid package is installed is a key that fails to
 * resolve on every site that later removes one.
 *
 * The organisation comes from the row it swept and never from anywhere else,
 * which is what makes a sweep across every organisation still a per-tenant
 * write. It is the same shape the four shipped organisation sweeps have.
 */
final class DomainRecheckTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    /**
     * The key follows the vocabulary every registered task on both hosts uses.
     * The design's table spells it "org.domain_recheck"; a host job row stores
     * this string and both drains resolve a handler by it, so it is spelled the
     * way the other fifteen are and renaming it later would rewrite stored job
     * rows.
     */
    public const KEY = 'vidyalms.organisation.domain_recheck';

    /** How long a verified claim is trusted before it is looked at again. */
    public const RECHECK_HOURS = 24;

    /**
     * How many consecutive failures demote a claim.
     *
     * More than one, deliberately. A single lookup failure is routinely a
     * transient resolver problem, and demoting on it would revoke a working
     * configuration because a name server was briefly slow. Three consecutive
     * failures over three runs is a namespace the organisation no longer
     * controls.
     */
    public const FAILURE_THRESHOLD = 3;

    /** How many claims one run looks at, so a large site drains over several runs. */
    public const BATCH = 100;

    public function __construct(
        private readonly OrgIdentityStore $store,
        private readonly DomainVerificationPort $verification,
        private readonly ClockPort $clock,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        if ($this->verification instanceof UnverifiedDomains) {
            // The honest answer, and the reason it is not a demotion. See the
            // class docblock: a refusing default cannot tell a missing record
            // from a missing verifier.
            return 'unverifiable: no domain verification provider is installed, so no claim was rechecked';
        }

        $now = $this->clock->now();
        $due = $this->store->domainsDueRecheck($now->modify('-' . self::RECHECK_HOURS . ' hours'), self::BATCH);
        $checked = 0;
        $held = 0;
        $demoted = 0;
        $promoted = 0;

        foreach ($due as $row) {
            $organisationId = (int) ($row['organisation_id'] ?? 0);
            $domainId = (int) ($row['id'] ?? 0);
            $domain = (string) ($row['domain'] ?? '');

            if ($organisationId < 1 || $domainId < 1 || $domain === '') {
                continue;
            }

            $checked++;
            $stamp = $now->format('Y-m-d H:i:s');
            $status = (string) ($row['status'] ?? DomainStatus::PENDING);

            if ($this->verification->isVerified($organisationId, $domain)) {
                if ($status === DomainStatus::VERIFIED) {
                    $held++;
                    $this->store->recordRecheck($organisationId, $domainId, [
                        'last_checked_at' => $stamp,
                        'failure_count' => 0,
                    ]);

                    continue;
                }

                // The promotion, which had no writer anywhere. Three of the
                // eight published domain transitions were promotions and all
                // three were unreachable, so installing a verifier completed
                // nothing: a claim landed pending and stayed pending for ever.
                // It runs through the state machine rather than around it.
                if (!DomainStatus::permits($status, DomainStatus::VERIFIED)) {
                    continue;
                }

                $promoted++;
                $this->store->recordRecheck($organisationId, $domainId, [
                    'status' => DomainStatus::VERIFIED,
                    'verified_key' => DomainStatus::verifiedKeyFor(DomainStatus::VERIFIED, $domain),
                    'verified_at' => $stamp,
                    'last_checked_at' => $stamp,
                    'failure_count' => 0,
                ]);

                continue;
            }

            $failures = (int) ($row['failure_count'] ?? 0) + 1;

            if ($status === DomainStatus::FAILED) {
                // Already failed and still unprovable: the clock moves and
                // nothing else does, so a claim nobody is fixing is not
                // rewritten on every run.
                $this->store->recordRecheck($organisationId, $domainId, ['last_checked_at' => $stamp]);

                continue;
            }

            if ($failures < self::FAILURE_THRESHOLD) {
                $this->store->recordRecheck($organisationId, $domainId, [
                    'last_checked_at' => $stamp,
                    'failure_count' => $failures,
                ]);

                continue;
            }

            $demoted++;
            $this->store->recordRecheck($organisationId, $domainId, [
                'status' => DomainStatus::FAILED,
                // Releasing the key is half the demotion. A claim that is no
                // longer verified must stop blocking another organisation from
                // proving the same domain, and leaving the key behind would
                // make one organisation's lapsed claim a permanent lock.
                'verified_key' => null,
                'verified_at' => null,
                'last_checked_at' => $stamp,
                'failure_count' => $failures,
            ]);
        }

        return sprintf(
            'checked=%d held=%d promoted=%d demoted=%d',
            $checked,
            $held,
            $promoted,
            $demoted,
        );
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::KEY . ':' . $periodBucket;
    }
}
