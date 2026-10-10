<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;
use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSuppressionPolicy;
use Shondalai\VidyaLMS\Core\Domain\Reporting\DeliveryBoundaryPolicy;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportSchedule;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportScopeDigest;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportRunStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportContext;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatRegistry;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\StreamWriter;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\AggregatesPeople;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\SupportsSuppression;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryRegistry;

/**
 * One tabular report, written through one export format, recorded as one run
 * and handed to one delivery provider.
 *
 * It is one class because the order of these steps is the whole of the
 * contract and splitting it would let a caller get the order wrong: the run row
 * opens **before** the report is computed so the 202 caller has an id to poll,
 * the suppression policy runs **between** the provider and the writer so that
 * the screen and the file suppress identically, and the delivery runs **after**
 * the artefact exists so a mail failure never discards the expensive half.
 *
 * It is not a god object and the boundary is stated: it resolves nothing, it
 * authorises nothing and it holds no report logic. Its callers authorise and
 * resolve; it turns an already-authorised context into an artefact and a
 * manifest row.
 */
final class ReportExportPipeline
{
    /** How long a download link lives. Short, because it is a link to personal data. */
    public const SIGNED_URL_SECONDS = 900;

    /** Settings, each a row rather than a column so a later per-report override has somewhere to go. */
    public const RETENTION_SETTING = 'reports.artefact_retention_days';
    public const SUPPRESSION_SETTING = 'reports.suppression_min_cell';
    public const CELL_SAFETY_SETTING = 'reports.csv_cell_safety_default';
    public const ROW_CAP_SETTING = 'reports.export_row_cap_sync';

    public const DEFAULT_RETENTION_DAYS = 30;

    /** How often report_runs.current_line is written while a run streams. */
    public const PROGRESS_EVERY = 500;

    public function __construct(
        private readonly ExportFormatRegistry $formats,
        private readonly ReportDeliveryRegistry $deliveries,
        private readonly ReportRunStore $runs,
        private readonly StoragePort $storage,
        private readonly ClockPort $clock,
        private readonly Settings $settings,
        /**
         * Trailing and defaulted, so a caller built before the delivery result
         * was recorded keeps constructing this class unchanged. A failed
         * delivery is logged as well as stored, because an operator watching a
         * log is the second reader of the same fact and neither one alone is
         * how a bounce gets noticed.
         */
        private readonly LoggerInterface $logger = new NullLogger(),
        /**
         * The field disclosure stage, which runs between the provider and the
         * writer so the screen and the file carry the same columns.
         *
         * Trailing and defaulted, so a caller built before it existed keeps
         * constructing this class. The default is
         * {@see DisclosurePipeline::failClosed()}, which discloses nothing inside
         * an organisation: an absent disclosure stage on an organisation-scoped
         * run is exactly the state the published miss direction is written for,
         * and the direction is that the field is omitted.
         */
        private readonly ?DisclosurePipeline $disclosure = null,
        /**
         * The two delivery refusals, consulted before the run row opens.
         *
         * Trailing and defaulted, and the default refuses what it cannot prove
         * safe: a caller with no audience resolver cannot show that an author
         * render or an attaching delivery stays inside one organisation, and a
         * rule about cross-tenant disclosure has one safe direction.
         */
        private readonly ?DeliveryBoundaryPolicy $boundary = null,
    ) {
    }

