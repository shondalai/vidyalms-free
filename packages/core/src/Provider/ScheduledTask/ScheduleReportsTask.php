<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Reporting\ReportExportPipeline;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportScheduleStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\OrgScopedReportInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Mints the occurrence, generates, stores and delivers, for every schedule
 * whose next run has arrived.
 *
 * **No report mailer is built.** The message rides the notification engine, in
 * the shape the design prescribes: the reference is minted in the published
 * `{source}:{key}:{subjectId}:{periodBucket}` form through
 * {@see \Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence},
 * the artefact reaches the recipient through the `ReportDelivery` registry, and
 * the free provider there enqueues one `notification_queue` row carrying a
 * signed expiring URL. A second mailer beside the one in the product would have
 * its own quiet hours, its own unsubscribe handling and its own bounce
 * accounting, which is three ways for one site to disagree with itself.
 *
 * **It renders per recipient by default and that is a security property, not a
 * convenience.** Rendering once under the author's visibility and mailing the
 * same file to a mixed audience is a data breach the first time a site has two
 * group leaders. Each recipient gets a run computed under their own
 * {@see VisibilityScope}, so one schedule serves twenty leaders correctly.
 * Author scope is an explicit choice and
 * {@see \Shondalai\VidyaLMS\Core\Domain\Reporting\DeliveryBoundaryPolicy}
 * refuses it outright once the audience spans more than one organisation; the
 * pipeline consults that policy before the run row opens.
 *
 * **One turn is bounded.** A site with forty schedules and a hundred
 * recipients each is four thousand exports, and a task that tried them all in
 * one turn would be killed by the host halfway through with no record of where
 * it stopped. The batch is small, the cursor is `next_run_at`, and a schedule
 * that was served is pushed to its next boundary before the next one starts.
 */
