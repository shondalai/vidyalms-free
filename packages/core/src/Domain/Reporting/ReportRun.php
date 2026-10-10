<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

/**
 * One row of `report_runs`, as the domain sees it.
 *
 * `GenerateReportTask` used to JSON-encode a result and write it to
 * `reports/{key}/{jobId}.json` with no run record, no format, no row count, no
 * truncation flag, no expiry and nothing that handed the caller a URL, even
 * though `StoragePort::signedUrl()` already existed. Three separate problems
 * force this manifest and each is independent of the others:
 *
 * 1. The download must be a signed expiring URL, and nothing recorded which
 *    path belonged to which request.
 * 2. An artefact of personal data needs a retention date under GDPR Article
 *    5(1)(e), and a sweep must be able to find it without opening object
 *    storage by hand.
 * 3. A run that produced **zero rows** must be distinguishable from one that
 *    **failed**, because a schedule's empty-report policy branches on exactly
 *    that.
 *
 * Two fields are worth their own sentence. `scopeDigest` records which
 * visibility scope produced the artefact, so a cached run is never handed to a
 * scope it was not computed for; it is `OrgScope::digest()` verbatim for an
 * organisation run, carrying that class's own `v1` shape prefix inside the
 * hash. And because `OrgScope::digest()` deliberately omits the actor, so that
 * two administrators of one organisation can share an artefact they are both
 * entitled to every row of, **a run whose rows depend on the acting person is
 * keyed on the digest and the requester together**, which is what
 * {@see artefactKey()} composes.
 *
 * `timezone` is part of the run's **identity** and not of its rendering. A
 * period-bounded, timezone-sensitive extract produced from
 * `POST /manage/reports/{key}/exports` that recorded nothing about the boundary
 * which determined its buckets cannot be regenerated identically, and an
 * artefact a seller files against has to be.
 */
final class ReportRun
{
    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_DONE = 'done';

    /** Ran, succeeded, produced no rows. Not a failure, and the schedule branches on it. */
    public const STATE_EMPTY = 'empty';

    public const STATE_FAILED = 'failed';

    /** The sweep removed the artefact; the manifest row survives as evidence. */
    public const STATE_EXPIRED = 'expired';

    /**
     * The delivery states, which are deliberately not the run states.
     *
     * A run whose rows are perfect and whose mail bounced is not a failed run,
     * and the ReportDelivery miss direction says so: the run completes, the
     * artefact waits, and the failure is recorded where an admin can see it.
     * Collapsing the two would either hide the bounce or discard the expensive
     * half to report a mail problem.
     */
    public const DELIVERY_DELIVERED = 'delivered';

    /** The artefact is waiting where the caller can already reach it; no delivery was asked for. */
    public const DELIVERY_WAITING = 'waiting';

    /**
     * The run found nothing and its schedule asked for silence.
     *
     * It is its own state rather than `waiting`, which was the value a run got
     * both when the empty policy withheld it deliberately and when the named
     * delivery provider was not registered at all. An operator reading the
     * manifest could not tell a schedule that is working quietly from one whose
     * delivery registration is broken, which is exactly the distinction the
     * manifest's third stated reason exists to support.
     */
    public const DELIVERY_WITHHELD = 'withheld';

    public const DELIVERY_FAILED = 'failed';

    /** @var list<string> */
    public const STATES = [
        self::STATE_QUEUED,
        self::STATE_RUNNING,
        self::STATE_DONE,
        self::STATE_EMPTY,
        self::STATE_FAILED,
        self::STATE_EXPIRED,
    ];

    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $reportKey,
        public readonly string $formatKey,
        public readonly ?string $deliveryKey,
        public readonly array $parameters,
        public readonly int $parametersVersion,
        public readonly string $timezone,
        public readonly string $scopeDigest,
        public readonly int $requestedBy,
        public readonly ?int $renderedAs,
        public readonly ?int $organisationId,
        public readonly ?int $scheduleId,
        public readonly string $state,
        public readonly int $rowCount,
        /**
         * How far this run has got, written as the export streams.
         *
         * It is beside `rowCount` rather than instead of it because they are
         * different facts: one is progress and one is the answer. A long
         * organisation export that could only be described as "running" is
         * pending rather than observable, and an operator watching one has
         * nothing to tell the person who asked for it.
         */
        public readonly int $currentLine,
        public readonly bool $truncated,
        public readonly ?string $storagePath,
        public readonly int $byteSize,
        public readonly ?string $error,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $finishedAt,
        public readonly \DateTimeImmutable $expiresAt,
        /**
         * What happened to the delivery, beside what happened to the run.
         *
         * Trailing and defaulted, because this constructor is called
         * positionally from the pipeline, the store and both hosts' tests: an
         * additive trailing parameter is the one shape that does not break a
         * caller. Null means the run has not reached its delivery step yet.
         */
        public readonly ?string $deliveryState = null,
        public readonly ?string $deliveryError = null,
        /** Which recipe wrote scopeDigest. See {@see ReportScopeDigest::shapeOf()}. */
        public readonly string $scopeDigestShape = ReportScopeDigest::SHAPE,
    ) {
        if ($reportKey === '') {
            throw new \InvalidArgumentException('A report run names its report.');
        }

        if (!\in_array($state, self::STATES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('"%s" is not a report run state.', $state));
        }
    }

    /**
     * The key an artefact is cached and found under.
     *
     * The digest alone is not enough and the reason is written into
     * `OrgScope::digest()`'s own docblock: it identifies the reach and not the
     * reader, deliberately, so a "my direct reports" report keyed on the digest
     * alone would be served to a second administrator holding the same role.
     * Every per-actor artefact in this product keys on the digest **and** the
     * actor id, and this is the one place that composition is written.
     */
    public static function artefactKey(string $scopeDigest, int $requestedBy): string
    {
        return $scopeDigest . ':u' . $requestedBy;
    }

    public function isTerminal(): bool
    {
        return \in_array($this->state, [self::STATE_DONE, self::STATE_EMPTY, self::STATE_FAILED, self::STATE_EXPIRED], true);
    }

    public function hasArtefact(): bool
    {
        return $this->storagePath !== null && $this->state === self::STATE_DONE;
    }
}