    /**
     * @param list<int> $recipients who the delivery provider should reach; the
     *                              requester when the caller names nobody
     */
    public function execute(
        TabularReportInterface $report,
        ReportContext $context,
        string $formatKey,
        ?string $deliveryKey,
        string $scopeDigest,
        ?int $organisationId = null,
        array $recipients = [],
        ?int $scheduleId = null,
        string $renderAs = DeliveryContext::RENDER_AS_RECIPIENT,
        /**
         * How long this run own artefact lives, when the owning organisation
         * has a longer policy than the site.
         *
         * Trailing and defaulted, so every site-scoped caller is unchanged and
         * keeps the site setting. It is passed rather than read here because
         * the owning organisation policy is an organisation-scoped read and
         * this class holds no scope: the caller that authorised the run has
         * one, and asking it is what stops a second unscoped read of an
         * organisation-owned table appearing in the reporting layer.
         */
        ?int $artefactDays = null,
        /**
         * What a run that produced no rows does about its delivery.
         *
         * Trailing and defaulted to null, which is exactly today's behaviour
         * for every shipped caller: no artefact at zero rows and the delivery
         * attempted anyway. A schedule passes one of
         * {@see ReportSchedule::EMPTY_POLICIES}, and this is the only place the
         * three outcomes can be told apart, because it is the only place that
         * knows the row count before the delivery runs.
         *
         * The branch is possible at all because `report_runs` distinguishes a
         * run that produced zero rows from one that failed, which is the third
         * of the three independent reasons the manifest exists.
         */
        ?string $emptyPolicy = null,
        ?string $messageSubject = null,
        ?string $messageBody = null,
    ): ReportRun {
        // Both refusals run before anything is written. A refusal that fired
        // after the artefact existed would leave a completed run holding
        // personal data nobody was ever allowed to receive, which is worse than
        // the delivery it prevented.
        ($this->boundary ?? DeliveryBoundaryPolicy::unresolved())->requireDeliverable(
            $report,
            $deliveryKey !== null && $deliveryKey !== '' && $this->deliveries->has($deliveryKey)
                ? $this->deliveries->get($deliveryKey)
                : null,
            $renderAs,
            $recipients,
            $organisationId,
        );

        if (!$this->formats->has($formatKey)) {
            // An unregistered format is absent from the negotiated set: a
            // caller that names none is written in the site's default and
            // never refused. A caller that named THIS one is refused, with the
            // list of what the site can write.
            //
            // Both documents used to say "never an error" here, by analogy
            // with CredentialFormat, and the analogy does not hold: a
            // credential format is negotiated through an Accept header, where
            // falling back to the first registered writer is the protocol,
            // and an export format is a named request parameter. Quietly
            // writing a CSV for a request that said xlsx hands somebody a file
            // their tool cannot open with nothing saying why. The R2 review
            // amended the tables to match this.
            throw ValidationException::forField('format', sprintf(
                'This site can write %s. It cannot write "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $this->formats->keys()),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $formatKey,
            ));
        }

        $format = $this->formats->get($formatKey);
        $run = $this->runs->open(new ReportRun(
            null,
            $report->key(),
            $formatKey,
            $deliveryKey,
            $context->parameters,
            1,
            $context->timezone,
            $scopeDigest,
            $context->principal->userId,
            // Who this artefact was rendered for, when a schedule rendered per
            // recipient rather than once under the author's scope. It is the
            // recipient and not the requester, and it is only written when
            // there is exactly one: a run served to two people was not
            // rendered for either of them in particular.
            $renderAs === DeliveryContext::RENDER_AS_RECIPIENT && \count($recipients) === 1
                ? $recipients[0]
                : null,
            $organisationId,
            $scheduleId,
            ReportRun::STATE_RUNNING,
            0,
            0,
            false,
            null,
            0,
            null,
            $this->clock->now(),
            null,
            $this->expiry($artefactDays),
            null,
            null,
            // Which recipe produced the digest above, so a shape bump is a
            // filterable migration rather than a silent orphaning of every
            // artefact manifest. Derived from the scope on the context rather
            // than passed in beside the digest, because the two must not be
            // able to disagree.
            ReportScopeDigest::shapeOf($context->visibility),
        ));

        $runId = $run->id ?? throw new \RuntimeException('An opened report run has no id.');

        try {
            [$rowCount, $bytes] = $this->write($report, $context, $format, $runId);
        } catch (\Throwable $failure) {
            $this->logger->error('Report export failed.', ['run_id' => $runId, 'exception' => $failure]);
            $this->runs->fail($runId, 'Report export failed. Ask a site administrator to check the server log.');

            throw $failure;
        }

        // A zero-row run writes nothing, unless the caller asked for the empty
        // report itself: "send with the empty report" means a real file with a
        // real header and no data rows, which is a different message from "the
        // report found nothing" and a different one again from silence.
        $writeArtefact = $rowCount > 0 || $emptyPolicy === ReportSchedule::EMPTY_SEND;
        $path = $writeArtefact ? $this->pathFor($report->key(), $runId, $format->extension()) : null;

        if ($path !== null) {
            $this->storage->putPrivate($path, $bytes);
        }

        $this->runs->complete($runId, $rowCount, false, $path, \strlen($bytes));
        $finished = $this->runs->find($runId) ?? throw new \RuntimeException('The report run row vanished.');

        // "Do not send at all" is the one outcome that has to be decided here
        // rather than inside a provider: a delivery provider handed an empty
        // run has already been told to deliver, and a provider that silently
        // declined would make a schedule that never mails indistinguishable
        // from one that is broken.
        $withheld = $rowCount === 0 && $emptyPolicy === ReportSchedule::EMPTY_SKIP;
        $deliverThrough = $withheld ? null : $deliveryKey;
        $delivery = $this->deliveries->deliverThrough($deliverThrough, $finished, DeliveryContext::create(
            $finished,
            $recipients,
            // An emailed bearer link lasts for this artefact's retention
            // window. Interactive downloads still use the short-lived URL.
            $path === null ? null : $this->storage->signedUrl($path, max(1, $finished->expiresAt->getTimestamp() - $this->clock->now()->getTimestamp())),
            $finished->expiresAt,
            $renderAs,
            'vidyalms.category.reports',
            $context->locale,
            $messageSubject,
            $messageBody,
        ));

        // The answer is recorded rather than discarded. The registry's
        // published miss direction is that the run completes, the artefact
        // waits and the schedule records a delivery failure the admin can see,
        // and the third clause had no implementation: a delivery to a provider
        // nobody registered ended state "done" with error null and no line
        // anywhere. It is a separate column pair rather than the run's own
        // state, because a run whose rows are perfect and whose mail bounced
        // is not a failed run.
        if (!$delivery->delivered) {
            $this->runs->recordDelivery($runId, ReportRun::DELIVERY_FAILED, $delivery->reason);
            $this->logger->warning(
                'A VidyaLMS report run was written but not delivered: run {run}, delivery {delivery}, {reason}.',
                ['run' => $runId, 'delivery' => $deliveryKey ?? 'none', 'reason' => $delivery->reason],
            );
        } elseif ($withheld) {
            // Deliberate silence, told apart from an unregistered delivery
            // provider. Both used to record "waiting", so a schedule working
            // exactly as it was configured looked identical to one whose
            // delivery key nobody registers.
            $this->runs->recordDelivery(
                $runId,
                ReportRun::DELIVERY_WITHHELD,
                'vidyalms.report.empty_withheld',
            );
        } else {
            $this->runs->recordDelivery(
                $runId,
                $deliverThrough === null || $deliverThrough === ''
                    ? ReportRun::DELIVERY_WAITING
                    : ReportRun::DELIVERY_DELIVERED,
            );
        }

        return $this->runs->find($runId) ?? $finished;
    }