final class ScheduleReportsTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const KEY = 'reports.schedule';

    /** The occurrence source, in the prescribed reference form. */
    public const SOURCE = 'schedule';

    /** How many schedules one turn serves. */
    private const BATCH = 10;

    /** How many recipients one schedule serves in one turn. */
    private const RECIPIENT_CAP = 200;

    public function __construct(
        private readonly ReportScheduleStore $schedules,
        private readonly ReportRegistry $reports,
        private readonly ReportExportPipeline $pipeline,
        private readonly AudienceResolverRegistry $audiences,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
        private readonly AuthorizationPort $authorization,
        /**
         * Trailing and defaulted. A recipient whose run could not be computed
         * is a fact an operator has to be able to find, and the schedule row
         * records only the failure that stopped the whole turn.
         */
        private readonly LoggerInterface $logger = new NullLogger(),
        /**
         * The tenant an organisation-anchored schedule runs under.
         *
         * Trailing and defaulted, so a harness built before organisation
         * schedules existed keeps constructing this class. A cron has no
         * caller, so the scope is witnessed the way every other free
         * organisation sweep witnesses one: from the row's own recorded
         * organisation and its recorded author. Without it every
         * organisation-scoped report on a schedule threw inside the provider
         * and the disclosure stage ran against a null scope, which omits
         * nothing.
         */
        private readonly ?OrganisationSweepAuthority $tenants = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $now = $this->clock->now();
        $served = 0;
        $runs = 0;
        $skipped = 0;
        $deadline = microtime(true) + 25;

        foreach ($this->schedules->due($now, self::BATCH) as $schedule) {
            $id = $schedule->id;

            if (microtime(true) >= $deadline) {
                break;
            }
            if ($id === null || !$this->schedules->claim($schedule, $now)) {
                continue;
            }

            $served++;

            try {
                [$written, $partial] = $this->serve($schedule, $now, $deadline);
                $runs += $written;
                // A recipient the run could not be computed for is recorded on
                // the schedule row and not only in a logger nobody has wired
                // up. A schedule whose every recipient failed answered
                // run_count with last_error null, which reads exactly like a
                // schedule that worked.
                $this->schedules->recordRun($id, $now, $schedule->nextDueAfter($now), $partial);

                if ($partial !== null) {
                    $skipped++;
                }
            } catch (\Throwable $failure) {
                // The failure is recorded on the schedule and the boundary
                // still moves. A schedule that stayed due would be retried on
                // every tick for ever, and a site whose report provider was
                // uninstalled would then have one task failing every hour with
                // nothing saying which schedule caused it.
                $skipped++;
                $this->logger->warning('Scheduled report failed.', ['schedule' => $id, 'exception' => $failure]);
                $this->schedules->recordRun($id, $now, $schedule->nextDueAfter($now), 'The scheduled report could not be completed. Check the site log.');
            }
        }

        return sprintf('reports.schedule served=%d runs=%d failed=%d', $served, $runs, $skipped);
    }

    /**
     * One schedule run on demand, without moving its recurrence.
     *
     * It is the same code the clock walks, deliberately: a schedule that works
     * at 03:00 has to be the schedule somebody tested by pressing Run, and two
     * implementations of one thing is how the tested one and the fired one stop
     * agreeing. The boundary is untouched, because an administrator asking for
     * a copy is not asking for tonight's run to be skipped.
     *
     * @return list<array<string, mixed>> one entry per run written
     */
    public function serveNow(ReportSchedule $schedule): array
    {
        $before = $this->runsFor($schedule);
        $this->serve($schedule, $this->clock->now());

        return \array_slice($this->runsFor($schedule), \count($before));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function runsFor(ReportSchedule $schedule): array
    {
        if ($schedule->id === null) {
            return [];
        }

        $rows = $this->database->selectAll(
            \Shondalai\VidyaLMS\Core\Port\Database\Query::from('report_runs')
                ->select(['id', 'report_key', 'format_key', 'state', 'row_count', 'rendered_as'])
                ->where('schedule_id', '=', $schedule->id)
                ->orderBy('id', \Shondalai\VidyaLMS\Core\Port\Database\Query::SORT_ASC),
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'report' => (string) $row['report_key'],
            'format' => (string) $row['format_key'],
            'state' => (string) $row['state'],
            'row_count' => (int) $row['row_count'],
            'rendered_as' => isset($row['rendered_as']) ? (int) $row['rendered_as'] : null,
        ], $rows);
    }

    public function binCount(): int
    {
        // One bin. The batch is already bounded and the claim is a single
        // ordered read of one index, so sharding would only make two runs
        // contend for the same head of the queue.
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d-H');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return self::SOURCE . ':' . self::KEY . ':' . $periodBucket . ':' . $binIndex;
    }

    /**
     * One schedule served, and the number of runs it produced.
     *
     * @return array{0: int, 1: ?string} the runs written and the first
     *                                   per-recipient failure, if any
     */
    private function serve(ReportSchedule $schedule, \DateTimeImmutable $now, ?float $deadline = null): array
    {
        if (!$this->reports->has($schedule->reportKey)) {
            // The registry-miss direction, applied here rather than guessed:
            // a schedule naming a report nobody registers produces nothing and
            // says why on the schedule row. It does not fall back to another
            // report, because a file computed under a rule nobody asked for is
            // worse than no file.
            throw new \RuntimeException(sprintf(
                'No report is registered under "%s", so this schedule produced nothing.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $schedule->reportKey,
            ));
        }

        $report = $this->reports->get($schedule->reportKey);

        if (!$report instanceof TabularReportInterface) {
            throw new \RuntimeException(sprintf(
                'The report "%s" has no rows to export, so it cannot be scheduled.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $schedule->reportKey,
            ));
        }

        $author = Principal::user($schedule->createdBy);
        $this->guard->require($author, \Shondalai\VidyaLMS\Core\Application\Shared\Capability::MANAGE);
        $this->guard->require($author, $report->requiredCapability());
        // The tenant is witnessed before the audience is asked, because an
        // organisation audience is answerable only inside one.
        $tenant = $this->tenantOf($schedule);
        $recipients = $this->recipientsOf($schedule, $tenant);

        if ($schedule->organisationId !== null && $tenant === null) {
            // The witness is the row's own recorded organisation and author,
            // exactly as every other free organisation sweep recovers one. A
            // schedule whose author is gone has no tenant to act as, and
            // guessing one would be the synthetic system principal the access
            // spine refuses everywhere else.
            throw new \RuntimeException(sprintf(
                'This schedule is anchored to organisation %d and no tenant could be witnessed from its own row, '
                . 'so nothing was computed for it.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $schedule->organisationId,
            ));
        }

        if ($report instanceof OrgScopedReportInterface) {
            if ($tenant === null) {
                throw new \RuntimeException(sprintf(
                    'The report "%s" is organisation-scoped and this schedule is anchored to no organisation, '
                    . 'so there is no tenant to compute it inside.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $schedule->reportKey,
                ));
            }

            if (!$report instanceof HonoursOrgScope) {
                throw new \RuntimeException(sprintf(
                    'The report "%s" was not written to honour an organisation scope, so it is refused inside one '
                    . 'rather than trusted to narrow itself.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $schedule->reportKey,
                ));
            }
        }

        if ($schedule->rendersPerRecipient()) {
            return $this->renderPerRecipient($schedule, $report, $recipients, $tenant, $deadline ?? microtime(true) + 25);
        }

        // Author render: one artefact under the author's own scope, sent to
        // everybody. The pipeline consults the boundary policy before the run
        // row opens, so an audience spanning two organisations is refused here
        // rather than delivered.
        $this->pipeline->execute(
            $report,
            $this->contextFor($schedule, $author, $tenant === null ? $this->scopeFor($author) : null, $tenant),
            $schedule->formatKey,
            $schedule->deliveryKey,
            $tenant?->digest() ?? ReportScopeDigest::of($this->scopeFor($author)),
            $schedule->organisationId,
            $recipients,
            $schedule->id,
            DeliveryContext::RENDER_AS_AUTHOR,
            null,
            $schedule->emptyPolicy,
            $schedule->subject,
            $schedule->body,
        );

        return [1, null];
    }

    /**
     * The tenant an organisation-anchored schedule runs under, or nothing.
     *
     * It never falls back to a scope the request did not witness: a schedule
     * anchored to no organisation has no tenant, and a schedule whose author
     * is gone has none either.
     */
    private function tenantOf(ReportSchedule $schedule): ?OrgScope
    {
        if ($schedule->organisationId === null || $schedule->id === null || $this->tenants === null) {
            return null;
        }

        return $this->tenants->forReportSchedule($schedule->id);
    }

    /**
     * @param list<int> $recipients
     *
     * @return array{0: int, 1: ?string}
     */
    private function renderPerRecipient(
        ReportSchedule $schedule,
        TabularReportInterface $report,
        array $recipients,
        ?OrgScope $tenant,
        float $deadline,
    ): array {
        $written = 0;
        $failure = null;

        foreach ($recipients as $recipient) {
            if (microtime(true) >= $deadline) {
                $failure = 'The report time limit was reached before every recipient could be served.';
                break;
            }
            $principal = Principal::user($recipient);

            if (!$this->guard->can($principal, $report->requiredCapability())) {
                // A recipient who may not read the report is not served it,
                // and the schedule is not failed by them: an audience is a
                // role and a role can hold somebody the report is not for.
                continue;
            }

            // Each recipient's own scope, so a group leader sees their groups
            // and never somebody else's. The scope is a digest input as well,
            // which is what stops one reader's artefact being handed to
            // another with a differently resolved view of the same rows.
            //
            // An organisation-anchored schedule runs under its witnessed
            // tenant instead, exactly as the organisation route does, and the
            // recipient's own visibility plays no part in it.
            $scope = $tenant === null ? $this->scopeFor($principal) : null;

            // The same probe both site entry points make, applied here because
            // it was the one path that did not. FiltersByVisibility's published
            // default is that a provider which does not declare it is site-wide
            // by definition, so a narrowed recipient was being mailed a
            // site-wide file that their own request for the same report is
            // refused. It is a skip and not a schedule failure, for the reason
            // the capability filter above is a skip: one recipient the report
            // cannot be narrowed for does not stop the other nineteen.
            if ($scope !== null && !$scope->isSiteWide() && !$report instanceof FiltersByVisibility) {
                $reason = sprintf(
                    'The report "%s" was not written to honour a visibility scope, so it is refused for a '
                    . 'narrowed recipient rather than trusted to narrow itself.',
                    $schedule->reportKey,
                );
                $failure ??= $reason;
                $this->logger->warning(
                    'A VidyaLMS scheduled report was not rendered for one recipient: '
                    . 'schedule {schedule}, recipient {recipient}, {reason}.',
                    ['schedule' => $schedule->id ?? 0, 'recipient' => $recipient, 'reason' => $reason],
                );

                continue;
            }

            try {
                $this->pipeline->execute(
                    $report,
                    $this->contextFor($schedule, $principal, $scope, $tenant),
                    $schedule->formatKey,
                    $schedule->deliveryKey,
                    $tenant?->digest() ?? ReportScopeDigest::of(
                        $scope ?? VisibilityScope::forUser($recipient),
                    ),
                    $schedule->organisationId,
                    [$recipient],
                    $schedule->id,
                    DeliveryContext::RENDER_AS_RECIPIENT,
                    null,
                    $schedule->emptyPolicy,
                    $schedule->subject,
                    $schedule->body,
                );

                $written++;
            } catch (\Throwable $thrown) {
                // One recipient the report cannot be computed for does not
                // fail the schedule for the other nineteen. That is the whole
                // argument for rendering per recipient: the runs are
                // independent, so their failures are too. It is still recorded
                // on the schedule row, because a failure that lives only in a
                // NullLogger is a failure nobody can find.
                $failure ??= 'A recipient report could not be generated. See the server log for details.';
                $this->logger->warning(
                    'A VidyaLMS scheduled report was not rendered for one recipient: '
                    . 'schedule {schedule}, recipient {recipient}, {reason}.',
                    [
                        'schedule' => $schedule->id ?? 0,
                        'recipient' => $recipient,
                        'reason' => $thrown->getMessage(),
                    ],
                );
            }
        }

        return [$written, $failure];
    }

    /**
     * The scope one recipient's run is computed under.
     *
     * A site manager reads the site, and anybody else reads their own rows.
     * That is the free build's whole answer, and it is deliberately the narrow
     * one: a richer resolution belongs to whatever seats the roles it would
     * resolve, and guessing wider here would hand a recipient rows their own
     * request would be refused.
     */
    private function scopeFor(Principal $principal): VisibilityScope
    {
        return $this->guard->can($principal, Capability::MANAGE)
            ? VisibilityScope::site()
            : VisibilityScope::forUser($principal->userId);
    }

    private function contextFor(
        ReportSchedule $schedule,
        Principal $principal,
        ?VisibilityScope $scope,
        ?OrgScope $tenant = null,
    ): ReportContext {
        return ReportContext::create(
            $schedule->definition,
            $principal,
            $scope,
            null,
            null,
            null,
            null,
            'en-GB',
            // The witnessed tenant, so an organisation-scoped provider can
            // narrow itself and the disclosure stage has a scope to omit
            // against. Passed as null it returned every column unchanged,
            // which is org_minimal withholding nothing.
            $tenant,
            // The schedule's own zone and never the site's. It is part of the
            // run's identity: an extract cut on the wrong midnight cannot be
            // regenerated identically, and report_runs.timezone is stamped
            // from here.
            $schedule->timezone,
        );
    }

    /**
     * The people this schedule is for, resolved inside the tenant it is
     * anchored to.
     *
     * The tenant is what makes an organisation audience answerable at all:
     * `org_admins`, `org_unit_managers`, `seat_admins`, the two cohort
     * audiences and `line_manager` all read `$context->tenant()` first and
     * yield nothing without one. It was not passed, so every organisation
     * anchored schedule resolved to nobody, wrote no run, recorded no error
     * and advanced to its next boundary, for ever. The audience registry's
     * miss direction is to resolve empty rather than broadcast, which is
     * exactly why the failure was silent: an empty audience and an
     * unanswerable one looked the same from the outside.
     *
     * A schedule with no organisation passes null and is unchanged.
     *
     * @return list<int>
     */
    private function recipientsOf(ReportSchedule $schedule, ?OrgScope $tenant = null): array
    {
        if (!$this->audiences->has($schedule->audienceKey)) {
            // The audience registry's published miss direction: resolve empty,
            // never broadcast. A schedule with an unresolvable audience mails
            // nobody rather than everybody.
            return [];
        }

        $context = AudienceContext::create(
            $this->database,
            $this->authorization,
            'report_schedule',
            $schedule->id,
            null,
            null,
            $schedule->audienceConfig,
            null,
            AudienceContext::DEFAULT_BATCH_SIZE,
            $tenant,
        );

        $recipients = [];

        foreach ($this->audiences->get($schedule->audienceKey)->resolve($context) as $userId) {
            if ($userId > 0 && !\in_array($userId, $recipients, true)) {
                $recipients[] = $userId;
            }

            if (\count($recipients) >= self::RECIPIENT_CAP) {
                break;
            }
        }

        return $recipients;
    }
}