    /**
     * The read-path stage, run identically by both report routes.
     *
     * It lives here beside the writer's own stage because the two halves of the
     * claim are one sentence: an aggregate safe on screen and unsafe in the CSV
     * is the same disclosure, and a column omitted in the file and shown on the
     * screen is the same defect with the halves swapped. The two read use cases
     * each ran half of it: the site route suppressed rows and never facts, never
     * probed {@see \Shondalai\VidyaLMS\Core\Registry\Report\AggregatesPeople} and
     * never called the disclosure stage, so a site-wide scalar report whose facts
     * are counts of people was served unsuppressed.
     *
     * @param array<string, mixed> $result the provider's own scalar half
     *
     * @return array{0: array<string, mixed>, 1: list<string>} the result and what was omitted
     */
    public function screenStage(
        ReportProviderInterface $report,
        ReportContext $context,
        array $result,
        int $page,
        int $perPage,
    ): array {
        $disclosure = $this->disclosureStage();
        [$result, $omitted] = $disclosure->applyToFacts($result, $context);
        $suppression = $this->suppressionFor($report, $context);
        $suppressed = 0;

        // The scalar half goes through both policies too. A summary block is
        // exactly as disclosing as a row and is exactly where per-report
        // suppression is forgotten: a five-person organisation's overview is
        // the smallest cell in the product.
        if ($report instanceof AggregatesPeople) {
            [$result, $suppressed] = $suppression->applyToFacts($result, $report->countFactKeys($context));
        }

        if ($report instanceof TabularReportInterface) {
            $declared = $report->columns($context);
            $columns = $disclosure->columnsFor($declared, $context);
            $omitted = array_values(array_unique([...$omitted, ...$disclosure->omitted($declared, $context)]));
            $items = [];
            $taken = 0;

            foreach ($report->rows($context) as $row) {
                $taken++;

                if ($taken <= ($page - 1) * $perPage) {
                    continue;
                }

                if ($taken > $page * $perPage) {
                    break;
                }

                // Disclosure first, then suppression, in the order the export
                // writer runs them, because suppression hides the cell that
                // would be recoverable from the ones a reader actually gets.
                [$safe, $hidden] = $suppression->applyToRow(
                    $disclosure->apply($row, $columns, $context),
                    $columns,
                );
                $items[] = $safe;
                $suppressed += $hidden;
            }

            $result['items'] = $items;
            $result['columns'] = array_map(
                static fn (ColumnDefinition $column): array => $column->toArray(),
                $columns,
            );
        }

        if ($suppressed > 0 || $report instanceof TabularReportInterface) {
            $result['suppressed_cells'] = $suppressed;
        }

        return [$result, $omitted];
    }

    /**
     * A signed, expiring URL for a finished run, or null when it has no
     * artefact.
     *
     * The core emits no other kind of URL for a private file, and a run past
     * its retention date is refused here rather than in the handler, because
     * the reason the artefact is gone is a property of the run.
     */
    public function downloadUrl(ReportRun $run): ?string
    {
        if (!$run->hasArtefact() || $run->storagePath === null) {
            return null;
        }

        if ($run->expiresAt < $this->clock->now()) {
            return null;
        }

        return $this->storage->signedUrl($run->storagePath, self::SIGNED_URL_SECONDS);
    }

    /**
     * The suppression the pipeline applies, resolved once from the setting.
     *
     * **The parameter is widened to any report provider and not narrowed to a
     * tabular one**, because a scalar report's summary block is exactly as
     * disclosing as a row and is exactly where per-report suppression is
     * forgotten. Widening a parameter type breaks no caller; requiring a tabular
     * report here would have meant the screen path resolving a second policy of
     * its own for the scalar half, which is the duplication this method exists
     * to prevent.
     *
     * A provider declaring {@see SupportsSuppression} answers for itself and
     * may answer null, which means it has already suppressed its own cells. It
     * has to opt out, because suppression implemented per report is suppression
     * forgotten in the eleventh one.
     */
    public function suppressionFor(ReportProviderInterface $report, ReportContext $context): CellSuppressionPolicy
    {
        if ($report instanceof SupportsSuppression) {
            return $report->suppressionFor($context) ?? CellSuppressionPolicy::none();
        }

        return CellSuppressionPolicy::ofMinimum(
            $this->settings->getInt(self::SUPPRESSION_SETTING, CellSuppressionPolicy::DEFAULT_MINIMUM),
        );
    }

    /**
     * The disclosure stage this pipeline applies, so the screen path applies the
     * same one rather than resolving a second.
     *
     * An aggregate safe on screen and unsafe in a download is the same
     * disclosure, and a column omitted in the file and shown on the screen is the
     * same defect with the halves swapped.
     */
    public function disclosureStage(): DisclosurePipeline
    {
        return $this->disclosure ?? DisclosurePipeline::failClosed();
    }

    /** The synchronous row cap, above which a caller is told to use the 202 route. */
    /**
     * The site floor an organisation policy may lengthen and never shorten.
     *
     * Published so the organisation report route can ask for it rather than
     * reading the setting a second time, which is how two answers to one
     * question start.
     */
    public function siteArtefactDays(): int
    {
        $days = $this->settings->getInt(self::RETENTION_SETTING, self::DEFAULT_RETENTION_DAYS);

        return $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;
    }

    public function syncRowCap(): int
    {
        return max(1, $this->settings->getInt(self::ROW_CAP_SETTING, 10000));
    }

    /**
     * @return array{0: int, 1: string} the row count and the bytes
     */
    private function write(
        TabularReportInterface $report,
        ReportContext $context,
        ExportFormatInterface $format,
        int $runId,
    ): array {
        // Disclosure first, then suppression. Suppression reasons about the
        // counts in a row and hides the one that would be recoverable by
        // subtraction, so it has to see the row a reader will actually get.
        $disclosure = $this->disclosure ?? DisclosurePipeline::failClosed();
        $columns = $disclosure->columnsFor($report->columns($context), $context);
        $suppression = $this->suppressionFor($report, $context);
        $out = new StreamWriter();
        $counted = 0;
        $deadline = microtime(true) + 20;
        $rowLimit = min(100000, $this->syncRowCap());

        $format->write(
            (function () use ($report, $context, $columns, $suppression, $disclosure, $runId, $deadline, $rowLimit, &$counted): iterable {
                foreach ($report->rows($context) as $row) {
                    $counted++;
                    if ($counted > $rowLimit || microtime(true) >= $deadline) {
                        throw new \RuntimeException('The export reached its row or time limit. Narrow the report filters.');
                    }

                    // A progress marker every batch rather than every row: the
                    // point of the column is that a twenty-minute export is
                    // observable, and writing it per row would make the marker
                    // the expensive part of the export.
                    if ($counted % self::PROGRESS_EVERY === 0) {
                        $this->runs->progress($runId, $counted);
                    }

                    [$suppressed] = $suppression->applyToRow(
                        $disclosure->apply($row, $columns, $context),
                        $columns,
                    );

                    yield $suppressed;
                }
            })(),
            $columns,
            ExportContext::create(
                CellSafetyPolicy::of($this->settings->getString(
                    self::CELL_SAFETY_SETTING,
                    CellSafetyPolicy::MODE_SPREADSHEET_SAFE,
                )),
                ExportContext::PROFILE_MACHINE,
                true,
                ExportContext::SHEET_ROW_LIMIT,
                $context->locale,
                $runId,
            ),
            $out,
        );

        return [$counted, $out->contents()];
    }

    /**
     * A provider key reaches a storage path, so it is reduced to one safe
     * segment before it gets there.
     */
    private function pathFor(string $key, int $runId, string $extension): string
    {
        $segment = preg_replace('/[^A-Za-z0-9_.-]/', '-', $key) ?? '';
        $segment = trim(str_replace('..', '-', $segment), '.-');

        return 'reports/' . ($segment === '' ? 'unknown' : $segment) . '/' . $runId . '.' . $extension;
    }

    /**
     * When this artefact stops being allowed to exist.
     *
     * Populated at insert from the retention setting, never left null, and a
     * setting of zero or less is read as the default rather than as "keep for
     * ever": a retention of nothing is a configuration mistake and honouring it
     * literally would delete an artefact the moment it was written.
     */
    private function expiry(?int $artefactDays = null): \DateTimeImmutable
    {
        $days = $this->settings->getInt(self::RETENTION_SETTING, self::DEFAULT_RETENTION_DAYS);
        $days = $days > 0 ? $days : self::DEFAULT_RETENTION_DAYS;

        // The owning organisation policy, with the site value as a floor. The
        // design says every per-organisation artefact stamps its expiry from
        // the owning organisation policy at insert, and this read the site-wide
        // setting for every run: an organisation that had set artefact_days had
        // set a column nothing looked at.
        if ($artefactDays !== null && $artefactDays > $days) {
            $days = $artefactDays;
        }

        return $this->clock->now()->modify('+' . $days . ' days');
    }
}
